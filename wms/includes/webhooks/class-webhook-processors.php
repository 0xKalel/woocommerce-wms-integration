<?php
/**
 * WMS Webhook Event Processors
 * 
 * Handles the business logic for different webhook event types
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Base webhook processor
 */
abstract class WC_WMS_Webhook_Processor {
    
    /**
     * WMS client instance
     */
    protected $client;
    
    /**
     * Product sync manager instance
     */
    protected $productSyncManager;
    
    /**
     * Last processed time
     */
    protected $lastProcessedTime;
    
    /**
     * Constructor
     */
    public function __construct(WC_WMS_Client $client) {
        $this->client = $client;
        $this->productSyncManager = new WC_WMS_Product_Sync_Manager($client);
    }
    
    /**
     * Process webhook event
     */
    abstract public function process($action, $data, $entity_id = null);
    
    /**
     * Get supported actions for this processor
     */
    abstract public function getSupportedActions(): array;
    
    /**
     * Get last processed time
     */
    public function getLastProcessedTime(): ?string {
        return $this->lastProcessedTime;
    }
    
    /**
     * Update last processed time
     */
    protected function updateLastProcessedTime(): void {
        $this->lastProcessedTime = current_time('mysql');
    }
}

/**
 * Order webhook processor
 */
class WC_WMS_Order_Webhook_Processor extends WC_WMS_Webhook_Processor {
    
    /**
     * Centralized order sync manager
     */
    private $orderSyncManager;
    
    /**
     * Constructor
     */
    public function __construct(WC_WMS_Client $client) {
        parent::__construct($client);
        $this->orderSyncManager = new WC_WMS_Order_Sync_Manager($client);
    }

    /**
     * Get supported actions
     */
    public function getSupportedActions(): array {
        return ['created', 'updated', 'planned', 'processing', 'shipped'];
    }
    
    /**
     * Process order webhook - DELEGATED TO CENTRALIZED MANAGER
     */
    public function process($action, $data, $entity_id = null) {
        $this->client->logger()->info('Processing order webhook via centralized manager', [
            'action' => $action,
            'entity_id' => $entity_id,
            'order_status' => $data['status'] ?? 'unknown'
        ]);
        
        $this->updateLastProcessedTime();
        
        // Use centralized order sync manager for ALL webhook processing
        $result = $this->orderSyncManager->processWebhookOrderEvent($action, $data);
        
        if ($result['success']) {
            return [
                'status' => 'success',
                'message' => ucfirst($action) . ' webhook processed',
                'order_id' => $result['result']['order_id'] ?? null
            ];
        } else {
            return [
                'status' => 'error', 
                'message' => $result['error'],
                'external_reference' => $data['external_reference'] ?? ''
            ];
        }
    }
    
    /**
     * Handle order created in WMS
     */
    private function handleOrderCreated(array $data, ?string $entity_id): array {
        $external_reference = $data['external_reference'] ?? '';
        
        if (empty($external_reference)) {
            return ['status' => 'error', 'message' => 'Missing external reference'];
        }
        
        $order = $this->orderSyncManager->findOrderByExternalReference($external_reference);
        if (!$order) {
            return ['status' => 'error', 'message' => 'Order not found'];
        }
        
        // Store WMS order ID and update status
        $order->update_meta_data('_wms_order_id', $entity_id);
        $order->update_meta_data('_wms_created_at', current_time('mysql'));
        $order->update_meta_data('_wms_creation_data', json_encode($data));
        
        // Mark as WMS-managed to prevent export loops
        $order->update_meta_data('_wms_webhook_processed', 'yes');
        $order->update_meta_data('_wms_webhook_processed_at', current_time('mysql'));
        
        $order->add_order_note(__('Order successfully created in WMS', 'wc-wms-integration'));
        $order->save();
        
        $this->client->logger()->info('Order creation acknowledged', [
            'order_id' => $order->get_id(),
            'wms_order_id' => $entity_id
        ]);
        
        return ['status' => 'success', 'message' => 'Order creation acknowledged'];
    }
    
