<?php
/**
 * Admin page data preparation
 * Gathers all data needed for the admin page
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Admin_Page_Data {
    
    /**
     * Gather all data needed for admin page
     */
    public static function get_page_data() {
        return [
            'connection_status' => self::get_stored_connection_status(),
            'setup_data' => self::get_stored_setup_data(),
            'shipping_methods_data' => self::get_stored_shipping_data(),
            'woocommerce_shipping_methods' => self::get_woocommerce_shipping_methods(),
            'webhook_data' => self::get_stored_webhook_data(),
            'local_stats' => self::get_local_stats(),
            'sync_status' => self::get_sync_status(),
            'recent_logs' => self::get_recent_logs(),
            'page_info' => [
                'generated_at' => current_time('mysql'),
                'data_source' => 'wordpress_options',
                'api_calls_made' => 0
            ]
        ];
    }
    
    /**
     * Get stored connection status
     */
    private static function get_stored_connection_status() {
        $username = get_option('wc_wms_integration_username', '');
        $password = get_option('wc_wms_integration_password', '');
        $last_test = get_option('wc_wms_last_connection_test', 0);
        $stored_status = get_option('wc_wms_connection_status', 'unknown');
        
        return [
            'credentials_configured' => !empty($username) && !empty($password),
            'last_test_status' => $stored_status,
            'last_test_time' => $last_test,
            'last_test_formatted' => $last_test ? date('Y-m-d H:i:s', $last_test) : 'Never tested',
            'needs_testing' => empty($last_test) || (time() - $last_test) > 3600, // Older than 1 hour
            'config_status' => !empty($username) && !empty($password) ? 'configured' : 'incomplete'
        ];
    }
    
    /**
     * Get stored setup data
     */
    private static function get_stored_setup_data() {
        return [
            'progress' => [
                'connection_configured' => !empty(get_option('wc_wms_integration_username')) && !empty(get_option('wc_wms_integration_password')),
                'shipping_methods_synced' => !empty(get_option('wc_wms_shipping_methods', [])),
                'location_types_synced' => !empty(get_option('wc_wms_location_types', [])),
                'products_exported' => get_option('wc_wms_products_exported', false),
                'stock_synced' => get_option('wc_wms_stock_synced', false),
                'orders_synced' => get_option('wc_wms_orders_last_sync', 0) > 0,
                'webhooks_registered' => !empty(get_option('wc_wms_registered_webhooks', [])),
                'auto_setup_completed' => get_option('wc_wms_auto_setup_completed', false)
            ],
            'shipping_methods' => [
                'current_mappings' => get_option('wc_wms_shipping_method_uuid_mapping', []),
                'default_shipping_method' => get_option('wc_wms_default_shipping_method_uuid', ''),
                'wms_methods' => get_option('wc_wms_shipping_methods', []),
                'last_sync' => get_option('wc_wms_shipping_methods_synced_at', '')
            ],
            'woocommerce_methods' => self::get_woocommerce_shipping_methods(),
            'auto_setup_results' => get_option('wc_wms_auto_setup_results', null),
            'last_sync_times' => [
                'shipping' => get_option('wc_wms_shipping_methods_synced_at', ''),
                'location_types' => get_option('wc_wms_location_types_synced_at', ''),
                'articles' => get_option('wc_wms_products_export_stats', [])['timestamp'] ?? '',
                'stock' => get_option('wc_wms_stock_synced_at', ''),
                'orders' => get_option('wc_wms_orders_last_sync', 0) ? date('Y-m-d H:i:s', get_option('wc_wms_orders_last_sync', 0)) : '',
                'webhooks' => get_option('wc_wms_webhooks_registered_at', ''),
                'auto_setup' => get_option('wc_wms_auto_setup_results', [])['timestamp'] ?? ''
            ]
        ];
    }
    
    /**
     * Get stored shipping data
     */
    private static function get_stored_shipping_data() {
        $wms_methods = get_option('wc_wms_shipping_methods', []);
        $last_sync = get_option('wc_wms_shipping_methods_synced_at', '');
        
        return [
            'current_mappings' => get_option('wc_wms_shipping_method_uuid_mapping', []),
            'default_shipping_method' => get_option('wc_wms_default_shipping_method_uuid', ''),
            'wms_methods' => $wms_methods,
            'wms_methods_count' => count($wms_methods),
            'last_sync' => $last_sync,
            'last_sync_formatted' => $last_sync ? date('Y-m-d H:i:s', strtotime($last_sync)) : 'Never synced',
            'needs_sync' => empty($last_sync) || (time() - strtotime($last_sync)) > 86400,
            'sync_available' => true
        ];
    }
    
    /**
     * Get stored webhook data
     */
    private static function get_stored_webhook_data() {
        $registered_webhooks = get_option('wc_wms_registered_webhooks', []);
        $last_registration = get_option('wc_wms_webhooks_registered_at', '');
        
        return [
            'registered_webhooks' => $registered_webhooks,
            'webhook_count' => count($registered_webhooks),
            'last_registration' => $last_registration,
            'last_registration_formatted' => $last_registration ? date('Y-m-d H:i:s', strtotime($last_registration)) : 'Never registered',
            'webhook_url' => home_url('/wp-json/wc-wms/v1/webhook'),
            'webhook_secret_configured' => !empty(get_option('wc_wms_webhook_secret', '')),
            'registration_available' => true
        ];
    }
    
    /**
     * Get local WooCommerce statistics
     */
    private static function get_local_stats() {
        global $wpdb;
        
        // Count products
        $total_products = wp_count_posts('product')->publish;
        $products_with_sku = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm 
             JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
             WHERE pm.meta_key = '_sku' AND pm.meta_value != '' 
             AND p.post_type = 'product' AND p.post_status = 'publish'"
        );
        
        // Count orders (last 30 days)
        $recent_orders = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} 
                 WHERE post_type = 'shop_order' 
                 AND post_date >= %s",
                date('Y-m-d H:i:s', strtotime('-30 days'))
            )
        );
        
        return [
            'products' => [
                'total' => $total_products,
                'with_sku' => $products_with_sku,
                'without_sku' => $total_products - $products_with_sku,
                'sku_percentage' => $total_products > 0 ? round(($products_with_sku / $total_products) * 100, 1) : 0
            ],
            'orders' => [
                'recent_30_days' => $recent_orders
            ],
            'export_stats' => get_option('wc_wms_products_export_stats', null)
        ];
    }
    
    /**
     * Get sync status overview
     */
    private static function get_sync_status() {
        $last_connection_test = get_option('wc_wms_last_connection_test', 0);
        $last_shipping_sync = get_option('wc_wms_shipping_methods_synced_at', '');
        $last_location_types_sync = get_option('wc_wms_location_types_synced_at', '');
        $last_product_export = get_option('wc_wms_products_export_stats', [])['timestamp'] ?? '';
        $last_stock_sync = get_option('wc_wms_stock_synced_at', '');
        $last_webhook_registration = get_option('wc_wms_webhooks_registered_at', '');
        
        return [
            'connection_test' => [
                'last_run' => $last_connection_test,
                'last_run_formatted' => $last_connection_test ? date('Y-m-d H:i:s', $last_connection_test) : 'Never',
                'status' => get_option('wc_wms_connection_status', 'unknown'),
                'needs_refresh' => (time() - $last_connection_test) > 3600
            ],
            'shipping_sync' => [
                'last_run' => $last_shipping_sync,
                'last_run_formatted' => $last_shipping_sync ?: 'Never',
                'methods_count' => count(get_option('wc_wms_shipping_methods', [])),
                'needs_refresh' => empty($last_shipping_sync) || (time() - strtotime($last_shipping_sync)) > 86400
            ],
            'location_types_sync' => [
                'last_run' => $last_location_types_sync,
                'last_run_formatted' => $last_location_types_sync ?: 'Never',
                'types_count' => count(get_option('wc_wms_location_types', [])),
                'pickable_count' => count(get_option('wc_wms_pickable_location_types', [])),
                'transport_count' => count(get_option('wc_wms_transport_location_types', [])),
                'needs_refresh' => empty($last_location_types_sync) || (time() - strtotime($last_location_types_sync)) > 86400
            ],
            'product_export' => [
                'last_run' => $last_product_export,
                'last_run_formatted' => $last_product_export ?: 'Never',
                'status' => get_option('wc_wms_products_exported', false) ? 'completed' : 'pending',
                'stats' => get_option('wc_wms_products_export_stats', null)
            ],
            'stock_sync' => [
                'last_run' => $last_stock_sync,
                'last_run_formatted' => $last_stock_sync ?: 'Never',
                'status' => get_option('wc_wms_stock_synced', false) ? 'completed' : 'pending',
                'stats' => get_option('wc_wms_stock_sync_stats', null),
                'needs_refresh' => empty($last_stock_sync) || (time() - strtotime($last_stock_sync)) > 21600
            ],
            'webhook_registration' => [
                'last_run' => $last_webhook_registration,
                'last_run_formatted' => $last_webhook_registration ?: 'Never',
                'webhooks_count' => count(get_option('wc_wms_registered_webhooks', [])),
                'needs_refresh' => empty($last_webhook_registration) || (time() - strtotime($last_webhook_registration)) > 604800
            ]
        ];
    }
    
    /**
     * Get WooCommerce shipping methods
     */
    private static function get_woocommerce_shipping_methods() {
        $shipping_zones = WC_Shipping_Zones::get_zones();
        $shipping_methods = [];
        
        // Get methods from all zones
        foreach ($shipping_zones as $zone) {
            foreach ($zone['shipping_methods'] as $method) {
                $key = $method->id . ':' . $method->instance_id;
                $shipping_methods[$key] = $method;
            }
        }
        
        // Also get methods from the default zone (Rest of the World)
        $default_zone = new WC_Shipping_Zone(0);
        foreach ($default_zone->get_shipping_methods() as $method) {
            $key = $method->id . ':' . $method->instance_id;
            $shipping_methods[$key] = $method;
        }
        
        return $shipping_methods;
    }
    
    /**
     * Group shipping methods by zone
     */
    public static function group_methods_by_zone($shipping_methods) {
        $shipping_zones = WC_Shipping_Zones::get_zones();
        $methods_by_zone = [];
        
        foreach ($shipping_methods as $method_key => $method) {
            $zone_name = __('Default Zone', 'wc-wms-integration');
            
            // Find which zone this method belongs to
            foreach ($shipping_zones as $zone_data) {
                foreach ($zone_data['shipping_methods'] as $zone_method) {
                    if ($zone_method->instance_id === $method->instance_id) {
                        $zone_name = $zone_data['zone_name'];
                        break 2;
                    }
                }
            }
            
            if (!isset($methods_by_zone[$zone_name])) {
                $methods_by_zone[$zone_name] = [];
            }
            $methods_by_zone[$zone_name][$method_key] = $method;
        }
        
        return $methods_by_zone;
    }
    
    /**
     * Get recent logs for logs tab
     */
    private static function get_recent_logs() {
        $logger = WC_WMS_Logger::instance();
        $logs = $logger->get_recent_logs('all', 20);
        
        $log_level = get_option('wc_wms_log_level', 'info');
        if ($log_level === 'disabled') {
            return [];
        }
        
        return $logs;
    }
}
