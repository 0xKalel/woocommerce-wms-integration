<?php
/**
 * Webhooks tab template
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

$webhook_data = $data['webhook_data'] ?? [];
$registered_webhooks = $webhook_data['registered_webhooks'] ?? [];
$webhook_url = $webhook_data['webhook_url'] ?? home_url('/wp-json/wc-wms/v1/webhook');
$webhook_count = $webhook_data['webhook_count'] ?? 0;
$last_registration = $webhook_data['last_registration_formatted'] ?? 'Never registered';

// Check if webhook secret is configured
$webhook_secret = get_option('wc_wms_webhook_secret', '');
$webhook_secret_configured = !empty($webhook_secret);
?>

<div id="webhooks-tab" class="tab-content" style="display: none;">
    <h2><?php _e('🔗 Webhook Configuration', 'wc-wms-integration'); ?></h2>
    
    <?php if (!empty($registered_webhooks)): ?>
    <h3><?php _e('📋 Registered Webhooks', 'wc-wms-integration'); ?></h3>
    <table class="widefat fixed striped">
        <thead>
            <tr>
                <th style="width: 300px;"><?php _e('Webhook ID', 'wc-wms-integration'); ?></th>
                <th style="width: 150px;"><?php _e('Event Type', 'wc-wms-integration'); ?></th>
                <th><?php _e('URL', 'wc-wms-integration'); ?></th>
                <th style="width: 100px;"><?php _e('Status', 'wc-wms-integration'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($registered_webhooks as $webhook): ?>
            <tr>
                <td>
                    <code style="font-size: 11px;"><?php echo esc_html($webhook['id'] ?? ($webhook['webhook_id'] ?? 'N/A')); ?></code>
                </td>
                <td>
                    <strong><?php 
                        // Handle different webhook event formats
                        if (isset($webhook['group']) && isset($webhook['action'])) {
                            echo esc_html($webhook['group'] . '.' . $webhook['action']);
                        } elseif (isset($webhook['event_type'])) {
                            echo esc_html($webhook['event_type']);
                        } elseif (isset($webhook['event'])) {
                            echo esc_html($webhook['event']);
                        } else {
                            echo 'Unknown';
                        }
                    ?></strong>
                </td>
                <td>
                    <code style="font-size: 11px; word-break: break-all;"><?php echo esc_html($webhook['url'] ?? $webhook_url); ?></code>
                </td>
                <td>
                    <span style="color: green;">✅ Active</span>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <p><strong>Total Registered:</strong> <?php echo $webhook_count; ?> | 
       <strong>Last Registration:</strong> <?php echo esc_html($last_registration); ?> 
    <?php else: ?>
    <div class="notice notice-warning">
        <p><strong><?php _e('No Registered Webhooks Found', 'wc-wms-integration'); ?></strong></p>
        <p><?php _e('Click "Register All Webhooks with WMS" below to set up webhook integration.', 'wc-wms-integration'); ?></p>
    </div>
    <?php endif; ?>
    
    <h3><?php _e('⚙️ Webhook Management', 'wc-wms-integration'); ?></h3>
    
    <table class="form-table">
        <tr>
            <th scope="row"><?php _e('Current Status', 'wc-wms-integration'); ?></th>
            <td>
                <button type="button" class="button" onclick="checkWebhookStatus()"><?php _e('Check Local Webhook Status', 'wc-wms-integration'); ?></button>
                <p class="description"><?php _e('Check locally tracked webhook registrations (remote verification not available)', 'wc-wms-integration'); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php _e('Register Webhooks', 'wc-wms-integration'); ?></th>
            <td>
                <button type="button" class="button button-primary" onclick="registerWebhooks()"><?php _e('Register All Webhooks with WMS', 'wc-wms-integration'); ?></button>
                <p class="description"><?php _e('⚠️ This will DELETE all existing webhooks first, then register fresh webhooks with the WMS API. Use this to ensure clean webhook registration.', 'wc-wms-integration'); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php _e('Validate Configuration', 'wc-wms-integration'); ?></th>
            <td>
                <button type="button" class="button" onclick="validateWebhookConfig()"><?php _e('Validate Webhook Config', 'wc-wms-integration'); ?></button>
                <p class="description"><?php _e('Check if webhook configuration meets requirements', 'wc-wms-integration'); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php _e('Generate New Secret', 'wc-wms-integration'); ?></th>
            <td>
                <button type="button" class="button" onclick="generateWebhookSecret()"><?php _e('Generate New Secret', 'wc-wms-integration'); ?></button>
                <p class="description"><?php _e('Generate a new webhook secret (will require re-registration)', 'wc-wms-integration'); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php _e('Delete All Webhooks', 'wc-wms-integration'); ?></th>
            <td>
                <button type="button" class="button button-secondary" onclick="deleteAllWebhooks()"><?php _e('Delete All Webhooks', 'wc-wms-integration'); ?></button>
                <p class="description"><?php _e('Remove all webhooks from eWarehousing (use with caution)', 'wc-wms-integration'); ?></p>
            </td>
        </tr>
    </table>
    
    <h3><?php _e('🌐 Webhook Endpoints', 'wc-wms-integration'); ?></h3>
    <table class="widefat fixed striped">
        <thead>
            <tr>
                <th><?php _e('Webhook Type', 'wc-wms-integration'); ?></th>
                <th><?php _e('URL', 'wc-wms-integration'); ?></th>
                <th><?php _e('Description', 'wc-wms-integration'); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong><?php _e('Main Webhook', 'wc-wms-integration'); ?></strong></td>
                <td><code style="font-size: 12px;"><?php echo esc_html($webhook_url); ?></code></td>
                <td><?php _e('Single endpoint for all webhook events (order, stock, shipment)', 'wc-wms-integration'); ?></td>
            </tr>
            <tr>
                <td><strong><?php _e('Test Endpoint', 'wc-wms-integration'); ?></strong></td>
                <td><code style="font-size: 12px;"><?php echo esc_html(home_url('/wp-json/wc-wms/v1/webhook/test')); ?></code></td>
                <td><?php _e('Testing endpoint for webhook validation', 'wc-wms-integration'); ?></td>
            </tr>
        </tbody>
    </table>
</div>
