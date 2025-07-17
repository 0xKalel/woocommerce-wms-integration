<?php
/**
 * WMS Product Sync Handler
 * 
 * Handles importing WMS articles into WooCommerce and syncing stock levels
 * WMS is the source of truth for products and stock
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Product_Sync {
    
    /**
     * WMS Client instance - REQUIRED dependency
     */
    private $wmsClient;
    
    /**
     * Constructor - STRICT dependency injection
     * 
     * @param WC_WMS_Client $wmsClient Required WMS client instance
     */
    public function __construct(WC_WMS_Client $wmsClient) {
        $this->wmsClient = $wmsClient;
        
        // Hook into WooCommerce product events
        $this->init_hooks();
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Sync products when they are created/updated
        add_action('woocommerce_new_product', [$this, 'queue_product_sync'], 10, 1);
        add_action('woocommerce_update_product', [$this, 'queue_product_sync'], 10, 1);
        
        // Sync variations when they are created/updated
        add_action('woocommerce_new_product_variation', [$this, 'queue_product_sync'], 10, 1);
        add_action('woocommerce_update_product_variation', [$this, 'queue_product_sync'], 10, 1);
        
        // Process sync queue via cron
        add_action('wc_wms_sync_products', [$this, 'process_sync_queue']);
        
        // Schedule product sync cron
        add_action('init', [$this, 'schedule_product_sync_cron']);
    }
    
    /**
     * Schedule product sync cron job
     */
    public function schedule_product_sync_cron() {
        if (!wp_next_scheduled('wc_wms_sync_products')) {
            wp_schedule_event(time(), 'hourly', 'wc_wms_sync_products');
        }
    }
    
    /**
     * Queue product for sync to WMS
     * 
     * @param int|WC_Product $productId Product ID or product object
     * @return void
     * @throws Exception When product is not found
     */
    public function queueProductSync($productId): void {
        if (!$productId) {
            throw new Exception('Product ID cannot be empty');
        }
        
        $product = is_object($productId) ? $productId : wc_get_product($productId);
        if (!$product) {
            throw new Exception("Product not found for sync: {$productId}");
        }
        
        // Get parent product ID for variations
        $parent_id = $product->get_parent_id();
        $sync_product_id = $parent_id ? $parent_id : $product_id;
        
        // Check if product should be synced
        if (!$this->should_sync_product($product)) {
            $this->wmsClient->logger()->info('Product skipped for sync', [
                'product_id' => $product_id,
                'reason' => 'Does not meet sync criteria'
            ]);
            return;
        }
        
        // Check if already queued
        if ($this->is_product_queued($sync_product_id)) {
            $this->wmsClient->logger()->debug('Product already queued for sync', ['product_id' => $sync_product_id]);
            return;
        }
        
        // Add to queue
        $this->add_to_sync_queue($sync_product_id);
        
        $this->wmsClient->logger()->info('Product queued for sync', ['product_id' => $sync_product_id]);
    }
    
    /**
     * Import articles from WMS and create/update WooCommerce products
     * 
     * Uses centralized Product Sync Manager for consistent import handling
     */
    public function import_articles_from_wms($params = []) {
        $this->wmsClient->logger()->info('Starting article import from WMS via centralized sync manager', $params);
        
        try {
            // USE CENTRALIZED PRODUCT SYNC MANAGER
            return $this->wmsClient->productSyncManager()->importArticlesFromWms($params);
        } catch (Exception $e) {
            $this->wmsClient->logger()->error('Failed to import articles from WMS', [
                'error' => $e->getMessage(),
                'params' => $params
            ]);
            return new WP_Error('wms_error', $e->getMessage());
        }
    }
    
    /**
     * Sync stock levels from WMS to WooCommerce
     */
    public function sync_stock_from_wms($product_ids = []) {
        $this->wmsClient->logger()->info('Starting stock sync from WMS', [
            'product_ids' => $product_ids
        ]);
        
        try {
            // Use Stock Integrator's bulk sync method for multiple products
            return $this->wmsClient->stockIntegrator()->syncAllStock();
        } catch (Exception $e) {
            $this->wmsClient->logger()->error('Failed to sync stock from WMS', [
                'error' => $e->getMessage(),
                'product_ids' => $product_ids
            ]);
            return new WP_Error('wms_error', $e->getMessage());
        }
    }
    
    /**
     * Sync individual product to WMS
     */
    public function sync_product($product_id) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return new WP_Error('product_not_found', 'Product not found');
        }
        
        $this->wmsClient->logger()->info('Starting product sync', ['product_id' => $product_id]);
        
        try {
            return $this->wmsClient->productIntegrator()->syncProductToWMS($product_id);
        } catch (Exception $e) {
            $this->wmsClient->logger()->error('Failed to sync product', [
                'product_id' => $product_id,
                'error' => $e->getMessage()
            ]);
            return new WP_Error('wms_error', $e->getMessage());
        }
    }
    
    /**
     * Sync all products
     */
    public function sync_all_products() {
        $this->wmsClient->logger()->info('Starting bulk product sync (all products)');
        
        try {
            // Get all published products
            $products = wc_get_products([
                'status' => 'publish',
                'limit' => -1,
                'return' => 'ids'
            ]);
            
            $synced = 0;
            $skipped = 0;
            $errors = 0;
            
            foreach ($products as $product_id) {
                $product = wc_get_product($product_id);
                
                if (!$product) {
                    $errors++;
                    continue;
                }
                
                // Check if product should be synced
                if (!$this->should_sync_product($product)) {
                    $skipped++;
                    continue;
                }
                
                // Check if already queued
                if ($this->is_product_queued($product_id)) {
                    $skipped++;
                    continue;
                }
                
                // Add to queue
                $this->add_to_sync_queue($product_id);
                $synced++;
            }
            
            $this->wmsClient->logger()->info('Bulk product sync completed', [
                'synced' => $synced,
                'skipped' => $skipped,
                'errors' => $errors
            ]);
            
            return [
                'synced' => $synced,
                'skipped' => $skipped,
                'errors' => $errors
            ];
            
        } catch (Exception $e) {
            $this->wmsClient->logger()->error('Bulk product sync failed', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'synced' => 0,
                'skipped' => 0,
                'errors' => 1,
                'error_message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Process sync queue
     */
    public function process_sync_queue() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wc_wms_product_sync_queue';
        
        // Create table if it doesn't exist
        $this->create_sync_queue_table();
        
        // Get pending items from queue
        $queue_items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name 
             WHERE status = 'pending' 
             AND (next_attempt IS NULL OR next_attempt <= %s)
             ORDER BY created_at ASC 
             LIMIT 5",
            current_time('mysql')
        ));
        
        foreach ($queue_items as $item) {
            $this->process_sync_queue_item($item);
        }
    }
    
    /**
     * Process single sync queue item
     */
    private function process_sync_queue_item($item) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wc_wms_product_sync_queue';
        
        // Update attempts
        $wpdb->update(
            $table_name,
            [
                'attempts' => $item->attempts + 1,
                'last_attempt' => current_time('mysql'),
                'status' => 'processing'
            ],
            ['id' => $item->id],
            ['%d', '%s', '%s'],
            ['%d']
        );
        
        $result = $this->sync_product($item->product_id);
        
        // Update queue item based on result
        if (is_wp_error($result)) {
            $this->handle_sync_queue_failure($item, $result->get_error_message());
        } else {
            $this->handle_sync_queue_success($item);
        }
    }
    
    /**
     * Handle sync queue item success
     */
    private function handle_sync_queue_success($item) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wc_wms_product_sync_queue';
        
        $wpdb->update(
            $table_name,
            [
                'status' => 'completed',
                'updated_at' => current_time('mysql')
            ],
            ['id' => $item->id],
            ['%s', '%s'],
            ['%d']
        );
        
        $this->wmsClient->logger()->debug('Product sync queue item completed', [
            'item_id' => $item->id,
            'product_id' => $item->product_id
        ]);
    }
    
    /**
     * Handle sync queue item failure
     */
    private function handle_sync_queue_failure($item, $error_message) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wc_wms_product_sync_queue';
        $max_attempts = 3;
        
        if ($item->attempts >= $max_attempts) {
            // Mark as failed
            $wpdb->update(
                $table_name,
                [
                    'status' => 'failed',
                    'error_message' => $error_message,
                    'updated_at' => current_time('mysql')
                ],
                ['id' => $item->id],
                ['%s', '%s', '%s'],
                ['%d']
            );
            
            $this->wmsClient->logger()->error('Product sync queue item failed permanently', [
                'item_id' => $item->id,
                'product_id' => $item->product_id,
                'attempts' => $item->attempts,
                'error' => $error_message
            ]);
        } else {
            // Schedule retry with exponential backoff
            $retry_delay = pow(2, $item->attempts) * 60; // Minutes
            $next_attempt = date('Y-m-d H:i:s', time() + $retry_delay);
            
            $wpdb->update(
                $table_name,
                [
                    'status' => 'pending',
                    'error_message' => $error_message,
                    'next_attempt' => $next_attempt,
                    'updated_at' => current_time('mysql')
                ],
                ['id' => $item->id],
                ['%s', '%s', '%s', '%s'],
                ['%d']
            );
            
            $this->wmsClient->logger()->warning('Product sync queue item scheduled for retry', [
                'item_id' => $item->id,
                'product_id' => $item->product_id,
                'attempts' => $item->attempts,
                'next_attempt' => $next_attempt,
                'error' => $error_message
            ]);
        }
    }
    
    /**
     * Add product to sync queue
     */
    private function add_to_sync_queue($product_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wc_wms_product_sync_queue';
        
        $wpdb->insert(
            $table_name,
            [
                'product_id' => $product_id,
                'status' => 'pending',
                'attempts' => 0,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ],
            ['%d', '%s', '%d', '%s', '%s']
        );
    }
    
    /**
     * Check if product should be synced
     */
    private function should_sync_product($product) {
        // Only skip truly unsyncable products
        $skip_types = apply_filters('wc_wms_skip_product_types', ['grouped', 'external']);
        if (in_array($product->get_type(), $skip_types)) {
            return false;
        }
        
        // Skip virtual/downloadable only if explicitly configured
        if (apply_filters('wc_wms_skip_virtual_products', false)) {
            if ($product->is_virtual() || $product->is_downloadable()) {
                return false;
            }
        }
        
        // Skip draft products only if explicitly configured
        if (apply_filters('wc_wms_skip_draft_products', true)) {
            if ($product->get_status() === 'draft') {
                return false;
            }
        }
        
        return apply_filters('wc_wms_should_sync_product', true, $product);
    }
    
    /**
     * Check if product is already queued
     */
    private function is_product_queued($product_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wc_wms_product_sync_queue';
        
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE product_id = %d AND status IN ('pending', 'processing')",
            $product_id
        ));
        
        return $count > 0;
    }
    
    /**
     * Create sync queue table
     */
    private function create_sync_queue_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wc_wms_product_sync_queue';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            product_id bigint(20) NOT NULL,
            status varchar(20) DEFAULT 'pending',
            attempts int(11) DEFAULT 0,
            last_attempt datetime DEFAULT NULL,
            next_attempt datetime DEFAULT NULL,
            error_message text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY status (status),
            KEY next_attempt (next_attempt)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Get sync statistics
     */
    public function get_sync_statistics() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wc_wms_product_sync_queue';
        
        $stats = [
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0
        ];
        
        $results = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM $table_name GROUP BY status"
        );
        
        foreach ($results as $row) {
            $stats[$row->status] = intval($row->count);
        }
        
        return $stats;
    }
}
