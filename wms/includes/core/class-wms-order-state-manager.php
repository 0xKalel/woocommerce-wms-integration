<?php
/**
 * WMS Order State Manager
 * 
 * Centralizes all order state management logic to eliminate scattered metadata handling
 * and provide a single source of truth for order processing states.
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Order State Manager Class
 * 
 * Handles all WMS order state operations in a centralized manner
 */
class WC_WMS_Order_State_Manager {
    
    /**
     * Single metadata key for all WMS order state
     */
    private const META_KEY = '_wms_order_state';
    
    /**
     * Order state constants
     */
    public const STATE_PENDING = 'pending';
    public const STATE_PROCESSING = 'processing';
    public const STATE_EXPORTED = 'exported';
    public const STATE_SYNCED_FROM_WMS = 'synced_from_wms';
    public const STATE_WEBHOOK_PROCESSED = 'webhook_processed';
    public const STATE_FAILED = 'failed';
    public const STATE_SKIPPED = 'skipped';
    
    /**
     * Processing sources
     */
    public const SOURCE_EXPORT = 'export';
    public const SOURCE_WEBHOOK = 'webhook';
    public const SOURCE_WMS_SYNC = 'wms_sync';
    public const SOURCE_MANUAL = 'manual';
    
    /**
     * Logger instance
     */
    private $logger;
    
    /**
     * Store suspended hooks for restoration
     */
    private $suspendedHooks = [];
    
    /**
     * Constructor
     */
    public function __construct(WC_WMS_Logger $logger) {
        $this->logger = $logger;
    }
    
    /**
     * Destructor - cleanup any suspended hooks
     */
    public function __destruct() {
        $this->cleanupSuspendedHooks();
    }
    
    /**
     * Get order state data
     */
    public function getOrderState(WC_Order $order): array {
        $stateData = $order->get_meta(self::META_KEY);
        
        if (empty($stateData)) {
            // Check if we need to migrate from legacy metadata
            return $this->migrateFromLegacyMetadata($order);
        }
        
        if (is_string($stateData)) {
            $stateData = json_decode($stateData, true);
        }
        
        return wp_parse_args($stateData, [
            'state' => self::STATE_PENDING,
            'wms_order_id' => null,
            'last_processed_at' => null,
            'processing_source' => null,
            'error_message' => null,
            'metadata' => []
        ]);
    }
    
    /**
     * Save order state data
     */
    public function saveOrderState(WC_Order $order, array $stateData): bool {
        $stateData['last_updated'] = current_time('mysql');
        
        $this->logger->debug('Saving order state', [
            'order_id' => $order->get_id(),
            'state' => $stateData['state'],
            'wms_order_id' => $stateData['wms_order_id'] ?? null
        ]);
        
        $order->update_meta_data(self::META_KEY, wp_json_encode($stateData));
        $order->save();
        
        return true;
    }
    
