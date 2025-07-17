<?php
/**
 * WMS Shipment Integrator
 * 
 * Handles shipment-related business logic and abstracts direct service calls
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Shipment_Integrator implements WC_WMS_Shipment_Integrator_Interface {
    
    /**
     * WMS client instance
     */
    private $client;
    
    /**
     * Shipment service instance
     */
    private $shipmentService;
    
    /**
     * Order state manager instance
     */
    private $orderStateManager;
    
    /**
     * Constructor
     */
    public function __construct(WC_WMS_Client $client) {
        $this->client = $client;
        $this->shipmentService = $client->shipments();
        $this->orderStateManager = $client->orderStateManager();
    }
    
    /**
     * Get integrator name (Interface requirement)
     */
    public function getIntegratorName(): string {
        return 'shipment';
    }
    
    /**
     * Check if integrator is ready (Interface requirement)
     */
    public function isReady(): bool {
        try {
            // Check if WMS client is available
            if (!$this->client || !$this->client->authenticator()->isAuthenticated()) {
                return false;
            }
            
            // Check if shipment service is available
            if (!$this->shipmentService || !$this->shipmentService->isAvailable()) {
                return false;
            }
            
            // Check database connectivity
            global $wpdb;
            if (!$wpdb || $wpdb->last_error) {
                return false;
            }
            
            return true;
            
        } catch (Exception $e) {
            $this->client->logger()->error('Shipment integrator readiness check failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Get integrator status (Interface requirement)
     */
    public function getStatus(): array {
        $status = [
            'name' => $this->getIntegratorName(),
            'ready' => $this->isReady(),
            'last_sync' => null,
            'shipments_tracked' => 0,
            'pending_shipments' => 0,
            'tracking_errors' => 0,
            'health_score' => 0,
            'issues' => []
        ];
        
        try {
            // Get shipment statistics
            $shipmentStats = $this->getShipmentStatistics();
            $status['shipments_tracked'] = $shipmentStats['shipped_orders'] ?? 0;
            $status['last_sync'] = $shipmentStats['last_sync_formatted'] ?? null;
            
            // Calculate health score (0-100)
            $healthScore = 100;
            
            if (!$status['ready']) {
                $healthScore -= 50;
                $status['issues'][] = 'Integrator not ready';
            }
            
            if (!$status['last_sync'] || $shipmentStats['last_sync'] < strtotime('-2 hours')) {
                $healthScore -= 20;
                $status['issues'][] = 'Shipment sync not recent';
            }
            
            // Check shipping percentage
            $shippingPercentage = $shipmentStats['shipping_percentage'] ?? 0;
            if ($shippingPercentage < 70) {
                $healthScore -= 15;
                $status['issues'][] = "Low shipping percentage: {$shippingPercentage}%";
            }
            
            $status['health_score'] = max(0, $healthScore);
            $status['shipping_percentage'] = $shippingPercentage;
            
        } catch (Exception $e) {
            $status['issues'][] = 'Failed to get status: ' . $e->getMessage();
            $status['health_score'] = 0;
        }
        
        return $status;
    }
    
    /**
     * Process shipment webhook data
     */
    public function processShipmentWebhook(array $shipmentData): array {
        $this->client->logger()->info('Processing shipment webhook through integrator', [
            'shipment_id' => $shipmentData['id'] ?? 'unknown',
            'order_external_reference' => $shipmentData['order_external_reference'] ?? 'unknown'
        ]);
        
        // Validate shipment data
        $validation = $this->validateShipmentData($shipmentData);
        if (!$validation['valid']) {
            throw new Exception('Invalid shipment data: ' . implode(', ', $validation['errors']));
        }
        
        // Find the corresponding WooCommerce order
        $order = $this->findOrderByExternalReference($shipmentData['order_external_reference']);
        if (!$order) {
            throw new Exception('WooCommerce order not found for reference: ' . $shipmentData['order_external_reference']);
        }
        
        // Get full shipment details from WMS
        $fullShipment = $this->getShipmentDetails($shipmentData['id']);
        
        // Update order with shipment data
        $this->updateOrderWithShipmentData($order, $fullShipment);
        
        // Log successful processing
        $this->client->logger()->info('Shipment webhook processed successfully', [
            'shipment_id' => $shipmentData['id'],
            'order_id' => $order->get_id(),
            'order_external_reference' => $shipmentData['order_external_reference']
        ]);
        
        return [
            'success' => true,
            'order_id' => $order->get_id(),
            'shipment_id' => $shipmentData['id'],
            'message' => 'Order updated with shipment data'
        ];
    }
    
    /**
     * Update WooCommerce order with shipment data
     */
    public function updateOrderWithShipmentData(WC_Order $order, array $shipmentData): bool {
        $this->client->logger()->info('Updating order with shipment data through integrator', [
            'order_id' => $order->get_id(),
            'shipment_id' => $shipmentData['id'] ?? 'unknown'
        ]);
        
        // Suspend order hooks to prevent circular updates
        $this->orderStateManager->suspendOrderHooks($order);
        
        try {
            // Update order metadata
            $this->updateOrderShipmentMeta($order, $shipmentData);
            
            // Update order status based on shipment status
            $this->updateOrderStatusFromShipment($order, $shipmentData);
            
            // Add order notes
            $this->addShipmentOrderNotes($order, $shipmentData);
            
            // Save order
            $order->save();
            
            // Mark as processed by WMS
            $this->orderStateManager->markAsWebhookProcessed($order);
            
            $this->client->logger()->info('Order updated successfully with shipment data', [
                'order_id' => $order->get_id(),
                'shipment_id' => $shipmentData['id'] ?? 'unknown'
            ]);
            
            return true;
            
        } catch (Exception $e) {
            $this->client->logger()->error('Failed to update order with shipment data', [
                'order_id' => $order->get_id(),
                'shipment_id' => $shipmentData['id'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            
            return false;
            
        } finally {
            // Always restore order hooks
            $this->orderStateManager->restoreOrderHooks($order);
        }
    }
    
    /**
     * Get shipments for a specific order
     */
    public function getOrderShipments(WC_Order $order): array {
        $orderExternalReference = $this->getOrderExternalReference($order);
        
        if (empty($orderExternalReference)) {
            return [];
        }
        
        $this->client->logger()->debug('Getting shipments for order', [
            'order_id' => $order->get_id(),
            'external_reference' => $orderExternalReference
        ]);
        
        try {
            $shipments = $this->shipmentService->getShipmentsByOrder($orderExternalReference);
            
            $this->client->logger()->info('Retrieved shipments for order', [
                'order_id' => $order->get_id(),
                'shipment_count' => count($shipments)
            ]);
            
            return $shipments;
            
        } catch (Exception $e) {
            $this->client->logger()->error('Failed to get shipments for order', [
                'order_id' => $order->get_id(),
                'external_reference' => $orderExternalReference,
                'error' => $e->getMessage()
            ]);
            
            return [];
        }
    }
    
    /**
     * Sync shipment tracking information
     */
    public function syncShipmentTracking(string $shipmentId): array {
        $this->client->logger()->info('Syncing shipment tracking', [
            'shipment_id' => $shipmentId
        ]);
        
        try {
            // Get shipment details from WMS
            $shipment = $this->getShipmentDetails($shipmentId);
            
            // Find the corresponding order
            $order = $this->findOrderByExternalReference($shipment['order_external_reference']);
            if (!$order) {
                throw new Exception('Order not found for shipment: ' . $shipmentId);
            }
            
            // Update order with tracking info
            $this->updateOrderWithShipmentData($order, $shipment);
            
            return [
                'success' => true,
                'shipment_id' => $shipmentId,
                'order_id' => $order->get_id(),
                'tracking_number' => $shipment['tracking_number'] ?? null,
                'tracking_url' => $shipment['tracking_url'] ?? null
            ];
            
        } catch (Exception $e) {
            $this->client->logger()->error('Failed to sync shipment tracking', [
                'shipment_id' => $shipmentId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Create return label for shipment
     */
    public function createReturnLabel(string $shipmentId): array {
        $this->client->logger()->info('Creating return label through integrator', [
            'shipment_id' => $shipmentId
        ]);
        
        try {
            // Create return label via service
            $returnLabel = $this->shipmentService->createReturnLabel($shipmentId);
            
            // Update order with return label info
            $this->updateOrderWithReturnLabel($shipmentId, $returnLabel);
            
            return [
                'success' => true,
                'shipment_id' => $shipmentId,
                'return_label_url' => $returnLabel['url'] ?? null,
                'return_label_id' => $returnLabel['id'] ?? null
            ];
            
        } catch (Exception $e) {
            $this->client->logger()->error('Failed to create return label', [
                'shipment_id' => $shipmentId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get shipment status
     */
    public function getShipmentStatus(string $shipmentId): string {
        try {
            $shipment = $this->getShipmentDetails($shipmentId);
            // Since shipments don't have status field in WMS API, we infer it
            return !empty($shipment) ? 'shipped' : 'unknown';
            
        } catch (Exception $e) {
            $this->client->logger()->error('Failed to get shipment status', [
                'shipment_id' => $shipmentId,
                'error' => $e->getMessage()
            ]);
            
            return 'unknown';
        }
    }
    
    /**
     * Get shipping methods
     */
    public function getShippingMethods(): array {
        $this->client->logger()->debug('Getting shipping methods through integrator');
        
        try {
            // Get from cache first
            $cachedMethods = get_option('wc_wms_shipping_methods', []);
            $lastSync = get_option('wc_wms_shipping_methods_synced_at', 0);
            
            // If cache is fresh (less than 1 hour old), return cached data
            if (!empty($cachedMethods) && $lastSync && (time() - strtotime($lastSync)) < 3600) {
                return $cachedMethods;
            }
            
            // Otherwise sync from WMS
            $syncResult = $this->syncShippingMethods();
            return $syncResult['methods'] ?? [];
            
        } catch (Exception $e) {
            $this->client->logger()->error('Failed to get shipping methods', [
                'error' => $e->getMessage()
            ]);
            
            return [];
        }
    }
    
    /**
     * Sync shipping methods from WMS
     */
    public function syncShippingMethods(): array {
        $this->client->logger()->info('Syncing shipping methods through integrator');
        
        try {
            // Use service to sync shipping methods
            $result = $this->shipmentService->syncShippingMethods();
            
            $this->client->logger()->info('Shipping methods synced successfully', [
                'count' => $result['count'] ?? 0
            ]);
            
            return $result;
            
        } catch (Exception $e) {
            $this->client->logger()->error('Failed to sync shipping methods', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get shipment statistics
     */
    public function getShipmentStatistics(): array {
        try {
            return $this->shipmentService->getShipmentStatistics();
            
        } catch (Exception $e) {
            $this->client->logger()->error('Failed to get shipment statistics', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'shipped_orders' => 0,
                'total_orders' => 0,
                'shipping_percentage' => 0,
                'last_sync' => 0,
                'last_sync_formatted' => 'Error'
            ];
        }
    }
    
    /**
     * Get shipment status statistics
     */
    public function getShipmentStatusStats(int $days = 30): array {
        try {
            return $this->shipmentService->getShipmentStatusStats($days);
            
        } catch (Exception $e) {
            $this->client->logger()->error('Failed to get shipment status stats', [
                'error' => $e->getMessage()
            ]);
            
            return [];
        }
    }
    
    /**
     * Get recent shipments
     */
    public function getRecentShipments(int $days = 7, int $limit = 50): array {
        try {
            return $this->shipmentService->getRecentShipments($days, $limit);
            
        } catch (Exception $e) {
            $this->client->logger()->error('Failed to get recent shipments', [
                'error' => $e->getMessage()
            ]);
            
            return [];
        }
    }
    
    /**
     * Get shipments with parameters (delegates to service)
     */
    public function getShipments(array $params = []): array {
        try {
            return $this->shipmentService->getShipments($params);
            
        } catch (Exception $e) {
            $this->client->logger()->error('Failed to get shipments', [
                'error' => $e->getMessage(),
                'params' => $params
            ]);
            
            return [];
        }
    }
    
    /**
     * Validate shipment data
     */
    public function validateShipmentData(array $shipmentData): array {
        $errors = [];
        
        // Check required fields
        if (empty($shipmentData['id'])) {
            $errors[] = 'Missing shipment ID';
        }
        
        if (empty($shipmentData['order_external_reference'])) {
            $errors[] = 'Missing order external reference';
        }
        
        // Validate data types
        if (isset($shipmentData['id']) && !is_string($shipmentData['id'])) {
            $errors[] = 'Shipment ID must be a string';
        }
        
        if (isset($shipmentData['order_external_reference']) && !is_string($shipmentData['order_external_reference'])) {
            $errors[] = 'Order external reference must be a string';
        }
        
        // Validate optional fields that actually exist in the API
        if (isset($shipmentData['created_at']) && !is_string($shipmentData['created_at'])) {
            $errors[] = 'Created at must be a string';
        }
        
        if (isset($shipmentData['reference']) && !is_string($shipmentData['reference'])) {
            $errors[] = 'Reference must be a string';
        }
        
        // NOTE: Status field validation removed - shipments don't have status in WMS API
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Get shipment details from WMS
     */
    private function getShipmentDetails(string $shipmentId): array {
        return $this->shipmentService->getShipment($shipmentId);
    }
    
    /**
     * Find order by external reference
     */
    private function findOrderByExternalReference(string $externalReference): ?WC_Order {
        // Try to find by order number first
        $orders = wc_get_orders([
            'search' => $externalReference,
            'limit' => 1
        ]);
        
        if (!empty($orders)) {
            return $orders[0];
        }
        
        // Try to find by meta data
        $orders = wc_get_orders([
            'meta_key' => '_wms_external_reference',
            'meta_value' => $externalReference,
            'limit' => 1
        ]);
        
        if (!empty($orders)) {
            return $orders[0];
        }
        
        return null;
    }
    
    /**
     * Get order external reference
     */
    private function getOrderExternalReference(WC_Order $order): string {
        // Check if order has WMS external reference
        $externalRef = $order->get_meta('_wms_external_reference');
        if (!empty($externalRef)) {
            return $externalRef;
        }
        
        // Fallback to order number
        return $order->get_order_number();
    }
    
    /**
     * Update order shipment metadata
     */
    private function updateOrderShipmentMeta(WC_Order $order, array $shipmentData): void {
        // Store shipment ID and reference
        $order->update_meta_data('_wms_shipment_id', $shipmentData['id']);
        $order->update_meta_data('_wms_shipment_reference', $shipmentData['reference'] ?? '');
        
        // Store tracking information
        if (isset($shipmentData['shipment_labels']) && is_array($shipmentData['shipment_labels'])) {
            $firstLabel = reset($shipmentData['shipment_labels']);
            if ($firstLabel) {
                $order->update_meta_data('_wms_tracking_number', $firstLabel['tracking_code'] ?? '');
                $order->update_meta_data('_wms_tracking_url', $firstLabel['tracking_url'] ?? '');
            }
        }
        
        // Store carrier information
        if (isset($shipmentData['shipping_method'])) {
            $order->update_meta_data('_wms_carrier', $shipmentData['shipping_method']['shipper'] ?? '');
            $order->update_meta_data('_wms_shipping_method_id', $shipmentData['shipping_method']['id'] ?? '');
        }
        
        // Store return label if available
        if (isset($shipmentData['return_label'])) {
            $order->update_meta_data('_wms_return_label_url', $shipmentData['return_label']['url'] ?? '');
            $order->update_meta_data('_wms_return_label_id', $shipmentData['return_label']['id'] ?? '');
        }
        
        // Store complete shipment data
        $order->update_meta_data('_wms_shipment_data', json_encode($shipmentData));
        $order->update_meta_data('_wms_shipped_at', current_time('mysql'));
    }
    
    /**
     * Update order status based on shipment status
     */
    private function updateOrderStatusFromShipment(WC_Order $order, array $shipmentData): void {
        $currentStatus = $order->get_status();
        
        // Since shipments don't have status in the WMS API, we infer:
        // If a shipment exists, it means the order has been shipped
        $inferredStatus = 'shipped';
        
        // Map inferred status to WooCommerce order status
        $statusMap = [
            'shipped' => 'completed'
        ];
        
        $newStatus = $statusMap[$inferredStatus];
        
        if ($currentStatus !== $newStatus && !in_array($currentStatus, ['completed', 'cancelled', 'refunded'])) {
            $order->update_status($newStatus, sprintf(
                __('Order status updated - shipment created: %s', 'wc-wms-integration'),
                $shipmentData['reference'] ?? $shipmentData['id']
            ));
            
            $this->client->logger()->info('Order status updated based on shipment', [
                'order_id' => $order->get_id(),
                'old_status' => $currentStatus,
                'new_status' => $newStatus,
                'shipment_reference' => $shipmentData['reference'] ?? $shipmentData['id'],
                'shipment_created_at' => $shipmentData['created_at'] ?? 'unknown'
            ]);
        }
    }
    
    /**
     * Add shipment-related order notes
     */
    private function addShipmentOrderNotes(WC_Order $order, array $shipmentData): void {
        $trackingNumber = '';
        $carrier = '';
        
        // Extract tracking information
        if (isset($shipmentData['shipment_labels']) && is_array($shipmentData['shipment_labels'])) {
            $firstLabel = reset($shipmentData['shipment_labels']);
            if ($firstLabel) {
                $trackingNumber = $firstLabel['tracking_code'] ?? '';
            }
        }
        
        // Extract carrier information
        if (isset($shipmentData['shipping_method'])) {
            $carrier = $shipmentData['shipping_method']['shipper'] ?? '';
        }
        
        // Build note message
        $noteMessage = __('Order shipped from WMS', 'wc-wms-integration');
        if ($trackingNumber) {
            $noteMessage .= sprintf(__(' - Tracking: %s', 'wc-wms-integration'), $trackingNumber);
        }
        if ($carrier) {
            $noteMessage .= sprintf(__(' (%s)', 'wc-wms-integration'), $carrier);
        }
        
        $order->add_order_note($noteMessage);
    }
    
    /**
     * Update order with return label information
     */
    private function updateOrderWithReturnLabel(string $shipmentId, array $returnLabelData): void {
        // Find order by shipment ID
        $orders = wc_get_orders([
            'meta_key' => '_wms_shipment_id',
            'meta_value' => $shipmentId,
            'limit' => 1
        ]);
        
        if (empty($orders)) {
            return;
        }
        
        $order = $orders[0];
        
        // Update return label metadata
        $order->update_meta_data('_wms_return_label_url', $returnLabelData['url'] ?? '');
        $order->update_meta_data('_wms_return_label_id', $returnLabelData['id'] ?? '');
        $order->update_meta_data('_wms_return_label_created_at', current_time('mysql'));
        
        // Add order note
        $order->add_order_note(__('Return label created and available for download', 'wc-wms-integration'));
        
        $order->save();
    }
}
