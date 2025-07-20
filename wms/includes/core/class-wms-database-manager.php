<?php
/**
 * WMS Database Manager
 * 
 * Handles all database operations for the WMS integration plugin
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Database_Manager {
    
    /**
     * Create all required database tables
     */
    public static function createTables(): void {
        self::createQueueTable();
        self::createLoggingTables();
        self::createWebhookIdsTable();
        self::createProductSyncQueueTable();
        self::createWebhookProcessingQueueTable();
        self::createSyncJobsTable(); // NEW: Sync jobs table
    }
    
    /**
     * Create main queue table
     */
    private static function createQueueTable(): void {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wc_wms_integration_queue';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            order_id bigint(20) NOT NULL,
            action varchar(50) NOT NULL,
            status varchar(20) DEFAULT 'pending',
            attempts int(11) DEFAULT 0,
            last_attempt datetime DEFAULT NULL,
            next_attempt datetime DEFAULT NULL,
            error_message text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY status (status),
            KEY next_attempt (next_attempt)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Create logging tables
     */
    private static function createLoggingTables(): void {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // API logs table
        $api_logs_table = $wpdb->prefix . 'wc_wms_api_logs';
        $api_logs_sql = "CREATE TABLE IF NOT EXISTS $api_logs_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            method varchar(10) NOT NULL,
            endpoint varchar(255) NOT NULL,
            request_data longtext,
            response_data longtext,
            error_message text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY method (method),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        // Webhook logs table
        $webhook_logs_table = $wpdb->prefix . 'wc_wms_webhook_logs';
        $webhook_logs_sql = "CREATE TABLE IF NOT EXISTS $webhook_logs_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            webhook_type varchar(50) NOT NULL,
            payload longtext,
            processed tinyint(1) DEFAULT 0,
            error_message text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY webhook_type (webhook_type),
            KEY processed (processed),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($api_logs_sql);
        dbDelta($webhook_logs_sql);
    }
    
    /**
     * Create webhook IDs table for duplicate detection
     */
    private static function createWebhookIdsTable(): void {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wc_wms_webhook_ids';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            webhook_id varchar(255) NOT NULL,
            processed_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY webhook_id (webhook_id),
            KEY processed_at (processed_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Create product sync queue table
     */
    private static function createProductSyncQueueTable(): void {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wc_wms_product_sync_queue';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            product_id bigint(20) NOT NULL,
            status varchar(20) DEFAULT 'pending',
            attempts int(11) DEFAULT 0,
            last_attempt datetime DEFAULT NULL,
            next_attempt datetime DEFAULT NULL,
            error_message text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY status (status),
            KEY next_attempt (next_attempt)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Create webhook processing queue table for ordering webhooks
     */
    private static function createWebhookProcessingQueueTable(): void {
        global $wpdb;
        
        $table_name = $wpdb->prefix . WC_WMS_Constants::TABLE_WEBHOOK_PROCESSING_QUEUE;
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            webhook_id varchar(255) NOT NULL,
            group_name varchar(50) NOT NULL,
            action varchar(50) NOT NULL,
            entity_id varchar(255) NOT NULL,
            external_reference varchar(255) DEFAULT NULL,
            payload longtext NOT NULL,
            priority int(11) NOT NULL DEFAULT 999,
            requires_prerequisite tinyint(1) NOT NULL DEFAULT 0,
            prerequisite_event varchar(100) DEFAULT NULL,
            status enum('pending', 'processing', 'processed', 'failed') NOT NULL DEFAULT 'pending',
            attempts int(11) NOT NULL DEFAULT 0,
            error_message text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            processed_at datetime DEFAULT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY webhook_id (webhook_id),
            KEY entity_id (entity_id),
            KEY external_reference (external_reference),
            KEY status (status),
            KEY priority (priority),
            KEY created_at (created_at),
            KEY group_action (group_name, action)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Create sync jobs table for queue-based sync system
     */
    private static function createSyncJobsTable(): void {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wc_wms_sync_jobs';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            batch_id varchar(50) NOT NULL,
            job_type varchar(50) NOT NULL,
            priority int(11) NOT NULL DEFAULT 0,
            status enum('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
            result_data longtext,
            error_message text,
            created_at datetime NOT NULL,
            started_at datetime NULL,
            completed_at datetime NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY batch_id (batch_id),
            KEY status (status),
            KEY priority (priority),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Clean up database tables on plugin deactivation
     */
    public static function cleanup(): void {
        // We don't drop tables on deactivation to preserve data
        // This is just a placeholder for future cleanup operations
        self::cleanupOldData();
    }
    
    /**
     * Clean up old data
     */
    private static function cleanupOldData(): void {
        global $wpdb;
        
        // Clean up old queue items
        $queue_table = $wpdb->prefix . 'wc_wms_integration_queue';
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $queue_table WHERE status = 'completed' AND updated_at < %s",
            date('Y-m-d H:i:s', strtotime('-7 days'))
        ));
        
        // Clean up old webhook processing queue items
        $webhook_queue_table = $wpdb->prefix . WC_WMS_Constants::TABLE_WEBHOOK_PROCESSING_QUEUE;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $webhook_queue_table WHERE status = 'processed' AND processed_at < %s",
            date('Y-m-d H:i:s', strtotime('-7 days'))
        ));
        
        // Clean up old logs
        $api_logs_table = $wpdb->prefix . 'wc_wms_api_logs';
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $api_logs_table WHERE created_at < %s",
            date('Y-m-d H:i:s', strtotime('-30 days'))
        ));
        
        $webhook_logs_table = $wpdb->prefix . 'wc_wms_webhook_logs';
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $webhook_logs_table WHERE created_at < %s",
            date('Y-m-d H:i:s', strtotime('-30 days'))
        ));
        
        // Clean up old sync jobs
        $sync_jobs_table = $wpdb->prefix . 'wc_wms_sync_jobs';
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $sync_jobs_table WHERE status IN ('completed', 'failed') AND completed_at < %s",
            date('Y-m-d H:i:s', strtotime('-7 days'))
        ));
    }
}
