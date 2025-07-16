<?php
/**
 * WMS Webhook Validator
 * 
 * Handles webhook signature validation and duplicate detection
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Webhook_Validator {
    
    /**
     * Logger instance
     */
    private $logger;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->logger = WC_WMS_Logger::instance();
    }
    
    /**
     * Validate webhook signature per eWarehousing specs
     */
    public function validate_signature($request) {
        $webhook_secret = get_option('wc_wms_integration_webhook_secret', '');
        
        if (empty($webhook_secret)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $this->logger->warning('Webhook received without secret validation (development mode)');
                return true;
            }
            $this->logger->error('Webhook secret not configured');
            return false;
        }
        
        $signature_header = $request->get_header('X-Hmac-Sha256');
        
        if (empty($signature_header)) {
            $this->logger->error('Webhook signature header missing');
            return false;
        }
        
        $body = $request->get_body();
        if (empty($body)) {
            $this->logger->error('Webhook body is empty');
            return false;
        }
        
        $expected_signature = $this->calculate_signature($body, $webhook_secret);
        
        if (!hash_equals($expected_signature, $signature_header)) {
            $this->logger->error('Webhook signature validation failed');
            return false;
        }
        
        return true;
    }
    
    /**
     * Validate webhook signature with body and signature strings
     */
    public function validate_signature_direct(string $body, string $signature): bool {
        $webhook_secret = get_option('wc_wms_integration_webhook_secret', '');
        
        if (empty($webhook_secret)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $this->logger->warning('Webhook signature validation skipped (no secret configured)');
                return true;
            }
            return false;
        }
        
        if (empty($signature)) {
            return false;
        }
        
        $expected_signature = $this->calculate_signature($body, $webhook_secret);
        
        return hash_equals($expected_signature, $signature);
    }
    
    /**
     * Calculate webhook signature per eWarehousing specs
     * Format: base64-encoded HMAC-SHA256
     */
    private function calculate_signature($body, $secret) {
        $digest = hash_hmac('sha256', $body, $secret, true);
        return base64_encode($digest);
    }
    
    /**
     * Check for duplicate webhooks using X-Webhook-Id header
     */
    public function is_duplicate($webhook_id) {
        if (empty($webhook_id)) {
            return false;
        }
        
        global $wpdb;
        
        $table_name = $wpdb->prefix . WC_WMS_Constants::TABLE_WEBHOOK_IDS;
        $this->ensure_webhook_ids_table();
        
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE webhook_id = %s",
            $webhook_id
        ));
        
        return $count > 0;
    }
    
    /**
     * Store webhook ID to prevent duplicate processing
     */
    public function store_webhook_id($webhook_id) {
        if (empty($webhook_id)) {
            return;
        }
        
        global $wpdb;
        
        $table_name = $wpdb->prefix . WC_WMS_Constants::TABLE_WEBHOOK_IDS;
        
        $wpdb->insert(
            $table_name,
            [
                'webhook_id' => $webhook_id,
                'processed_at' => current_time('mysql')
            ],
            ['%s', '%s']
        );
    }
    
    /**
     * Ensure webhook IDs table exists
     */
    private function ensure_webhook_ids_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . WC_WMS_Constants::TABLE_WEBHOOK_IDS;
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
     * Clean up old webhook IDs (called by cron)
     */
    public function cleanup_old_webhook_ids($days = 30) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . WC_WMS_Constants::TABLE_WEBHOOK_IDS;
        
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM $table_name WHERE processed_at < %s",
            date('Y-m-d H:i:s', strtotime("-{$days} days"))
        ));
        
        if ($deleted > 0) {
            $this->logger->info("Cleaned up {$deleted} old webhook IDs");
        }
        
        return $deleted;
    }
    
    /**
     * Get webhook ID statistics
     */
    public function get_webhook_id_stats(): array {
        global $wpdb;
        
        $table_name = $wpdb->prefix . WC_WMS_Constants::TABLE_WEBHOOK_IDS;
        $this->ensure_webhook_ids_table();
        
        $total_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        $recent_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE processed_at >= %s",
            date('Y-m-d H:i:s', strtotime('-24 hours'))
        ));
        $oldest_record = $wpdb->get_var("SELECT MIN(processed_at) FROM $table_name");
        
        return [
            'total_processed' => intval($total_count),
            'recent_24h' => intval($recent_count),
            'oldest_record' => $oldest_record,
            'table_size_mb' => $this->get_table_size_mb($table_name)
        ];
    }
    
    /**
     * Get table size in MB
     */
    private function get_table_size_mb(string $table_name): float {
        global $wpdb;
        
        $query = $wpdb->prepare(
            "SELECT (data_length + index_length) / 1024 / 1024 AS size_mb 
             FROM information_schema.tables 
             WHERE table_schema = %s AND table_name = %s",
            DB_NAME,
            $table_name
        );
        
        $size = $wpdb->get_var($query);
        return $size ? round(floatval($size), 2) : 0;
    }
}
