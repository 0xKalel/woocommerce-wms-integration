<?php
/**
 * WMS Webhook Integrator
 * 
 * Handles all webhook processing logic with clean event routing
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Webhook_Integrator implements WC_WMS_Webhook_Integrator_Interface {
    
    /**
     * WMS Client instance
     */
    private $client;
    
    /**
     * Webhook processor factory
     */
    private $processorFactory;
    
    /**
     * Constructor
     */
    public function __construct(WC_WMS_Client $client) {
        $this->client = $client;
        $this->processorFactory = new WC_WMS_Webhook_Processor_Factory($client);
        
        // Initialize REST endpoints
        add_action('rest_api_init', [$this, 'registerRestEndpoints']);
    }
    
    /**
     * Get integrator name (Interface requirement)
     */
    public function getIntegratorName(): string {
        return 'webhook';
    }
    
    /**
     * Check if integrator is ready (Interface requirement)
     */
    public function isReady(): bool {
        try {
            // Check if WMS client is available
            if (!$this->client || !$this->client->authenticator()->isAuthenticated()) {
                return false;
            }
            
            // Check if webhook service is available
            if (!$this->client->webhooks()) {
                return false;
            }
            
            // Check if webhook secret is configured
            $webhookSecret = $this->client->config()->getWebhookSecret();
            if (empty($webhookSecret)) {
                return false;
            }
            
            return true;
            
        } catch (Exception $e) {
            $this->client->logger()->error('Webhook integrator readiness check failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Get integrator status (Interface requirement)
     */
    public function getStatus(): array {
        $status = [
            'name' => $this->getIntegratorName(),
            'ready' => $this->isReady(),
            'webhook_endpoints' => count($this->getWebhookUrls()),
            'recent_webhooks' => 0,
            'webhook_errors' => 0,
            'health_score' => 0,
            'issues' => []
        ];
        
        try {
            // Get webhook configuration status
            $config_status = $this->client->webhooks()->validateWebhookConfiguration();
            $status['configuration_valid'] = $config_status['valid'];
            $status['configuration_issues'] = $config_status['issues'];
            
            // Get webhook registration status
            $registration_status = $this->client->webhooks()->getWebhookStatus();
            $status['webhooks_registered'] = $registration_status['registered_webhooks'];
            $status['all_webhooks_registered'] = $registration_status['all_registered'];
            $status['missing_webhooks'] = $registration_status['missing_events'];
            
            // Calculate health score
            $health_score = 100;
            
            if (!$status['ready']) {
                $health_score -= 50;
                $status['issues'][] = 'Integrator not ready';
            }
            
            if (!$status['configuration_valid']) {
                $health_score -= 30;
                $status['issues'] = array_merge($status['issues'], $status['configuration_issues']);
            }
            
            if (!$status['all_webhooks_registered']) {
                $health_score -= 20;
                $status['issues'][] = 'Not all webhooks registered';
            }
            
            $status['health_score'] = max(0, $health_score);
            
        } catch (Exception $e) {
            $status['issues'][] = 'Failed to get status: ' . $e->getMessage();
            $status['health_score'] = 0;
        }
        
        return $status;
    }
    
    /**
     * Handle webhook request
     * 
     * Note: eWarehousing has a 10-second timeout and retries once per hour for max 10 times
     * Must respond with 200 OK - no redirects are followed
     */
    public function handleWebhook(WP_REST_Request $request): WP_REST_Response {
        $processing_start = microtime(true);
        
        // Extract webhook headers
        $webhook_id = $request->get_header('X-Webhook-Id');
        $webhook_topic = $request->get_header('X-Webhook-Topic');
        $signature = $request->get_header('X-Hmac-Sha256');
        
        // Get webhook payload
        $payload = $request->get_json_params();
        $body = $request->get_body();
        
        // Validate payload format
        if (empty($payload) || !is_array($payload)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'Invalid payload format'
            ], 400);
        }
        
        // Extract eWarehousing webhook format
        $group = $payload['group'] ?? '';
        $action = $payload['action'] ?? '';
        $entity_id = $payload['entityId'] ?? '';
        $entity = $payload['entity'] ?? ''; // e.g., "variant"
        $customer = $payload['customer'] ?? ''; // customer UUID
        $webhook_body = $payload['body'] ?? [];
        
        $event_type = "{$group}.{$action}";
        
        $this->client->logger()->info('Webhook received', [
            'webhook_id' => $webhook_id,
            'webhook_topic' => $webhook_topic,
            'event_type' => $event_type,
            'entity_id' => $entity_id,
            'entity' => $entity,
            'customer' => $customer ? '[CUSTOMER_ID_PRESENT]' : null,
            'body_keys' => array_keys($webhook_body),
            'has_personal_data' => $this->containsPersonalData($webhook_body)
        ]);
        
        // Validate webhook signature
        if (!$this->client->webhooks()->validateWebhookSignature($body, $signature)) {
            $this->client->logger()->error('Webhook signature validation failed', [
                'webhook_id' => $webhook_id,
                'event_type' => $event_type
            ]);
            
            return new WP_REST_Response([
                'success' => false,
                'error' => 'Invalid signature'
            ], 401);
        }
        
        // Check for duplicate webhooks
        $validator = new WC_WMS_Webhook_Validator();
        if ($webhook_id && $validator->is_duplicate($webhook_id)) {
            $this->client->logger()->info('Duplicate webhook detected, skipping', [
                'webhook_id' => $webhook_id,
                'event_type' => $event_type
            ]);
            
            return new WP_REST_Response([
                'success' => true,
                'message' => 'Webhook already processed (duplicate)',
                'webhook_id' => $webhook_id
            ], 200);
        }
        
        // Store webhook ID to prevent duplicates
        if ($webhook_id) {
            $validator->store_webhook_id($webhook_id);
        }
        
        // Check if initial sync is completed before processing webhooks
        if (!get_option('wc_wms_initial_sync_completed', false)) {
            $this->client->logger()->info('Webhook skipped - initial sync not completed', [
                'webhook_id' => $webhook_id,
                'event_type' => $event_type,
                'message' => 'Webhook processing disabled until initial sync is completed'
            ]);
            
            return new WP_REST_Response([
                'success' => true,
                'message' => 'Webhook received but processing disabled until initial sync is completed',
                'webhook_id' => $webhook_id
            ], 200);
        }
        
        try {
            // Queue webhook for ordered processing instead of immediate processing
            $webhook_queue = new WC_WMS_Webhook_Queue_Manager();
            
            $webhook_data = [
                'webhook_id' => $webhook_id,
                'group' => $group,
                'action' => $action,
                'entity_id' => $entity_id,
                'external_reference' => $webhook_body['external_reference'] ?? '',
                'payload' => $webhook_body
            ];
            
            $queued = $webhook_queue->queueWebhook($webhook_data);
            
            if (!$queued) {
                throw new Exception('Failed to queue webhook for processing');
            }
            
            // Immediately try to process the queue to minimize delay
            $queue_result = $webhook_queue->processQueuedWebhooks(10);
            
            $processing_time = round((microtime(true) - $processing_start) * 1000, 2);
            
            $this->client->logger()->info('Webhook queued and processed', [
                'webhook_id' => $webhook_id,
                'event_type' => $event_type,
                'processing_time_ms' => $processing_time,
                'queue_processed' => $queue_result['processed'],
                'queue_successful' => $queue_result['successful'],
                'queue_failed' => $queue_result['failed'],
                'queue_skipped' => $queue_result['skipped']
            ]);
            
            return new WP_REST_Response([
                'success' => true,
                'message' => 'Webhook queued and processed',
                'webhook_id' => $webhook_id,
                'event_type' => $event_type,
                'processing_time_ms' => $processing_time,
                'queue_result' => [
                    'processed' => $queue_result['processed'],
                    'successful' => $queue_result['successful'],
                    'failed' => $queue_result['failed'],
                    'skipped' => $queue_result['skipped']
                ]
            ], 200);
            
        } catch (Exception $e) {
            $processing_time = round((microtime(true) - $processing_start) * 1000, 2);
            
            $this->client->logger()->error('Webhook processing failed', [
                'webhook_id' => $webhook_id,
                'event_type' => $event_type,
                'error' => $e->getMessage(),
                'processing_time_ms' => $processing_time
            ]);
            
            return new WP_REST_Response([
                'success' => false,
                'error' => 'Processing failed',
                'webhook_id' => $webhook_id,
                'event_type' => $event_type,
                'retry_recommended' => true
            ], 500);
        }
    }
    
    /**
     * Route webhook event to appropriate processor (Interface requirement)
     */
    public function routeWebhookEvent(string $group, string $action, array $body, string $entityId = null): array {
        $this->client->logger()->debug('Routing webhook event', [
            'group' => $group,
            'action' => $action,
            'entity_id' => $entityId
        ]);
        
        if (empty($group) || empty($action)) {
            throw new Exception('Missing webhook group or action');
        }
        
        // Get appropriate processor for the webhook group
        $processor = $this->processorFactory->getProcessor($group);
        
        // Check if processor supports the action
        $supported_actions = $processor->getSupportedActions();
        if (!in_array($action, $supported_actions) && !in_array('*', $supported_actions)) {
            $this->client->logger()->warning('Unsupported webhook action', [
                'group' => $group,
                'action' => $action,
                'supported_actions' => $supported_actions
            ]);
            
            return [
                'status' => 'ignored',
                'message' => "Unsupported action '{$action}' for group '{$group}'"
            ];
        }
        
        // Process the webhook event
        $result = $processor->process($action, $body, $entityId);
        
        $this->client->logger()->debug('Webhook event processed', [
            'group' => $group,
            'action' => $action,
            'result' => $result
        ]);
        
        return $result;
    }
    
    /**
     * Get webhook URLs (Interface requirement)
     */
    public function getWebhookUrls(): array {
        $base_url = home_url('/wp-json/wc-wms/v1/webhook');
        
        return [
            'main' => $base_url,
            'test' => $base_url . '/test'
        ];
    }
    
    /**
     * Register REST API endpoints
     */
    public function registerRestEndpoints(): void {
        // Main webhook endpoint
        register_rest_route('wc-wms/v1', '/webhook', [
            'methods' => 'POST',
            'callback' => [$this, 'handleWebhook'],
            'permission_callback' => '__return_true'
        ]);
        
        // Test endpoint for development
        register_rest_route('wc-wms/v1', '/webhook/test', [
            'methods' => ['GET', 'POST'],
            'callback' => [$this, 'testWebhookEndpoint'],
            'permission_callback' => '__return_true'
        ]);
        
        // Webhook status endpoint
        register_rest_route('wc-wms/v1', '/webhook/status', [
            'methods' => 'GET',
            'callback' => [$this, 'getWebhookStatusEndpoint'],
            'permission_callback' => '__return_true'
        ]);
    }
    
    /**
     * Test webhook endpoint
     */
    public function testWebhookEndpoint(WP_REST_Request $request): WP_REST_Response {
        $method = $request->get_method();
        
        $this->client->logger()->info('Webhook test endpoint accessed', [
            'method' => $method,
            'user_agent' => $request->get_header('User-Agent')
        ]);
        
        $config = $this->client->webhooks()->getWebhookConfig();
        $status = $this->getStatus();
        
        return new WP_REST_Response([
            'success' => true,
            'message' => 'Webhook endpoint is working correctly',
            'timestamp' => current_time('mysql'),
            'method' => $method,
            'integrator_status' => $status,
            'webhook_config' => $config,
            'endpoints' => $this->getWebhookUrls(),
            'supported_groups' => $this->processorFactory->getSupportedGroups(),
            'requirements' => [
                'signature_header' => 'X-Hmac-Sha256',
                'webhook_id_header' => 'X-Webhook-Id',
                'webhook_topic_header' => 'X-Webhook-Topic',
                'content_type' => 'application/json'
            ]
        ], 200);
    }
    
    /**
     * Get webhook status endpoint
     */
    public function getWebhookStatusEndpoint(WP_REST_Request $request): WP_REST_Response {
        try {
            $status = $this->getStatus();
            $webhook_status = $this->client->webhooks()->getWebhookStatus();
            
            return new WP_REST_Response([
                'success' => true,
                'integrator_status' => $status,
                'webhook_status' => $webhook_status,
                'timestamp' => current_time('mysql')
            ], 200);
            
        } catch (Exception $e) {
            return new WP_REST_Response([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Register all webhooks with WMS
     */
    public function registerAllWebhooks(): array {
        return $this->client->webhooks()->registerAllWebhooks();
    }
    
    /**
     * Delete all webhooks from WMS
     */
    public function deleteAllWebhooks(): array {
        return $this->client->webhooks()->deleteAllWebhooks();
    }
    
    /**
     * Get webhook registration status
     */
    public function getWebhookRegistrationStatus(): array {
        return $this->client->webhooks()->getWebhookStatus();
    }
    
    /**
     * Validate webhook configuration
     */
    public function validateWebhookConfiguration(): array {
        return $this->client->webhooks()->validateWebhookConfiguration();
    }
    
    /**
     * Test webhook connectivity
     */
    public function testWebhookConnectivity(): array {
        return $this->client->webhooks()->testWebhookEndpoint();
    }
    
    /**
     * Check for potential security issues with logging
     */
    public function checkLoggingSecurity(): array {
        $issues = [];
        $recommendations = [];
        
        // Check if debug mode is enabled in production
        if (defined('WP_DEBUG') && WP_DEBUG && !$this->isLocalEnvironment()) {
            $issues[] = 'WP_DEBUG is enabled in production environment';
            $recommendations[] = 'Disable WP_DEBUG in production (wp-config.php)';
        }
        
        // Check if error display is enabled
        if (ini_get('display_errors') && !$this->isLocalEnvironment()) {
            $issues[] = 'PHP error display is enabled in production';
            $recommendations[] = 'Disable display_errors in PHP configuration';
        }
        
        // Check log file accessibility (basic test)
        $log_paths = [
            '/error_log',
            '/wp-content/debug.log',
            '/wp-content/uploads/wc-logs/'
        ];
        
        foreach ($log_paths as $path) {
            $url = home_url($path);
            $response = wp_remote_head($url, ['timeout' => 5]);
            
            if (!is_wp_error($response)) {
                $status_code = wp_remote_retrieve_response_code($response);
                if ($status_code === 200) {
                    $issues[] = "Log file potentially accessible: {$path}";
                    $recommendations[] = "Secure or move log files outside web root: {$path}";
                }
            }
        }
        
        // Check database log retention
        global $wpdb;
        $webhook_table = $wpdb->prefix . 'wc_wms_webhook_logs';
        $old_logs = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $webhook_table WHERE created_at < %s",
            date('Y-m-d H:i:s', strtotime('-30 days'))
        ));
        
        if ($old_logs > 100) {
            $issues[] = "Large number of old webhook logs ({$old_logs} logs older than 30 days)";
            $recommendations[] = 'Enable automatic log cleanup or manually clean old logs';
        }
        
        return [
            'secure' => empty($issues),
            'issues' => $issues,
            'recommendations' => $recommendations,
            'last_check' => current_time('mysql')
        ];
    }
    
    /**
     * Check if running in local environment
     */
    private function isLocalEnvironment(): bool {
        $local_hosts = ['localhost', '127.0.0.1', '::1', '.local', '.dev', '.test'];
        $host = $_SERVER['HTTP_HOST'] ?? '';
        
        foreach ($local_hosts as $local_host) {
            if (strpos($host, $local_host) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get webhook processing statistics
     */
    public function getWebhookProcessingStats(): array {
        return [
            'processor_stats' => $this->processorFactory->getProcessorStats(),
            'supported_groups' => $this->processorFactory->getSupportedGroups(),
            'webhook_urls' => $this->getWebhookUrls(),
            'last_status_check' => current_time('mysql')
        ];
    }
    
    /**
     * Handle webhook processing errors
     */
    public function handleWebhookError(string $webhook_id, string $error_message, array $context = []): void {
        $this->client->logger()->error('Webhook processing error', array_merge([
            'webhook_id' => $webhook_id,
            'error' => $error_message
        ], $context));
        
        // Store error for admin reporting
        $errors = get_option('wc_wms_webhook_errors', []);
        $errors[] = [
            'webhook_id' => $webhook_id,
            'error' => $error_message,
            'context' => $context,
            'timestamp' => current_time('mysql')
        ];
        
        // Keep only last 100 errors
        if (count($errors) > 100) {
            $errors = array_slice($errors, -100);
        }
        
        update_option('wc_wms_webhook_errors', $errors);
    }
    
    /**
     * Get recent webhook errors
     */
    public function getRecentWebhookErrors(int $limit = 20): array {
        $errors = get_option('wc_wms_webhook_errors', []);
        return array_slice($errors, -$limit);
    }
    
    /**
     * Clear webhook errors
     */
    public function clearWebhookErrors(): void {
        delete_option('wc_wms_webhook_errors');
    }
    
    /**
     * Check if data contains personal information
     */
    private function containsPersonalData(array $data): bool {
        $personalDataFields = [
            'email', 'phone', 'first_name', 'last_name',
            'company', 'address', 'city', 'postcode',
            'customer_email', 'billing_email', 'shipping_address'
        ];
        
        return $this->arrayContainsKeys($data, $personalDataFields);
    }
    
    /**
     * Recursively check if array contains any of the specified keys
     */
    private function arrayContainsKeys(array $array, array $keys): bool {
        foreach ($keys as $key) {
            if (array_key_exists($key, $array)) {
                return true;
            }
        }
        
        // Check nested arrays
        foreach ($array as $value) {
            if (is_array($value) && $this->arrayContainsKeys($value, $keys)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get webhook activity summary
     */
    public function getWebhookActivitySummary(int $days = 7): array {
        global $wpdb;
        
        $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        // This would require a webhook logs table - for now return basic info
        return [
            'period_days' => $days,
            'since' => $since,
            'total_webhooks' => 0, // Would count from logs table
            'successful_webhooks' => 0,
            'failed_webhooks' => 0,
            'unique_webhook_ids' => 0,
            'webhook_types' => [],
            'note' => 'Webhook activity tracking requires webhook logs table'
        ];
    }
}
