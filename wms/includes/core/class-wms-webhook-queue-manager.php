<?php
/**
 * WMS Webhook Processing Queue Manager
 * 
 * Handles ordering and queuing of incoming webhooks to ensure proper processing sequence
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Webhook_Queue_Manager {
    
    /**
     * Webhook processing queue table name
     */
    private $table_name;
    
    /**
     * Logger instance
     */
    private $logger;
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . WC_WMS_Constants::TABLE_WEBHOOK_PROCESSING_QUEUE;
        $this->logger = WC_WMS_Logger::instance();
    }
    
    /**
     * Queue webhook for processing with ordering support
     */
    public function queueWebhook(array $webhook_data): bool {
        global $wpdb;
        
        $webhook_id = $webhook_data['webhook_id'] ?? '';
        $group = $webhook_data['group'] ?? '';
        $action = $webhook_data['action'] ?? '';
        $entity_id = $webhook_data['entity_id'] ?? '';
        $external_reference = $webhook_data['external_reference'] ?? '';
        $payload = $webhook_data['payload'] ?? [];
        
        // Validate required fields
        if (empty($webhook_id) || empty($group) || empty($action)) {
            $this->logger->error('Invalid webhook data for queuing', $webhook_data);
            return false;
        }
        
        // Check if already queued
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_name} WHERE webhook_id = %s",
            $webhook_id
        ));
        
        if ($existing) {
            $this->logger->info('Webhook already queued', ['webhook_id' => $webhook_id]);
            return true; // Already queued, consider success
        }
        
        $priority = $this->calculatePriority($group, $action);
        $requires_prerequisite = $this->requiresPrerequisite($group, $action);
        $prerequisite_event = $this->getPrerequisiteEvent($group, $action);
        
        $result = $wpdb->insert(
            $this->table_name,
            [
                'webhook_id' => $webhook_id,
                'group_name' => $group,
                'action' => $action,
                'entity_id' => $entity_id,
                'external_reference' => $external_reference,
                'payload' => json_encode($payload),
                'priority' => $priority,
                'requires_prerequisite' => $requires_prerequisite ? 1 : 0,
                'prerequisite_event' => $prerequisite_event,
                'status' => 'pending',
                'attempts' => 0,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s']
        );
        
        if ($result === false) {
            $this->logger->error('Failed to queue webhook', [
                'webhook_id' => $webhook_id,
                'group' => $group,
                'action' => $action,
                'error' => $wpdb->last_error
            ]);
            return false;
        }
        
        $this->logger->info('Webhook queued successfully', [
            'webhook_id' => $webhook_id,
            'group' => $group,
            'action' => $action,
            'priority' => $priority,
            'requires_prerequisite' => $requires_prerequisite
        ]);
        
        return true;
    }
    
    /**
     * Process queued webhooks in correct order
     */
    public function processQueuedWebhooks(int $batch_size = 20): array {
        $results = [
            'processed' => 0,
            'successful' => 0,
            'failed' => 0,
            'skipped' => 0,
            'errors' => []
        ];
        
        $this->logger->info('Starting webhook queue processing', ['batch_size' => $batch_size]);
        
        // Get pending webhooks ordered by priority and creation time
        $webhooks = $this->getPendingWebhooks($batch_size);
        
        if (empty($webhooks)) {
            $this->logger->debug('No pending webhooks to process');
            return $results;
        }
        
        $this->logger->info('Retrieved pending webhooks', ['count' => count($webhooks)]);
        
        foreach ($webhooks as $webhook) {
            try {
                // Check if prerequisite is met
                if ($webhook->requires_prerequisite && !$this->isPrerequisiteMet($webhook)) {
                    $this->logger->debug('Prerequisite not met, skipping webhook', [
                        'webhook_id' => $webhook->webhook_id,
                        'prerequisite_event' => $webhook->prerequisite_event
                    ]);
                    $results['skipped']++;
                    continue; // Skip for now, will be processed later
                }
                
                // Mark as processing
                $this->markAsProcessing($webhook->id);
                
                // Process the webhook
                $result = $this->processWebhook($webhook);
                
                if ($result['success']) {
                    $this->markAsProcessed($webhook->id);
                    $results['successful']++;
                    $this->logger->info('Webhook processed successfully', [
                        'webhook_id' => $webhook->webhook_id,
                        'group' => $webhook->group_name,
                        'action' => $webhook->action
                    ]);
                } else {
                    $this->handleProcessingFailure($webhook->id, $result['error']);
                    $results['failed']++;
                    $results['errors'][] = [
                        'webhook_id' => $webhook->webhook_id,
                        'error' => $result['error']
                    ];
                }
                
                $results['processed']++;
                
            } catch (Exception $e) {
                $this->handleProcessingFailure($webhook->id, $e->getMessage());
                $results['failed']++;
                $results['processed']++;
                $results['errors'][] = [
                    'webhook_id' => $webhook->webhook_id,
                    'error' => $e->getMessage()
                ];
                
                $this->logger->error('Webhook processing exception', [
                    'webhook_id' => $webhook->webhook_id,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        $this->logger->info('Webhook queue processing completed', $results);
        
        return $results;
    }
    
    /**
     * Calculate processing priority (lower number = higher priority)
     */
    private function calculatePriority(string $group, string $action): int {
        $priority_map = [
            // Order lifecycle - highest priority
            'order.created' => 1,
            'order.updated' => 2,
            'order.planned' => 3,
            'order.processing' => 4,
            'order.shipped' => 5,
            
            // Stock updates - high priority
            'stock.updated' => 10,
            'stock.adjustment' => 11,
            
            // Shipments - medium priority
            'shipment.created' => 15,
            'shipment.updated' => 16,
            'shipment.shipped' => 17,
            'shipment.delivered' => 18,
            
            // Inbounds - lower priority
            'inbound.created' => 20,
            'inbound.updated' => 21,
            'inbound.completed' => 22,
            
            // Articles/variants - lowest priority
            'article.created' => 30,
            'article.updated' => 31,
            'article.deleted' => 32,
            'variant.updated' => 33,
        ];
        
        $event_type = $group . '.' . $action;
        return $priority_map[$event_type] ?? 999;
    }
    
    /**
     * Check if webhook requires prerequisite
     */
    private function requiresPrerequisite(string $group, string $action): bool {
        $requires_prerequisite = [
            'order.updated' => true,
            'order.planned' => true,
            'order.processing' => true,
            'order.shipped' => true,
            'shipment.updated' => true,
            'shipment.shipped' => true,
            'shipment.delivered' => true,
        ];
        
        $event_type = $group . '.' . $action;
        return $requires_prerequisite[$event_type] ?? false;
    }
    
    /**
     * Get prerequisite event for webhook
     */
    private function getPrerequisiteEvent(string $group, string $action): ?string {
        $prerequisite_map = [
            'order.updated' => 'order.created',
            'order.planned' => 'order.created',
            'order.processing' => 'order.created',
            'order.shipped' => 'order.created',
            'shipment.updated' => 'shipment.created',
            'shipment.shipped' => 'shipment.created',
            'shipment.delivered' => 'shipment.created',
        ];
        
        $event_type = $group . '.' . $action;
        return $prerequisite_map[$event_type] ?? null;
    }
    
    /**
     * Get pending webhooks ordered by priority
     */
    private function getPendingWebhooks(int $limit): array {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             WHERE status = 'pending' 
             ORDER BY priority ASC, created_at ASC
             LIMIT %d",
            $limit
        ));
    }
    
    /**
     * Check if prerequisite webhook has been processed
     */
    private function isPrerequisiteMet(object $webhook): bool {
        global $wpdb;
        
        if (empty($webhook->prerequisite_event)) {
            return true;
        }
        
        // Split prerequisite event
        $parts = explode('.', $webhook->prerequisite_event);
        if (count($parts) !== 2) {
            return true; // Invalid prerequisite format, allow processing
        }
        
        $prereq_group = $parts[0];
        $prereq_action = $parts[1];
        
        // SPECIAL CASE: For order.updated events, check if order already exists in WooCommerce
        // This handles orders that were synced (not created via webhook)
        if ($webhook->group_name === 'order' && $webhook->action === 'updated' && $prereq_group === 'order' && $prereq_action === 'created') {
            // Check if the order already exists in WooCommerce
            $payload = json_decode($webhook->payload, true);
            if (!empty($payload['external_reference'])) {
                // Use the centralized order sync manager to check if order exists
                $client = WC_WMS_Service_Container::getWmsClient();
                $orderSyncManager = new WC_WMS_Order_Sync_Manager($client);
                $existingOrder = $orderSyncManager->findOrderByExternalReference($payload['external_reference']);
                
                if ($existingOrder) {
                    $this->logger->info('Order already exists in WooCommerce, bypassing prerequisite check', [
                        'webhook_id' => $webhook->webhook_id,
                        'external_reference' => $payload['external_reference'],
                        'order_id' => $existingOrder->get_id()
                    ]);
                    return true; // Order exists, prerequisite not needed
                }
            }
        }
        
        // Check if prerequisite has been processed for the same entity
        $prerequisite_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} 
             WHERE entity_id = %s 
             AND group_name = %s 
             AND action = %s 
             AND status = 'processed'",
            $webhook->entity_id,
            $prereq_group,
            $prereq_action
        ));
        
        return $prerequisite_exists > 0;
    }
    
    /**
     * Process individual webhook
     */
    private function processWebhook(object $webhook): array {
        try {
            // Decode payload
            $payload = json_decode($webhook->payload, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON payload: ' . json_last_error_msg());
            }
            
            // Get webhook integrator and process
            $integrator = WC_WMS_Service_Container::getWmsClient()->webhookIntegrator();
            $result = $integrator->routeWebhookEvent($webhook->group_name, $webhook->action, $payload, $webhook->entity_id);
            
            return [
                'success' => true,
                'result' => $result
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Mark webhook as processing
     */
    private function markAsProcessing(int $webhook_id): bool {
        global $wpdb;
        
        return $wpdb->update(
            $this->table_name,
            [
                'status' => 'processing',
                'updated_at' => current_time('mysql')
            ],
            ['id' => $webhook_id],
            ['%s', '%s'],
            ['%d']
        ) !== false;
    }
    
    /**
     * Mark webhook as processed
     */
    private function markAsProcessed(int $webhook_id): bool {
        global $wpdb;
        
        return $wpdb->update(
            $this->table_name,
            [
                'status' => 'processed',
                'processed_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ],
            ['id' => $webhook_id],
            ['%s', '%s', '%s'],
            ['%d']
        ) !== false;
    }
    
    /**
     * Handle processing failure
     */
    private function handleProcessingFailure(int $webhook_id, string $error_message): bool {
        global $wpdb;
        
        // Get current attempts
        $webhook = $wpdb->get_row($wpdb->prepare(
            "SELECT attempts FROM {$this->table_name} WHERE id = %d",
            $webhook_id
        ));
        
        if (!$webhook) {
            return false;
        }
        
        $attempts = intval($webhook->attempts) + 1;
        $max_attempts = 3;
        
        if ($attempts >= $max_attempts) {
            // Mark as failed
            return $wpdb->update(
                $this->table_name,
                [
                    'status' => 'failed',
                    'attempts' => $attempts,
                    'error_message' => $error_message,
                    'updated_at' => current_time('mysql')
                ],
                ['id' => $webhook_id],
                ['%s', '%d', '%s', '%s'],
                ['%d']
            ) !== false;
        } else {
            // Mark for retry
            return $wpdb->update(
                $this->table_name,
                [
                    'status' => 'pending',
                    'attempts' => $attempts,
                    'error_message' => $error_message,
                    'updated_at' => current_time('mysql')
                ],
                ['id' => $webhook_id],
                ['%s', '%d', '%s', '%s'],
                ['%d']
            ) !== false;
        }
    }
    
    /**
     * Get queue statistics
     */
    public function getQueueStats(): array {
        global $wpdb;
        
        $stats = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM {$this->table_name} GROUP BY status",
            ARRAY_A
        );
        
        $result = [
            'pending' => 0,
            'processing' => 0,
            'processed' => 0,
            'failed' => 0,
            'total' => 0
        ];
        
        foreach ($stats as $stat) {
            $result[$stat['status']] = intval($stat['count']);
            $result['total'] += intval($stat['count']);
        }
        
        // Get oldest pending webhook
        $oldest_pending = $wpdb->get_var(
            "SELECT MIN(created_at) FROM {$this->table_name} WHERE status = 'pending'"
        );
        
        $result['oldest_pending'] = $oldest_pending;
        
        return $result;
    }
    
    /**
     * Clean up old processed webhooks
     */
    public function cleanup(int $days = 7): int {
        global $wpdb;
        
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name} 
             WHERE status = 'processed' 
             AND processed_at < %s",
            date('Y-m-d H:i:s', strtotime("-{$days} days"))
        ));
        
        if ($deleted > 0) {
            $this->logger->info("Cleaned up {$deleted} processed webhooks older than {$days} days");
        }
        
        return $deleted ?: 0;
    }
    
    /**
     * Retry failed webhooks
     */
    public function retryFailedWebhooks(): int {
        global $wpdb;
        
        $updated = $wpdb->query(
            "UPDATE {$this->table_name} 
             SET status = 'pending', 
                 attempts = 0,
                 error_message = NULL,
                 updated_at = NOW() 
             WHERE status = 'failed'"
        );
        
        if ($updated > 0) {
            $this->logger->info("Reset {$updated} failed webhooks for retry");
        }
        
        return $updated ?: 0;
    }
    
    /**
     * Get recent webhook processing activity
     */
    public function getRecentActivity(int $limit = 20): array {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT webhook_id, group_name, action, entity_id, status, attempts, error_message, 
                    created_at, processed_at, updated_at
             FROM {$this->table_name} 
             ORDER BY updated_at DESC 
             LIMIT %d",
            $limit
        ), ARRAY_A);
    }
    
    /**
     * Force process specific webhook
     */
    public function forceProcessWebhook(int $webhook_id): bool {
        global $wpdb;
        
        return $wpdb->update(
            $this->table_name,
            [
                'status' => 'pending',
                'attempts' => 0,
                'error_message' => null,
                'updated_at' => current_time('mysql')
            ],
            ['id' => $webhook_id],
            ['%s', '%d', '%s', '%s'],
            ['%d']
        ) !== false;
    }

    /**
     * Reset stuck webhooks that have been in "processing" status too long
     */
    public function resetStuckWebhooks(): int {
        global $wpdb;
        
        $timeout_minutes = 5; // 5 minutes timeout
        $timeout_timestamp = date('Y-m-d H:i:s', strtotime("-{$timeout_minutes} minutes"));
        
        // Find webhooks stuck in processing for more than timeout period
        $stuck_webhooks = $wpdb->get_results($wpdb->prepare(
            "SELECT id, webhook_id, group_name, action, entity_id, updated_at 
             FROM {$this->table_name} 
             WHERE status = 'processing' 
             AND updated_at < %s",
            $timeout_timestamp
        ));
        
        if (empty($stuck_webhooks)) {
            return 0;
        }
        
        // Reset stuck webhooks back to pending
        $reset_count = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table_name} 
             SET status = 'pending', 
                 attempts = attempts + 1,
                 error_message = 'Reset from stuck processing state',
                 updated_at = NOW() 
             WHERE status = 'processing' 
             AND updated_at < %s",
            $timeout_timestamp
        ));
        
        if ($reset_count > 0) {
            $this->logger->warning("Reset {$reset_count} stuck webhooks from processing state", [
                'timeout_minutes' => $timeout_minutes,
                'stuck_webhooks' => array_map(function($webhook) {
                    return [
                        'webhook_id' => $webhook->webhook_id,
                        'group' => $webhook->group_name,
                        'action' => $webhook->action,
                        'entity_id' => $webhook->entity_id,
                        'stuck_since' => $webhook->updated_at
                    ];
                }, $stuck_webhooks)
            ]);
        }
        
        return $reset_count ?: 0;
    }
    
    /**
     * Clean up failed webhooks that have exceeded max attempts
     */
    public function cleanupFailedWebhooks(): int {
        global $wpdb;
        
        $max_attempts = 3;
        $retention_days = 7;
        
        // Move failed webhooks with max attempts to archived status
        $cleanup_count = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table_name} 
             SET status = 'archived',
                 updated_at = NOW() 
             WHERE status = 'failed' 
             AND attempts >= %d 
             AND updated_at < %s",
            $max_attempts,
            date('Y-m-d H:i:s', strtotime("-{$retention_days} days"))
        ));
        
        if ($cleanup_count > 0) {
            $this->logger->info("Archived {$cleanup_count} failed webhooks that exceeded max attempts");
        }
        
        return $cleanup_count ?: 0;
    }
    
    /**
     * Get stuck webhooks for monitoring
     */
    public function getStuckWebhooks(): array {
        global $wpdb;
        
        $timeout_minutes = 5;
        $timeout_timestamp = date('Y-m-d H:i:s', strtotime("-{$timeout_minutes} minutes"));
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT webhook_id, group_name, action, entity_id, updated_at,
                    TIMESTAMPDIFF(MINUTE, updated_at, NOW()) as stuck_minutes
             FROM {$this->table_name} 
             WHERE status = 'processing' 
             AND updated_at < %s
             ORDER BY updated_at ASC",
            $timeout_timestamp
        ), ARRAY_A);
    }
    
    /**
     * Get comprehensive queue health status
     */
    public function getQueueHealthStatus(): array {
        global $wpdb;
        
        $stats = $this->getQueueStats();
        $stuck_webhooks = $this->getStuckWebhooks();
        
        // Get oldest pending webhook
        $oldest_pending = $wpdb->get_var(
            "SELECT MIN(created_at) FROM {$this->table_name} WHERE status = 'pending'"
        );
        
        // Get processing webhooks older than 1 minute
        $processing_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} 
             WHERE status = 'processing' 
             AND updated_at < %s",
            date('Y-m-d H:i:s', strtotime('-1 minute'))
        ));
        
        return [
            'stats' => $stats,
            'stuck_webhooks' => $stuck_webhooks,
            'stuck_count' => count($stuck_webhooks),
            'oldest_pending' => $oldest_pending,
            'processing_too_long' => (int) $processing_count,
            'health_status' => count($stuck_webhooks) > 0 ? 'unhealthy' : 'healthy',
            'timestamp' => current_time('mysql')
        ];
    }
    
    /**
     * Force process specific webhook (for troubleshooting)
     */
    public function forceProcessWebhookById(string $webhook_id): array {
        global $wpdb;
        
        $webhook = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE webhook_id = %s",
            $webhook_id
        ));
        
        if (!$webhook) {
            return [
                'success' => false,
                'error' => 'Webhook not found'
            ];
        }
        
        // Reset to pending if stuck
        if ($webhook->status === 'processing') {
            $wpdb->update(
                $this->table_name,
                ['status' => 'pending', 'updated_at' => current_time('mysql')],
                ['webhook_id' => $webhook_id],
                ['%s', '%s'],
                ['%s']
            );
        }
        
        // Process the webhook
        $result = $this->processWebhook($webhook);
        
        if ($result['success']) {
            $this->markAsProcessed($webhook->id);
            $this->logger->info('Webhook force processed successfully', [
                'webhook_id' => $webhook_id,
                'group' => $webhook->group_name,
                'action' => $webhook->action
            ]);
        } else {
            $this->handleProcessingFailure($webhook->id, $result['error']);
        }
        
        return $result;
    }
    
    /**
     * Enhanced queue processing with stuck webhook detection
     */
    public function processQueuedWebhooksWithTimeout(int $batchSize = 20): array {
        // First, reset any stuck webhooks
        $reset_count = $this->resetStuckWebhooks();
        
        // Then process the queue normally
        $results = $this->processQueuedWebhooks($batchSize);
        
        // Add reset count to results
        $results['reset_stuck'] = $reset_count;
        
        return $results;
    }
}