    /**
     * Handle order updated in WMS - Enhanced with status processing
     */
    private function handleOrderUpdated(array $data, ?string $entity_id): array {
        $external_reference = $data['external_reference'] ?? '';
        $wms_status = $data['status'] ?? '';
        
        if (empty($external_reference)) {
            return ['status' => 'error', 'message' => 'Missing external reference'];
        }
        
        $order = $this->orderSyncManager->findOrderByExternalReference($external_reference);
        if (!$order) {
            return ['status' => 'error', 'message' => 'Order not found'];
        }
        
        // CRITICAL VALIDATION: Verify order matches expected WMS entity
        $existing_wms_order_id = $order->get_meta('_wms_order_id');
        if (!empty($existing_wms_order_id) && $existing_wms_order_id !== $entity_id) {
            $this->client->logger()->error('WMS Order ID mismatch detected!', [
                'external_reference' => $external_reference,
                'wc_order_id' => $order->get_id(),
                'webhook_entity_id' => $entity_id,
                'stored_wms_order_id' => $existing_wms_order_id,
                'wms_status' => $wms_status
            ]);
            return [
                'status' => 'error', 
                'message' => 'WMS Order ID mismatch - possible webhook routing error'
            ];
        }
        
        // Handle specific status changes
        if (!empty($wms_status)) {
            $result = $this->processStatusChange($order, $wms_status, $data, $entity_id);
            if ($result !== null) {
                return $result;
            }
        }
        
        // Update order metadata
        $order->update_meta_data('_wms_updated_at', current_time('mysql'));
        $order->update_meta_data('_wms_update_data', json_encode($data));
        
        // Mark as WMS-managed to prevent export loops
        $order->update_meta_data('_wms_webhook_processed', 'yes');
        $order->update_meta_data('_wms_webhook_processed_at', current_time('mysql'));
        
        // Add order note if significant changes
        if (!empty($wms_status) && $wms_status !== $order->get_meta('_wms_last_status')) {
            $order->add_order_note(sprintf(
                __('Order status updated in WMS: %s', 'wc-wms-integration'),
                $wms_status
            ));
            $order->update_meta_data('_wms_last_status', $wms_status);
        }
        
        $order->save();
        
        $this->client->logger()->info('Order update processed', [
            'order_id' => $order->get_id(),
            'wms_order_id' => $entity_id,
            'wms_status' => $wms_status
        ]);
        
        return ['status' => 'success', 'message' => 'Order update processed'];
    }
    
    /**
     * Handle order shipped in WMS
     */
    private function handleOrderShipped(array $data, ?string $entity_id): array {
        $external_reference = $data['external_reference'] ?? '';
        
        if (empty($external_reference)) {
            return ['status' => 'error', 'message' => 'Missing external reference'];
        }
        
        $order = $this->orderSyncManager->findOrderByExternalReference($external_reference);
        if (!$order) {
            return ['status' => 'error', 'message' => 'Order not found'];
        }
        
        // CRITICAL VALIDATION: Verify order matches expected WMS entity
        $existing_wms_order_id = $order->get_meta('_wms_order_id');
        if (!empty($existing_wms_order_id) && $existing_wms_order_id !== $entity_id) {
            $this->client->logger()->error('WMS Order ID mismatch in shipment!', [
                'external_reference' => $external_reference,
                'wc_order_id' => $order->get_id(),
                'webhook_entity_id' => $entity_id,
                'stored_wms_order_id' => $existing_wms_order_id
            ]);
            return [
                'status' => 'error', 
                'message' => 'WMS Order ID mismatch - possible webhook routing error'
            ];
        }
        
        // Update order status to shipped/completed
        if (!in_array($order->get_status(), ['completed', 'shipped'])) {
            $order->update_status('completed', __('Order shipped from WMS', 'wc-wms-integration'));
        }
        
        // Add shipping details
        if (isset($data['tracking_number'])) {
            $order->update_meta_data('_wms_tracking_number', sanitize_text_field($data['tracking_number']));
        }
        
        if (isset($data['carrier'])) {
            $order->update_meta_data('_wms_carrier', sanitize_text_field($data['carrier']));
        }
        
        if (isset($data['tracking_url'])) {
            $order->update_meta_data('_wms_tracking_url', esc_url_raw($data['tracking_url']));
        }
        
        $order->update_meta_data('_wms_shipped_at', current_time('mysql'));
        $order->update_meta_data('_wms_shipping_data', json_encode($data));
        $order->save();
        
        $this->client->logger()->info('Order marked as shipped', [
            'order_id' => $order->get_id(),
            'wms_order_id' => $entity_id,
            'tracking_number' => $data['tracking_number'] ?? null
        ]);
        
        return ['status' => 'success', 'message' => 'Order shipped'];
    }
    
