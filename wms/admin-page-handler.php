<?php
/**
 * Admin page AJAX handler
 * Handles all form submissions and AJAX actions
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Admin_Page_Handler {
    
    /**
     * Process admin page actions
     */
    public static function process_actions() {
        $connection_message = '';
        $connection_class = '';
        
        if (isset($_POST['action'])) {
            check_admin_referer('wc_wms_admin_actions', 'nonce');
            
            $client = WC_WMS_Client::create();
            
            switch ($_POST['action']) {
                case 'test_connection':
                    $test_result = $client->testConnection();
                    if (!$test_result['success']) {
                        $connection_message = 'Error: ' . $test_result['message'];
                        $connection_class = 'notice-error';
                    } else {
                        $connection_message = 'Connection successful! ' . $test_result['message'];
                        $connection_class = 'notice-success';
                    }
                    break;
                    
                // Shipping method sync is handled via AJAX interface
                case 'sync_shipping_methods':
                    $connection_message = 'Please use the AJAX interface for shipping method sync.';
                    $connection_class = 'notice-info';
                    break;
            }
        }
        
        return [
            'message' => $connection_message,
            'class' => $connection_class
        ];
    }

}

add_action('wp_ajax_wc_wms_sync_stock', function() {
    check_admin_referer('wc_wms_admin_actions', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    try {
        $client = WC_WMS_Client::create();
        $result = $client->stockIntegrator()->syncAllStock();
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
            return;
        }
        
        wp_send_json_success([
            'message' => sprintf('Stock sync completed: %d products updated, %d errors', 
                $result['updated'] ?? 0, $result['errors'] ?? 0),
            'updated_count' => $result['updated'] ?? 0,
            'errors' => $result['error_details'] ?? [],
            'summary' => $result['summary'] ?? 'Stock sync completed',
            'health_score' => $result['health_score'] ?? null
        ]);
        
    } catch (Exception $e) {
        $client = WC_WMS_Client::create();
        $client->logger()->error('Stock sync AJAX failed', ['error' => $e->getMessage()]);
        wp_send_json_error('Stock sync failed: ' . $e->getMessage());
    }
});

// Import articles from WMS AJAX handler
add_action('wp_ajax_wc_wms_import_articles', function() {
    try {
        check_admin_referer('wc_wms_admin_actions', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }
        
        // Increase execution time for large imports
        set_time_limit(300); // 5 minutes
        ini_set('memory_limit', '512M');
        
        $client = WC_WMS_Client::create();
        $client->logger()->info('Starting article import from WMS via AJAX');
        
        // Import articles using product integrator
        $result = $client->productIntegrator()->importArticlesFromWMS(['limit' => 500]);
        
        if (is_wp_error($result)) {
            $client->logger()->error('Article import failed', [
                'error' => $result->get_error_message()
            ]);
            wp_send_json_error($result->get_error_message());
            return;
        }
        
        $client->logger()->info('Article import completed via AJAX', $result);
        
        wp_send_json_success([
            'message' => sprintf('Article import completed: %d imported, %d updated, %d skipped, %d errors', 
                $result['imported'] ?? 0, $result['updated'] ?? 0, $result['skipped'] ?? 0, $result['errors'] ?? 0),
            'imported' => $result['imported'] ?? 0,
            'updated' => $result['updated'] ?? 0,
            'skipped' => $result['skipped'] ?? 0,
            'errors' => $result['errors'] ?? 0,
            'error_details' => array_slice($result['error_details'] ?? [], 0, 10),
            'summary' => $result['summary'] ?? 'Article import completed',
            'health_score' => $result['health_score'] ?? null,
            'business_insights' => $result['business_insights'] ?? null
        ]);
        
    } catch (Exception $e) {
        $client = WC_WMS_Client::create();
        $client->logger()->error('Article import AJAX failed', ['error' => $e->getMessage()]);
        wp_send_json_error('Import failed: ' . $e->getMessage());
    } catch (Error $e) {
        wp_send_json_error('Fatal error: ' . $e->getMessage());
    }
});



