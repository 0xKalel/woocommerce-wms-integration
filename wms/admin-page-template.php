<?php
/**
 * Main admin page template
 * Coordinates all admin page components
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Include required files
require_once plugin_dir_path(__FILE__) . 'admin-page-data.php';

// Process any form actions
$action_result = WC_WMS_Admin_Page_Handler::process_actions();

// Gather all page data
$data = WC_WMS_Admin_Page_Data::get_page_data();

// Enqueue styles and scripts
wp_enqueue_style('wc-wms-admin-page', plugin_dir_url(__FILE__) . 'admin-page.css', [], WC_WMS_INTEGRATION_VERSION);
wp_enqueue_script('wc-wms-admin-page', plugin_dir_url(__FILE__) . 'admin-page.js', ['jquery'], WC_WMS_INTEGRATION_VERSION, true);

// Localize script with nonce
wp_localize_script('wc-wms-admin-page', 'WC_WMS_ADMIN_NONCE', wp_create_nonce('wc_wms_admin_actions'));
?>

<div class="wrap">
    <h1><?php _e('WooCommerce WMS Integration', 'wc-wms-integration'); ?></h1>
    
    <?php if (!empty($action_result['message'])): ?>
    <div class="notice <?php echo $action_result['class']; ?>">
        <p><?php echo esc_html($action_result['message']); ?></p>
    </div>
    <?php endif; ?>
    
    <div class="nav-tab-wrapper">
        <a href="#connection" class="nav-tab nav-tab-active" onclick="showTab('connection')"><?php _e('🔌 Connection', 'wc-wms-integration'); ?></a>
        <a href="#setup" class="nav-tab" onclick="showTab('setup')"><?php _e('🚀 Setup', 'wc-wms-integration'); ?></a>
        <a href="#synchronization" class="nav-tab" onclick="showTab('synchronization')"><?php _e('🔄 Synchronization', 'wc-wms-integration'); ?></a>
        <a href="#inbound" class="nav-tab" onclick="showTab('inbound')"><?php _e('📦 Inbound', 'wc-wms-integration'); ?></a>
        <a href="#webhooks" class="nav-tab" onclick="showTab('webhooks')"><?php _e('🔗 Webhooks', 'wc-wms-integration'); ?></a>
        <a href="#logs" class="nav-tab" onclick="showTab('logs')"><?php _e('📊 Logs', 'wc-wms-integration'); ?></a>
    </div>
    
    <?php
    // Include individual tab templates
    include plugin_dir_path(__FILE__) . 'admin-tabs/connection-tab.php';
    include plugin_dir_path(__FILE__) . 'admin-tabs/setup-tab.php';
    include plugin_dir_path(__FILE__) . 'admin-tabs/synchronization-tab.php';
    include plugin_dir_path(__FILE__) . 'admin-tabs/inbound-tab.php';
    include plugin_dir_path(__FILE__) . 'admin-tabs/webhooks-tab.php';
    include plugin_dir_path(__FILE__) . 'admin-tabs/logs-tab.php';
    ?>
    
</div>
