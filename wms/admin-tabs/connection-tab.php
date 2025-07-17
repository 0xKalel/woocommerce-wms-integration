<?php
/**
 * Connection tab template
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}
?>

<div id="connection-tab" class="tab-content" style="display: block;">
    <h2><?php _e('🔌 WMS Connection', 'wc-wms-integration'); ?></h2>
    <p class="description"><?php _e('Configure your WMS API connection settings.', 'wc-wms-integration'); ?></p>
    
    <form method="post">
        <?php wp_nonce_field('wc_wms_admin_actions', 'nonce'); ?>
        <input type="hidden" name="action" value="save_settings">
        
        <table class="form-table">
            <tr>
                <th scope="row"><?php _e('API URL', 'wc-wms-integration'); ?></th>
                <td>
                    <input type="url" name="wms_api_url" value="<?php echo esc_attr(get_option('wc_wms_integration_api_url')); ?>" class="regular-text" readonly>
                    <p class="description"><?php _e('WMS API base URL (read-only)', 'wc-wms-integration'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Username', 'wc-wms-integration'); ?> <span class="required">*</span></th>
                <td>
                    <input type="text" name="wms_username" value="<?php echo esc_attr(get_option('wc_wms_integration_username')); ?>" class="regular-text" required>
                    <p class="description"><?php _e('Your WMS API username', 'wc-wms-integration'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Password', 'wc-wms-integration'); ?> <span class="required">*</span></th>
                <td>
                    <input type="password" name="wms_password" value="<?php echo esc_attr(get_option('wc_wms_integration_password')); ?>" class="regular-text" required>
                    <p class="description"><?php _e('Your WMS API password', 'wc-wms-integration'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Customer ID', 'wc-wms-integration'); ?> <span class="required">*</span></th>
                <td>
                    <input type="text" name="wms_customer_id" value="<?php echo esc_attr(get_option('wc_wms_integration_customer_id')); ?>" class="regular-text" required>
                    <p class="description"><?php _e('Your WMS customer ID (UUID format)', 'wc-wms-integration'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('WMS Code', 'wc-wms-integration'); ?> <span class="required">*</span></th>
                <td>
                    <input type="text" name="wms_code" value="<?php echo esc_attr(get_option('wc_wms_integration_wms_code')); ?>" class="regular-text" required>
                    <p class="description"><?php _e('Your WMS environment code', 'wc-wms-integration'); ?></p>
                </td>
            </tr>
        </table>
        
        <p class="submit">
            <input type="submit" class="button-primary" value="<?php _e('Save Connection Settings', 'wc-wms-integration'); ?>">
        </p>
    </form>
    
    <hr>
    
    <h3><?php _e('🔍 Test Connection', 'wc-wms-integration'); ?></h3>
    <div class="connection-test" style="background: #f9f9f9; padding: 20px; border-radius: 5px; margin: 20px 0;">
        <p><?php _e('Test your API connection to ensure everything is working correctly.', 'wc-wms-integration'); ?></p>
        
        <form method="post" style="display: inline-block;">
            <?php wp_nonce_field('wc_wms_admin_actions', 'nonce'); ?>
            <input type="hidden" name="action" value="test_connection">
            <input type="submit" class="button" value="<?php _e('Test Connection', 'wc-wms-integration'); ?>">
        </form>
        
        <?php 
        $last_connection_test = get_option('wc_wms_last_connection_test', 0);
        if ($last_connection_test) {
            $connection_status = get_option('wc_wms_connection_status', 'unknown');
            $time_ago = human_time_diff($last_connection_test, time());
            echo '<p style="margin-top: 10px;"><small>';
            if ($connection_status === 'success') {
                echo '✅ Last test: ' . $time_ago . ' ago (successful)';
            } else {
                echo '❌ Last test: ' . $time_ago . ' ago (failed)';
            }
            echo '</small></p>';
        }
        ?>
    </div>
    
    <hr>
    
    <h3><?php _e('📖 WMS Portal Access', 'wc-wms-integration'); ?></h3>
    <table class="form-table">
        <tr>
            <th scope="row"><?php _e('Portal URL', 'wc-wms-integration'); ?></th>
            <td><a href="https://dev.wms.ewarehousing-solutions.com" target="_blank">https://dev.wms.ewarehousing-solutions.com</a></td>
        </tr>
        <tr>
            <th scope="row"><?php _e('Portal Access', 'wc-wms-integration'); ?></th>
            <td><?php _e('Use your WMS portal credentials to access the WMS interface for testing', 'wc-wms-integration'); ?></td>
        </tr>
    </table>
</div>
