<?php
/**
 * WMS Queue Manager
 * 
 * Handles database operations for the WMS integration queue
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Queue_Manager {
    
    /**
     * Order queue table name
     */
    private $order_table;
    
    /**
     * Product queue table name  
     */
    private $product_table;
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->order_table = $wpdb->prefix . 'wc_wms_integration_queue';
        $this->product_table = $wpdb->prefix . 'wc_wms_product_sync_queue';
    }
    
    /**
     * Enqueue order for processing
     */
    public function enqueueOrder(int $orderId, string $action = 'export', int $priority = 0): bool {
        global $wpdb;
        
        // Check if already queued
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->order_table} WHERE order_id = %d AND action = %s AND status IN ('pending', 'processing')",
            $orderId,
            $action
        ));
        
        if ($existing) {
            return false; // Already queued
        }
        
        $result = $wpdb->insert(
            $this->order_table,
            [
                'order_id' => $orderId,
                'action' => $action,
                'status' => 'pending',
                'attempts' => 0,
                'next_attempt' => current_time('mysql'),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ],
            ['%d', '%s', '%s', '%d', '%s', '%s', '%s']
        );
        
        return $result !== false;
    }
    
    /**
     * Enqueue product for processing
     */
    public function enqueueProduct(int $productId, string $action = 'sync', int $priority = 0): bool {
        global $wpdb;
        
        // Check if already queued
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->product_table} WHERE product_id = %d AND status IN ('pending', 'processing')",
            $productId
        ));
        
        if ($existing) {
            return false; // Already queued
        }
        
        $result = $wpdb->insert(
            $this->product_table,
            [
                'product_id' => $productId,
                'status' => 'pending',
                'attempts' => 0,
                'next_attempt' => current_time('mysql'),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ],
            ['%d', '%s', '%d', '%s', '%s', '%s']
        );
        
        return $result !== false;
    }
    
    /**
     * Get pending items from queue
     */
    public function getPendingItems(string $type = 'order', int $limit = 10): array {
        global $wpdb;
        
        $table = ($type === 'order') ? $this->order_table : $this->product_table;
        $id_field = ($type === 'order') ? 'order_id' : 'product_id';
        
        $query = $wpdb->prepare(
            "SELECT * FROM {$table} 
             WHERE status = 'pending' 
             AND next_attempt <= %s 
             ORDER BY created_at ASC 
             LIMIT %d",
            current_time('mysql'),
            $limit
        );
        
        $results = $wpdb->get_results($query, ARRAY_A);
        
        return $results ?: [];
    }
    
    /**
     * Mark item as processing
     */
    public function markAsProcessing(int $itemId, string $type = 'order'): bool {
        global $wpdb;
        
        $table = ($type === 'order') ? $this->order_table : $this->product_table;
        
        $result = $wpdb->update(
            $table,
            [
                'status' => 'processing',
                'updated_at' => current_time('mysql')
            ],
            ['id' => $itemId],
            ['%s', '%s'],
            ['%d']
        );
        
        return $result !== false;
    }
    
    /**
     * Mark item as completed
     */
    public function markAsCompleted(int $itemId, string $type = 'order'): bool {
        global $wpdb;
        
        $table = ($type === 'order') ? $this->order_table : $this->product_table;
        
        $result = $wpdb->update(
            $table,
            [
                'status' => 'completed',
                'updated_at' => current_time('mysql')
            ],
            ['id' => $itemId],
            ['%s', '%s'],
            ['%d']
        );
        
        return $result !== false;
    }
    
    /**
     * Mark item as failed or retry
     */
    public function markAsFailedOrRetry(int $itemId, string $errorMessage, string $type = 'order'): bool {
        global $wpdb;
        
        $table = ($type === 'order') ? $this->order_table : $this->product_table;
        
        // Get current attempts
        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT attempts FROM {$table} WHERE id = %d",
            $itemId
        ));
        
        if (!$item) {
            return false;
        }
        
        $attempts = intval($item->attempts) + 1;
        $maxAttempts = 5;
        
        if ($attempts >= $maxAttempts) {
            // Mark as failed
            $result = $wpdb->update(
                $table,
                [
                    'status' => 'failed',
                    'attempts' => $attempts,
                    'error_message' => $errorMessage,
                    'updated_at' => current_time('mysql')
                ],
                ['id' => $itemId],
                ['%s', '%d', '%s', '%s'],
                ['%d']
            );
        } else {
            // Mark for retry with exponential backoff
            $nextAttempt = strtotime('+' . (pow(2, $attempts)) . ' minutes');
            
            $result = $wpdb->update(
                $table,
                [
                    'status' => 'pending',
                    'attempts' => $attempts,
                    'error_message' => $errorMessage,
                    'next_attempt' => date('Y-m-d H:i:s', $nextAttempt),
                    'updated_at' => current_time('mysql')
                ],
                ['id' => $itemId],
                ['%s', '%d', '%s', '%s', '%s'],
                ['%d']
            );
        }
        
        return $result !== false;
    }
    
    /**
     * Get queue statistics
     */
    public function getStats(string $type = 'order'): array {
        global $wpdb;
        
        $table = ($type === 'order') ? $this->order_table : $this->product_table;
        
        $stats = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM {$table} GROUP BY status",
            ARRAY_A
        );
        
        $result = [
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0,
            'total' => 0
        ];
        
        foreach ($stats as $stat) {
            $result[$stat['status']] = intval($stat['count']);
            $result['total'] += intval($stat['count']);
        }
        
        return $result;
    }
    
    /**
     * Get recent activity
     */
    public function getRecentActivity(int $limit = 20): array {
        global $wpdb;
        
        $order_activity = $wpdb->get_results($wpdb->prepare(
            "SELECT 'order' as type, order_id as item_id, action, status, attempts, error_message, updated_at 
             FROM {$this->order_table} 
             ORDER BY updated_at DESC 
             LIMIT %d",
            $limit
        ), ARRAY_A);
        
        $product_activity = $wpdb->get_results($wpdb->prepare(
            "SELECT 'product' as type, product_id as item_id, 'sync' as action, status, attempts, error_message, updated_at 
             FROM {$this->product_table} 
             ORDER BY updated_at DESC 
             LIMIT %d",
            $limit
        ), ARRAY_A);
        
        $activity = array_merge($order_activity ?: [], $product_activity ?: []);
        
        // Sort by updated_at
        usort($activity, function($a, $b) {
            return strtotime($b['updated_at']) - strtotime($a['updated_at']);
        });
        
        return array_slice($activity, 0, $limit);
    }
    
    /**
     * Retry failed items
     */
    public function retryFailedItems(string $type = 'order'): array {
        global $wpdb;
        
        $table = ($type === 'order') ? $this->order_table : $this->product_table;
        
        $updated = $wpdb->query(
            "UPDATE {$table} 
             SET status = 'pending', 
                 next_attempt = NOW(), 
                 updated_at = NOW() 
             WHERE status = 'failed'"
        );
        
        return [
            'type' => $type,
            'items_retried' => $updated ?: 0
        ];
    }
    
    /**
     * Force process specific item
     */
    public function forceProcessItem(int $itemId, string $type = 'order'): bool {
        global $wpdb;
        
        $table = ($type === 'order') ? $this->order_table : $this->product_table;
        
        $result = $wpdb->update(
            $table,
            [
                'status' => 'pending',
                'next_attempt' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ],
            ['id' => $itemId],
            ['%s', '%s', '%s'],
            ['%d']
        );
        
        return $result !== false;
    }
    
    /**
     * Clean up old items
     */
    public function cleanup(): array {
        global $wpdb;
        
        // Delete completed items older than 7 days
        $deleted_order_completed = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->order_table} WHERE status = 'completed' AND updated_at < %s",
            date('Y-m-d H:i:s', strtotime('-7 days'))
        ));
        
        $deleted_product_completed = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->product_table} WHERE status = 'completed' AND updated_at < %s",
            date('Y-m-d H:i:s', strtotime('-7 days'))
        ));
        
        // Delete failed items older than 30 days
        $deleted_order_failed = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->order_table} WHERE status = 'failed' AND updated_at < %s",
            date('Y-m-d H:i:s', strtotime('-30 days'))
        ));
        
        $deleted_product_failed = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->product_table} WHERE status = 'failed' AND updated_at < %s",
            date('Y-m-d H:i:s', strtotime('-30 days'))
        ));
        
        return [
            'order_completed' => $deleted_order_completed ?: 0,
            'order_failed' => $deleted_order_failed ?: 0,
            'product_completed' => $deleted_product_completed ?: 0,
            'product_failed' => $deleted_product_failed ?: 0
        ];
    }
}