<?php
/**
 * Setup tab template
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Global database access
global $wpdb;

// Use stored setup data (no API calls)
$setup_data = $data['setup_data'];
$shipping_methods_data = $setup_data['shipping_methods'];
$woocommerce_shipping_methods = $setup_data['woocommerce_methods'];
$methods_by_zone = WC_WMS_Admin_Page_Data::group_methods_by_zone($woocommerce_shipping_methods);
?>

<div id="setup-tab" class="tab-content" style="display: none;">
    <h2><?php _e('🚀 Initial Setup', 'wc-wms-integration'); ?></h2>
    <p class="description"><?php _e('Set up your WMS integration for the first time.', 'wc-wms-integration'); ?></p>
    
    <hr>
    
    <!-- ONE-CLICK SETUP -->
    <div class="one-click-setup" style="background: #f0f8f0; padding: 25px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #4CAF50; text-align: center;">
        <h3 style="margin: 0 0 15px 0;"><?php _e('🚀 Complete Setup', 'wc-wms-integration'); ?></h3>
        <p style="font-size: 16px; margin: 0 0 20px 0;"><?php _e('Click the button below to automatically set up everything:', 'wc-wms-integration'); ?></p>
        
        
        <button type="button" class="button button-primary button-large" onclick="syncEverything()" style="padding: 15px 30px; font-size: 16px; height: auto;">
            🚀 <?php _e('Sync Everything Now', 'wc-wms-integration'); ?>
        </button>
        
        <p style="margin: 15px 0 0 0; font-size: 14px; color: #666;">
            <?php _e('This will take 1-3 minutes depending on your catalog size. You can monitor progress below.', 'wc-wms-integration'); ?>
        </p>
        
        <!-- Progress Display -->
        <div id="sync-progress" style="display: none; margin-top: 20px; text-align: left;">
            <h4>🔄 Sync Progress:</h4>
            <div id="progress-steps" style="background: #f9f9f9; padding: 15px; border-radius: 5px;">
                <!-- Progress steps will be populated by JavaScript -->
            </div>
        </div>
        
        <!-- Results Display -->
        <div id="sync-results" style="display: none; margin-top: 20px; text-align: left;">
            <h4>🎉 Sync Results:</h4>
            <div id="results-content" style="background: #f9f9f9; padding: 15px; border-radius: 5px;">
                <!-- Results will be populated by JavaScript -->
            </div>
        </div>
    </div>
    

    
    <!-- Current Shipping Method Mappings (if any exist) -->
    <?php if (!empty($shipping_methods_data['wms_methods']) && !empty($shipping_methods_data['current_mappings'])): ?>
    <div class="current-mappings" style="background: #f0f8f0; padding: 20px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #4CAF50;">
        <h4><?php _e('🗺️ Current Shipping Method Mappings', 'wc-wms-integration'); ?></h4>
        <p><?php _e('Your WooCommerce shipping methods are currently mapped to these WMS methods:', 'wc-wms-integration'); ?></p>
        
        <div style="background: white; padding: 15px; border-radius: 5px; margin: 10px 0;">
            <?php 
            $mappings = $shipping_methods_data['current_mappings'];
            $wms_methods = $shipping_methods_data['wms_methods'];
            $wc_methods = $setup_data['woocommerce_methods'];
            
            // Create lookup for WMS method names
            $wms_method_names = [];
            foreach ($wms_methods as $method) {
                $wms_method_names[$method['id']] = $method['name'] . ' (' . $method['code'] . ')';
            }
            
            if (!empty($mappings)) {
                echo '<table style="width: 100%; border-collapse: collapse;">';
                echo '<thead><tr style="background: #f5f5f5;"><th style="padding: 8px; text-align: left; border: 1px solid #ddd;">WooCommerce Method</th><th style="padding: 8px; text-align: left; border: 1px solid #ddd;">WMS Method</th></tr></thead>';
                echo '<tbody>';
                
                foreach ($mappings as $wc_method_key => $wms_method_id) {
                    $wc_method_name = isset($wc_methods[$wc_method_key]) ? $wc_methods[$wc_method_key]->get_title() : $wc_method_key;
                    $wms_method_name = $wms_method_names[$wms_method_id] ?? 'Unknown Method';
                    
                    echo '<tr>';
                    echo '<td style="padding: 8px; border: 1px solid #ddd;">' . esc_html($wc_method_name) . '</td>';
                    echo '<td style="padding: 8px; border: 1px solid #ddd;">' . esc_html($wms_method_name) . '</td>';
                    echo '</tr>';
                }
                
                echo '</tbody></table>';
                
                echo '<p style="margin-top: 15px;"><small>🔄 Mappings were automatically created during setup. You can adjust these in the Advanced section if needed.</small></p>';
            } else {
                echo '<p><em>No mappings configured yet. Use the "Sync Everything" button above to create automatic mappings.</em></p>';
            }
            ?>
        </div>
    </div>
    <?php endif; ?>
    

</div>