    /**
     * Process status change with proper WooCommerce mapping
     */
    private function processStatusChange(WC_Order $order, string $wms_status, array $data, ?string $entity_id): ?array {
        // Map WMS status to WooCommerce status
        $wc_status = self::STATUS_MAPPING[$wms_status] ?? null;
        
        if ($wc_status === null) {
            $this->client->logger()->warning('Unknown WMS status', [
                'wms_status' => $wms_status,
                'order_id' => $order->get_id()
            ]);
            return null;
        }
        
        // Handle specific status cases
        switch ($wms_status) {
            case 'cancelled':
                return $this->handleOrderCancellation($order, $data, $entity_id);
                
            case 'shipped':
                return $this->handleOrderShippedStatus($order, $data, $entity_id);
                
            case 'invalid_address':
                return $this->handleInvalidAddress($order, $data, $entity_id);
                
            case 'problem':
                return $this->handleOrderProblem($order, $data, $entity_id);
                
            case 'backorder':
                return $this->handleBackorder($order, $data, $entity_id);
                
            default:
                // Handle general status update
                if ($order->get_status() !== $wc_status) {
                    $order->update_status($wc_status, sprintf(
                        __('Order status updated in WMS to: %s', 'wc-wms-integration'),
                        $wms_status
                    ));
                }
                return null;
        }
    }
    
    /**
     * Handle order cancellation
     */
    private function handleOrderCancellation(WC_Order $order, array $data, ?string $entity_id): array {
        // Update order status to cancelled
        if ($order->get_status() !== 'cancelled') {
            $reason = $data['cancellation_reason'] ?? 'Cancelled in WMS';
            $order->update_status('cancelled', sprintf(
                __('Order cancelled in WMS: %s', 'wc-wms-integration'),
                $reason
            ));
        }
        
        $order->update_meta_data('_wms_cancelled_at', current_time('mysql'));
        $order->update_meta_data('_wms_cancellation_data', json_encode($data));
        $order->save();
        
        $this->client->logger()->info('Order marked as cancelled', [
            'order_id' => $order->get_id(),
            'wms_order_id' => $entity_id,
            'reason' => $data['cancellation_reason'] ?? 'Not specified'
        ]);
        
        return ['status' => 'success', 'message' => 'Order cancelled'];
    }
    
    /**
     * Handle order planned status
     */
    private function handleOrderPlanned(array $data, ?string $entity_id): array {
        $external_reference = $data['external_reference'] ?? '';
        
        if (empty($external_reference)) {
            return ['status' => 'error', 'message' => 'Missing external reference'];
        }
        
        $order = $this->orderSyncManager->findOrderByExternalReference($external_reference);
        if (!$order) {
            return ['status' => 'error', 'message' => 'Order not found'];
        }
        
        // CRITICAL VALIDATION: Verify order matches expected WMS entity
        $existing_wms_order_id = $order->get_meta('_wms_order_id');
        if (!empty($existing_wms_order_id) && $existing_wms_order_id !== $entity_id) {
            $this->client->logger()->error('WMS Order ID mismatch in planned status!', [
                'external_reference' => $external_reference,
                'wc_order_id' => $order->get_id(),
                'webhook_entity_id' => $entity_id,
                'stored_wms_order_id' => $existing_wms_order_id
            ]);
            return [
                'status' => 'error', 
                'message' => 'WMS Order ID mismatch - possible webhook routing error'
            ];
        }
        
        $order->add_order_note(__('Order planned for fulfillment in WMS', 'wc-wms-integration'));
        $order->update_meta_data('_wms_planned_at', current_time('mysql'));
        $order->update_meta_data('_wms_planned_data', json_encode($data));
        $order->save();
        
        return ['status' => 'success', 'message' => 'Order planned'];
    }
    
