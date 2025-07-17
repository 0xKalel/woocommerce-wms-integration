<?php
/**
 * WMS Admin Manager
 * 
 * Handles all admin interface functionality for the WMS integration plugin
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Admin_Manager {
    
    /**
     * Initialize admin functionality
     */
    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'addAdminMenu']);
        add_action('admin_notices', [__CLASS__, 'displayNotices']);
        add_filter('plugin_action_links_' . plugin_basename(WC_WMS_INTEGRATION_PLUGIN_FILE), [__CLASS__, 'addSettingsLink']);
        
        // Register AJAX handlers for admin notices
        add_action('wp_ajax_wc_wms_integration_dismiss_notice', [__CLASS__, 'dismissNotice']);
        add_action('wp_ajax_wc_wms_gdpr_dismiss_notice', [__CLASS__, 'dismissGdprNotice']);
    }
    
    /**
     * Add admin menu
     */
    public static function addAdminMenu(): void {
        add_submenu_page(
            'woocommerce',
            __('WMS Integration', 'wc-wms-integration'),
            __('WMS Integration', 'wc-wms-integration'),
            'manage_options',
            'wc-wms-integration',
            [__CLASS__, 'displayAdminPage']
        );
    }
    
    /**
     * Display admin page
     */
    public static function displayAdminPage(): void {
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'admin-page.php';
    }
    
    /**
     * Check if initial sync is completed
     */
    public static function isInitialSyncCompleted(): bool {
        return get_option('wc_wms_initial_sync_completed', false);
    }
    
    /**
     * Get initial sync completion timestamp
     */
    public static function getInitialSyncCompletedAt(): string {
        return get_option('wc_wms_initial_sync_completed_at', '');
    }
    
    /**
     * Add settings link to plugin actions
     */
    public static function addSettingsLink($links): array {
        $settings_link = '<a href="admin.php?page=wc-wms-integration">' . __('Settings', 'wc-wms-integration') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
    
    /**
     * Display admin notices
     */
    public static function displayNotices(): void {
        self::displayWooCommerceNotice();
        self::displayInitialSyncNotice();
        self::displayDevelopmentNotice();
    }
    
    /**
     * Display WooCommerce missing notice
     */
    private static function displayWooCommerceNotice(): void {
        if (class_exists('WooCommerce')) {
            return;
        }
        
        ?>
        <div class="notice notice-error">
            <p><?php _e('WooCommerce WMS Integration requires WooCommerce to be installed and active.', 'wc-wms-integration'); ?></p>
        </div>
        <?php
    }
    
    /**
     * Display initial sync notice
     */
    private static function displayInitialSyncNotice(): void {
        // Only show on WMS integration pages
        if (!isset($_GET['page']) || $_GET['page'] !== 'wc-wms-integration') {
            return;
        }
        
        // Don't show if WooCommerce is not active
        if (!class_exists('WooCommerce')) {
            return;
        }
        
        // Check if initial sync is completed
        if (self::isInitialSyncCompleted()) {
            return;
        }
        
        // Check if basic credentials are configured
        $has_basic_config = !empty(get_option('wc_wms_integration_username')) && 
                           !empty(get_option('wc_wms_integration_password')) &&
                           !empty(get_option('wc_wms_integration_api_url'));
        
        if (!$has_basic_config) {
            return; // Let the environment validator handle configuration prompts
        }
        
        ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php _e('⚠️ Initial Sync Required', 'wc-wms-integration'); ?></strong><br>
                <?php _e('Automatic synchronization processes (webhooks, orders, stock) are disabled until you complete the initial sync.', 'wc-wms-integration'); ?><br>
                <?php _e('Please go to the "Synchronization" tab and click "Sync Everything" to enable automatic processes.', 'wc-wms-integration'); ?>
            </p>
        </div>
        <?php
    }
    
    /**
     * Display development notice
     */
    private static function displayDevelopmentNotice(): void {
        if (get_option('wc_wms_integration_dev_notice_dismissed')) {
            return;
        }
        
        // Only show development notice if basic settings are configured
        $has_basic_config = !empty(get_option('wc_wms_integration_username')) && 
                           !empty(get_option('wc_wms_integration_password'));
        
        if (!$has_basic_config) {
            return; // Let the environment validator handle configuration prompts
        }
        
        ?>
        <div class="notice notice-info is-dismissible" data-notice="wc-wms-integration-dev">
            <p><?php _e('WMS Integration is active. Configure your settings in WooCommerce → WMS Integration.', 'wc-wms-integration'); ?></p>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $(document).on('click', '.notice[data-notice="wc-wms-integration-dev"] .notice-dismiss', function() {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wc_wms_integration_dismiss_notice',
                        nonce: '<?php echo wp_create_nonce('wc_wms_integration_dismiss_notice'); ?>'
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Dismiss development notice
     */
    public static function dismissNotice(): void {
        if (wp_verify_nonce($_POST['nonce'], 'wc_wms_integration_dismiss_notice')) {
            update_option('wc_wms_integration_dev_notice_dismissed', true);
        }
        wp_die();
    }
    
    /**
     * Dismiss GDPR notice
     */
    public static function dismissGdprNotice(): void {
        if (wp_verify_nonce($_POST['nonce'], 'wc_wms_gdpr_dismiss_notice')) {
            update_option('wc_wms_gdpr_notice_dismissed', true);
        }
        wp_die();
    }
    
    /**
     * Initialize default options
     */
    public static function initializeOptions(): void {
        // Set default options - only API URL from environment, rest must be configured by user
        add_option('wc_wms_integration_version', WC_WMS_INTEGRATION_VERSION);
        add_option('wc_wms_integration_api_url', getenv('WMS_API_URL') ?: 'https://eu-dev.middleware.ewarehousing-solutions.com/');
        add_option('wc_wms_integration_username', '');
        add_option('wc_wms_integration_password', '');
        add_option('wc_wms_integration_customer_id', '');
        add_option('wc_wms_integration_wms_code', '');
        add_option('wc_wms_integration_webhook_secret', wp_generate_password(32, false));
        add_option('wc_wms_customer_email_domain', 'wms.local');
    }
}
