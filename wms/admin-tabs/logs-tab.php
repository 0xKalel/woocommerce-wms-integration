<?php
/**
 * Logs tab template
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

$recent_logs = $data['recent_logs'] ?? [];
?>

<div id="logs-tab" class="tab-content" style="display: none;">
    <h2><?php _e('Recent Activity', 'wc-wms-integration'); ?></h2>
    
    <?php if (empty($recent_logs)): ?>
        <div class="notice notice-info">
            <p><strong><?php _e('No activity logged yet.', 'wc-wms-integration'); ?></strong></p>
            <p><?php _e('Activity will appear here when:', 'wc-wms-integration'); ?></p>
            <ul style="margin-left: 20px;">
                <li>• <?php _e('API requests are made to WMS', 'wc-wms-integration'); ?></li>
                <li>• <?php _e('Webhooks are received from WMS', 'wc-wms-integration'); ?></li>
                <li>• <?php _e('Orders are processed', 'wc-wms-integration'); ?></li>
                <li>• <?php _e('Connection tests are performed', 'wc-wms-integration'); ?></li>
            </ul>
            <p><em><?php _e('Try running a connection test to generate the first log entry.', 'wc-wms-integration'); ?></em></p>
        </div>
    <?php else: ?>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 150px;"><?php _e('Time', 'wc-wms-integration'); ?></th>
                    <th style="width: 100px;"><?php _e('Type', 'wc-wms-integration'); ?></th>
                    <th><?php _e('Details', 'wc-wms-integration'); ?></th>
                    <th style="width: 100px;"><?php _e('Status', 'wc-wms-integration'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_logs as $log): ?>
                    <tr>
                        <td><?php echo esc_html(date('M j, H:i:s', strtotime($log->created_at))); ?></td>
                        <td>
                            <span class="dashicons dashicons-<?php echo $log->log_type === 'api' ? 'cloud' : 'admin-post'; ?>"></span>
                            <?php echo esc_html(ucfirst($log->log_type)); ?>
                        </td>
                        <td>
                            <?php if ($log->log_type === 'api'): ?>
                                <strong><?php echo esc_html($log->method); ?></strong> 
                                <?php echo esc_html($log->endpoint); ?>
                                <?php if (!empty($log->request_data)): ?>
                                    <br><small><?php echo esc_html(substr($log->request_data, 0, 100)); ?>...</small>
                                <?php endif; ?>
                            <?php else: ?>
                                <strong><?php echo esc_html($log->webhook_type); ?></strong>
                                <?php if (!empty($log->payload)): ?>
                                    <br><small><?php echo esc_html(substr($log->payload, 0, 100)); ?>...</small>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($log->log_type === 'api'): ?>
                                <?php if ($log->error_message): ?>
                                    <span style="color: red;">❌ Error</span>
                                <?php else: ?>
                                    <span style="color: green;">✅ Success</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php if ($log->processed): ?>
                                    <span style="color: green;">✅ Processed</span>
                                <?php else: ?>
                                    <span style="color: orange;">⏳ Pending</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