    /**
     * Handle order processing status
     */
    private function handleOrderProcessing(array $data, ?string $entity_id): array {
        $external_reference = $data['external_reference'] ?? '';
        
        if (empty($external_reference)) {
            return ['status' => 'error', 'message' => 'Missing external reference'];
        }
        
        $order = $this->orderSyncManager->findOrderByExternalReference($external_reference);
        if (!$order) {
            return ['status' => 'error', 'message' => 'Order not found'];
        }
        
        // CRITICAL VALIDATION: Verify order matches expected WMS entity
        $existing_wms_order_id = $order->get_meta('_wms_order_id');
        if (!empty($existing_wms_order_id) && $existing_wms_order_id !== $entity_id) {
            $this->client->logger()->error('WMS Order ID mismatch in processing status!', [
                'external_reference' => $external_reference,
                'wc_order_id' => $order->get_id(),
                'webhook_entity_id' => $entity_id,
                'stored_wms_order_id' => $existing_wms_order_id
            ]);
            return [
                'status' => 'error', 
                'message' => 'WMS Order ID mismatch - possible webhook routing error'
            ];
        }
        
        $order->add_order_note(__('Order is being processed in WMS', 'wc-wms-integration'));
        $order->update_meta_data('_wms_processing_started_at', current_time('mysql'));
        $order->update_meta_data('_wms_processing_data', json_encode($data));
        $order->save();
        
        return ['status' => 'success', 'message' => 'Order processing'];
    }
    
    /**
     * Handle shipped status from order.updated webhook
     */
    private function handleOrderShippedStatus(WC_Order $order, array $data, ?string $entity_id): array {
        // Use the existing shipped handler
        return $this->handleOrderShipped($data, $entity_id);
    }
    
    /**
     * Handle invalid address status
     */
    private function handleInvalidAddress(WC_Order $order, array $data, ?string $entity_id): array {
        if ($order->get_status() !== 'on-hold') {
            $order->update_status('on-hold', __('Order on hold - Invalid address detected by WMS', 'wc-wms-integration'));
        }
        
        $order->update_meta_data('_wms_invalid_address_at', current_time('mysql'));
        $order->update_meta_data('_wms_address_issue_data', json_encode($data));
        $order->save();
        
        return ['status' => 'success', 'message' => 'Order on hold - invalid address'];
    }
    
    /**
     * Handle order problem status
     */
    private function handleOrderProblem(WC_Order $order, array $data, ?string $entity_id): array {
        if ($order->get_status() !== 'on-hold') {
            $problem_reason = $data['problem_reason'] ?? 'Problem detected in WMS';
            $order->update_status('on-hold', sprintf(
                __('Order on hold - Problem: %s', 'wc-wms-integration'),
                $problem_reason
            ));
        }
        
        $order->update_meta_data('_wms_problem_detected_at', current_time('mysql'));
        $order->update_meta_data('_wms_problem_data', json_encode($data));
        $order->save();
        
        return ['status' => 'success', 'message' => 'Order on hold - problem detected'];
    }
    
    /**
     * Handle backorder status
     */
    private function handleBackorder(WC_Order $order, array $data, ?string $entity_id): array {
        if ($order->get_status() !== 'on-hold') {
            $order->update_status('on-hold', __('Order on hold - Items on backorder', 'wc-wms-integration'));
        }
        
        $order->update_meta_data('_wms_backorder_at', current_time('mysql'));
        $order->update_meta_data('_wms_backorder_data', json_encode($data));
        $order->save();
        
        return ['status' => 'success', 'message' => 'Order on hold - backorder'];
    }
}

/**
 * Stock webhook processor
 */
class WC_WMS_Stock_Webhook_Processor extends WC_WMS_Webhook_Processor {
    
    /**
     * Get supported actions
     */
    public function getSupportedActions(): array {
        return ['updated', 'adjustment'];
    }
    
