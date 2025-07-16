<?php
/**
 * WMS Logging Service
 * 
 * Centralized logging service with structured logging and performance monitoring
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Logging_Service {
    
    /**
     * WooCommerce logger instance
     */
    private $wcLogger;
    
    /**
     * Database instance
     */
    private $wpdb;
    
    /**
     * Log context
     */
    private $context = [];
    
    /**
     * Performance tracking
     */
    private $performanceData = [];
    
    /**
     * Log levels
     */
    const LEVEL_DEBUG = 'debug';
    const LEVEL_INFO = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR = 'error';
    const LEVEL_CRITICAL = 'critical';
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->wcLogger = wc_get_logger();
        $this->context = [
            'source' => 'wc-wms-integration',
            'version' => WC_WMS_INTEGRATION_VERSION,
            'timestamp' => current_time('mysql'),
            'request_id' => $this->generateRequestId()
        ];
    }
    
    /**
     * Log debug message
     */
    public function debug(string $message, array $context = []): void {
        $this->log(self::LEVEL_DEBUG, $message, $context);
    }
    
    /**
     * Log info message
     */
    public function info(string $message, array $context = []): void {
        $this->log(self::LEVEL_INFO, $message, $context);
    }
    
    /**
     * Log warning message
     */
    public function warning(string $message, array $context = []): void {
        $this->log(self::LEVEL_WARNING, $message, $context);
    }
    
    /**
     * Log error message
     */
    public function error(string $message, array $context = []): void {
        $this->log(self::LEVEL_ERROR, $message, $context);
    }
    
    /**
     * Log critical message
     */
    public function critical(string $message, array $context = []): void {
        $this->log(self::LEVEL_CRITICAL, $message, $context);
    }
    
    /**
     * Main logging method
     */
    public function log(string $level, string $message, array $context = []): void {
        $fullContext = array_merge($this->context, $context, [
            'level' => $level,
            'timestamp' => current_time('mysql'),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        ]);
        
        // Log to WooCommerce logger
        $this->wcLogger->log($level, $message, $fullContext);
        
        // Log to database if configured
        if ($this->shouldLogToDatabase($level)) {
            $this->logToDatabase($level, $message, $fullContext);
        }
        
        // Log to file if in debug mode
        if (defined('WC_WMS_DEBUG') && WC_WMS_DEBUG) {
            $this->logToFile($level, $message, $fullContext);
        }
        
        // Handle critical errors
        if ($level === self::LEVEL_CRITICAL) {
            $this->handleCriticalError($message, $fullContext);
        }
    }
    
    /**
     * Log API request/response
     */
    public function logApiCall(string $method, string $endpoint, array $requestData = null, $response = null, float $executionTime = null, string $error = null): void {
        $logData = [
            'method' => $method,
            'endpoint' => $endpoint,
            'execution_time' => $executionTime,
            'timestamp' => current_time('mysql'),
            'request_id' => $this->context['request_id']
        ];
        
        if ($requestData !== null) {
            $logData['request_data'] = $this->sanitizeLogData($requestData);
        }
        
        if ($response !== null) {
            $logData['response_data'] = $this->sanitizeLogData($response);
        }
        
        if ($error !== null) {
            $logData['error_message'] = $error;
            $this->error('API call failed', $logData);
        } else {
            $this->info('API call completed', $logData);
        }
        
        // Store in API logs table
        $this->storeApiLog($logData);
        
        // Track performance
        if ($executionTime !== null) {
            $this->trackPerformance('api_call', $executionTime, $endpoint);
        }
    }
    
    /**
     * Log webhook processing
     */
    public function logWebhook(string $webhookType, array $payload, string $webhookId = null, bool $processed = false, string $error = null): void {
        $logData = [
            'webhook_type' => $webhookType,
            'webhook_id' => $webhookId,
            'processed' => $processed,
            'timestamp' => current_time('mysql'),
            'request_id' => $this->context['request_id']
        ];
        
        if ($payload) {
            $logData['payload'] = $this->sanitizeLogData($payload);
        }
        
        if ($error !== null) {
            $logData['error_message'] = $error;
            $this->error('Webhook processing failed', $logData);
        } else {
            $this->info('Webhook processed', $logData);
        }
        
        // Store in webhook logs table
        $this->storeWebhookLog($logData);
    }
    
    /**
     * Log order processing
     */
    public function logOrderProcessing(int $orderId, string $stage, bool $success = true, array $additionalData = []): void {
        $order = wc_get_order($orderId);
        $logData = array_merge([
            'order_id' => $orderId,
            'order_number' => $order ? $order->get_order_number() : null,
            'stage' => $stage,
            'success' => $success,
            'timestamp' => current_time('mysql')
        ], $additionalData);
        
        if ($success) {
            $this->info("Order processing: {$stage}", $logData);
        } else {
            $this->error("Order processing failed: {$stage}", $logData);
        }
    }
    
    /**
     * Track performance metrics
     */
    public function trackPerformance(string $operation, float $executionTime, string $context = ''): void {
        $key = $operation . ($context ? ":{$context}" : '');
        
        if (!isset($this->performanceData[$key])) {
            $this->performanceData[$key] = [
                'count' => 0,
                'total_time' => 0,
                'min_time' => PHP_FLOAT_MAX,
                'max_time' => 0,
                'avg_time' => 0
            ];
        }
        
        $data = &$this->performanceData[$key];
        $data['count']++;
        $data['total_time'] += $executionTime;
        $data['min_time'] = min($data['min_time'], $executionTime);
        $data['max_time'] = max($data['max_time'], $executionTime);
        $data['avg_time'] = $data['total_time'] / $data['count'];
        
        // Log slow operations
        $slowThreshold = 5.0; // 5 seconds
        if ($executionTime > $slowThreshold) {
            $this->warning('Slow operation detected', [
                'operation' => $operation,
                'context' => $context,
                'execution_time' => $executionTime,
                'threshold' => $slowThreshold
            ]);
        }
    }
    
    /**
     * Get performance statistics
     */
    public function getPerformanceStats(): array {
        return $this->performanceData;
    }
    
    /**
     * Start performance timing
     */
    public function startTiming(string $operation): string {
        $timingId = uniqid($operation . '_');
        $this->performanceData[$timingId] = microtime(true);
        return $timingId;
    }
    
    /**
     * End performance timing
     */
    public function endTiming(string $timingId, string $context = ''): float {
        if (!isset($this->performanceData[$timingId])) {
            return 0.0;
        }
        
        $startTime = $this->performanceData[$timingId];
        $executionTime = microtime(true) - $startTime;
        unset($this->performanceData[$timingId]);
        
        // Extract operation name from timing ID
        $operation = substr($timingId, 0, strrpos($timingId, '_'));
        $this->trackPerformance($operation, $executionTime, $context);
        
        return $executionTime;
    }
    
    /**
     * Set additional context for all logs
     */
    public function setContext(array $context): void {
        $this->context = array_merge($this->context, $context);
    }
    
    /**
     * Clear additional context
     */
    public function clearContext(): void {
        $this->context = [
            'source' => 'wc-wms-integration',
            'version' => WC_WMS_INTEGRATION_VERSION,
            'timestamp' => current_time('mysql'),
            'request_id' => $this->generateRequestId()
        ];
    }
    
    /**
     * Get recent logs
     */
    public function getRecentLogs(int $limit = 100, string $level = null): array {
        $table = $this->wpdb->prefix . WC_WMS_Constants::TABLE_API_LOGS;
        
        $where = '';
        $params = [];
        
        if ($level) {
            $where = 'WHERE level = %s';
            $params[] = $level;
        }
        
        $params[] = $limit;
        
        $sql = "SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT %d";
        
        return $this->wpdb->get_results(
            $this->wpdb->prepare($sql, ...$params),
            ARRAY_A
        );
    }
    
    /**
     * Clean up old logs
     */
    public function cleanup(): array {
        $cutoffDate = date('Y-m-d H:i:s', strtotime('-' . WC_WMS_Constants::CLEANUP_LOGS_DAYS . ' days'));
        
        // Clean API logs
        $apiTable = $this->wpdb->prefix . WC_WMS_Constants::TABLE_API_LOGS;
        $apiDeleted = $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$apiTable} WHERE created_at < %s",
            $cutoffDate
        ));
        
        // Clean webhook logs
        $webhookTable = $this->wpdb->prefix . WC_WMS_Constants::TABLE_WEBHOOK_LOGS;
        $webhookDeleted = $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$webhookTable} WHERE created_at < %s",
            $cutoffDate
        ));
        
        $result = [
            'api_logs_deleted' => $apiDeleted ?: 0,
            'webhook_logs_deleted' => $webhookDeleted ?: 0
        ];
        
        $this->info('Log cleanup completed', $result);
        
        return $result;
    }
    
    /**
     * Store API log in database
     */
    private function storeApiLog(array $logData): void {
        $table = $this->wpdb->prefix . WC_WMS_Constants::TABLE_API_LOGS;
        
        $this->wpdb->insert(
            $table,
            [
                'method' => $logData['method'],
                'endpoint' => $logData['endpoint'],
                'request_data' => isset($logData['request_data']) ? json_encode($logData['request_data']) : null,
                'response_data' => isset($logData['response_data']) ? json_encode($logData['response_data']) : null,
                'error_message' => $logData['error_message'] ?? null,
                'execution_time' => $logData['execution_time'],
                'created_at' => $logData['timestamp']
            ],
            ['%s', '%s', '%s', '%s', '%s', '%f', '%s']
        );
    }
    
    /**
     * Store webhook log in database
     */
    private function storeWebhookLog(array $logData): void {
        $table = $this->wpdb->prefix . WC_WMS_Constants::TABLE_WEBHOOK_LOGS;
        
        $this->wpdb->insert(
            $table,
            [
                'webhook_type' => $logData['webhook_type'],
                'webhook_id' => $logData['webhook_id'] ?? null,
                'payload' => isset($logData['payload']) ? json_encode($logData['payload']) : null,
                'processed' => $logData['processed'] ? 1 : 0,
                'error_message' => $logData['error_message'] ?? null,
                'created_at' => $logData['timestamp']
            ],
            ['%s', '%s', '%s', '%d', '%s', '%s']
        );
    }
    
    /**
     * Check if should log to database
     */
    private function shouldLogToDatabase(string $level): bool {
        $databaseLevels = ['error', 'critical'];
        return in_array($level, $databaseLevels) || (defined('WC_WMS_LOG_ALL') && WC_WMS_LOG_ALL);
    }
    
    /**
     * Log to database
     */
    private function logToDatabase(string $level, string $message, array $context): void {
        // Critical errors are stored in the API logs table for immediate visibility
        if ($level === self::LEVEL_CRITICAL) {
            $table = $this->wpdb->prefix . WC_WMS_Constants::TABLE_API_LOGS;
            
            $this->wpdb->insert(
                $table,
                [
                    'method' => 'CRITICAL',
                    'endpoint' => 'system',
                    'error_message' => $message,
                    'request_data' => json_encode($context),
                    'created_at' => current_time('mysql')
                ],
                ['%s', '%s', '%s', '%s', '%s']
            );
        }
    }
    
    /**
     * Log to file
     */
    private function logToFile(string $level, string $message, array $context): void {
        $logDir = WP_CONTENT_DIR . '/uploads/wc-wms-logs/';
        if (!is_dir($logDir)) {
            wp_mkdir_p($logDir);
        }
        
        $logFile = $logDir . date('Y-m-d') . '.log';
        $timestamp = current_time('Y-m-d H:i:s');
        $requestId = $context['request_id'] ?? 'unknown';
        
        $logEntry = sprintf(
            "[%s] [%s] [%s] %s %s\n",
            $timestamp,
            strtoupper($level),
            $requestId,
            $message,
            !empty($context) ? json_encode($context, JSON_UNESCAPED_SLASHES) : ''
        );
        
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Handle critical errors
     */
    private function handleCriticalError(string $message, array $context): void {
        // Could send email notifications, Slack alerts, etc.
        $adminEmail = get_option('admin_email');
        $siteName = get_bloginfo('name');
        
        $subject = "WMS Integration Critical Error - {$siteName}";
        $body = "A critical error occurred in the WMS integration:\n\n";
        $body .= "Message: {$message}\n";
        $body .= "Time: " . current_time('Y-m-d H:i:s') . "\n";
        $body .= "Context: " . json_encode($context, JSON_PRETTY_PRINT) . "\n";
        
        wp_mail($adminEmail, $subject, $body);
    }
    
    /**
     * Sanitize log data for storage
     */
    private function sanitizeLogData($data): array {
        if (!is_array($data)) {
            return ['data' => $data];
        }
        
        $sensitiveKeys = [
            'password', 'token', 'secret', 'key', 'auth', 'authorization',
            'credit_card', 'ssn', 'social_security'
        ];
        
        return $this->recursiveSanitize($data, $sensitiveKeys);
    }
    
    /**
     * Recursively sanitize sensitive data
     */
    private function recursiveSanitize(array $data, array $sensitiveKeys): array {
        foreach ($data as $key => $value) {
            $lowerKey = strtolower($key);
            
            // Check if key contains sensitive information
            foreach ($sensitiveKeys as $sensitiveKey) {
                if (strpos($lowerKey, $sensitiveKey) !== false) {
                    $data[$key] = '[REDACTED]';
                    continue 2;
                }
            }
            
            // Recursively sanitize arrays
            if (is_array($value)) {
                $data[$key] = $this->recursiveSanitize($value, $sensitiveKeys);
            }
        }
        
        return $data;
    }
    
    /**
     * Generate unique request ID
     */
    private function generateRequestId(): string {
        return uniqid('wms_', true);
    }
}