<?php
/**
 * WMS Cron Handler
 * 
 * Handles all cron job callbacks for the WMS integration plugin
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Cron_Handler {
    
    /**
     * Register all cron hooks
     */
    public static function registerHooks(): void {
        add_action('wc_wms_cleanup_queue', [__CLASS__, 'cleanupQueue']);
        add_action('wc_wms_cleanup_webhooks', [__CLASS__, 'cleanupWebhooks']);
        add_action('wc_wms_cleanup_sensitive_logs', [__CLASS__, 'cleanupSensitiveLogs']);
        add_action('wc_wms_process_webhook_async', [__CLASS__, 'processAsyncWebhook']);
        add_action('wc_wms_process_order_queue', [__CLASS__, 'processOrderQueue']);
        add_action('wc_wms_process_webhook_queue', [__CLASS__, 'processWebhookQueue']);
        add_action('wc_wms_sync_stock', [__CLASS__, 'syncStock']);
        add_action('wc_wms_sync_orders', [__CLASS__, 'syncOrders']);
        add_action('wc_wms_sync_inbounds', [__CLASS__, 'syncInbounds']);
        add_action('wc_wms_sync_shipments', [__CLASS__, 'syncShipments']);
        add_action('wc_wms_sync_products', [__CLASS__, 'syncProducts']);
        add_action('wc_wms_reset_rate_limits', [__CLASS__, 'resetRateLimits']);
        
        // Webhook management hooks
        add_action('wc_wms_check_stuck_webhooks', [__CLASS__, 'checkStuckWebhooks']);
        add_action('wc_wms_webhook_health_check', [__CLASS__, 'healthCheckWebhookQueue']);
        
        // NEW: Sync jobs processor
        add_action('wc_wms_process_sync_jobs', [__CLASS__, 'processSyncJobs']);
    }
    
    /**
     * Cleanup old queue items
     */
    public static function cleanupQueue(): void {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wc_wms_integration_queue';
        
        // Delete completed items
        $deleted_completed = $wpdb->query($wpdb->prepare(
            "DELETE FROM $table_name WHERE status = 'completed' AND updated_at < %s",
            date('Y-m-d H:i:s', strtotime('-7 days'))
        ));
        
        // Delete failed items
        $deleted_failed = $wpdb->query($wpdb->prepare(
            "DELETE FROM $table_name WHERE status = 'failed' AND updated_at < %s",
            date('Y-m-d H:i:s', strtotime('-30 days'))
        ));
        
        // Product sync queue
        $product_sync_table = $wpdb->prefix . 'wc_wms_product_sync_queue';
        $deleted_product_completed = $wpdb->query($wpdb->prepare(
            "DELETE FROM $product_sync_table WHERE status = 'completed' AND updated_at < %s",
            date('Y-m-d H:i:s', strtotime('-7 days'))
        ));
        
        $deleted_product_failed = $wpdb->query($wpdb->prepare(
            "DELETE FROM $product_sync_table WHERE status = 'failed' AND updated_at < %s",
            date('Y-m-d H:i:s', strtotime('-30 days'))
        ));
        
        // Sync jobs queue (NEW)
        $sync_jobs_table = $wpdb->prefix . 'wc_wms_sync_jobs';
        $deleted_sync_completed = $wpdb->query($wpdb->prepare(
            "DELETE FROM $sync_jobs_table WHERE status IN ('completed', 'failed') AND completed_at < %s",
            date('Y-m-d H:i:s', strtotime('-7 days'))
        ));
        
        // Webhook processing queue
        $webhook_queue_table = $wpdb->prefix . WC_WMS_Constants::TABLE_WEBHOOK_PROCESSING_QUEUE;
        $deleted_webhook_processed = $wpdb->query($wpdb->prepare(
            "DELETE FROM $webhook_queue_table WHERE status = 'processed' AND processed_at < %s",
            date('Y-m-d H:i:s', strtotime('-7 days'))
        ));
        
        $deleted_webhook_failed = $wpdb->query($wpdb->prepare(
            "DELETE FROM $webhook_queue_table WHERE status = 'failed' AND updated_at < %s",
            date('Y-m-d H:i:s', strtotime('-30 days'))
        ));
        
        // Log results
        if ($deleted_completed > 0 || $deleted_failed > 0 || $deleted_product_completed > 0 || $deleted_product_failed > 0 || $deleted_sync_completed > 0) {
            error_log(sprintf(
                'WMS Integration: Queue cleanup completed. Removed %d order completed, %d order failed, %d product completed, %d product failed, %d sync jobs.',
                $deleted_completed,
                $deleted_failed,
                $deleted_product_completed,
                $deleted_product_failed,
                $deleted_sync_completed
            ));
        }
    }
    
    /**
     * Cleanup old webhook logs
     */
    public static function cleanupWebhookLogs(): void {
        global $wpdb;
        
        // Cleanup API logs
        $api_table = $wpdb->prefix . 'wc_wms_api_logs';
        $deleted_api = $wpdb->query($wpdb->prepare(
            "DELETE FROM $api_table WHERE created_at < %s",
            date('Y-m-d H:i:s', strtotime('-30 days'))
        ));
        
        // Cleanup webhook logs
        $webhook_table = $wpdb->prefix . 'wc_wms_webhook_logs';
        $deleted_webhooks = $wpdb->query($wpdb->prepare(
            "DELETE FROM $webhook_table WHERE created_at < %s",
            date('Y-m-d H:i:s', strtotime('-30 days'))
        ));
        
        // Log cleanup results
        if ($deleted_api > 0 || $deleted_webhooks > 0) {
            error_log(sprintf(
                'WMS Integration: Log cleanup completed. Removed %d API logs and %d webhook logs.',
                $deleted_api,
                $deleted_webhooks
            ));
        }
    }
    
    /**
     * Cleanup sensitive logs for GDPR compliance
     */
    public static function cleanupSensitiveLogs(): void {
        global $wpdb;
        
        // Cleanup webhook logs with personal data (GDPR compliance)
        $webhook_table = $wpdb->prefix . 'wc_wms_webhook_logs';
        $sensitive_logs = $wpdb->query($wpdb->prepare(
            "DELETE FROM $webhook_table WHERE created_at < %s AND (
                payload LIKE '%email%' OR 
                payload LIKE '%first_name%' OR 
                payload LIKE '%last_name%' OR 
                payload LIKE '%address%' OR 
                payload LIKE '%phone%'
            )",
            date('Y-m-d H:i:s', strtotime('-7 days'))
        ));
        
        // Cleanup API logs with personal data
        $api_table = $wpdb->prefix . 'wc_wms_api_logs';
        $sensitive_api = $wpdb->query($wpdb->prepare(
            "DELETE FROM $api_table WHERE created_at < %s AND (
                request_data LIKE '%email%' OR 
                request_data LIKE '%first_name%' OR 
                request_data LIKE '%last_name%' OR 
                response_data LIKE '%email%' OR 
                response_data LIKE '%address%'
            )",
            date('Y-m-d H:i:s', strtotime('-7 days'))
        ));
        
        // Log cleanup results
        if ($sensitive_logs > 0 || $sensitive_api > 0) {
            error_log(sprintf(
                'WMS Integration: Sensitive data cleanup completed. Removed %d webhook logs and %d API logs with personal data.',
                $sensitive_logs,
                $sensitive_api
            ));
        }
    }
    
    /**
     * Process webhook asynchronously
     */
    public static function processAsyncWebhook($args): void {
        $event_type = $args['event_type'] ?? '';
        $event_data = $args['event_data'] ?? [];
        $queued_at = $args['queued_at'] ?? time();
        
        // Avoid processing stale webhooks (older than 5 minutes)
        if (time() - $queued_at > WC_WMS_Constants::WEBHOOK_STALE_TIMEOUT) {
            error_log('WMS Integration: Skipped stale async webhook: ' . $event_type);
            return;
        }
        
        try {
            // Get webhook handler instance
            $webhook_handler = new WC_WMS_Webhook_Handler();
            
            // Process the webhook
            $result = $webhook_handler->route_webhook_event($event_type, $event_data);
            
            error_log('WMS Integration: Async webhook processed successfully: ' . $event_type);
            
        } catch (Exception $e) {
            error_log('WMS Integration: Async webhook processing failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Process order queue (cron handler)
     */
    public static function processOrderQueue(): void {
        // Check if initial sync is completed
        if (!get_option('wc_wms_initial_sync_completed', false)) {
            error_log('WMS Integration: Skipping order queue processing - initial sync not completed');
            return;
        }
        
        try {
            $wmsClient = WC_WMS_Service_Container::getWmsClient();
            $queueService = new WC_WMS_Queue_Service($wmsClient);
            
            $results = $queueService->processOrderQueue(10);
            
            // Use the WMS logger for structured logging
            $wmsClient->logger()->info('Order queue processing completed via cron', [
                'processed' => $results['processed'],
                'successful' => $results['successful'],
                'failed' => $results['failed']
            ]);
            
        } catch (Exception $e) {
            error_log('WMS Integration: Order queue cron processing failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Process webhook queue (cron handler)
     */
    public static function processWebhookQueue(): void {
        // Check if initial sync is completed
        if (!get_option('wc_wms_initial_sync_completed', false)) {
            error_log('WMS Integration: Skipping webhook queue processing - initial sync not completed');
            return;
        }
        
        try {
            $webhookQueue = new WC_WMS_Webhook_Queue_Manager();
            
            // Use processing with timeout handling
            $results = $webhookQueue->processQueuedWebhooksWithTimeout(20);
            
            // Log results with timeout information
            if ($results['processed'] > 0 || $results['reset_stuck'] > 0) {
                $logger = WC_WMS_Logger::instance();
                $logger->info('Webhook queue processing completed via cron', [
                    'processed' => $results['processed'],
                    'successful' => $results['successful'],
                    'failed' => $results['failed'],
                    'skipped' => $results['skipped'],
                    'reset_stuck' => $results['reset_stuck']
                ]);
                
                // Log errors if any
                if (!empty($results['errors'])) {
                    foreach ($results['errors'] as $error) {
                        $logger->error('Webhook queue processing error', $error);
                    }
                }
                
                // Log warning if stuck webhooks were reset
                if ($results['reset_stuck'] > 0) {
                    $logger->warning('Reset stuck webhooks during processing', [
                        'reset_count' => $results['reset_stuck']
                    ]);
                }
            }
            
        } catch (Exception $e) {
            error_log('WMS Integration: Webhook queue cron processing failed: ' . $e->getMessage());
            
            // Also log to WMS logger if available
            try {
                $logger = WC_WMS_Logger::instance();
                $logger->error('Webhook queue cron processing exception', [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
            } catch (Exception $loggerException) {
                // Fallback to error_log if logger fails
                error_log('WMS Integration: Failed to log webhook queue error: ' . $loggerException->getMessage());
            }
        }
    }
    
    /**
     * Sync stock (cron handler)
     */
    public static function syncStock(): void {
        // Check if initial sync is completed
        if (!get_option('wc_wms_initial_sync_completed', false)) {
            error_log('WMS Integration: Skipping stock sync - initial sync not completed');
            return;
        }
        
        try {
            $wmsClient = WC_WMS_Service_Container::getWmsClient();
            $stockIntegrator = $wmsClient->stockIntegrator();
            
            // Sync stock for all products
            $result = $stockIntegrator->syncAllStock();
            
            // Use the WMS logger for structured logging
            $wmsClient->logger()->info('Stock sync completed via cron', [
                'success' => !is_wp_error($result),
                'processed' => $result['processed'] ?? 0,
                'updated' => $result['updated'] ?? 0,
                'errors_count' => count($result['errors'] ?? [])
            ]);
            
        } catch (Exception $e) {
            error_log('WMS Integration: Stock sync cron failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Sync orders from WMS (cron handler) - USING CENTRALIZED MANAGER
     */
    public static function syncOrders(): void {
        // Check if initial sync is completed
        if (!get_option('wc_wms_initial_sync_completed', false)) {
            error_log('WMS Integration: Skipping order sync - initial sync not completed');
            return;
        }
        
        try {
            $wmsClient = WC_WMS_Service_Container::getWmsClient();
            
            // Use centralized order sync manager
            $orderSyncManager = new WC_WMS_Order_Sync_Manager($wmsClient);
            $result = $orderSyncManager->processCronOrderSync(['limit' => 50]);
            
            // Use the WMS logger for structured logging
            $wmsClient->logger()->info('Order sync completed via cron (centralized)', [
                'success' => !is_wp_error($result),
                'total_fetched' => $result['total_fetched'] ?? 0,
                'created' => $result['created'] ?? 0,
                'updated' => $result['updated'] ?? 0,
                'skipped' => $result['skipped'] ?? 0,
                'errors_count' => count($result['errors'] ?? [])
            ]);
            
            // Update sync timestamp
            update_option('wc_wms_orders_last_sync', time());
            
        } catch (Exception $e) {
            error_log('WMS Integration: Order sync cron failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Sync inbounds from WMS (cron handler)
     */
    public static function syncInbounds(): void {
        // Check if initial sync is completed
        if (!get_option('wc_wms_initial_sync_completed', false)) {
            error_log('WMS Integration: Skipping inbound sync - initial sync not completed');
            return;
        }
        
        try {
            $inboundService = WC_WMS_Service_Container::getInboundService();
            
            // Get recent inbounds from WMS (last 7 days for cron)
            $params = [
                'limit' => 100,
                'from' => date('Y-m-d', strtotime('-7 days')),
                'sort' => 'inboundDate',
                'direction' => 'desc'
            ];
            
            $inbounds = $inboundService->getInbounds($params);
            
            if (is_wp_error($inbounds)) {
                error_log('WMS Integration: Inbound sync cron failed: ' . $inbounds->get_error_message());
                return;
            }
            
            // Process and count the inbounds
            $total_synced = count($inbounds);
            $completed_inbounds = 0;
            
            foreach ($inbounds as $inbound) {
                if (isset($inbound['status']) && $inbound['status'] === 'completed') {
                    $completed_inbounds++;
                }
            }
            
            // Update sync tracking
            update_option('wc_wms_inbounds_last_sync', time());
            update_option('wc_wms_inbounds_synced_count', $total_synced);
            
            // Log success
            $wmsClient = WC_WMS_Service_Container::getWmsClient();
            $wmsClient->logger()->info('Inbound sync completed via cron', [
                'success' => true,
                'total_synced' => $total_synced,
                'completed_inbounds' => $completed_inbounds,
                'sync_period' => '7 days'
            ]);
            
        } catch (Exception $e) {
            error_log('WMS Integration: Inbound sync cron failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Sync shipments from WMS (cron handler)
     */
    public static function syncShipments(): void {
        // Check if initial sync is completed
        if (!get_option('wc_wms_initial_sync_completed', false)) {
            error_log('WMS Integration: Skipping shipment sync - initial sync not completed');
            return;
        }
        
        try {
            $wmsClient = WC_WMS_Service_Container::getWmsClient();
            $shipmentIntegrator = $wmsClient->shipmentIntegrator();
            
            // Get recent shipments using integrator
            $recentShipments = $shipmentIntegrator->getRecentShipments(3, 100);
            
            if (empty($recentShipments)) {
                $wmsClient->logger()->info('No recent shipments found during cron sync');
                return;
            }
            
            // Process shipments and update related orders
            $total_synced = count($recentShipments);
            $orders_updated = 0;
            $errors = [];
            
            foreach ($recentShipments as $shipment) {
                try {
                    // DEBUG: Log shipment data type and structure before processing
                    error_log('WMS Cron Shipment Debug: Processing shipment - Type: ' . gettype($shipment) . ', Data: ' . print_r($shipment, true));
                    
                    // Ensure $shipment is an array before passing to processShipmentWebhook
                    if (!is_array($shipment)) {
                        error_log('WMS Cron Shipment Error: Expected array, got ' . gettype($shipment) . ' - Value: ' . print_r($shipment, true));
                        $errors[] = [
                            'shipment' => 'Invalid data type: ' . gettype($shipment),
                            'error' => 'Expected array, got ' . gettype($shipment)
                        ];
                        continue;
                    }
                    
                    // Process shipment webhook data to update orders
                    $result = $shipmentIntegrator->processShipmentWebhook($shipment);
                    
                    if ($result['success']) {
                        $orders_updated++;
                    }
                    
                } catch (Exception $e) {
                    $errors[] = [
                        'shipment_id' => $shipment['id'] ?? 'unknown',
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            // Update sync tracking
            update_option('wc_wms_shipments_last_sync', time());
            update_option('wc_wms_shipments_synced_count', $total_synced);
            
            // Log success
            $wmsClient->logger()->info('Shipment sync completed via cron', [
                'success' => true,
                'total_synced' => $total_synced,
                'orders_updated' => $orders_updated,
                'errors_count' => count($errors),
                'sync_period' => '3 days'
            ]);
            
            // Log errors if any
            if (!empty($errors)) {
                foreach ($errors as $error) {
                    $wmsClient->logger()->error('Shipment sync error', $error);
                }
            }
            
        } catch (Exception $e) {
            error_log('WMS Integration: Shipment sync cron failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Sync products (cron handler)
     */
    public static function syncProducts(): void {
        // Check if initial sync is completed
        if (!get_option('wc_wms_initial_sync_completed', false)) {
            error_log('WMS Integration: Skipping product sync - initial sync not completed');
            return;
        }
        
        try {
            $wmsClient = WC_WMS_Service_Container::getWmsClient();
            $queueService = new WC_WMS_Queue_Service($wmsClient);
            
            // Process product queue
            $results = $queueService->processProductQueue(20);
            
            // Use the WMS logger for structured logging
            $wmsClient->logger()->info('Product sync completed via cron', [
                'processed' => $results['processed'],
                'successful' => $results['successful'],
                'failed' => $results['failed']
            ]);
            
        } catch (Exception $e) {
            error_log('WMS Integration: Product sync cron failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Reset rate limits
     */
    public static function resetRateLimits(): void {
        $current_time = time();
        $reset_time = get_option('wc_wms_rate_limit_reset', 0);
        
        if ($reset_time > 0 && $current_time > $reset_time) {
            // Reset rate limit counters
            update_option('wc_wms_rate_limit_remaining', WC_WMS_Constants::RATE_LIMIT_DEFAULT);
            update_option('wc_wms_rate_limit_reset', 0);
            update_option('wc_wms_rate_limit_exceeded_at', 0);
            
            error_log('WMS Integration: Rate limit counters reset');
        }
    }
    
    /**
     * Check and reset stuck webhooks (NEW)
     */
    public static function checkStuckWebhooks(): void {
        try {
            $webhookQueue = new WC_WMS_Webhook_Queue_Manager();
            
            // Get stuck webhooks before reset
            $stuck_webhooks = $webhookQueue->getStuckWebhooks();
            
            if (!empty($stuck_webhooks)) {
                $logger = WC_WMS_Logger::instance();
                $logger->warning('Found stuck webhooks', [
                    'stuck_count' => count($stuck_webhooks),
                    'webhooks' => $stuck_webhooks
                ]);
                
                // Reset stuck webhooks
                $reset_count = $webhookQueue->resetStuckWebhooks();
                
                if ($reset_count > 0) {
                    $logger->info('Reset stuck webhooks', [
                        'reset_count' => $reset_count
                    ]);
                }
            }
            
        } catch (Exception $e) {
            error_log('WMS Integration: Stuck webhook check failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Health check for webhook queue (NEW)
     */
    public static function healthCheckWebhookQueue(): void {
        try {
            $webhookQueue = new WC_WMS_Webhook_Queue_Manager();
            $health = $webhookQueue->getQueueHealthStatus();
            
            if ($health['health_status'] === 'unhealthy') {
                $logger = WC_WMS_Logger::instance();
                $logger->warning('Webhook queue health check failed', $health);
                
                // Optionally send alert/notification here
                do_action('wms_webhook_queue_unhealthy', $health);
            }
            
        } catch (Exception $e) {
            error_log('WMS Integration: Webhook health check failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Enhanced cleanup with failed webhook management (ENHANCED)
     */
    public static function cleanupWebhooks(): void {
        try {
            $webhookQueue = new WC_WMS_Webhook_Queue_Manager();
            
            // Clean up old processed webhooks (existing functionality)
            $processed_cleaned = $webhookQueue->cleanup(7);
            
            // Clean up failed webhooks that exceeded attempts
            $failed_cleaned = $webhookQueue->cleanupFailedWebhooks();
            
            if ($processed_cleaned > 0 || $failed_cleaned > 0) {
                $logger = WC_WMS_Logger::instance();
                $logger->info('Webhook cleanup completed', [
                    'processed_cleaned' => $processed_cleaned,
                    'failed_archived' => $failed_cleaned
                ]);
            }
            
        } catch (Exception $e) {
            error_log('WMS Integration: Webhook cleanup failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Process sync jobs (NEW: Queue-based sync system)
     */
    public static function processSyncJobs(): void {
        try {
            $syncJobsManager = new WC_WMS_Sync_Jobs_Manager();
            
            // Process up to 5 jobs per cron run to prevent timeout
            $jobs_processed = 0;
            $max_jobs = 5;
            
            while ($jobs_processed < $max_jobs) {
                $result = $syncJobsManager->processNextJob();
                
                if ($result === null) {
                    // No more jobs to process
                    break;
                }
                
                $jobs_processed++;
                
                // If a job failed, log it but continue processing
                if (!$result['success']) {
                    error_log('WMS Integration: Sync job failed - ' . $result['error']);
                }
            }
            
            if ($jobs_processed > 0) {
                error_log("WMS Integration: Processed {$jobs_processed} sync jobs via cron");
                
                // Schedule next run immediately if there might be more jobs
                if ($jobs_processed >= $max_jobs) {
                    wp_schedule_single_event(time() + 30, 'wc_wms_process_sync_jobs');
                }
            }
            
        } catch (Exception $e) {
            error_log('WMS Integration: Sync jobs processing failed: ' . $e->getMessage());
        }
    }
}