    /**
     * Process stock webhook
     */
    public function process($action, $data, $entity_id = null) {
        $this->client->logger()->info('Processing stock webhook', [
            'action' => $action,
            'entity_id' => $entity_id
        ]);
        
        $this->updateLastProcessedTime();
        
        switch ($action) {
            case 'updated':
                return $this->handleStockUpdated($data);
                
            case 'adjustment':
                return $this->handleStockAdjustment($data);
                
            default:
                return ['status' => 'ignored', 'message' => 'Unknown stock action'];
        }
    }
    
    /**
     * Handle stock updated
     */
    private function handleStockUpdated(array $data): array {
        $updated_count = 0;
        $errors = [];
        
        // Handle single product update
        if (isset($data['sku']) || isset($data['article_code'])) {
            $result = $this->updateProductStock($data);
            if (is_wp_error($result)) {
                $errors[] = $result->get_error_message();
            } else {
                $updated_count++;
            }
        }
        
        // Handle bulk updates
        if (isset($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as $item_data) {
                $result = $this->updateProductStock($item_data);
                if (is_wp_error($result)) {
                    $errors[] = $result->get_error_message();
                } else {
                    $updated_count++;
                }
            }
        }
        
        $this->client->logger()->info('Stock update processed', [
            'updated_count' => $updated_count,
            'errors_count' => count($errors)
        ]);
        
        return [
            'status' => empty($errors) ? 'success' : 'partial',
            'updated_count' => $updated_count,
            'errors' => $errors
        ];
    }
    
    /**
     * Handle stock adjustment
     */
    private function handleStockAdjustment(array $data): array {
        // Similar to stock updated but with different logging
        $this->client->logger()->info('Processing stock adjustment', [
            'adjustment_type' => $data['adjustment_type'] ?? 'unknown'
        ]);
        
        return $this->handleStockUpdated($data);
    }
    
    /**
     * Update single product stock
     */
    private function updateProductStock(array $data) {
        $sku = $data['sku'] ?? $data['article_code'] ?? '';
        $quantity = isset($data['stock_physical']) ? intval($data['stock_physical']) : null;
        $stock_status = $data['stock_status'] ?? '';
        
        if (empty($sku)) {
            return new WP_Error('missing_sku', 'SKU not provided in stock update');
        }
        
        $product = $this->productSyncManager->findProductBySku($sku);
        if (!$product) {
            return new WP_Error('product_not_found', "Product not found for SKU: {$sku}");
        }
        
        // Update stock quantity
        if ($quantity !== null) {
            $product->set_stock_quantity($quantity);
            $product->set_manage_stock(true);
        }
        
        // Update stock status
        if ($stock_status) {
            $status_map = [
                'in_stock' => 'instock',
                'out_of_stock' => 'outofstock',
                'on_backorder' => 'onbackorder'
            ];
            
            $wc_stock_status = $status_map[$stock_status] ?? $stock_status;
            $product->set_stock_status($wc_stock_status);
        }
        
        // Update stock sync metadata
        $product->update_meta_data('_wms_stock_synced_at', current_time('mysql'));
        $product->update_meta_data('_wms_stock_sync_data', json_encode($data));
        $product->save();
        
        $this->client->logger()->debug('Product stock updated', [
            'sku' => $sku,
            'product_id' => $product->get_id(),
            'quantity' => $quantity,
            'status' => $stock_status
        ]);
        
        return true;
    }
}

/**
 * Shipment webhook processor
 */
class WC_WMS_Shipment_Webhook_Processor extends WC_WMS_Webhook_Processor {
    
    /**
     * Shipment integrator instance
     */
    private $shipmentIntegrator;
    
    /**
     * Constructor
     */
    public function __construct(WC_WMS_Client $client) {
        parent::__construct($client);
        $this->shipmentIntegrator = new WC_WMS_Shipment_Integrator($client);
    }
    
    /**
     * Get supported actions
     */
    public function getSupportedActions(): array {
        return ['created', 'updated', 'shipped', 'delivered'];
    }
    
