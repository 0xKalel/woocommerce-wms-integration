<?php
/**
 * WMS Order Hooks Handler
 * 
 * Handles all WooCommerce order events and triggers WMS integration
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Order_Hooks {
    
    /**
     * Singleton instance
     */
    private static $instance = null;
    
    /**
     * Initialization flag
     */
    private static $hooks_registered = false;
    
    /**
     * WMS Client instance
     */
    private $client;
    
    /**
     * Order sync manager instance
     */
    private $orderSyncManager;
    
    /**
     * Queue service instance
     */
    private $queueService;
    
    /**
     * Logger instance
     */
    private $logger;
    
    /**
     * Order state manager instance
     */
    private $orderStateManager;
    
    /**
     * Get singleton instance
     */
    public static function getInstance(WC_WMS_Client $client) {
        if (self::$instance === null) {
            self::$instance = new self($client);
        }
        return self::$instance;
    }
    
    /**
     * Private constructor (singleton pattern)
     */
    private function __construct(WC_WMS_Client $client) {
        $this->client = $client;
        $this->orderSyncManager = new WC_WMS_Order_Sync_Manager($client);
        $this->queueService = new WC_WMS_Queue_Service($client);
        $this->logger = $client->logger();
        $this->orderStateManager = $client->orderStateManager();
        
        // Only register hooks once across all instances
        if (!self::$hooks_registered) {
            $this->registerHooks();
            self::$hooks_registered = true;
        }
    }
    
    /**
     * Check if order should skip WMS processing (centralized logic)
     */
    private function shouldSkipWMSProcessing(WC_Order $order, string $context = ''): bool {
        return $this->orderStateManager->shouldSkipWMSProcessing($order, $context);
    }
    
    /**
     * Register all WooCommerce hooks
     */
    private function registerHooks() {
        // Order creation and updates
        add_action('woocommerce_new_order', [$this, 'handleNewOrder'], 10, 2);
        add_action('woocommerce_update_order', [$this, 'handleOrderUpdate'], 10, 1);
        
        // Order status changes
        add_action('woocommerce_order_status_changed', [$this, 'handleOrderStatusChange'], 10, 4);
        
        // Specific status transitions
        add_action('woocommerce_order_status_pending_to_processing', [$this, 'handleOrderToProcessing'], 10, 2);
        add_action('woocommerce_order_status_pending_to_on-hold', [$this, 'handleOrderToOnHold'], 10, 2);
        add_action('woocommerce_order_status_on-hold_to_processing', [$this, 'handleOrderToProcessing'], 10, 2);
        
        // Order cancellations
        add_action('woocommerce_order_status_cancelled', [$this, 'handleOrderCancellation'], 10, 2);
        add_action('woocommerce_order_status_processing_to_cancelled', [$this, 'handleOrderCancellation'], 10, 2);
        add_action('woocommerce_order_status_on-hold_to_cancelled', [$this, 'handleOrderCancellation'], 10, 2);
        
        // Order refunds
        add_action('woocommerce_order_status_refunded', [$this, 'handleOrderRefund'], 10, 2);
        
        // Order deletions
        add_action('before_delete_post', [$this, 'handleOrderDeletion'], 10, 1);
        
        // Order item changes
        add_action('woocommerce_before_save_order_items', [$this, 'handleOrderItemsChange'], 10, 1);
        
        // Payment completion
        add_action('woocommerce_payment_complete', [$this, 'handlePaymentComplete'], 10, 1);
        
        // Queue processing
        add_action('wc_wms_process_order_queue', [$this, 'processOrderQueue']);
        
        // Admin manual actions
        add_action('wp_ajax_wc_wms_export_order', [$this, 'handleManualExport']);
        add_action('wp_ajax_wc_wms_cancel_order', [$this, 'handleManualCancel']);
        add_action('wp_ajax_wc_wms_sync_order_status', [$this, 'handleManualSync']);
        
        // Hooks registered successfully - no logging needed
        // (WordPress handles duplicate registrations gracefully)
    }
    
    /**
     * Handle new order creation
     */
    public function handleNewOrder($order_id, $order = null) {
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order_id);
        }
        
        if (!$order) {
            $this->logger->error('Invalid order in handleNewOrder', ['order_id' => $order_id]);
            return;
        }
        
        // Check if initial sync is completed before processing orders
        if (!get_option('wc_wms_initial_sync_completed', false)) {
            $this->logger->info('Order processing skipped - initial sync not completed', [
                'order_id' => $order_id,
                'order_number' => $order->get_order_number(),
                'message' => 'Order processing disabled until initial sync is completed'
            ]);
            return;
        }
        
        // Use centralized skip logic
        if ($this->shouldSkipWMSProcessing($order, 'new_order')) {
            return;
        }
        
        // SKIP IF WMS ORDER SYNC IS IN PROGRESS (prevent circular sync)
        if (WC_WMS_Order_Sync_Manager::isSyncInProgress()) {
            $this->logger->debug('Skipping order processing - WMS order sync in progress', [
                'order_id' => $order_id,
                'reason' => 'WMS order sync in progress - preventing circular sync'
            ]);
            return;
        }
        
        $this->logger->info('New order created', [
            'order_id' => $order_id,
            'order_number' => $order->get_order_number(),
            'order_status' => $order->get_status(),
            'total' => $order->get_total(),
            'customer_id' => $order->get_customer_id()
        ]);
        
        // Check if order should be exported immediately
        if ($this->shouldExportImmediately($order)) {
            $queued = $this->queueService->queueOrderExport($order_id, 5); // High priority
            
            if ($queued) {
                $this->logger->info('New order queued for immediate WMS export', [
                    'order_id' => $order_id,
                    'reason' => 'New order with exportable status'
                ]);
                
                // Add order note
                $order->add_order_note(__('Order queued for WMS export', 'wc-wms-integration'));
            }
        } else {
            $this->logger->debug('New order not queued for export', [
                'order_id' => $order_id,
                'status' => $order->get_status(),
                'reason' => 'Does not meet immediate export criteria'
            ]);
        }
    }
    
    /**
     * Handle order updates
     */
    public function handleOrderUpdate($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        // Check if initial sync is completed before processing order updates
        if (!get_option('wc_wms_initial_sync_completed', false)) {
            $this->logger->info('Order update processing skipped - initial sync not completed', [
                'order_id' => $order_id,
                'message' => 'Order update processing disabled until initial sync is completed'
            ]);
            return;
        }
        
        // Use centralized skip logic
        if ($this->shouldSkipWMSProcessing($order, 'order_update')) {
            return;
        }
        
        // SKIP IF WMS ORDER SYNC IS IN PROGRESS (prevent circular sync)
        if (WC_WMS_Order_Sync_Manager::isSyncInProgress()) {
            $this->logger->debug('Skipping order update processing - WMS order sync in progress', [
                'order_id' => $order_id,
                'reason' => 'WMS order sync in progress - preventing circular sync'
            ]);
            return;
        }
        
        // Check if order is already in WMS
        $wmsOrderId = $order->get_meta('_wms_order_id');
        
        if ($wmsOrderId) {
            $this->logger->debug('Order updated, checking for WMS sync', [
                'order_id' => $order_id,
                'wms_order_id' => $wmsOrderId
            ]);
            
            // Queue for update if significant changes detected
            if ($this->hasSignificantChanges($order)) {
                $this->queueService->queueOrderExport($order_id, 2); // Lower priority for updates
                
                $this->logger->info('Order queued for WMS update', [
                    'order_id' => $order_id,
                    'wms_order_id' => $wmsOrderId
                ]);
            }
        }
    }
    
    /**
     * Handle order status changes
     */
    public function handleOrderStatusChange($order_id, $old_status, $new_status, $order) {
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order_id);
        }
        
        if (!$order) {
            return;
        }
        
        // Check if initial sync is completed before processing order status changes
        if (!get_option('wc_wms_initial_sync_completed', false)) {
            $this->logger->info('Order status change processing skipped - initial sync not completed', [
                'order_id' => $order_id,
                'old_status' => $old_status,
                'new_status' => $new_status,
                'message' => 'Order status change processing disabled until initial sync is completed'
            ]);
            return;
        }
        
        // Use centralized skip logic
        if ($this->shouldSkipWMSProcessing($order, 'status_change')) {
            return;
        }
        
        // SKIP IF WMS ORDER SYNC IS IN PROGRESS (prevent circular sync)
        if (WC_WMS_Order_Sync_Manager::isSyncInProgress()) {
            $this->logger->debug('Skipping order status change processing - WMS order sync in progress', [
                'order_id' => $order_id,
                'old_status' => $old_status,
                'new_status' => $new_status,
                'reason' => 'WMS order sync in progress - preventing circular sync'
            ]);
            return;
        }
        
        $this->logger->info('Order status changed', [
            'order_id' => $order_id,
            'old_status' => $old_status,
            'new_status' => $new_status,
            'order_number' => $order->get_order_number()
        ]);
        
        // Skip queueing if specific status transition hooks will handle it
        $specific_transitions = [
            'pending_to_processing',
            'pending_to_on-hold', 
            'on-hold_to_processing'
        ];
        
        $transition_key = $old_status . '_to_' . $new_status;
        if (in_array($transition_key, $specific_transitions)) {
            $this->logger->debug('Skipping general status change queueing - specific handler will process', [
                'order_id' => $order_id,
                'transition' => $transition_key
            ]);
            return;
        }
        
        // Handle transition to exportable status (but not covered by specific handlers)
        if ($this->isExportableStatus($new_status) && !$this->isExportableStatus($old_status)) {
            $queued = $this->queueService->queueOrderExport($order_id, 3); // Medium priority
            
            if ($queued) {
                $order->add_order_note(sprintf(
                    __('Order queued for WMS export (status: %s)', 'wc-wms-integration'),
                    $new_status
                ));
            } else {
                $this->logger->debug('Order not queued - likely already queued or not eligible', [
                    'order_id' => $order_id,
                    'new_status' => $new_status
                ]);
            }
        }
        
        // Handle transition to cancellation
        if ($new_status === 'cancelled' && $this->isExportableStatus($old_status)) {
            $this->handleOrderCancellation($order_id, $order);
        }
        
        // Handle transition to refunded
        if ($new_status === 'refunded') {
            $this->handleOrderRefund($order_id, $order);
        }
        
        // Update WMS if order already exported
        $wmsOrderId = $order->get_meta('_wms_order_id');
        if ($wmsOrderId) {
            // Store the status change for potential WMS sync
            $order->update_meta_data('_wms_status_sync_needed', true);
            $order->update_meta_data('_wms_last_status_change', current_time('mysql'));
            $order->save();
        }
    }
    
    /**
     * Handle order transition to processing
     */
    public function handleOrderToProcessing($order_id, $order) {
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order_id);
        }
        
        if (!$order) {
            return;
        }
        
        // Use centralized skip logic
        if ($this->shouldSkipWMSProcessing($order, 'processing_status')) {
            return;
        }
        
        // Prevent duplicate processing within the same request using static variable
        static $processed_orders = [];
        $current_hook = current_action();
        $call_key = $order_id . '_' . $current_hook;
        
        if (isset($processed_orders[$call_key])) {
            $this->logger->debug('Skipping duplicate processing handler call', [
                'order_id' => $order_id,
                'hook' => $current_hook,
                'already_processed_at' => $processed_orders[$call_key]
            ]);
            return;
        }
        $processed_orders[$call_key] = current_time('mysql');
        
        $this->logger->info('Order moved to processing status', [
            'order_id' => $order_id,
            'order_number' => $order->get_order_number()
        ]);
        
        // High priority export for processing orders
        $queued = $this->queueService->queueOrderExport($order_id, 5);
        
        if ($queued) {
            $order->add_order_note(__('Order prioritized for WMS export (processing status)', 'wc-wms-integration'));
        } else {
            $this->logger->warning('Failed to queue order for export', [
                'order_id' => $order_id,
                'reason' => 'Queue service returned false (not eligible or already queued)'
            ]);
        }
    }
    
    /**
     * Handle order transition to on-hold
     */
    public function handleOrderToOnHold($order_id, $order) {
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order_id);
        }
        
        if (!$order) {
            return;
        }
        
        // Use centralized skip logic
        if ($this->shouldSkipWMSProcessing($order, 'on_hold_status')) {
            return;
        }
        
        $this->logger->info('Order moved to on-hold status', [
            'order_id' => $order_id,
            'order_number' => $order->get_order_number()
        ]);
        
        // Medium priority for on-hold orders
        $queued = $this->queueService->queueOrderExport($order_id, 3);
        
        if ($queued) {
            $order->add_order_note(__('Order queued for WMS export (on-hold status)', 'wc-wms-integration'));
        } else {
            $this->logger->debug('Order not queued - likely already queued or not eligible', [
                'order_id' => $order_id,
                'reason' => 'Queue service returned false'
            ]);
        }
    }
    
    /**
     * Handle order cancellation
     */
    public function handleOrderCancellation($order_id, $order = null) {
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order_id);
        }
        
        if (!$order) {
            return;
        }
        
        $wmsOrderId = $order->get_meta('_wms_order_id');
        
        $this->logger->info('Order cancelled', [
            'order_id' => $order_id,
            'order_number' => $order->get_order_number(),
            'wms_order_id' => $wmsOrderId ?: 'not_exported'
        ]);
        
        if ($wmsOrderId) {
            // Queue for cancellation in WMS
            $queued = $this->queueService->queueOrderCancellation($order_id, 5); // High priority
            
            if ($queued) {
                $this->logger->info('Order queued for WMS cancellation', [
                    'order_id' => $order_id,
                    'wms_order_id' => $wmsOrderId
                ]);
                
                $order->add_order_note(__('Order queued for WMS cancellation', 'wc-wms-integration'));
            }
        } else {
            $this->logger->debug('Cancelled order was not exported to WMS', [
                'order_id' => $order_id
            ]);
        }
    }
    
    /**
     * Handle order refund
     */
    public function handleOrderRefund($order_id, $order = null) {
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order_id);
        }
        
        if (!$order) {
            return;
        }
        
        $wmsOrderId = $order->get_meta('_wms_order_id');
        
        $this->logger->info('Order refunded', [
            'order_id' => $order_id,
            'order_number' => $order->get_order_number(),
            'wms_order_id' => $wmsOrderId ?: 'not_exported'
        ]);
        
        if ($wmsOrderId) {
            // For refunds, we typically cancel in WMS unless it's a partial refund
            $totalRefunded = $order->get_total_refunded();
            $orderTotal = $order->get_total();
            
            if ($totalRefunded >= $orderTotal) {
                // Full refund - cancel in WMS
                $this->queueService->queueOrderCancellation($order_id, 4);
                $order->add_order_note(__('Order queued for WMS cancellation (full refund)', 'wc-wms-integration'));
            } else {
                // Partial refund - just add a note for now
                $order->add_order_note(sprintf(
                    __('Partial refund processed (WMS order may need manual adjustment): %s', 'wc-wms-integration'),
                    wc_price($totalRefunded)
                ));
            }
        }
    }
    
    /**
     * Handle order deletion
     */
    public function handleOrderDeletion($post_id) {
        if (get_post_type($post_id) !== 'shop_order') {
            return;
        }
        
        $order = wc_get_order($post_id);
        if (!$order) {
            return;
        }
        
        $wmsOrderId = $order->get_meta('_wms_order_id');
        
        $this->logger->warning('Order being deleted', [
            'order_id' => $post_id,
            'order_number' => $order->get_order_number(),
            'wms_order_id' => $wmsOrderId ?: 'not_exported'
        ]);
        
        if ($wmsOrderId) {
            // Try to cancel in WMS before deletion
            $this->queueService->queueOrderCancellation($post_id, 10); // Highest priority
        }
        
        // Clean up queue items for this order
        global $wpdb;
        $queue_table = $wpdb->prefix . 'wc_wms_integration_queue';
        $wpdb->delete($queue_table, ['order_id' => $post_id], ['%d']);
    }
    
    /**
     * Handle order items changes
     */
    public function handleOrderItemsChange($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        $wmsOrderId = $order->get_meta('_wms_order_id');
        
        if ($wmsOrderId) {
            $this->logger->info('Order items changed for exported order', [
                'order_id' => $order_id,
                'wms_order_id' => $wmsOrderId
            ]);
            
            // Mark for update
            $order->update_meta_data('_wms_items_sync_needed', true);
            $order->update_meta_data('_wms_last_items_change', current_time('mysql'));
        }
    }
    
    /**
     * Handle payment completion
     */
    public function handlePaymentComplete($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        // Use centralized skip logic
        if ($this->shouldSkipWMSProcessing($order, 'payment_complete')) {
            return;
        }
        
        $this->logger->info('Payment completed for order', [
            'order_id' => $order_id,
            'order_number' => $order->get_order_number(),
            'payment_method' => $order->get_payment_method()
        ]);
        
        // High priority export after payment completion
        if ($this->orderSyncManager->shouldExportOrder($order)) {
            $queued = $this->queueService->queueOrderExport($order_id, 5);
            
            if ($queued) {
                $order->add_order_note(__('Order prioritized for WMS export (payment complete)', 'wc-wms-integration'));
            }
        }
    }
    
    /**
     * Process order queue (cron handler)
     */
    public function processOrderQueue() {
        $this->logger->info('Processing order queue (cron triggered)');
        
        try {
            $results = $this->queueService->processOrderQueue(10);
            
            $this->logger->info('Order queue processing completed', $results);
            
            // Update last processing time
            update_option('wc_wms_last_queue_process', current_time('mysql'));
            
        } catch (Exception $e) {
            $this->logger->error('Order queue processing failed', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Handle manual order export from admin
     */
    public function handleManualExport() {
        check_ajax_referer('wc_wms_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Insufficient permissions', 'wc-wms-integration'));
        }
        
        $order_id = intval($_POST['order_id'] ?? 0);
        
        if (!$order_id) {
            wp_send_json_error(['message' => 'Invalid order ID']);
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(['message' => 'Order not found']);
            return;
        }
        
        $this->logger->info('Manual order export requested', [
            'order_id' => $order_id,
            'user_id' => get_current_user_id()
        ]);
        
        try {
            $result = $this->orderSyncManager->processOrderExport($order_id);
            
            if (!$result['success']) {
                wp_send_json_error(['message' => $result['error']]);
                return;
            }
            
            wp_send_json_success([
                'message' => __('Order exported to WMS successfully', 'wc-wms-integration'),
                'wms_order_id' => $result['id'] ?? null
            ]);
            
        } catch (Exception $e) {
            $this->logger->error('Manual order export failed', [
                'order_id' => $order_id,
                'error' => $e->getMessage()
            ]);
            
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
    
    /**
     * Handle manual order cancellation from admin
     */
    public function handleManualCancel() {
        check_ajax_referer('wc_wms_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Insufficient permissions', 'wc-wms-integration'));
        }
        
        $order_id = intval($_POST['order_id'] ?? 0);
        
        if (!$order_id) {
            wp_send_json_error(['message' => 'Invalid order ID']);
            return;
        }
        
        $this->logger->info('Manual order cancellation requested', [
            'order_id' => $order_id,
            'user_id' => get_current_user_id()
        ]);
        
        try {
            $result = $this->orderSyncManager->processOrderCancellation($order_id);
            
            if (!$result['success']) {
                wp_send_json_error(['message' => $result['error']]);
                return;
            }
            
            wp_send_json_success([
                'message' => __('Order cancelled in WMS successfully', 'wc-wms-integration')
            ]);
            
        } catch (Exception $e) {
            $this->logger->error('Manual order cancellation failed', [
                'order_id' => $order_id,
                'error' => $e->getMessage()
            ]);
            
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
    
    /**
     * Handle manual order status sync from admin
     */
    public function handleManualSync() {
        check_ajax_referer('wc_wms_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Insufficient permissions', 'wc-wms-integration'));
        }
        
        $order_id = intval($_POST['order_id'] ?? 0);
        
        if (!$order_id) {
            wp_send_json_error(['message' => 'Invalid order ID']);
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(['message' => 'Order not found']);
            return;
        }
        
        $this->logger->info('Manual order sync requested', [
            'order_id' => $order_id,
            'user_id' => get_current_user_id()
        ]);
        
        try {
            $result = $this->orderSyncManager->processManualOrderSync([$order_id]);
            
            if ($result['successful'] > 0) {
                wp_send_json_success([
                    'message' => __('Order status synced from WMS successfully', 'wc-wms-integration')
                ]);
            } else {
                wp_send_json_error(['message' => 'Sync failed or no changes detected']);
            }
            
        } catch (Exception $e) {
            $this->logger->error('Manual order sync failed', [
                'order_id' => $order_id,
                'error' => $e->getMessage()
            ]);
            
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
    
    /**
     * Check if order should be exported immediately
     */
    private function shouldExportImmediately(WC_Order $order): bool {
        // Check if order has exportable status
        if (!$this->isExportableStatus($order->get_status())) {
            return false;
        }
        
        // Use the centralized manager's logic
        return $this->orderSyncManager->shouldExportOrder($order);
    }
    
    /**
     * Check if status is exportable
     */
    private function isExportableStatus(string $status): bool {
        $exportableStatuses = apply_filters('wc_wms_exportable_statuses', [
            'processing', 'on-hold'
        ]);
        
        return in_array($status, $exportableStatuses);
    }
    
    /**
     * Check if order has significant changes requiring WMS update
     */
    private function hasSignificantChanges(WC_Order $order): bool {
        // Check if items sync is needed
        if ($order->get_meta('_wms_items_sync_needed')) {
            return true;
        }
        
        // Check if status sync is needed
        if ($order->get_meta('_wms_status_sync_needed')) {
            return true;
        }
        
        // Check if shipping address changed
        $lastShippingHash = $order->get_meta('_wms_shipping_hash');
        $currentShippingHash = md5(serialize($order->get_address('shipping')));
        
        if ($lastShippingHash !== $currentShippingHash) {
            $order->update_meta_data('_wms_shipping_hash', $currentShippingHash);
            return true;
        }
        
        return false;
    }
    
    /**
     * Get hooks status for debugging
     */
    public function getHooksStatus(): array {
        return [
            'singleton_instance' => self::$instance !== null,
            'hooks_registered' => self::$hooks_registered,
            'registered_hooks' => [
                'woocommerce_new_order' => has_action('woocommerce_new_order', [$this, 'handleNewOrder']),
                'woocommerce_order_status_changed' => has_action('woocommerce_order_status_changed', [$this, 'handleOrderStatusChange']),
                'woocommerce_update_order' => has_action('woocommerce_update_order', [$this, 'handleOrderUpdate']),
                'woocommerce_order_status_cancelled' => has_action('woocommerce_order_status_cancelled', [$this, 'handleOrderCancellation']),
                'wc_wms_process_order_queue' => has_action('wc_wms_process_order_queue', [$this, 'processOrderQueue']),
            ],
            'queue_status' => $this->queueService->getQueueStats(),
            'last_queue_process' => get_option('wc_wms_last_queue_process', 'Never'),
            'integration_ready' => $this->client->isReady()
        ];
    }
    
    
    /**
     * Check if hooks are already registered
     */
    public static function areHooksRegistered(): bool {
        return self::$hooks_registered;
    }
}