<?php
/**
 * WMS Order Integrator
 * 
 * Integrates WooCommerce orders with WMS
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Order_Integrator implements WC_WMS_Order_Integrator_Interface {
    
    /**
     * WMS client instance
     */
    private $client;
    
    /**
     * Order state manager instance
     */
    private $orderStateManager;
    
    /**
     * Shipment integrator instance
     */
    private $shipmentIntegrator;
    
    /**
     * Order sync manager instance
     */
    private $orderSyncManager;
    
    /**
     * Constructor
     */
    public function __construct(WC_WMS_Client $client) {
        $this->client = $client;
        $this->orderStateManager = $client->orderStateManager();
        $this->shipmentIntegrator = new WC_WMS_Shipment_Integrator($client);
        $this->orderSyncManager = new WC_WMS_Order_Sync_Manager($client);
    }
    
    /**
     * Get integrator name (Interface requirement)
     */
    public function getIntegratorName(): string {
        return 'order';
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
            
            // Check if order service is available
            if (!$this->client->orders() || !$this->client->orders()->isAvailable()) {
                return false;
            }            
            // Check database connectivity
            global $wpdb;
            if (!$wpdb || $wpdb->last_error) {
                return false;
            }
            
            return true;
            
        } catch (Exception $e) {
            $this->client->logger()->error('Order integrator readiness check failed', [
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
            'orders_synced' => 0,
            'pending_exports' => 0,
            'export_errors' => 0,
            'health_score' => 0,
            'issues' => []
        ];        
        try {
            // Get sync statistics
            $syncStats = $this->getOrderSyncStatistics();
            $status['orders_synced'] = $syncStats['wms_orders'] ?? 0;
            $status['last_sync'] = $syncStats['last_sync'] ?? null;
            
            // Calculate pending exports (orders that should be exported but aren't)
            $pendingCount = $this->getPendingExportCount();
            $status['pending_exports'] = $pendingCount;
            
            // Calculate health score (0-100)
            $healthScore = 100;
            
            if (!$status['ready']) {
                $healthScore -= 50;
                $status['issues'][] = 'Integrator not ready';
            }
            
            if ($status['pending_exports'] > 10) {
                $healthScore -= 20;
                $status['issues'][] = "High number of pending exports: {$status['pending_exports']}";
            }
            
            if (!$status['last_sync'] || intval($status['last_sync']) < strtotime('-1 hour')) {
                $healthScore -= 15;
                $status['issues'][] = 'Order sync not recent';
            }            
            // Check sync percentage
            $syncPercentage = $syncStats['sync_percentage'] ?? 0;
            if ($syncPercentage < 80) {
                $healthScore -= 15;
                $status['issues'][] = "Low sync percentage: {$syncPercentage}%";
            }
            
            $status['health_score'] = max(0, $healthScore);
            $status['sync_percentage'] = $syncPercentage;
            
        } catch (Exception $e) {
            $status['issues'][] = 'Failed to get status: ' . $e->getMessage();
            $status['health_score'] = 0;
        }
        
        return $status;
    }
    
    // ===== INTERFACE METHODS =====
    
    /**
     * Export order to WMS (Interface requirement)
     */
    public function exportOrder(int $orderId): mixed {
        return $this->orderSyncManager->processOrderExport($orderId);
    }
    
    /**
     * Cancel order in WMS (Interface requirement)
     */
    public function cancelOrder(int $orderId): mixed {
        return $this->orderSyncManager->processOrderCancellation($orderId);
    }
    
    /**
     * Transform order data for WMS (Interface requirement)
     */
    public function transformOrderData(WC_Order $order): array {
        return $this->client->orders()->transformWooCommerceOrder($order);
    }
    
    /**
     * Check if order should be exported to WMS (Interface requirement)
     */
    public function shouldExportOrder(WC_Order $order): bool {
        return $this->orderSyncManager->shouldExportOrder($order);
    }
    
    // ===== CORE FUNCTIONALITY =====
    
    /**
     * Send WooCommerce order to WMS
     */
    public function sendOrderToWMS(WC_Order $order): array {
        $this->client->logger()->info('Sending WooCommerce order to WMS', [
            'order_id' => $order->get_id(),
            'order_number' => $order->get_order_number(),
            'order_status' => $order->get_status()
        ]);
        
        // Check if order is already in WMS
        $wmsOrderId = $this->orderStateManager->getWmsOrderId($order);
        if ($wmsOrderId) {
            return $this->updateOrderInWMS($order, $wmsOrderId);
        }
        
        // Transform order to WMS format
        $orderData = $this->client->orders()->transformWooCommerceOrder($order);
        
        // Create order in WMS
        $result = $this->createOrderInWMS($order, $orderData);
        
        return $result;
    }
    
    /**
     * Process order status update from WMS
     */
    public function processOrderStatusUpdate(array $wmsOrderData): array {
        $externalReference = $wmsOrderData['external_reference'] ?? '';
        $wmsOrderId = $wmsOrderData['id'] ?? '';
        
        if (empty($externalReference)) {
            throw new Exception('Missing external reference in WMS order data');
        }
        
        return $this->orderSyncManager->processWebhookOrderEvent('updated', $wmsOrderData);
    }
    
    /**
     * Cancel order in WMS
     */
    public function cancelOrderInWMS(WC_Order $order): array {
        $wmsOrderId = $order->get_meta('_wms_order_id');
        
        if (empty($wmsOrderId)) {
            throw new Exception('Order not found in WMS');
        }
        
        // Cancel order in WMS
        $result = $this->client->orders()->cancelOrder($wmsOrderId);
        
        // Update order meta
        $order->update_meta_data('_wms_cancelled_at', current_time('mysql'));
        $order->update_meta_data('_wms_cancel_result', json_encode($result));
        $order->save();
        
        // Add order note
        $order->add_order_note(__('Order cancelled in WMS', 'wc-wms-integration'));
        
        return $result;
    }
    
    /**
     * Sync order status from WMS
     */
    public function syncOrderStatusFromWMS(WC_Order $order): bool {
        $wmsOrderId = $order->get_meta('_wms_order_id');
        
        if (empty($wmsOrderId)) {
            return false;
        }
        
        try {
            // Get order from WMS with order lines
            $wmsOrder = $this->client->orders()->getOrder($wmsOrderId, ['order_lines', 'meta_data']);
            
            $result = $this->orderSyncManager->updateOrderFromWMS($order, $wmsOrder);
            
            return $result['success'] ?? false;
            
        } catch (Exception $e) {
            $this->client->logger()->error('Failed to sync order status from WMS', [
                'order_id' => $order->get_id(),
                'wms_order_id' => $wmsOrderId,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }
    
    /**
     * Get order sync statistics
     */
    public function getOrderSyncStatistics(): array {
        global $wpdb;
        
        // Count orders with WMS IDs
        $wmsOrders = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = '_wms_order_id' 
             AND p.post_type = 'shop_order'"
        );
        
        // Count total orders
        $totalOrders = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             WHERE p.post_type = 'shop_order'
             AND p.post_status NOT IN ('trash', 'auto-draft')"
        );
        
        // Count orders by status
        $statusCounts = $wpdb->get_results(
            "SELECT p.post_status, COUNT(*) as count
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'shop_order'
             AND pm.meta_key = '_wms_order_id'
             GROUP BY p.post_status"
        );
        
        $statusStats = [];
        foreach ($statusCounts as $status) {
            $statusStats[$status->post_status] = intval($status->count);
        }        
        return [
            'wms_orders' => intval($wmsOrders),
            'total_orders' => intval($totalOrders),
            'sync_percentage' => $totalOrders > 0 ? 
                round(($wmsOrders / $totalOrders) * 100, 2) : 0,
            'status_breakdown' => $statusStats,
            'last_sync' => get_option('wc_wms_orders_last_sync', 0)
        ];
    }
    
    // ===== SHIPMENT INTEGRATION METHODS =====
    
    /**
     * Get order shipments using shipment integrator
     */
    public function getOrderShipments(WC_Order $order): array {
        return $this->shipmentIntegrator->getOrderShipments($order);
    }
    
    /**
     * Update order with shipment data using shipment integrator
     */
    public function updateOrderWithShipmentData(WC_Order $order, array $shipmentData): bool {
        return $this->shipmentIntegrator->updateOrderWithShipmentData($order, $shipmentData);
    }
    
    /**
     * Sync shipment tracking for order
     */
    public function syncOrderShipmentTracking(WC_Order $order): array {
        $shipmentId = $order->get_meta('_wms_shipment_id');
        
        if (empty($shipmentId)) {
            return [
                'success' => false,
                'error' => 'No shipment ID found for order'
            ];
        }
        
        return $this->shipmentIntegrator->syncShipmentTracking($shipmentId);
    }    
    // ===== CORE WMS API OPERATIONS =====
    
    /**
     * Create order in WMS
     */
    private function createOrderInWMS(WC_Order $order, array $orderData): array {
        $result = $this->client->orders()->createOrder($orderData);
        
        if (isset($result['id'])) {
            // Mark as exported using centralized state manager
            $this->orderStateManager->markAsExported($order, $result['id'], 'export');
            
            // Store additional WMS metadata
            $order->update_meta_data('_wms_external_reference', $result['external_reference']);
            $order->update_meta_data('_wms_created_at', current_time('mysql'));
            $order->update_meta_data('_wms_order_data', json_encode($result));
            $order->save();
            
            // Add order note
            $order->add_order_note(sprintf(
                __('Order created in WMS with ID: %s', 'wc-wms-integration'),
                $result['id']
            ));
            
            $this->client->logger()->info('Order created in WMS successfully', [
                'order_id' => $order->get_id(),
                'wms_order_id' => $result['id']
            ]);
        }
        
        return $result;
    }
    
    /**
     * Update order in WMS
     */
    private function updateOrderInWMS(WC_Order $order, string $wmsOrderId): array {        // Only send updatable fields to WMS
        $updateData = $this->getUpdatableOrderData($order);
        
        $result = $this->client->orders()->updateOrder($wmsOrderId, $updateData);
        
        if (isset($result['id'])) {
            // Update order meta
            $order->update_meta_data('_wms_updated_at', current_time('mysql'));
            $order->update_meta_data('_wms_order_data', json_encode($result));
            $order->save();
            
            // Add order note
            $order->add_order_note(__('Order updated in WMS', 'wc-wms-integration'));
            
            $this->client->logger()->info('Order updated in WMS successfully', [
                'order_id' => $order->get_id(),
                'wms_order_id' => $wmsOrderId
            ]);
        }
        
        return $result;
    }
    
    // ===== BULK OPERATIONS - DELEGATES TO CENTRALIZED SYNC MANAGER =====
    
    /**
     * Process bulk order sync to WMS
     */
    public function syncOrdersToWMS(array $orderIds = []): array {
        $this->client->logger()->info('Starting bulk order sync to WMS', [
            'order_ids' => $orderIds
        ]);
        
        $results = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'error_details' => []
        ];        
        // Get orders to sync
        if (empty($orderIds)) {
            $orders = wc_get_orders([
                'status' => ['processing', 'on-hold'],
                'limit' => 100
            ]);
        } else {
            $orders = array_map('wc_get_order', $orderIds);
            $orders = array_filter($orders);
        }
        
        foreach ($orders as $order) {
            try {
                $result = $this->sendOrderToWMS($order);
                
                if (isset($result['id'])) {
                    $wmsOrderId = $order->get_meta('_wms_order_id');
                    if ($wmsOrderId) {
                        $results['updated']++;
                    } else {
                        $results['created']++;
                    }
                } else {
                    $results['skipped']++;
                }
                
            } catch (Exception $e) {
                $results['errors']++;
                $results['error_details'][] = [
                    'order_id' => $order->get_id(),
                    'order_number' => $order->get_order_number(),
                    'error' => $e->getMessage()
                ];
                
                $this->client->logger()->error('Failed to sync order to WMS', [
                    'order_id' => $order->get_id(),
                    'error' => $e->getMessage()
                ]);
            }
        }        
        $this->client->logger()->info('Bulk order sync to WMS completed', $results);
        
        return $results;
    }
    
    /**
     * Sync orders from WMS to WooCommerce - FIXED: No more circular dependency
     */
    public function syncOrdersFromWMS(array $options = []): array {
        $this->client->logger()->info('Starting order synchronization from WMS via integrator interface (delegating to sync manager)', [
            'options' => $options
        ]);
        
        // Interface method - delegates to sync manager for coordination
        return $this->orderSyncManager->processCronOrderSync($options);
    }
    
    /**
     * Actual sync implementation - called by sync manager to avoid circular dependency
     */
    public function syncOrdersFromWMSActual(array $options = []): array {
        $this->client->logger()->info('Executing actual order synchronization from WMS', [
            'options' => $options
        ]);
        
        $results = [
            'total_fetched' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => []
        ];
        
        try {
            // Fetch orders from WMS using client
            $params = [
                'limit' => $options['limit'] ?? 100,
                'direction' => 'desc',
                'sort' => 'createdAt'
            ];
            
            // Add date filters if provided
            if (isset($options['from_date'])) {
                $params['from'] = $options['from_date'];
            }
            
            if (isset($options['to_date'])) {
                $params['to'] = $options['to_date'];
            }
            
            if (isset($options['status'])) {
                $params['status'] = $options['status'];
            }
            
            $this->client->logger()->info('Fetching orders from WMS for sync', [
                'params' => $params
            ]);
            
            // Get orders from WMS
            $wmsOrders = $this->client->orders()->getOrders($params);
            $results['total_fetched'] = count($wmsOrders);
            
            if (empty($wmsOrders)) {
                $this->client->logger()->info('No orders found in WMS for sync');
                return $results;
            }
            
            // Process each order
            foreach ($wmsOrders as $wmsOrder) {
                try {
                    // Get full order details including order_lines
                    $fullOrder = $this->client->orders()->getOrder($wmsOrder['id'], ['order_lines', 'meta_data']);
                    
                    // Process using centralized sync manager webhook event handler
                    $syncResult = $this->orderSyncManager->processWebhookOrderEvent('created', $fullOrder);
                    
                    if ($syncResult['success']) {
                        $orderResult = $syncResult['result'];
                        if (isset($orderResult['order_id'])) {
                            $results['updated']++;
                        } else {
                            $results['created']++;
                        }
                    } else {
                        $results['skipped']++;
                        $results['errors'][] = [
                            'wms_order_id' => $wmsOrder['id'],
                            'error' => $syncResult['error']
                        ];
                    }
                    
                } catch (Exception $e) {
                    $results['errors'][] = [
                        'wms_order_id' => $wmsOrder['id'] ?? 'unknown',
                        'error' => $e->getMessage()
                    ];
                    $results['skipped']++;
                }
            }
            
        } catch (Exception $e) {
            $this->client->logger()->error('Order sync failed', [
                'error' => $e->getMessage()
            ]);
            
            $results['errors'][] = [
                'general_error' => $e->getMessage()
            ];
        }
        
        $this->client->logger()->info('Order synchronization completed', $results);
        
        return $results;
    }
    
    /**
     * Sync single order from WMS data - FIXED: No more circular dependency
     */
    public function syncSingleOrderFromWMS(array $wmsOrder): array {
        $this->client->logger()->info('Syncing single order from WMS via integrator (legacy interface)', [
            'wms_order_id' => $wmsOrder['id'] ?? 'unknown',
            'external_reference' => $wmsOrder['external_reference'] ?? 'unknown'
        ]);
        
        // This method is kept for interface compatibility but should not be used for new orders
        // The actual sync logic is now in the Sync Manager to prevent circular dependencies
        
        $externalReference = $wmsOrder['external_reference'] ?? '';
        
        if (empty($externalReference)) {
            return [
                'success' => false,
                'error' => 'Missing external reference in WMS order data'
            ];
        }
        
        // Try to find existing order
        $order = $this->orderSyncManager->findOrderByExternalReference($externalReference);
        
        if ($order) {
            // Update existing order
            $result = $this->orderSyncManager->updateOrderFromWMS($order, $wmsOrder);
            
            return [
                'success' => $result['success'],
                'action' => 'updated',
                'order_id' => $order->get_id(),
                'wms_order_id' => $wmsOrder['id'] ?? 'unknown'
            ];
        } else {
            // For new orders, let the sync manager handle it
            return [
                'success' => false,
                'action' => 'skipped',
                'message' => 'Order not found and cannot be created from integrator (use sync manager)',
                'external_reference' => $externalReference
            ];
        }
    }    
    // ===== UTILITY AND STATISTICS METHODS =====
    
    /**
     * Get count of orders pending export to WMS
     */
    private function getPendingExportCount(): int {
        global $wpdb;
        
        $query = $wpdb->prepare("
            SELECT COUNT(*) 
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wms_order_id'
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-processing', 'wc-on-hold', 'wc-pending')
            AND pm.meta_value IS NULL
            AND p.post_date >= %s
        ", date('Y-m-d H:i:s', strtotime('-30 days')));
        
        return intval($wpdb->get_var($query));
    }
    
    /**
     * Get order export queue status
     */
    public function getExportQueueStatus(): array {
        global $wpdb;
        
        // Get orders by status that should be exported
        $statusQuery = $wpdb->prepare("
            SELECT 
                p.post_status,
                COUNT(*) as total,
                SUM(CASE WHEN pm.meta_value IS NOT NULL THEN 1 ELSE 0 END) as exported
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wms_order_id'
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-processing', 'wc-on-hold', 'wc-pending')
            AND p.post_date >= %s
            GROUP BY p.post_status
        ", date('Y-m-d H:i:s', strtotime('-7 days')));        
        $statusResults = $wpdb->get_results($statusQuery, ARRAY_A);
        
        $queueStatus = [
            'by_status' => [],
            'total_pending' => 0,
            'total_exported' => 0,
            'export_rate' => 0
        ];
        
        foreach ($statusResults as $result) {
            $status = str_replace('wc-', '', $result['post_status']);
            $total = intval($result['total']);
            $exported = intval($result['exported']);
            $pending = $total - $exported;
            
            $queueStatus['by_status'][$status] = [
                'total' => $total,
                'exported' => $exported,
                'pending' => $pending,
                'export_rate' => $total > 0 ? round(($exported / $total) * 100, 1) : 0
            ];
            
            $queueStatus['total_pending'] += $pending;
            $queueStatus['total_exported'] += $exported;
        }
        
        $totalOrders = $queueStatus['total_pending'] + $queueStatus['total_exported'];
        $queueStatus['export_rate'] = $totalOrders > 0 ? 
            round(($queueStatus['total_exported'] / $totalOrders) * 100, 1) : 0;
        
        return $queueStatus;
    }    
    /**
     * Get order processing metrics
     */
    public function getProcessingMetrics(): array {
        global $wpdb;
        
        $since = date('Y-m-d H:i:s', strtotime('-24 hours'));
        
        // Get orders created and exported in last 24h
        $metricsQuery = $wpdb->prepare("
            SELECT 
                COUNT(*) as total_orders,
                SUM(CASE WHEN pm.meta_value IS NOT NULL THEN 1 ELSE 0 END) as exported_orders,
                AVG(CASE 
                    WHEN pm.meta_value IS NOT NULL AND pm2.meta_value IS NOT NULL 
                    THEN TIMESTAMPDIFF(MINUTE, p.post_date, pm2.meta_value)
                    ELSE NULL 
                END) as avg_export_time_minutes
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wms_order_id'
            LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_wms_created_at'
            WHERE p.post_type = 'shop_order'
            AND p.post_date >= %s
        ", $since);
        
        $metrics = $wpdb->get_row($metricsQuery, ARRAY_A);
        
        return [
            'period' => '24 hours',
            'total_orders' => intval($metrics['total_orders'] ?? 0),
            'exported_orders' => intval($metrics['exported_orders'] ?? 0),
            'pending_orders' => intval($metrics['total_orders'] ?? 0) - intval($metrics['exported_orders'] ?? 0),
            'export_rate' => intval($metrics['total_orders'] ?? 0) > 0 ? 
                round((intval($metrics['exported_orders'] ?? 0) / intval($metrics['total_orders'] ?? 0)) * 100, 1) : 0,
            'avg_export_time_minutes' => $metrics['avg_export_time_minutes'] ? 
                round(floatval($metrics['avg_export_time_minutes']), 1) : null
        ];
    }    
    /**
     * Get failed export attempts
     */
    public function getFailedExports(): array {
        global $wpdb;
        
        $failedOrders = wc_get_orders([
            'meta_query' => [
                [
                    'key' => '_wms_export_attempts',
                    'value' => 1,
                    'compare' => '>='
                ],
                [
                    'key' => '_wms_order_id',
                    'compare' => 'NOT EXISTS'
                ]
            ],
            'limit' => 20,
            'orderby' => 'date',
            'order' => 'DESC'
        ]);
        
        $failedExports = [];
        foreach ($failedOrders as $order) {
            $failedExports[] = [
                'order_id' => $order->get_id(),
                'order_number' => $order->get_order_number(),
                'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'total' => $order->get_total(),
                'status' => $order->get_status(),
                'created_date' => $order->get_date_created() ? $order->get_date_created()->format('Y-m-d H:i:s') : '',
                'attempts' => intval($order->get_meta('_wms_export_attempts')),
                'last_error' => $order->get_meta('_wms_last_export_error'),
                'last_attempt' => $order->get_meta('_wms_last_export_attempt')
            ];
        }
        
        return $failedExports;
    }    
    /**
     * Retry failed exports
     */
    public function retryFailedExports(int $limit = 10): array {
        $failedExports = $this->getFailedExports();
        $results = [
            'attempted' => 0,
            'successful' => 0,
            'failed' => 0,
            'details' => []
        ];
        
        $ordersToRetry = array_slice($failedExports, 0, $limit);
        
        foreach ($ordersToRetry as $failedOrder) {
            $results['attempted']++;
            
            try {
                $order = wc_get_order($failedOrder['order_id']);
                if (!$order) {
                    continue;
                }
                
                // Update attempt count
                $attempts = intval($order->get_meta('_wms_export_attempts')) + 1;
                $order->update_meta_data('_wms_export_attempts', $attempts);
                $order->update_meta_data('_wms_last_export_attempt', current_time('mysql'));
                
                // Try to export
                $result = $this->sendOrderToWMS($order);
                
                if (isset($result['id'])) {
                    $results['successful']++;
                    // Clear error flags
                    $order->delete_meta_data('_wms_export_attempts');
                    $order->delete_meta_data('_wms_last_export_error');
                    $order->save();                    
                    $results['details'][] = [
                        'order_id' => $order->get_id(),
                        'status' => 'success',
                        'wms_id' => $result['id']
                    ];
                } else {
                    throw new Exception('No WMS ID returned');
                }
                
            } catch (Exception $e) {
                $results['failed']++;
                
                if (isset($order)) {
                    $order->update_meta_data('_wms_last_export_error', $e->getMessage());
                    $order->save();
                }
                
                $results['details'][] = [
                    'order_id' => $failedOrder['order_id'],
                    'status' => 'failed',
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $results;
    }
    
    /**
     * Get order sync statistics for dashboard
     */
    public function getOrderSyncStats(): array {
        global $wpdb;
        
        // Get total orders synced from WMS
        $syncedFromWms = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = '_wms_synced_from_wms' AND meta_value = 'yes'
        ");        
        // Get total orders exported to WMS
        $exportedToWms = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = '_wms_order_id' AND meta_value != ''
        ");
        
        // Get total WooCommerce orders
        $totalOrders = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->posts} 
            WHERE post_type = 'shop_order'
        ");
        
        return [
            'total_orders' => intval($totalOrders),
            'synced_from_wms' => intval($syncedFromWms),
            'exported_to_wms' => intval($exportedToWms),
            'bidirectional_sync' => intval($syncedFromWms) + intval($exportedToWms),
            'sync_coverage' => $totalOrders > 0 ? round(((intval($syncedFromWms) + intval($exportedToWms)) / $totalOrders) * 100, 1) : 0
        ];
    }
    
    // ===== HELPER METHODS FOR ORDER DATA FORMATTING =====
    
    /**
     * Get updatable order data (for PATCH requests)
     */
    private function getUpdatableOrderData(WC_Order $order): array {
        $updateData = [];
        
        // Only include fields that can be updated
        $updateData['note'] = $order->get_customer_note() ?: null;
        $updateData['customer_note'] = $order->get_customer_note() ?: null;
        $updateData['shipping_email'] = $order->get_billing_email();
        $updateData['language'] = $this->getOrderLanguage($order);
        $updateData['order_amount'] = intval($order->get_total() * 100);
        $updateData['currency'] = $order->get_currency();
        $updateData['meta_data'] = $this->getOrderMetaData($order);        
        // Check if shipping address changed
        $currentShippingAddress = $order->get_meta('_wms_shipping_address');
        $newShippingAddress = $this->getWMSShippingAddress($order);
        
        if ($currentShippingAddress !== json_encode($newShippingAddress)) {
            $updateData['shipping_address'] = $newShippingAddress;
        }
        
        // Remove null values
        $updateData = array_filter($updateData, function($value) {
            return $value !== null;
        });
        
        return $updateData;
    }
    
    /**
     * Get order language from integrator context
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
     * Get WMS shipping address from integrator context
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
        
        return [
            'addressed_to' => trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()),
            'contact_person' => trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()),
            'street' => $streetName ?: $street,
            'street2' => $order->get_shipping_address_2(),
            'street_number' => $streetNumber,
            'street_number_addition' => $streetNumberAddition,
            'zipcode' => $order->get_shipping_postcode(),
            'city' => $order->get_shipping_city(),
            'country' => $order->get_shipping_country(),
            'state' => $order->get_shipping_state(),
            'phone_number' => $order->get_billing_phone(),
            'mobile_number' => $order->get_meta('_shipping_phone'),
            'fax_number' => null,
            'email_address' => $order->get_billing_email()
        ];
    }    
    /**
     * Get order meta data for WMS from integrator context
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
}