    /**
     * Process shipment webhook
     */
    public function process($action, $data, $entity_id = null) {
        $this->client->logger()->info('Processing shipment webhook', [
            'action' => $action,
            'entity_id' => $entity_id
        ]);
        
        $this->updateLastProcessedTime();
        
        try {
            // Use shipment integrator to process the webhook
            $result = $this->shipmentIntegrator->processShipmentWebhook($data);
            
            $this->client->logger()->info('Shipment webhook processed successfully', [
                'action' => $action,
                'entity_id' => $entity_id,
                'result' => $result
            ]);
            
            return [
                'status' => 'success',
                'message' => $result['message'] ?? 'Shipment webhook processed',
                'data' => $result
            ];
            
        } catch (Exception $e) {
            $this->client->logger()->error('Shipment webhook processing failed', [
                'action' => $action,
                'entity_id' => $entity_id,
                'error' => $e->getMessage()
            ]);
            
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
}

/**
 * Inbound webhook processor
 */
class WC_WMS_Inbound_Webhook_Processor extends WC_WMS_Webhook_Processor {
    
    /**
     * Get supported actions
     */
    public function getSupportedActions(): array {
        return ['created', 'updated', 'completed'];
    }
    
    /**
     * Process inbound webhook
     */
    public function process($action, $data, $entity_id = null) {
        $this->client->logger()->info('Processing inbound webhook', [
            'action' => $action,
            'entity_id' => $entity_id,
            'has_data' => !empty($data),
            'data_keys' => is_array($data) ? array_keys($data) : []
        ]);
        
        // Validate webhook payload structure matches API docs
        if (empty($data) || !is_array($data)) {
            throw new Exception('Invalid inbound webhook data: empty or not array');
        }
        
        // Validate required fields based on API documentation
        $required_fields = ['id', 'reference', 'status'];
        foreach ($required_fields as $field) {
            if (!isset($data[$field])) {
                throw new Exception("Missing required field in inbound webhook: {$field}");
            }
        }
        
        $inbound_id = $data['id'];
        $inbound_reference = $data['reference']; // WMS generated (e.g., "INB00000000001")
        $external_reference = $data['external_reference'] ?? null;
        $status = $data['status'];
        
        try {
            // Process different inbound actions
            switch ($action) {
                case 'created':
                    $result = $this->processInboundCreated($data);
                    break;
                    
                case 'updated':
                    $result = $this->processInboundUpdated($data);
                    break;
                    
                case 'completed':
                    $result = $this->processInboundCompleted($data);
                    break;
                    
                default:
                    throw new Exception("Unsupported inbound action: {$action}");
            }
            
            $this->updateLastProcessedTime();
            
            // Log the successful processing with proper API fields
            $this->client->logger()->info('Inbound webhook processed successfully', [
                'action' => $action,
                'inbound_id' => $inbound_id,
                'inbound_reference' => $inbound_reference,
                'external_reference' => $external_reference,
                'status' => $status,
                'result' => $result
            ]);
            
            return $result;
            
        } catch (Exception $e) {
            $this->client->logger()->error('Failed to process inbound webhook', [
                'action' => $action,
                'inbound_id' => $inbound_id,
                'inbound_reference' => $inbound_reference,
                'external_reference' => $external_reference,
                'status' => $status,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Process inbound created webhook
     */
    private function processInboundCreated(array $data): array {
        // Extract key information from API structure
        $inbound_info = [
            'id' => $data['id'],
            'reference' => $data['reference'],
            'external_reference' => $data['external_reference'] ?? null,
            'status' => $data['status'],
            'inbound_date' => $data['inbound_date'] ?? null,
            'customer' => $data['customer'] ?? null,
            'is_return' => $data['is_return'] ?? false,
            'note' => $data['note'] ?? null,
            'line_count' => count($data['inbound_lines'] ?? [])
        ];
        
        $this->client->logger()->info('New inbound created in WMS', $inbound_info);
        
        // Trigger WordPress action with full data
        do_action('wc_wms_inbound_created', $data, $inbound_info);
        
        return [
            'action' => 'logged',
            'message' => "Inbound {$inbound_info['reference']} created successfully",
            'inbound_info' => $inbound_info
        ];
    }
    
    /**
     * Process inbound updated webhook
     */
    private function processInboundUpdated(array $data): array {
        $inbound_info = [
            'id' => $data['id'],
            'reference' => $data['reference'],
            'external_reference' => $data['external_reference'] ?? null,
            'status' => $data['status'],
            'inbound_date' => $data['inbound_date'] ?? null,
            'note' => $data['note'] ?? null,
            'line_count' => count($data['inbound_lines'] ?? [])
        ];
        
        $this->client->logger()->info('Inbound updated in WMS', $inbound_info);
        
        // Trigger WordPress action
        do_action('wc_wms_inbound_updated', $data, $inbound_info);
        
        return [
            'action' => 'logged',
            'message' => "Inbound {$inbound_info['reference']} updated to status: {$inbound_info['status']}",
            'inbound_info' => $inbound_info
        ];
    }
    
    /**
     * Process inbound completed webhook - Most important for stock sync
     */
    private function processInboundCompleted(array $data): array {
        $inbound_info = [
            'id' => $data['id'],
            'reference' => $data['reference'],
            'external_reference' => $data['external_reference'] ?? null,
            'status' => $data['status'],
            'inbound_date' => $data['inbound_date'] ?? null,
            'line_count' => count($data['inbound_lines'] ?? [])
        ];
        
        $this->client->logger()->info('Inbound completed in WMS', $inbound_info);
        
        // Extract affected SKUs for stock sync - crucial for inventory management
        $affected_skus = [];
        $processed_quantities = [];
        
        if (!empty($data['inbound_lines']) && is_array($data['inbound_lines'])) {
            foreach ($data['inbound_lines'] as $line) {
                // Handle the variant object structure from API docs
                if (isset($line['variant']) && is_array($line['variant'])) {
                    $article_code = $line['variant']['article_code'] ?? null;
                    if (!empty($article_code)) {
                        $affected_skus[] = $article_code;
                        $processed_quantities[$article_code] = [
                            'quantity' => $line['quantity'] ?? 0,
                            'processed' => $line['processed'] ?? 0,
                            'packing_slip' => $line['packing_slip'] ?? 0
                        ];
                    }
                }
            }
        }
        
        $inbound_info['affected_skus'] = $affected_skus;
        $inbound_info['processed_quantities'] = $processed_quantities;
        
        // Trigger stock sync for affected products - this is the key functionality
        if (!empty($affected_skus)) {
            do_action('wc_wms_inbound_completed_stock_update', $affected_skus, $data, $processed_quantities);
            
            $this->client->logger()->info('Triggered stock sync for inbound completion', [
                'reference' => $inbound_info['reference'],
                'affected_skus' => $affected_skus,
                'sku_count' => count($affected_skus)
            ]);
        }
        
        // Trigger general completion action
        do_action('wc_wms_inbound_completed', $data, $inbound_info);
        
        return [
            'action' => 'completed',
            'message' => "Inbound {$inbound_info['reference']} completed. Stock sync triggered for " . count($affected_skus) . " SKUs",
            'inbound_info' => $inbound_info,
            'affected_skus' => $affected_skus
        ];
    }
    
}

/**
 * Generic webhook processor for unknown webhook types
 */
class WC_WMS_Generic_Webhook_Processor extends WC_WMS_Webhook_Processor {
    
    /**
     * Get supported actions
     */
    public function getSupportedActions(): array {
        return ['*']; // Accepts all actions
    }
    
    /**
     * Process generic webhook
     */
    public function process($action, $data, $entity_id = null) {
        $this->client->logger()->info('Processing generic webhook', [
            'action' => $action,
            'entity_id' => $entity_id,
            'data_keys' => array_keys($data)
        ]);
        
        $this->updateLastProcessedTime();
        
        // Log the unhandled webhook event for debugging
        $this->client->logger()->warning('Unknown webhook type processed', [
            'action' => $action,
            'entity_id' => $entity_id,
            'data' => $data
        ]);
        
        return [
            'status' => 'logged',
            'message' => 'Unknown webhook type logged for review'
        ];
    }
}
