<?php
/**
 * WMS Order Sync Manager - CENTRALIZED ORDER SYNCHRONIZATION
 * 
 * Single source of truth for ALL order sync operations:
 * - Webhooks
 * - Cron jobs  
 * - Manual sync
 * - Order processing
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Order_Sync_Manager {
    
    /**
     * WMS client instance
     */
    private $client;
    
    /**
     * Order state manager instance
     */
    private $orderStateManager;
    
    /**
     * Product sync manager instance
     */
    private $productSyncManager;
    
    /**
     * WMS Order Status to WooCommerce Status Mapping - SINGLE SOURCE OF TRUTH
     */
    const STATUS_MAPPING = [
        // Active processing statuses
        'created' => 'processing',
        'plannable' => 'processing', 
        'planned' => 'processing',
        'processing' => 'processing',
        
        // Shipped/completed statuses
        'shipped' => 'completed',
        'partially_shipped' => 'processing', // Keep as processing until shipment is complete
        
        // Problem/hold statuses
        'on_hold' => 'on-hold',
        'problem' => 'on-hold',
        'backorder' => 'on-hold',
        'awaiting_documents' => 'on-hold',
        'restock' => 'on-hold',
        'invalid_address' => 'on-hold',
        'shipment_waiting_for_ewh' => 'on-hold',
        
        // Terminal statuses
        'cancelled' => 'cancelled',
        'invalid' => 'cancelled'
    ];
    
    /**
     * Constructor
     */
    public function __construct(WC_WMS_Client $client) {
        $this->client = $client;
        $this->orderStateManager = $client->orderStateManager();
        $this->productSyncManager = new WC_WMS_Product_Sync_Manager($client);
    }
    
    // ===== CORE METHODS =====
    
    /**
     * Find order by external reference
     */
    public function findOrderByExternalReference(string $externalReference): ?WC_Order {
        if (empty($externalReference)) {
            return null;
        }
        
        $this->client->logger()->debug('Finding order by external reference', [
            'external_reference' => $externalReference
        ]);
        
        // Only search by WMS external reference meta - this is the correct method
        $orders = wc_get_orders([
            'limit' => 1,
            'meta_key' => '_wms_external_reference',
            'meta_value' => $externalReference,
        ]);
        
        if (!empty($orders)) {
            $this->client->logger()->debug('Found order by WMS external reference meta', [
                'external_reference' => $externalReference,
                'order_id' => $orders[0]->get_id(),
                'order_number' => $orders[0]->get_order_number()
            ]);
            return $orders[0];
        }
        
        // No order found with this external reference
        $this->client->logger()->debug('Order not found by external reference', [
            'external_reference' => $externalReference
        ]);
        
        return null;
    }
    
    /**
     * Map WMS status to WooCommerce status - SINGLE SOURCE OF TRUTH
     */
    public function mapWmsStatusToWooCommerce(string $wmsStatus): string {
        return self::STATUS_MAPPING[$wmsStatus] ?? 'processing';
    }
    
    /**
     * Update order from WMS data - SINGLE IMPLEMENTATION
     */
    public function updateOrderFromWMS(WC_Order $order, array $wmsData): array {
        $orderId = $order->get_id();
        $wmsOrderId = $wmsData['id'] ?? 'unknown';
        
        $this->client->logger()->info('Centralized order update from WMS', [
            'order_id' => $orderId,
            'wms_order_id' => $wmsOrderId,
            'wms_status' => $wmsData['status'] ?? 'unknown',
            'has_order_lines' => !empty($wmsData['order_lines'])
        ]);
        
        try {
            // Suspend hooks to prevent circular sync
            $this->orderStateManager->suspendOrderHooks($order);
            
            $result = [
                'order_id' => $orderId,
                'wms_order_id' => $wmsOrderId,
                'status_updated' => false,
                'order_lines_processed' => false,
                'items_added' => 0,
                'items_updated' => 0,
                'errors' => []
            ];
            
            // 1. Process order lines if present
            if (!empty($wmsData['order_lines'])) {
                $lineResult = $this->processOrderLines($order, $wmsData['order_lines']);
                $result['order_lines_processed'] = true;
                $result['items_added'] = $lineResult['items_added'];
                $result['items_updated'] = $lineResult['items_updated'];
                $result['errors'] = array_merge($result['errors'], $lineResult['errors']);
            }
            
            // 2. Update order status
            $this->updateOrderStatus($order, $wmsData);
            $result['status_updated'] = true;
            
            // 3. Update order metadata
            $this->updateOrderMetadata($order, $wmsData);
            
            // 4. Add order notes if needed
            $this->addOrderNotes($order, $wmsData);
            
            // 5. Mark as processed using state manager
            $this->orderStateManager->markAsWebhookProcessed($order);
            
            // Save order
            $order->save();
            
            $this->client->logger()->info('Centralized order update completed', [
                'order_id' => $orderId,
                'result' => $result
            ]);
            
            return [
                'success' => true,
                'result' => $result
            ];
            
        } catch (Exception $e) {
            $this->client->logger()->error('Centralized order update failed', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
            
        } finally {
            // Always restore hooks
            $this->orderStateManager->restoreOrderHooks($order);
        }
    }
    
    /**
     * Process order lines - SINGLE IMPLEMENTATION
     */
    public function processOrderLines(WC_Order $order, array $orderLines): array {
        $this->client->logger()->info('Processing order lines from WMS', [
            'order_id' => $order->get_id(),
            'order_lines_count' => count($orderLines)
        ]);
        
        $result = [
            'items_added' => 0,
            'items_updated' => 0,
            'items_skipped' => 0,
            'errors' => []
        ];
        
        foreach ($orderLines as $lineIndex => $line) {
            try {
                $lineResult = $this->processSingleOrderLine($order, $line, $lineIndex);
                
                if ($lineResult['success']) {
                    if ($lineResult['action'] === 'added') {
                        $result['items_added']++;
                    } elseif ($lineResult['action'] === 'updated') {
                        $result['items_updated']++;
                    }
                } else {
                    $result['items_skipped']++;
                    $result['errors'][] = $lineResult['error'];
                }
                
            } catch (Exception $e) {
                $result['errors'][] = [
                    'line_index' => $lineIndex,
                    'error' => $e->getMessage()
                ];
                $result['items_skipped']++;
            }
        }
        
        // Recalculate order totals
        $order->calculate_totals();
        
        $this->client->logger()->info('Order lines processing completed', [
            'order_id' => $order->get_id(),
            'result' => $result
        ]);
        
        return $result;
    }
    
    /**
     * Process single order line
     */
    private function processSingleOrderLine(WC_Order $order, array $line, int $lineIndex): array {
        $variant = $line['variant'] ?? [];
        $articleCode = $variant['article_code'] ?? $variant['sku'] ?? '';
        $quantity = $line['quantity'] ?? 1;
        $variantId = $variant['id'] ?? '';
        
        $this->client->logger()->debug("Processing order line #{$lineIndex}", [
            'article_code' => $articleCode,
            'quantity' => $quantity,
            'variant_id' => $variantId
        ]);
        
        // Skip if no way to identify the product
        if (empty($articleCode) && empty($variantId)) {
            return [
                'success' => false,
                'error' => "Missing both article_code and variant_id for line #{$lineIndex}"
            ];
        }
        
        // Find or create product using centralized product sync
        $product = $this->productSyncManager->ensureProductExists($variant, $articleCode);
        
        if (!$product) {
            return [
                'success' => false,
                'error' => "Failed to find/create product for line #{$lineIndex}"
            ];
        }
        
        // Update article_code if it was missing
        if (empty($articleCode)) {
            $articleCode = $product->get_sku();
        }
        
        // Check if item already exists in order
        $existingItem = $this->findOrderItemBySku($order, $articleCode);
        
        if ($existingItem) {
            // Update quantity if different
            if ($existingItem->get_quantity() != $quantity) {
                $existingItem->set_quantity($quantity);
                $existingItem->save();
                
                $this->client->logger()->info('Updated existing order item', [
                    'sku' => $articleCode,
                    'old_quantity' => $existingItem->get_quantity(),
                    'new_quantity' => $quantity
                ]);
                
                return ['success' => true, 'action' => 'updated'];
            }
            
            return ['success' => true, 'action' => 'unchanged'];
        } else {
            // Add new item to order
            $order->add_product($product, $quantity);
            
            $this->client->logger()->info('Added new order item', [
                'sku' => $articleCode,
                'quantity' => $quantity,
                'product_id' => $product->get_id()
            ]);
            
            return ['success' => true, 'action' => 'added'];
        }
    }
    
    // ===== ENTRY POINT METHODS =====
    
    /**
     * Process order export (for queue operations)
     */
    public function processOrderExport(int $orderId): array {
        $this->client->logger()->info('Processing order export via centralized manager', [
            'order_id' => $orderId
        ]);
        
        $order = wc_get_order($orderId);
        if (!$order) {
            return [
                'success' => false,
                'error' => 'Order not found'
            ];
        }
        
        // Use existing order integrator but through centralized manager
        $orderIntegrator = $this->client->orderIntegrator();
        $result = $orderIntegrator->exportOrder($orderId);
        
        if (is_wp_error($result)) {
            return [
                'success' => false,
                'error' => $result->get_error_message()
            ];
        }
        
        return [
            'success' => true,
            'result' => $result
        ];
    }
    
    /**
     * Process order cancellation (for queue operations)
     */
    public function processOrderCancellation(int $orderId): array {
        $this->client->logger()->info('Processing order cancellation via centralized manager', [
            'order_id' => $orderId
        ]);
        
        $order = wc_get_order($orderId);
        if (!$order) {
            return [
                'success' => false,
                'error' => 'Order not found'
            ];
        }
        
        // Use existing order integrator but through centralized manager
        $orderIntegrator = $this->client->orderIntegrator();
        $result = $orderIntegrator->cancelOrder($orderId);
        
        if (is_wp_error($result)) {
            return [
                'success' => false,
                'error' => $result->get_error_message()
            ];
        }
        
        return [
            'success' => true,
            'result' => $result
        ];
    }
    
    /**
     * Check if order should be exported
     */
    public function shouldExportOrder(WC_Order $order): bool {
        // Check if order is in valid status for export
        $skipStatuses = apply_filters('wc_wms_skip_export_statuses', [
            'pending', 'failed', 'cancelled', 'refunded'
        ]);
        
        if (in_array($order->get_status(), $skipStatuses)) {
            return false;
        }
        
        // Check if order is already exported
        $wmsOrderId = $order->get_meta('_wms_order_id');
        if (!empty($wmsOrderId)) {
            return false;
        }
        
        // Check if order has exportable items (physical products)
        $items = $order->get_items();
        if (empty($items)) {
            return false;
        }
        
        // Check if order has physical products
        $hasPhysicalProducts = false;
        foreach ($items as $item) {
            $product = $item->get_product();
            if ($product && !$product->is_virtual() && !$product->is_downloadable()) {
                $hasPhysicalProducts = true;
                break;
            }
        }
        
        if (!$hasPhysicalProducts) {
            return false;
        }
        
        return apply_filters('wc_wms_should_export_order', true, $order);
    }
    
    /**
     * Process webhook order event
     */
    public function processWebhookOrderEvent(string $action, array $orderData): array {
        $externalReference = $orderData['external_reference'] ?? '';
        $wmsOrderId = $orderData['id'] ?? '';
        
        $this->client->logger()->info('Processing webhook order event', [
            'action' => $action,
            'wms_order_id' => $wmsOrderId,
            'external_reference' => $externalReference
        ]);
        
        // Find WooCommerce order
        $order = $this->findOrderByExternalReference($externalReference);
        
        if (!$order) {
            // If order not found and this is a 'created' action, try to import it from WMS
            if ($action === 'created') {
                $this->client->logger()->info('Order not found locally, attempting to import from WMS', [
                    'wms_order_id' => $wmsOrderId,
                    'external_reference' => $externalReference
                ]);
                
                // DIRECT ORDER CREATION - No more circular dependency
                try {
                    $this->client->logger()->info('Creating WooCommerce order from WMS data', [
                        'wms_order_id' => $wmsOrderId,
                        'external_reference' => $externalReference,
                        'has_order_lines' => !empty($orderData['order_lines']),
                        'order_lines_count' => count($orderData['order_lines'] ?? [])
                    ]);
                    
                    // Create order directly - no delegation to prevent circular dependency
                    $newOrder = $this->createWooCommerceOrderFromWMS($orderData);
                    
                    if ($newOrder) {
                        $this->client->logger()->info('Order successfully created from WMS webhook data', [
                            'wc_order_id' => $newOrder->get_id(),
                            'wms_order_id' => $wmsOrderId,
                            'external_reference' => $externalReference
                        ]);
                        
                        return [
                            'success' => true,
                            'result' => [
                                'order_id' => $newOrder->get_id(),
                                'wms_order_id' => $wmsOrderId,
                                'action' => 'created'
                            ],
                            'message' => 'Order created from webhook data'
                        ];
                    } else {
                        return [
                            'success' => false,
                            'error' => 'Failed to create WooCommerce order from WMS data',
                            'external_reference' => $externalReference
                        ];
                    }
                    
                } catch (Exception $e) {
                    $this->client->logger()->error('Failed to create order from WMS', [
                        'wms_order_id' => $wmsOrderId,
                        'external_reference' => $externalReference,
                        'error' => $e->getMessage()
                    ]);
                    
                    return [
                        'success' => false,
                        'error' => 'Failed to create order from webhook data: ' . $e->getMessage(),
                        'external_reference' => $externalReference
                    ];
                }
            }
            
            // For non-created actions, order should exist
            return [
                'success' => false,
                'error' => 'Order not found',
                'external_reference' => $externalReference
            ];
        }
        
        // Update order using centralized method
        return $this->updateOrderFromWMS($order, $orderData);
    }
    
    /**
     * Process cron order sync - FIXED: Proper delegation to Order Integrator
     */
    public function processCronOrderSync(array $options = []): array {
        $this->client->logger()->info('Starting cron order sync');
        
        // PROPER DELEGATION - Order Integrator contains the actual sync implementation
        $orderIntegrator = $this->client->orderIntegrator();
        $result = $orderIntegrator->syncOrdersFromWMSActual($options);
        
        $this->client->logger()->info('Cron order sync completed', $result);
        
        return $result;
    }
    
    /**
     * Process manual order sync - FIXED: No circular dependency
     */
    public function processManualOrderSync(array $orderIds = []): array {
        $this->client->logger()->info('Starting manual order sync', [
            'order_ids' => $orderIds
        ]);
        
        if (empty($orderIds)) {
            // Sync recent orders using the integrator's actual implementation
            $orderIntegrator = $this->client->orderIntegrator();
            return $orderIntegrator->syncOrdersFromWMSActual(['limit' => 50]);
        } else {
            // Sync specific orders
            $results = [
                'processed' => 0,
                'successful' => 0,
                'failed' => 0,
                'errors' => []
            ];
            
            foreach ($orderIds as $orderId) {
                try {
                    $order = wc_get_order($orderId);
                    if (!$order) {
                        continue;
                    }
                    
                    $wmsOrderId = $order->get_meta('_wms_order_id');
                    if (empty($wmsOrderId)) {
                        continue;
                    }
                    
                    // Get fresh data from WMS
                    $wmsData = $this->client->orders()->getOrder($wmsOrderId);
                    $result = $this->updateOrderFromWMS($order, $wmsData);
                    
                    $results['processed']++;
                    if ($result['success']) {
                        $results['successful']++;
                    } else {
                        $results['failed']++;
                        $results['errors'][] = $result['error'];
                    }
                    
                } catch (Exception $e) {
                    $results['failed']++;
                    $results['errors'][] = $e->getMessage();
                }
            }
            
            return $results;
        }
    }
    
    // ===== HELPER METHODS =====
    
    /**
     * Update order status from WMS data
     */
    private function updateOrderStatus(WC_Order $order, array $wmsData): void {
        $wmsStatus = $wmsData['status'] ?? '';
        $currentStatus = $order->get_status();
        $newStatus = $this->mapWmsStatusToWooCommerce($wmsStatus);
        
        if ($currentStatus !== $newStatus) {
            $order->update_status($newStatus, sprintf(
                __('Status updated from WMS: %s', 'wc-wms-integration'),
                $wmsStatus
            ));
            
            $this->client->logger()->info('Order status updated', [
                'order_id' => $order->get_id(),
                'old_status' => $currentStatus,
                'new_status' => $newStatus,
                'wms_status' => $wmsStatus
            ]);
        }
    }
    
    /**
     * Update order metadata from WMS data
     */
    private function updateOrderMetadata(WC_Order $order, array $wmsData): void {
        $order->update_meta_data('_wms_order_data', json_encode($wmsData));
        $order->update_meta_data('_wms_last_sync', current_time('mysql'));
        
        if (isset($wmsData['reference'])) {
            $order->update_meta_data('_wms_reference', $wmsData['reference']);
        }
        
        if (isset($wmsData['status'])) {
            $order->update_meta_data('_wms_status', $wmsData['status']);
        }
        
        // Handle shipping method updates
        if (isset($wmsData['shipping_method'])) {
            $this->updateOrderShippingMethod($order, $wmsData['shipping_method']);
        }
    }
    
    /**
     * Update order shipping method from WMS data
     */
    private function updateOrderShippingMethod(WC_Order $order, $wmsShippingMethodData): void {
        // Handle both string ID and full object data
        if (is_string($wmsShippingMethodData)) {
            $wmsShippingMethodId = $wmsShippingMethodData;
            $shippingMethodDetails = null;
        } else {
            $wmsShippingMethodId = $wmsShippingMethodData['id'] ?? '';
            $shippingMethodDetails = $wmsShippingMethodData;
        }
        
        if (empty($wmsShippingMethodId)) {
            return;
        }
        
        $this->client->logger()->info('Updating order shipping method from WMS', [
            'order_id' => $order->get_id(),
            'wms_shipping_method_id' => $wmsShippingMethodId,
            'has_details' => !empty($shippingMethodDetails)
        ]);
        
        // Store WMS shipping method ID and details
        $order->update_meta_data('_wms_shipping_method_id', $wmsShippingMethodId);
        
        if ($shippingMethodDetails) {
            $order->update_meta_data('_wms_shipping_method_data', json_encode($shippingMethodDetails));
            
            // Store individual shipping details for easy access
            if (isset($shippingMethodDetails['code'])) {
                $order->update_meta_data('_wms_shipping_method_code', $shippingMethodDetails['code']);
            }
            if (isset($shippingMethodDetails['shipper'])) {
                $order->update_meta_data('_wms_shipping_carrier', $shippingMethodDetails['shipper']);
            }
            if (isset($shippingMethodDetails['shipper_code'])) {
                $order->update_meta_data('_wms_shipping_carrier_code', $shippingMethodDetails['shipper_code']);
            }
            if (isset($shippingMethodDetails['description'])) {
                $order->update_meta_data('_wms_shipping_description', $shippingMethodDetails['description']);
            }
        }
        
        // Map WMS shipping method to WooCommerce shipping method
        $wcShippingMethod = $this->mapWmsShippingMethod($wmsShippingMethodId, $shippingMethodDetails);
        
        if ($wcShippingMethod) {
            // Update shipping method for existing shipping items
            $shipping_items = $order->get_items('shipping');
            
            if (!empty($shipping_items)) {
                foreach ($shipping_items as $item) {
                    /** @var WC_Order_Item_Shipping $item */
                    $this->updateShippingItemProperties($item, $wcShippingMethod);
                    $item->save();
                }
            } else {
                // Add new shipping item if none exists
                $shipping_item = new WC_Order_Item_Shipping();
                $this->updateShippingItemProperties($shipping_item, $wcShippingMethod);
                $order->add_item($shipping_item);
            }
            
            $this->client->logger()->info('Order shipping method updated', [
                'order_id' => $order->get_id(),
                'wms_shipping_method_id' => $wmsShippingMethodId,
                'wc_method_id' => $wcShippingMethod['method_id'],
                'wc_method_title' => $wcShippingMethod['method_title'],
                'carrier' => $wcShippingMethod['carrier'] ?? 'N/A'
            ]);
        } else {
            $this->client->logger()->warning('Failed to map WMS shipping method', [
                'order_id' => $order->get_id(),
                'wms_shipping_method_id' => $wmsShippingMethodId
            ]);
        }
    }
    
    /**
     * Update shipping item properties with proper WooCommerce API calls
     */
    private function updateShippingItemProperties(WC_Order_Item_Shipping $item, array $wcShippingMethod): void {
        // Set method ID using proper WooCommerce method
        $item->set_props([
            'method_id' => $wcShippingMethod['method_id'],
            'method_title' => $wcShippingMethod['method_title'],
            'total' => $wcShippingMethod['cost'] ?? 0
        ]);
        
        // Alternative approach using individual setters (commented out as backup)
        // $item->set_method_id($wcShippingMethod['method_id']);
        // $item->set_method_title($wcShippingMethod['method_title']);
        // $item->set_total($wcShippingMethod['cost'] ?? 0);
        
        // Add carrier info as metadata
        if (isset($wcShippingMethod['carrier'])) {
            $item->add_meta_data('_wms_carrier', $wcShippingMethod['carrier']);
        }
        
        // Add method details as metadata for reference
        if (isset($wcShippingMethod['method_details'])) {
            $item->add_meta_data('_wms_method_details', json_encode($wcShippingMethod['method_details']));
        }
    }
    
    /**
     * Map WMS shipping method ID to WooCommerce shipping method
     */
    private function mapWmsShippingMethod(string $wmsShippingMethodId, ?array $shippingMethodDetails = null): ?array {
        // Ensure shipping methods are synced
        $this->ensureShippingMethodsSync();
        
        // Try to get from synced shipping methods first
        $syncedMethods = get_option('wc_wms_shipping_methods', []);
        
        if (isset($syncedMethods[$wmsShippingMethodId])) {
            $wmsMethod = $syncedMethods[$wmsShippingMethodId];
            
            $this->client->logger()->debug('Found synced shipping method', [
                'wms_id' => $wmsShippingMethodId,
                'wms_code' => $wmsMethod['code'],
                'wms_name' => $wmsMethod['name'],
                'shipper' => $wmsMethod['shipper']
            ]);
            
            // Map based on shipper/carrier
            $mappedMethod = $this->mapByCarrier($wmsMethod['shipper'], $wmsMethod);
            
            if ($mappedMethod) {
                return $mappedMethod;
            }
        }
        
        // Fallback: Try to get method details from WMS if not cached
        if (!$shippingMethodDetails) {
            try {
                $shipmentIntegrator = $this->client->shipmentIntegrator();
                $shippingMethods = $shipmentIntegrator->getShippingMethods();
                
                // Find shipping method by ID using native PHP
                $shippingMethodDetails = null;
                foreach ($shippingMethods as $method) {
                    if (isset($method['id']) && $method['id'] === $wmsShippingMethodId) {
                        $shippingMethodDetails = $method;
                        break;
                    }
                }
            } catch (Exception $e) {
                $this->client->logger()->warning('Failed to fetch shipping method details', [
                    'wms_id' => $wmsShippingMethodId,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        // Map using details if available
        if ($shippingMethodDetails) {
            $mappedMethod = $this->mapByCarrier($shippingMethodDetails['shipper'] ?? '', $shippingMethodDetails);
            
            if ($mappedMethod) {
                return $mappedMethod;
            }
        }
        
        // Final fallback: Static mapping for known methods
        $staticMapping = [
            'a299249a-b2cd-4666-a6e5-77d3e1156a22' => [
                'method_id' => 'flat_rate',
                'method_title' => 'Standard Shipping',
                'carrier' => 'Standard'
            ],
            // Add more known mappings as needed
        ];
        
        if (isset($staticMapping[$wmsShippingMethodId])) {
            return $staticMapping[$wmsShippingMethodId];
        }
        
        // Default fallback
        return [
            'method_id' => 'flat_rate',
            'method_title' => 'WMS Shipping Method',
            'carrier' => 'WMS',
            'cost' => 0
        ];
    }
    
    /**
     * Map shipping method by carrier/shipper
     */
    private function mapByCarrier(string $carrier, array $wmsMethodData): ?array {
        $carrier = strtolower($carrier);
        
        // Carrier-specific mapping
        $carrierMapping = [
            'dhl' => [
                'method_id' => 'dhl_shipping',
                'method_title' => 'DHL',
                'carrier' => 'DHL'
            ],
            'ups' => [
                'method_id' => 'ups_shipping',
                'method_title' => 'UPS',
                'carrier' => 'UPS'
            ],
            'fedex' => [
                'method_id' => 'fedex_shipping',
                'method_title' => 'FedEx',
                'carrier' => 'FedEx'
            ],
            'postnl' => [
                'method_id' => 'postnl_shipping',
                'method_title' => 'PostNL',
                'carrier' => 'PostNL'
            ],
            'dpd' => [
                'method_id' => 'dpd_shipping',
                'method_title' => 'DPD',
                'carrier' => 'DPD'
            ],
            'gls' => [
                'method_id' => 'gls_shipping',
                'method_title' => 'GLS',
                'carrier' => 'GLS'
            ],
            'tnt' => [
                'method_id' => 'tnt_shipping',
                'method_title' => 'TNT',
                'carrier' => 'TNT'
            ],
            // Add more carriers as needed
        ];
        
        if (isset($carrierMapping[$carrier])) {
            $mapping = $carrierMapping[$carrier];
            
            // Enhance with method details if available
            if (isset($wmsMethodData['name'])) {
                $mapping['method_title'] = $wmsMethodData['name'];
            } elseif (isset($wmsMethodData['description'])) {
                $mapping['method_title'] = $wmsMethodData['description'];
            }
            
            return $mapping;
        }
        
        return null;
    }
    
    /**
     * Add order notes based on WMS data
     */
    private function addOrderNotes(WC_Order $order, array $wmsData): void {
        $wmsStatus = $wmsData['status'] ?? '';
        $lastWmsStatus = $order->get_meta('_wms_status');
        
        if ($wmsStatus !== $lastWmsStatus) {
            $order->add_order_note(sprintf(
                __('WMS status changed from %s to %s', 'wc-wms-integration'),
                $lastWmsStatus ?: 'unknown',
                $wmsStatus
            ));
        }
    }
    
    /**
     * Find order item by SKU
     */
    private function findOrderItemBySku(WC_Order $order, string $sku): ?WC_Order_Item_Product {
        foreach ($order->get_items() as $item) {
            if ($item instanceof WC_Order_Item_Product) {
                $product = $item->get_product();
                if ($product && $product->get_sku() === $sku) {
                    return $item;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Ensure shipping methods are synced and cached
     */
    private function ensureShippingMethodsSync(): void {
        $lastSync = get_option('wc_wms_shipping_methods_synced_at', 0);
        $syncInterval = 3600; // 1 hour
        
        // Check if sync is needed
        if ($lastSync && (time() - strtotime($lastSync)) < $syncInterval) {
            return; // Sync is fresh
        }
        
        try {
            $shipmentIntegrator = $this->client->shipmentIntegrator();
            $result = $shipmentIntegrator->syncShippingMethods();
            
            $this->client->logger()->info('Shipping methods sync completed', [
                'success' => $result['success'] ?? false,
                'count' => $result['count'] ?? 0
            ]);
            
        } catch (Exception $e) {
            $this->client->logger()->error('Failed to sync shipping methods', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Create WooCommerce order from WMS data - DIRECT IMPLEMENTATION
     */
    private function createWooCommerceOrderFromWMS(array $wmsOrder): ?WC_Order {
        $this->client->logger()->info('Creating WooCommerce order from WMS data', [
            'wms_order_id' => $wmsOrder['id'] ?? 'unknown',
            'external_reference' => $wmsOrder['external_reference'] ?? 'unknown'
        ]);
        
        try {
            // Create new order
            $order = wc_create_order();
            
            if (is_wp_error($order)) {
                throw new Exception('Failed to create WooCommerce order: ' . $order->get_error_message());
            }
            
            // Set basic order data
            $this->setOrderBasicData($order, $wmsOrder);
            
            // Set customer data from shipping address
            $this->setOrderCustomerData($order, $wmsOrder);
            
            // Add order items from order_lines
            $this->addOrderItemsFromWMS($order, $wmsOrder);
            
            // Set shipping address
            $this->setOrderShippingAddress($order, $wmsOrder);
            
            // Set WMS metadata
            $this->setOrderWMSMetadata($order, $wmsOrder);
            
            // Calculate totals
            $order->calculate_totals();
            
            // Set status based on WMS status
            $this->setOrderStatusFromWMS($order, $wmsOrder);
            
            // Save order
            $order->save();
            
            $this->client->logger()->info('WooCommerce order created from WMS', [
                'order_id' => $order->get_id(),
                'wms_order_id' => $wmsOrder['id'] ?? 'unknown',
                'item_count' => count($order->get_items()),
                'status' => $order->get_status()
            ]);
            
            return $order;
            
        } catch (Exception $e) {
            $this->client->logger()->error('Failed to create WooCommerce order from WMS', [
                'wms_order_id' => $wmsOrder['id'] ?? 'unknown',
                'external_reference' => $wmsOrder['external_reference'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            
            return null;
        }
    }
    
    /**
     * Set basic order data from WMS
     */
    private function setOrderBasicData(WC_Order $order, array $wmsOrder): void {
        // Set order key
        $order->set_order_key(wc_generate_order_key());
        
        // Set dates
        if (isset($wmsOrder['created_at'])) {
            $order->set_date_created($wmsOrder['created_at']);
        }
        
        // Set currency
        if (isset($wmsOrder['currency'])) {
            $order->set_currency($wmsOrder['currency']);
        }
        
        // Set order amount if available
        if (isset($wmsOrder['order_amount'])) {
            $order->set_total($wmsOrder['order_amount'] / 100); // Convert from cents
        }
        
        // Set notes
        if (isset($wmsOrder['note'])) {
            $order->set_customer_note($wmsOrder['note']);
        }
        
        if (isset($wmsOrder['customer_note'])) {
            $order->add_order_note($wmsOrder['customer_note']);
        }
    }
    
    /**
     * Set customer data from WMS shipping address
     */
    private function setOrderCustomerData(WC_Order $order, array $wmsOrder): void {
        $shippingAddress = $wmsOrder['shipping_address'] ?? [];
        
        if (!empty($shippingAddress)) {
            // Parse name from addressed_to
            $fullName = $shippingAddress['addressed_to'] ?? '';
            $nameParts = explode(' ', trim($fullName), 2);
            $firstName = $nameParts[0] ?? '';
            $lastName = $nameParts[1] ?? '';
            
            // Set billing address (use shipping address as fallback)
            $order->set_billing_first_name($firstName);
            $order->set_billing_last_name($lastName);
            $order->set_billing_address_1($shippingAddress['street'] ?? '');
            $order->set_billing_address_2($shippingAddress['street2'] ?? '');
            $order->set_billing_city($shippingAddress['city'] ?? '');
            $order->set_billing_state($shippingAddress['state'] ?? '');
            $order->set_billing_postcode($shippingAddress['zipcode'] ?? '');
            $order->set_billing_country($shippingAddress['country'] ?? '');
            $order->set_billing_phone($shippingAddress['phone_number'] ?? '');
            $order->set_billing_email($shippingAddress['email_address'] ?? '');
        }
    }
    
    /**
     * Set shipping address from WMS data
     */
    private function setOrderShippingAddress(WC_Order $order, array $wmsOrder): void {
        $shippingAddress = $wmsOrder['shipping_address'] ?? [];
        
        if (!empty($shippingAddress)) {
            // Parse name from addressed_to
            $fullName = $shippingAddress['addressed_to'] ?? '';
            $nameParts = explode(' ', trim($fullName), 2);
            $firstName = $nameParts[0] ?? '';
            $lastName = $nameParts[1] ?? '';
            
            $order->set_shipping_first_name($firstName);
            $order->set_shipping_last_name($lastName);
            $order->set_shipping_address_1($shippingAddress['street'] ?? '');
            $order->set_shipping_address_2($shippingAddress['street2'] ?? '');
            $order->set_shipping_city($shippingAddress['city'] ?? '');
            $order->set_shipping_state($shippingAddress['state'] ?? '');
            $order->set_shipping_postcode($shippingAddress['zipcode'] ?? '');
            $order->set_shipping_country($shippingAddress['country'] ?? '');
        }
    }
    
    /**
     * Add order items from WMS order lines
     */
    private function addOrderItemsFromWMS(WC_Order $order, array $wmsOrder): void {
        $orderLines = $wmsOrder['order_lines'] ?? [];
        
        $this->client->logger()->info('Adding order items from WMS', [
            'order_id' => $order->get_id(),
            'order_lines_count' => count($orderLines)
        ]);
        
        foreach ($orderLines as $lineIndex => $line) {
            $variant = $line['variant'] ?? [];
            $articleCode = $variant['article_code'] ?? $variant['sku'] ?? '';
            $quantity = $line['quantity'] ?? 1;
            $description = $line['description'] ?? $variant['name'] ?? '';
            
            $this->client->logger()->debug('Processing order line', [
                'line_index' => $lineIndex,
                'article_code' => $articleCode,
                'quantity' => $quantity,
                'description' => $description
            ]);
            
            if (empty($articleCode)) {
                $this->client->logger()->warning('Skipping order line - no article_code/SKU found', [
                    'line_index' => $lineIndex,
                    'description' => $description
                ]);
                continue;
            }
            
            // Try to find existing WooCommerce product by SKU
            $product = $this->productSyncManager->findProductBySku($articleCode);
            
            if ($product) {
                // Add existing product
                $order->add_product($product, $quantity);
                $this->client->logger()->debug('Added existing product to order', [
                    'product_id' => $product->get_id(),
                    'sku' => $articleCode,
                    'quantity' => $quantity
                ]);
            } else {
                // Create simple product if not found
                $product = $this->client->productSyncManager()->createSimpleProductFromVariant($variant, $articleCode);
                if ($product) {
                    $order->add_product($product, $quantity);
                    $this->client->logger()->debug('Created and added new product to order', [
                        'product_id' => $product->get_id(),
                        'sku' => $articleCode,
                        'quantity' => $quantity
                    ]);
                } else {
                    $this->client->logger()->error('Failed to create product for order line', [
                        'line_index' => $lineIndex,
                        'article_code' => $articleCode
                    ]);
                }
            }
        }
        
        $finalItemCount = count($order->get_items());
        $this->client->logger()->info('Completed adding order items', [
            'order_id' => $order->get_id(),
            'items_added' => $finalItemCount
        ]);
    }
    /**
     * Set WMS metadata on order
     */
    private function setOrderWMSMetadata(WC_Order $order, array $wmsOrder): void {
        // Use Order State Manager for main WMS order ID
        if (isset($wmsOrder['id'])) {
            $this->orderStateManager->markAsSyncedFromWMS($order, $wmsOrder['id']);
        }
        
        // Store additional WMS metadata
        $order->update_meta_data('_wms_reference', $wmsOrder['reference'] ?? '');
        $order->update_meta_data('_wms_external_reference', $wmsOrder['external_reference'] ?? '');
        $order->update_meta_data('_wms_status', $wmsOrder['status'] ?? '');
        $order->update_meta_data('_wms_order_data', json_encode($wmsOrder));
        
        // Store additional WMS fields
        if (isset($wmsOrder['po_number'])) {
            $order->update_meta_data('_wms_po_number', $wmsOrder['po_number']);
        }
        
        if (isset($wmsOrder['requested_delivery_date'])) {
            $order->update_meta_data('_wms_requested_delivery_date', $wmsOrder['requested_delivery_date']);
        }
        
        if (isset($wmsOrder['business_to_business'])) {
            $order->update_meta_data('_wms_business_to_business', $wmsOrder['business_to_business']);
        }
    }
    
    /**
     * Set order status from WMS using status mapping
     */
    private function setOrderStatusFromWMS(WC_Order $order, array $wmsOrder): void {
        $wmsStatus = $wmsOrder['status'] ?? '';
        
        if (empty($wmsStatus)) {
            return;
        }
        
        // Use centralized status mapping
        $wcStatus = $this->mapWmsStatusToWooCommerce($wmsStatus);
        
        if ($wcStatus && $order->get_status() !== $wcStatus) {
            $order->update_status($wcStatus, sprintf(
                __('Status synced from WMS: %s', 'wc-wms-integration'),
                $wmsStatus
            ));
        }
    }
    
    /**
     * Transform WooCommerce order to WMS format
     */
    public function transformWooCommerceOrder(WC_Order $order): array {
        $this->client->logger()->debug('Transforming WooCommerce order to WMS format', [
            'order_id' => $order->get_id(),
            'order_number' => $order->get_order_number()
        ]);
        
        // Get WMS customer UUID (must be created first)
        $customerId = $this->getWMSCustomerId($order);
        
        // Get shipping address in correct format
        $shippingAddress = $this->getWMSShippingAddress($order);
        
        // Get order lines in correct format
        $orderLines = $this->getWMSOrderLines($order);
        
        // Get shipping method UUID
        $shippingMethodId = $this->getWMSShippingMethodId($order);
        
        // Build order data according to API spec - MINIMAL PAYLOAD to avoid "extra fields" error
        $orderData = [
            // Required fields
            'customer' => $customerId,
            'order_lines' => $orderLines,
            'requested_delivery_date' => $this->getRequestedDeliveryDate($order),
            'external_reference' => (string) $order->get_order_number(),
            'shipping_method' => $shippingMethodId,
            'shipping_address' => $shippingAddress,
            
            // Minimal optional fields (only include what's shown in API examples)
            'note' => $order->get_customer_note() ?: null,
        ];
        
        // Remove null values
        $orderData = array_filter($orderData, function($value) {
            return $value !== null;
        });
        
        $this->client->logger()->debug('WooCommerce order transformed to WMS format', [
            'order_id' => $order->get_id(),
            'external_reference' => $orderData['external_reference'],
            'line_count' => count($orderData['order_lines'])
        ]);
        
        return $orderData;
    }
    
    /**
     * Get WMS customer UUID from configuration
     */
    private function getWMSCustomerId(WC_Order $order): string {
        // Get the configured WMS customer ID (your business partner account)
        $wmsCustomerId = get_option('wc_wms_integration_customer_id');
        
        if (empty($wmsCustomerId)) {
            throw new Exception('WMS customer ID not configured. Please configure your WMS customer ID in the settings.');
        }
        
        // Validate UUID format
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $wmsCustomerId)) {
            throw new Exception('Invalid WMS customer ID format. Must be a valid UUID.');
        }
        
        return $wmsCustomerId;
    }
    
    /**
     * Get shipping address in WMS format
     */
    private function getWMSShippingAddress(WC_Order $order): array {
        $street = $order->get_shipping_address_1();
        $streetNumber = '';
        $streetName = $street;
        $streetNumberAddition = null;
        
        // Try to extract street number (basic implementation)
        if (preg_match('/^(\d+)\s*([a-zA-Z]?)\s+(.+)$/', $street, $matches)) {
            $streetNumber = $matches[1];
            $streetNumberAddition = !empty($matches[2]) ? $matches[2] : null;
            $streetName = $matches[3];
        }
        
        // Simplified shipping address to match API documentation examples
        return [
            'addressed_to' => trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()),
            'street' => $streetName ?: $street,
            'street_number' => $streetNumber,
            'zipcode' => $order->get_shipping_postcode(),
            'city' => $order->get_shipping_city(),
            'country' => $order->get_shipping_country()
        ];
    }
    
    /**
     * Get order lines in WMS format
     */
    private function getWMSOrderLines(WC_Order $order): array {
        $orderLines = [];
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) continue;
            
            // Get product SKU for article_code
            $sku = $product->get_sku();
            if (empty($sku)) {
                $sku = 'WC_' . $product->get_id();
            }
            
            $orderLines[] = [
                'article_code' => $sku, // Use SKU as article_code
                'quantity' => $item->get_quantity(),
                'description' => $product->get_name()
                // Order meta data excluded to prevent API compatibility issues
            ];
        }
        
        return $orderLines;
    }
    
    /**
     * Get shipping method ID from WooCommerce order
     */
    private function getWMSShippingMethodId(WC_Order $order): ?string {
        $shippingMethods = $order->get_shipping_methods();
        if (empty($shippingMethods)) {
            // Use default shipping method if no shipping method on order
            return get_option('wc_wms_default_shipping_method_uuid', null);
        }
        
        $shippingMethod = reset($shippingMethods);
        $methodKey = $shippingMethod->get_method_id() . ':' . $shippingMethod->get_instance_id();
        
        // Get shipping method mapping from existing admin system
        $shippingMapping = get_option('wc_wms_shipping_method_uuid_mapping', []);
        
        // Return the mapped WMS UUID if exists, otherwise use default
        return $shippingMapping[$methodKey] ?? get_option('wc_wms_default_shipping_method_uuid', null);
    }
    
    /**
     * Get requested delivery date
     */
    private function getRequestedDeliveryDate(WC_Order $order): string {
        // Check if customer requested specific delivery date
        $requestedDate = $order->get_meta('_requested_delivery_date');
        if ($requestedDate) {
            return date('Y-m-d', strtotime($requestedDate));
        }
        
        // Check for delivery date from checkout fields
        $deliveryDate = $order->get_meta('_delivery_date');
        if ($deliveryDate) {
            return date('Y-m-d', strtotime($deliveryDate));
        }
        
        // Default to next business day
        $tomorrow = strtotime('+1 day');
        
        // Skip weekends
        while (date('N', $tomorrow) >= 6) {
            $tomorrow = strtotime('+1 day', $tomorrow);
        }
        
        return date('Y-m-d', $tomorrow);
    }
    
    /**
     * Get order language
     */
    private function getOrderLanguage(WC_Order $order): string {
        // Check order meta for language
        $language = $order->get_meta('_order_language');
        if ($language) {
            return $language;
        }
        
        // Check customer language
        $customerId = $order->get_customer_id();
        if ($customerId) {
            $language = get_user_meta($customerId, '_language', true);
            if ($language) {
                return $language;
            }
        }
        
        // Default to site language
        return substr(get_locale(), 0, 2) ?: 'en';
    }
    
    /**
     * Get order meta data for WMS
     */
    private function getOrderMetaData(WC_Order $order): array {
        $metaData = [];
        
        // Add order status
        $metaData['wc_order_status'] = $order->get_status();
        
        // Add payment method
        $metaData['payment_method'] = $order->get_payment_method();
        
        // Add order total
        $metaData['order_total'] = $order->get_total();
        
        // Add customer ID
        if ($order->get_customer_id()) {
            $metaData['wc_customer_id'] = $order->get_customer_id();
        }
        
        // Add custom meta fields
        $customMeta = $order->get_meta('_wms_meta_data');
        if ($customMeta && is_array($customMeta)) {
            $metaData = array_merge($metaData, $customMeta);
        }
        
        return $metaData;
    }
    
    /**
     * Get item meta data
     */
    private function getItemMetaData(WC_Order_Item $item): array {
        $metaData = [];
        
        foreach ($item->get_meta_data() as $meta) {
            $metaData[$meta->key] = $meta->value;
        }
        
        return $metaData;
    }
}
