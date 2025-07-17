<?php
/**
 * WMS Integration Logger
 * 
 * Handles logging for all WMS integration operations
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Logger {
    
    /**
     * Log levels
     */
    const LEVEL_DEBUG = 'debug';
    const LEVEL_INFO = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR = 'error';
    const LEVEL_CRITICAL = 'critical';
    
    /**
     * Logger instance
     */
    private static $instance = null;
    
    /**
     * WooCommerce logger instance
     */
    private $logger;
    
    /**
     * Log context
     */
    private $context = 'wc-wms-integration';
    
    /**
     * Get instance
     */
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        if (class_exists('WC_Logger')) {
            $this->logger = wc_get_logger();
        }
    }
    
    /**
     * Log debug message
     */
    public function debug($message, $context = []) {
        $this->log(self::LEVEL_DEBUG, $message, $context);
    }
    
    /**
     * Log info message
     */
    public function info($message, $context = []) {
        $this->log(self::LEVEL_INFO, $message, $context);
    }
    
    /**
     * Log warning message
     */
    public function warning($message, $context = []) {
        $this->log(self::LEVEL_WARNING, $message, $context);
    }
    
    /**
     * Log error message
     */
    public function error($message, $context = []) {
        $this->log(self::LEVEL_ERROR, $message, $context);
    }
    
    /**
     * Log critical message
     */
    public function critical($message, $context = []) {
        $this->log(self::LEVEL_CRITICAL, $message, $context);
    }
    
    /**
     * Log API request
     */
    public function log_api_request($method, $endpoint, $data = null, $response = null, $error = null) {
        $log_data = [
            'type' => 'api_request',
            'method' => $method,
            'endpoint' => $endpoint,
            'request_data' => $data,
            'response' => $response,
            'error' => $error,
            'timestamp' => current_time('mysql')
        ];
        
        if ($error) {
            $this->error('API Request Failed', $log_data);
        } else {
            $this->info('API Request Success', $log_data);
        }
        
        // Also store in database for admin interface
        $this->store_api_log($log_data);
    }
    
    /**
     * Log webhook received
     */
    public function log_webhook($type, $data, $processed = false, $error = null) {
        $log_data = [
            'type' => 'webhook',
            'webhook_type' => $type,
            'data' => $data,
            'processed' => $processed,
            'error' => $error,
            'timestamp' => current_time('mysql')
        ];
        
        if ($error) {
            $this->error('Webhook Processing Failed', $log_data);
        } else {
            $this->info('Webhook Processed', $log_data);
        }
        
        // Store in database
        $this->store_webhook_log($log_data);
    }
    
    /**
     * Log order processing
     */
    public function log_order_processing($order_id, $action, $success = true, $details = []) {
        $log_data = [
            'type' => 'order_processing',
            'order_id' => $order_id,
            'action' => $action,
            'success' => $success,
            'details' => $details,
            'timestamp' => current_time('mysql')
        ];
        
        if ($success) {
            $this->info("Order {$action} Success", $log_data);
        } else {
            $this->error("Order {$action} Failed", $log_data);
        }
    }
    
    /**
     * Generic log method
     */
    private function log($level, $message, $context = []) {
        if (!$this->logger) {
            error_log("[WMS-{$level}] {$message} " . json_encode($context));
            return;
        }
        
        // Format message with context
        $formatted_message = $message;
        if (!empty($context)) {
            $formatted_message .= ' | Context: ' . json_encode($context, JSON_PRETTY_PRINT);
        }
        
        // Log to WooCommerce logger
        $this->logger->log($level, $formatted_message, ['source' => $this->context]);
        
        // Also log to PHP error log for debugging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[WMS-{$level}] {$formatted_message}");
        }
    }
    
    /**
     * Store API log in database
     */
    private function store_api_log($data) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wc_wms_api_logs';
        
        // Create table if it doesn't exist
        $this->maybe_create_api_logs_table();
        
        $wpdb->insert(
            $table_name,
            [
                'method' => $data['method'],
                'endpoint' => $data['endpoint'],
                'request_data' => json_encode($data['request_data']),
                'response_data' => json_encode($data['response']),
                'error_message' => $data['error'],
                'created_at' => $data['timestamp']
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s']
        );
    }
    
    /**
     * Store webhook log in database
     */
    private function store_webhook_log($data) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wc_wms_webhook_logs';
        
        // Create table if it doesn't exist
        $this->maybe_create_webhook_logs_table();
        
        $wpdb->insert(
            $table_name,
            [
                'webhook_type' => $data['webhook_type'],
                'payload' => json_encode($data['data']),
                'processed' => $data['processed'] ? 1 : 0,
                'error_message' => $data['error'],
                'created_at' => $data['timestamp']
            ],
            ['%s', '%s', '%d', '%s', '%s']
        );
    }
    
    /**
     * Create API logs table
     */
    private function maybe_create_api_logs_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wc_wms_api_logs';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
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
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Create webhook logs table
     */
    private function maybe_create_webhook_logs_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wc_wms_webhook_logs';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
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
        dbDelta($sql);
    }
    
    /**
     * Get recent logs for admin interface
     */
    public function get_recent_logs($type = 'all', $limit = 50) {
        global $wpdb;
        
        if ($type === 'api' || $type === 'all') {
            $api_table = $wpdb->prefix . 'wc_wms_api_logs';
            $api_logs = $wpdb->get_results($wpdb->prepare(
                "SELECT *, 'api' as log_type FROM $api_table ORDER BY created_at DESC LIMIT %d",
                $limit
            ));
        }
        
        if ($type === 'webhook' || $type === 'all') {
            $webhook_table = $wpdb->prefix . 'wc_wms_webhook_logs';
            $webhook_logs = $wpdb->get_results($wpdb->prepare(
                "SELECT *, 'webhook' as log_type FROM $webhook_table ORDER BY created_at DESC LIMIT %d",
                $limit
            ));
        }
        
        $logs = [];
        if (isset($api_logs)) $logs = array_merge($logs, $api_logs);
        if (isset($webhook_logs)) $logs = array_merge($logs, $webhook_logs);
        
        // Sort by created_at
        usort($logs, function($a, $b) {
            return strtotime($b->created_at) - strtotime($a->created_at);
        });
        
        return array_slice($logs, 0, $limit);
    }
}