    /**
     * Check if order should skip WMS processing
     */
    public function shouldSkipWMSProcessing(WC_Order $order, string $context = ''): bool {
        $state = $this->getOrderState($order);
        
        // Skip states that shouldn't be processed
        $skipStates = [
            self::STATE_SYNCED_FROM_WMS,
            self::STATE_EXPORTED,
            self::STATE_SKIPPED
        ];
        
        if (in_array($state['state'], $skipStates)) {
            $this->logger->debug('Skipping WMS processing', [
                'order_id' => $order->get_id(),
                'context' => $context,
                'state' => $state['state'],
                'reason' => 'Order state indicates skip'
            ]);
            return true;
        }
        
        // Check if recently processed by webhook (cooldown period)
        if ($state['state'] === self::STATE_WEBHOOK_PROCESSED) {
            $lastProcessed = $state['last_processed_at'];
            if ($lastProcessed && $this->isRecentlyProcessed($lastProcessed)) {
                $this->logger->debug('Skipping WMS processing', [
                    'order_id' => $order->get_id(),
                    'context' => $context,
                    'state' => $state['state'],
                    'reason' => 'Recently processed by webhook'
                ]);
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Mark order as exported to WMS
     */
    public function markAsExported(WC_Order $order, string $wmsOrderId, string $source = self::SOURCE_EXPORT): void {
        $stateData = [
            'state' => self::STATE_EXPORTED,
            'wms_order_id' => $wmsOrderId,
            'last_processed_at' => current_time('mysql'),
            'processing_source' => $source,
            'error_message' => null
        ];
        
        $this->saveOrderState($order, $stateData);
        
        $this->logger->info('Order marked as exported', [
            'order_id' => $order->get_id(),
            'wms_order_id' => $wmsOrderId,
            'source' => $source
        ]);
    }
    
    /**
     * Mark order as synced from WMS (should skip processing)
     */
    public function markAsSyncedFromWMS(WC_Order $order, string $wmsOrderId): void {
        $stateData = [
            'state' => self::STATE_SYNCED_FROM_WMS,
            'wms_order_id' => $wmsOrderId,
            'last_processed_at' => current_time('mysql'),
            'processing_source' => self::SOURCE_WMS_SYNC,
            'error_message' => null
        ];
        
        $this->saveOrderState($order, $stateData);
        
        $this->logger->info('Order marked as synced from WMS', [
            'order_id' => $order->get_id(),
            'wms_order_id' => $wmsOrderId
        ]);
    }
    
    /**
     * Mark order as webhook processed (temporary skip)
     */
    public function markAsWebhookProcessed(WC_Order $order): void {
        $currentState = $this->getOrderState($order);
        
        $stateData = [
            'state' => self::STATE_WEBHOOK_PROCESSED,
            'wms_order_id' => $currentState['wms_order_id'],
            'last_processed_at' => current_time('mysql'),
            'processing_source' => self::SOURCE_WEBHOOK,
            'error_message' => null
        ];
        
        $this->saveOrderState($order, $stateData);
        
        $this->logger->debug('Order marked as webhook processed', [
            'order_id' => $order->get_id()
        ]);
    }
    
    /**
     * Mark order as failed
     */
    public function markAsFailed(WC_Order $order, string $errorMessage, string $source = self::SOURCE_EXPORT): void {
        $currentState = $this->getOrderState($order);
        
        $stateData = [
            'state' => self::STATE_FAILED,
            'wms_order_id' => $currentState['wms_order_id'],
            'last_processed_at' => current_time('mysql'),
            'processing_source' => $source,
            'error_message' => $errorMessage
        ];
        
        $this->saveOrderState($order, $stateData);
        
        $this->logger->error('Order marked as failed', [
            'order_id' => $order->get_id(),
            'error' => $errorMessage,
            'source' => $source
        ]);
    }
    
    /**
     * Reset order state to pending (for reprocessing)
     */
    public function resetToPending(WC_Order $order): void {
        $currentState = $this->getOrderState($order);
        
        $stateData = [
            'state' => self::STATE_PENDING,
            'wms_order_id' => $currentState['wms_order_id'],
            'last_processed_at' => null,
            'processing_source' => null,
            'error_message' => null
        ];
        
        $this->saveOrderState($order, $stateData);
        
        $this->logger->info('Order state reset to pending', [
            'order_id' => $order->get_id()
        ]);
    }
    
    /**
     * Get WMS order ID
     */
    public function getWmsOrderId(WC_Order $order): ?string {
        $state = $this->getOrderState($order);
        return $state['wms_order_id'];
    }
    
    /**
     * Check if order was recently processed
     */
    private function isRecentlyProcessed(string $lastProcessedAt, int $cooldownMinutes = 5): bool {
        $lastProcessed = strtotime($lastProcessedAt);
        $cooldownTime = time() - ($cooldownMinutes * 60);
        
        return $lastProcessed > $cooldownTime;
    }
    
    /**
     * Migrate from legacy metadata format
     */
    private function migrateFromLegacyMetadata(WC_Order $order): array {
        $state = self::STATE_PENDING;
        $wmsOrderId = $order->get_meta('_wms_order_id');
        $lastProcessedAt = null;
        $source = null;
        
        // Check legacy flags in priority order
        if ($order->get_meta('_wms_synced_from_wms') === 'yes') {
            $state = self::STATE_SYNCED_FROM_WMS;
            $source = self::SOURCE_WMS_SYNC;
            $lastProcessedAt = $order->get_meta('_wms_sync_date') ?: current_time('mysql');
        } elseif ($order->get_meta('_wms_skip_export_hooks') === 'yes') {
            $state = self::STATE_SKIPPED;
            $source = self::SOURCE_MANUAL;
        } elseif ($order->get_meta('_wms_webhook_processed') === 'yes') {
            $state = self::STATE_WEBHOOK_PROCESSED;
            $source = self::SOURCE_WEBHOOK;
            $processedAt = $order->get_meta('_wms_webhook_processed_at');
            if ($processedAt) {
                $lastProcessedAt = date('Y-m-d H:i:s', $processedAt);
            }
        } elseif ($wmsOrderId) {
            $state = self::STATE_EXPORTED;
            $source = self::SOURCE_EXPORT;
        }
        
        $stateData = [
            'state' => $state,
            'wms_order_id' => $wmsOrderId,
            'last_processed_at' => $lastProcessedAt,
            'processing_source' => $source,
            'error_message' => null,
            'metadata' => []
        ];
        
        // Save migrated state
        $this->saveOrderState($order, $stateData);
        
        // Clean up legacy metadata
        $this->cleanupLegacyMetadata($order);
        
        
        return $stateData;
    }
    
    /**
     * Clean up old metadata keys
     */
    private function cleanupLegacyMetadata(WC_Order $order): void {
        $legacyKeys = [
            '_wms_synced_from_wms',
            '_wms_skip_export_hooks',
            '_wms_webhook_processed',
            '_wms_webhook_processed_at',
            '_wms_sync_date',
            '_wms_sync_source'
        ];
        
        foreach ($legacyKeys as $key) {
            $order->delete_meta_data($key);
        }
        
        $order->save();
    }
    
    /**
     * Get order state summary for debugging
     */
    public function getOrderStateSummary(WC_Order $order): array {
        $state = $this->getOrderState($order);
        
        return [
            'order_id' => $order->get_id(),
            'order_status' => $order->get_status(),
            'wms_state' => $state['state'],
            'wms_order_id' => $state['wms_order_id'],
            'last_processed' => $state['last_processed_at'],
            'processing_source' => $state['processing_source'],
            'should_skip' => $this->shouldSkipWMSProcessing($order),
            'error_message' => $state['error_message']
        ];
    }
    
    /**
     * Suspend order hooks to prevent recursion during webhook processing
     */
    public function suspendOrderHooks(WC_Order $order): void {
        $orderId = $order->get_id();
        
        // Don't suspend if already suspended
        if (isset($this->suspendedHooks[$orderId])) {
            return;
        }
        
        $this->suspendedHooks[$orderId] = [];
        
        global $wp_filter;
        
        // COMPREHENSIVE hook suspension - includes product-related hooks that trigger order updates
        $hooksToSuspend = [
            // Order-specific hooks
            'woocommerce_order_status_changed',
            'woocommerce_new_order',
            'woocommerce_order_actions',
            'woocommerce_process_shop_order_meta',
            'woocommerce_order_object_updated_props',
            'woocommerce_update_order',
            'woocommerce_order_item_meta_saved',
            'woocommerce_calculate_totals',
            
            // Product hooks that can trigger order updates
            'woocommerce_update_product',
            'woocommerce_process_product_meta',
            'woocommerce_product_object_updated_props',
            'woocommerce_new_product',
            
            // WordPress core hooks
            'save_post',
            'wp_insert_post_data',
            'clean_post_cache',
            'updated_post_meta',
            'added_post_meta',
            
            // WooCommerce CRUD hooks
            'woocommerce_before_single_object_save',
            'woocommerce_after_single_object_save',
            
            // Third-party plugin hooks that commonly interfere
            'elementor/frontend/before_render',
            'wpseo_save_post',
        ];
        
        foreach ($hooksToSuspend as $hook) {
            if (isset($wp_filter[$hook])) {
                $this->suspendedHooks[$orderId][$hook] = $wp_filter[$hook];
                $wp_filter[$hook] = new WP_Hook();
            }
        }
        
        // Add global hook filter to catch any hooks we missed
        add_filter('pre_do_action', [$this, 'globalHookFilter'], 999, 2);
        
        $this->logger->debug('Order hooks suspended', [
            'order_id' => $orderId,
            'suspended_hooks' => array_keys($this->suspendedHooks[$orderId]),
            'total_suspended' => count($this->suspendedHooks[$orderId])
        ]);
    }
    
    /**
     * Restore previously suspended order hooks
     */
    public function restoreOrderHooks(WC_Order $order): void {
        $orderId = $order->get_id();
        
        // Nothing to restore if not suspended
        if (!isset($this->suspendedHooks[$orderId])) {
            return;
        }
        
        global $wp_filter;
        
        // Restore all suspended hooks
        foreach ($this->suspendedHooks[$orderId] as $hook => $hookObject) {
            if ($hookObject instanceof WP_Hook) {
                $wp_filter[$hook] = $hookObject;
            }
        }
        
        // Remove global hook filter
        remove_filter('pre_do_action', [$this, 'globalHookFilter'], 999);
        
        $this->logger->debug('Order hooks restored', [
            'order_id' => $orderId,
            'restored_hooks' => array_keys($this->suspendedHooks[$orderId]),
            'total_restored' => count($this->suspendedHooks[$orderId])
        ]);
        
        // Clean up suspended hooks storage
        unset($this->suspendedHooks[$orderId]);
    }
    
    /**
     * Global hook filter to catch any hooks that weren't explicitly suspended
     */
    public function globalHookFilter($result, $hook_name) {
        // Only filter during active sync operations
        if (!WC_WMS_Order_Sync_Manager::isSyncInProgress()) {
            return $result;
        }
        
        // Block WooCommerce and WordPress hooks that could interfere with sync
        $blockedPatterns = [
            'woocommerce_',
            'wc_',
            'save_post',
            'wp_insert_post',
            'updated_post_meta',
            'added_post_meta',
            'clean_post_cache',
            'transition_post_status'
        ];
        
        foreach ($blockedPatterns as $pattern) {
            if (strpos($hook_name, $pattern) !== false) {
                $this->logger->debug('Global hook filter blocked hook during sync', [
                    'hook' => $hook_name,
                    'pattern_matched' => $pattern
                ]);
                return false; // Block the hook execution
            }
        }
        
        return $result;
    }
    
    /**
     * Check if order hooks are currently suspended
     */
    public function areHooksSuspended(WC_Order $order): bool {
        return isset($this->suspendedHooks[$order->get_id()]);
    }
    
    /**
     * Clean up any suspended hooks (emergency cleanup)
     */
    public function cleanupSuspendedHooks(): void {
        if (!empty($this->suspendedHooks)) {
            $this->logger->warning('Cleaning up suspended hooks', [
                'suspended_orders' => array_keys($this->suspendedHooks)
            ]);
            
            global $wp_filter;
            
            foreach ($this->suspendedHooks as $orderId => $hooks) {
                foreach ($hooks as $hook => $hookObject) {
                    if ($hookObject instanceof WP_Hook) {
                        $wp_filter[$hook] = $hookObject;
                    }
                }
            }
            
            $this->suspendedHooks = [];
        }
    }
}
