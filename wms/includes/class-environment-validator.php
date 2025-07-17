<?php
/**
 * Environment Validator for WMS Integration
 * 
 * Validates required configuration from WordPress options
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Environment_Validator {
    
    /**
     * Required configuration options
     */
    private static $required_options = [
        'wc_wms_integration_api_url' => 'API URL',
        'wc_wms_integration_username' => 'Username',
        'wc_wms_integration_password' => 'Password',
        'wc_wms_integration_customer_id' => 'Customer ID',
        'wc_wms_integration_wms_code' => 'WMS Code'
    ];
    
    /**
     * Validate configuration
     */
    public static function validate() {
        $missing_config = [];
        
        foreach (self::$required_options as $option_key => $display_name) {
            $value = get_option($option_key);
            
            if (empty($value)) {
                $missing_config[] = $display_name;
            }
        }
        
        if (!empty($missing_config)) {
            add_action('admin_notices', function() use ($missing_config) {
                // Only show notice on plugin pages or if there are orders pending
                $screen = get_current_screen();
                $show_notice = false;
                
                // Show on plugin admin page
                if ($screen && strpos($screen->id, 'wc-wms-integration') !== false) {
                    $show_notice = true;
                }
                
                // Show on WooCommerce pages if there are pending orders
                if ($screen && (strpos($screen->id, 'woocommerce') !== false || strpos($screen->id, 'shop_order') !== false)) {
                    global $wpdb;
                    $pending_orders = $wpdb->get_var(
                        "SELECT COUNT(*) FROM {$wpdb->prefix}wc_wms_integration_queue WHERE status = 'pending'"
                    );
                    if ($pending_orders > 0) {
                        $show_notice = true;
                    }
                }
                
                if ($show_notice) {
                    ?>
                    <div class="notice notice-warning is-dismissible">
                        <p><strong><?php _e('WMS Integration: Configuration Required', 'wc-wms-integration'); ?></strong></p>
                        <p><?php _e('Please configure the following WMS settings:', 'wc-wms-integration'); ?></p>
                        <ul style="margin-left: 20px;">
                            <?php foreach ($missing_config as $setting): ?>
                                <li><?php echo esc_html($setting); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <p>
                            <a href="<?php echo admin_url('admin.php?page=wc-wms-integration#settings'); ?>" class="button button-primary">
                                <?php _e('Configure WMS Settings', 'wc-wms-integration'); ?>
                            </a>
                        </p>
                    </div>
                    <?php
                }
            });
            
            return false;
        }
        
        return true;
    }
    
    /**
     * Get configuration status
     */
    public static function get_status() {
        $config = [];
        
        foreach (self::$required_options as $option_key => $display_name) {
            $value = get_option($option_key);
            
            $config[$option_key] = [
                'display_name' => $display_name,
                'configured' => !empty($value),
                'has_value' => !empty($value)
            ];
        }
        
        return $config;
    }
    
    /**
     * Check if all required settings are configured
     */
    public static function is_configured() {
        foreach (self::$required_options as $option_key => $display_name) {
            if (empty(get_option($option_key))) {
                return false;
            }
        }
        return true;
    }
}

// Initialize validation on admin pages
add_action('admin_init', [WC_WMS_Environment_Validator::class, 'validate']);