// Retry failed orders AJAX handler
add_action('wp_ajax_wc_wms_retry_failed_orders', function() {
    check_admin_referer('wc_wms_admin_actions', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'wc_wms_integration_queue';
    
    // Reset failed orders to pending
    $updated = $wpdb->update(
        $table_name,
        ['status' => 'pending', 'attempts' => 0],
        ['status' => 'failed']
    );
    
    if ($updated === false) {
        wp_send_json_error('Database error occurred');
    }
    
    wp_send_json_success('Reset ' . $updated . ' failed orders for retry');
});


// Refresh setup progress AJAX handler
add_action('wp_ajax_wc_wms_refresh_setup_progress', function() {
    check_admin_referer('wc_wms_admin_actions', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
        return;
    }
    
    // Get current setup progress
    $connection_configured = !empty(get_option('wc_wms_integration_username')) && !empty(get_option('wc_wms_integration_password'));
    $shipping_methods_synced = !empty(get_option('wc_wms_shipping_methods', []));
    $products_exported = get_option('wc_wms_products_exported', false);
    $webhooks_registered = !empty(get_option('wc_wms_registered_webhooks', []));
    
    $progress_items = [
        'connection' => [
            'icon' => $connection_configured ? '✅' : '⏳',
            'label' => 'Connection configured',
            'completed' => $connection_configured
        ],
        'products' => [
            'icon' => $products_exported ? '✅' : '⏳',
            'label' => 'Articles imported from WMS',
            'completed' => $products_exported
        ],
        'shipping' => [
            'icon' => $shipping_methods_synced ? '✅' : '⏳',
            'label' => 'Shipping methods synced',
            'completed' => $shipping_methods_synced
        ],
        'webhooks' => [
            'icon' => $webhooks_registered ? '✅' : '⏳',
            'label' => 'Webhooks registered',
            'completed' => $webhooks_registered
        ]
    ];
    
    $export_stats = get_option('wc_wms_products_export_stats', null);
    
    wp_send_json_success([
        'progress_items' => $progress_items,
        'export_stats' => $export_stats,
        'overall_progress' => [
            'completed_steps' => array_sum(array_column($progress_items, 'completed')),
            'total_steps' => count($progress_items),
            'percentage' => round((array_sum(array_column($progress_items, 'completed')) / count($progress_items)) * 100)
        ]
    ]);
});



/**
 * Auto-map shipping methods with smart defaults
 */
function auto_map_shipping_methods() {
    try {
        $wms_methods = get_option('wc_wms_shipping_methods', []);
        
        if (empty($wms_methods)) {
            return [
                'success' => false,
                'message' => 'error: No WMS shipping methods found to map'
            ];
        }
        
        // Get the first WMS method as default
        $default_wms_method = $wms_methods[0]['id'] ?? null;
        
        if (!$default_wms_method) {
            return [
                'success' => false,
                'message' => 'error: No valid WMS method ID found'
            ];
        }
        
        // Get all WooCommerce shipping methods
        $wc_shipping_methods = WC_WMS_Admin_Page_Data::get_woocommerce_shipping_methods();
        
        if (empty($wc_shipping_methods)) {
            return [
                'success' => true,
                'message' => 'success - no WooCommerce shipping methods to map'
            ];
        }
        
        // Create mapping: map all WooCommerce methods to the default WMS method
        $mappings = [];
        foreach ($wc_shipping_methods as $method_key => $method) {
            $mappings[$method_key] = $default_wms_method;
        }
        
        // Save mappings
        update_option('wc_wms_shipping_method_uuid_mapping', $mappings);
        update_option('wc_wms_default_shipping_method_uuid', $default_wms_method);
        
        $default_method_name = $wms_methods[0]['name'] ?? 'Unknown';
        
        return [
            'success' => true,
            'message' => 'success - mapped ' . count($mappings) . ' WooCommerce methods to "' . $default_method_name . '"'
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'error: ' . $e->getMessage()
        ];
    }
}
add_action('wp_ajax_wc_wms_refresh_setup_cache', function() {
    check_admin_referer('wc_wms_admin_actions', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
        return;
    }
    
    // No API calls needed - just confirm refresh
    // Data is always fresh from WordPress options
    
    wp_send_json_success([
        'message' => 'Display refreshed successfully',
        'data_source' => 'wordpress_options',
        'api_calls_made' => 0,
        'refreshed_at' => current_time('mysql'),
        'note' => 'Data is loaded directly from WordPress options - no API calls needed'
    ]);
});


