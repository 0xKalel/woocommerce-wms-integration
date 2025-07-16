<?php
/**
 * WMS Webhook Service
 * 
 * Handles webhook registration and management with WMS API
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Webhook_Service implements WC_WMS_Webhook_Service_Interface {
    
    /**
     * WMS client instance
     */
    private $client;
    
    /**
     * Constructor
     */
    public function __construct(WC_WMS_Client $client) {
        $this->client = $client;
    }
    
    /**
     * Get service name
     */
    public function getServiceName(): string {
        return 'webhook';
    }
    
    /**
     * Check if service is available
     */
    public function isAvailable(): bool {
        return $this->client->config()->hasValidCredentials();
    }
    
    /**
     * Get service configuration
     */
    public function getConfig(): array {
        return [
            'service_name' => $this->getServiceName(),
            'is_available' => $this->isAvailable(),
            'webhook_secret_configured' => !empty($this->client->config()->getWebhookSecret()),
            'webhook_url' => $this->getWebhookUrl(),
            'endpoints' => [
                'webhooks' => '/webhooks/',
                'webhook_detail' => '/webhooks/{id}/'
            ]
        ];
    }
    
    /**
     * Get webhooks from WMS
     */
    public function getWebhooks(array $params = []): mixed {
        $allowedParams = [
            'limit' => 'integer',
            'offset' => 'integer',
            'url' => 'string',
            'group' => 'string',
            'action' => 'string'
        ];
        
        $filteredParams = [];
        foreach ($params as $key => $value) {
            if (isset($allowedParams[$key]) && !empty($value)) {
                $filteredParams[$key] = $value;
            }
        }
        
        $endpoint = '/webhooks/';
        if (!empty($filteredParams)) {
            $endpoint .= '?' . http_build_query($filteredParams);
        }
        
        $this->client->logger()->debug('Getting webhooks from WMS', [
            'endpoint' => $endpoint,
            'params' => $filteredParams
        ]);
        
        $response = $this->client->makeAuthenticatedRequest('GET', $endpoint);
        
        // Handle paginated response
        if (isset($response['results'])) {
            $webhooks = $response['results'];
            $count = $response['count'] ?? count($webhooks);
        } else {
            $webhooks = is_array($response) ? $response : [];
            $count = count($webhooks);
        }
        
        $this->client->logger()->info('Webhooks retrieved from WMS', [
            'count' => $count
        ]);
        
        return $webhooks;
    }
    
    /**
     * Create webhook in WMS
     */
    public function createWebhook(array $webhookData): mixed {
        $this->client->logger()->info('Creating webhook in WMS', [
            'url' => $webhookData['url'] ?? 'unknown',
            'group' => $webhookData['group'] ?? 'unknown',
            'action' => $webhookData['action'] ?? 'unknown'
        ]);
        
        // Validate required fields
        $required = ['url', 'group', 'action'];
        foreach ($required as $field) {
            if (empty($webhookData[$field])) {
                throw new Exception("Missing required field: {$field}");
            }
        }
        
        // Add webhook secret if configured
        if (empty($webhookData['hash_secret'])) {
            $webhookData['hash_secret'] = $this->client->config()->getWebhookSecret();
        }
        
        $response = $this->client->makeAuthenticatedRequest('POST', '/webhooks/', $webhookData);
        
        if (isset($response['id'])) {
            $this->client->logger()->info('Webhook created successfully', [
                'webhook_id' => $response['id'],
                'group' => $webhookData['group'],
                'action' => $webhookData['action']
            ]);
        }
        
        return $response;
    }
    
    /**
     * Update webhook in WMS
     */
    public function updateWebhook(string $webhookId, array $webhookData): mixed {
        $this->client->logger()->info('Updating webhook in WMS', [
            'webhook_id' => $webhookId,
            'data' => $webhookData
        ]);
        
        $response = $this->client->makeAuthenticatedRequest('PATCH', "/webhooks/{$webhookId}/", $webhookData);
        
        if (isset($response['id'])) {
            $this->client->logger()->info('Webhook updated successfully', [
                'webhook_id' => $webhookId
            ]);
        }
        
        return $response;
    }
    
    /**
     * Delete webhook from WMS
     */
    public function deleteWebhook(string $webhookId): mixed {
        $this->client->logger()->info('Deleting webhook from WMS', [
            'webhook_id' => $webhookId
        ]);
        
        $response = $this->client->makeAuthenticatedRequest('DELETE', "/webhooks/{$webhookId}/");
        
        $this->client->logger()->info('Webhook deleted successfully', [
            'webhook_id' => $webhookId
        ]);
        
        return $response;
    }
    
    /**
     * Get webhook configuration
     */
    public function getWebhookConfig(): array {
        $webhook_url = $this->getWebhookUrl();
        $webhook_secret = $this->client->config()->getWebhookSecret();
        
        return [
            'webhook_url' => $webhook_url,
            'webhook_secret_configured' => !empty($webhook_secret),
            'webhook_secret_length' => strlen($webhook_secret),
            'https_enabled' => is_ssl(),
            'rest_api_enabled' => (bool) get_option('permalink_structure'),
            'endpoints' => [
                'main' => $webhook_url,
                'test' => $webhook_url . '/test'
            ],
            'required_headers' => [
                'X-Webhook-Id' => 'Unique webhook identifier',
                'X-Webhook-Topic' => 'Webhook topic/event type',
                'X-Hmac-Sha256' => 'Webhook signature for validation'
            ]
        ];
    }
    
    /**
     * Validate webhook signature
     */
    public function validateWebhookSignature(string $payload, string $signature): bool {
        $webhook_secret = $this->client->config()->getWebhookSecret();
        
        if (empty($webhook_secret)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $this->client->logger()->warning('Webhook signature validation skipped (no secret configured)');
                return true;
            }
            return false;
        }
        
        if (empty($signature)) {
            return false;
        }
        
        $expected_signature = $this->calculateSignature($payload, $webhook_secret);
        
        return hash_equals($expected_signature, $signature);
    }
    
    /**
     * Register all required webhooks with WMS
     */
    public function registerAllWebhooks(): array {
        $webhook_url = $this->getWebhookUrl();
        $webhook_secret = $this->client->config()->getWebhookSecret();
        
        if (empty($webhook_secret)) {
            return [
                'success' => false,
                'error' => 'Webhook secret not configured',
                'registered' => [],
                'skipped' => [],
                'errors' => ['Webhook secret not configured']
            ];
        }
        
        $this->client->logger()->info('Starting webhook registration process', [
            'webhook_url' => $webhook_url,
            'process' => 'delete_existing_then_register'
        ]);
        
        // Delete all existing webhooks to ensure clean registration
        $this->client->logger()->info('Deleting existing webhooks before registration');
        
        try {
            $existing_webhooks = $this->getWebhooks(['limit' => 100]);
            $deletion_results = [
                'deleted' => [],
                'errors' => []
            ];
            
            foreach ($existing_webhooks as $webhook) {
                $webhook_id = $webhook['id'] ?? '';
                $webhook_desc = sprintf('%s.%s', $webhook['group'] ?? 'unknown', $webhook['action'] ?? 'unknown');
                
                if (empty($webhook_id)) {
                    $deletion_results['errors'][] = 'Invalid webhook ID for: ' . $webhook_desc;
                    continue;
                }
                
                try {
                    $this->deleteWebhook($webhook_id);
                    $deletion_results['deleted'][] = [
                        'id' => $webhook_id,
                        'description' => $webhook_desc
                    ];
                    
                    // Add small delay to avoid rate limiting
                    usleep(100000); // 0.1 second delay
                    
                } catch (Exception $e) {
                    $deletion_results['errors'][] = "Failed to delete {$webhook_desc} ({$webhook_id}): " . $e->getMessage();
                }
            }
            
            $this->client->logger()->info('Existing webhooks cleanup completed', [
                'deleted_count' => count($deletion_results['deleted']),
                'deletion_errors' => count($deletion_results['errors'])
            ]);
            
        } catch (Exception $e) {
            $this->client->logger()->warning('Failed to get existing webhooks for cleanup', [
                'error' => $e->getMessage()
            ]);
            // Continue with registration even if cleanup fails
        }
        
        // Register all configured webhooks
        $this->client->logger()->info('Registering fresh webhooks');
        
        // Standard webhook configurations (updated based on eWarehousing documentation)
        $webhook_configs = [
            // Order lifecycle webhooks
            ['group' => 'order', 'action' => 'created'],
            ['group' => 'order', 'action' => 'updated'],    // Handles cancellations via status
            ['group' => 'order', 'action' => 'planned'],    // Order allocated for picking
            ['group' => 'order', 'action' => 'processing'], // Order being processed
            ['group' => 'order', 'action' => 'shipped'],
            
            // Stock webhooks
            ['group' => 'stock', 'action' => 'updated'],
            
            // Shipment webhooks
            ['group' => 'shipment', 'action' => 'created'],
            ['group' => 'shipment', 'action' => 'updated'],
            
            // Inbound webhooks
            ['group' => 'inbound', 'action' => 'created'],
            ['group' => 'inbound', 'action' => 'updated'],
            ['group' => 'inbound', 'action' => 'completed'],
            
            // Product webhooks (optional - enable if needed)
            // ['group' => 'article', 'action' => 'created'],
            // ['group' => 'article', 'action' => 'updated'],
            // ['group' => 'article', 'action' => 'deleted'],
            // ['group' => 'variant', 'action' => 'updated']
        ];
        
        $results = [
            'registered' => [],
            'skipped' => [],
            'errors' => []
        ];
        
        foreach ($webhook_configs as $config) {
            $event_key = $config['group'] . '.' . $config['action'];
            
            // Register new webhook (no need to check for existing since we deleted all)
            $webhookData = array_merge($config, [
                'url' => $webhook_url,
                'hash_secret' => $webhook_secret
            ]);
            
            try {
                $result = $this->createWebhook($webhookData);
                
                if (isset($result['id'])) {
                    $results['registered'][] = [
                        'event' => $event_key,
                        'webhook_id' => $result['id'],
                        'id' => $result['id'],
                        'group' => $config['group'],
                        'action' => $config['action'],
                        'url' => $webhook_url
                    ];
                    
                    // Add small delay to avoid rate limiting
                    usleep(100000); // 0.1 second delay
                    
                } else {
                    $results['errors'][] = "Failed to register {$event_key}: No ID returned";
                }
                
            } catch (Exception $e) {
                $error_message = $e->getMessage();
                $results['errors'][] = "Failed to register {$event_key}: {$error_message}";
            }
        }
        
        // Store registration results
        $this->storeRegistrationResults($results);
        
        $this->client->logger()->info('Fresh webhook registration completed', [
            'registered_count' => count($results['registered']),
            'skipped_count' => count($results['skipped']),
            'error_count' => count($results['errors'])
        ]);
        
        return array_merge($results, [
            'success' => empty($results['errors']),
            'deletion_results' => $deletion_results ?? null,
            'summary' => sprintf(
                'Registered %d fresh webhooks, %d errors (cleanup: %d deleted)',
                count($results['registered']),
                count($results['errors']),
                count($deletion_results['deleted'] ?? [])
            )
        ]);
    }
    
    /**
     * Delete all webhooks registered with WMS
     */
    public function deleteAllWebhooks(): array {
        try {
            $existing_webhooks = $this->getWebhooks(['limit' => 100]);
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to get existing webhooks: ' . $e->getMessage(),
                'deleted' => [],
                'errors' => []
            ];
        }
        
        if (empty($existing_webhooks)) {
            return [
                'success' => true,
                'message' => 'No webhooks found to delete',
                'deleted' => [],
                'errors' => []
            ];
        }
        
        $results = [
            'deleted' => [],
            'errors' => []
        ];
        
        foreach ($existing_webhooks as $webhook) {
            $webhook_id = $webhook['id'] ?? '';
            $webhook_desc = $webhook['url'] ?? ($webhook['group'] . '.' . $webhook['action']);
            
            if (empty($webhook_id)) {
                $results['errors'][] = 'Invalid webhook ID for: ' . $webhook_desc;
                continue;
            }
            
            try {
                $this->deleteWebhook($webhook_id);
                $results['deleted'][] = [
                    'id' => $webhook_id,
                    'description' => $webhook_desc
                ];
            } catch (Exception $e) {
                $results['errors'][] = "Failed to delete {$webhook_id}: " . $e->getMessage();
            }
        }
        
        // Clear stored registration data
        $this->clearStoredRegistrationResults();
        
        $this->client->logger()->info('Bulk webhook deletion completed', [
            'deleted_count' => count($results['deleted']),
            'error_count' => count($results['errors'])
        ]);
        
        return array_merge($results, [
            'success' => empty($results['errors']),
            'summary' => sprintf(
                'Deleted %d webhooks, %d errors',
                count($results['deleted']),
                count($results['errors'])
            )
        ]);
    }
    
    /**
     * Get webhook registration status
     */
    public function getWebhookStatus(): array {
        try {
            $registered_webhooks = $this->getWebhooks(['limit' => 100]);
            $data_source = 'api';
        } catch (Exception $e) {
            // Fallback to stored data if API fails
            $registered_webhooks = get_option('wc_wms_registered_webhooks', []);
            $data_source = 'local_storage';
        }
        
        $expected_webhooks = [
            'order.created',
            'order.updated',
            'order.planned',
            'order.processing', 
            'order.shipped',
            'stock.updated',
            'shipment.created',
            'shipment.updated',
            'inbound.created',
            'inbound.updated',
            'inbound.completed'
        ];
        
        $registered_events = [];
        foreach ($registered_webhooks as $webhook) {
            $group = $webhook['group'] ?? '';
            $action = $webhook['action'] ?? '';
            if ($group && $action) {
                $registered_events[] = $group . '.' . $action;
            }
        }
        
        $missing_events = array_diff($expected_webhooks, $registered_events);
        
        return [
            'status' => $data_source === 'api' ? 'api_verified' : 'local_storage_only',
            'registered_webhooks' => count($registered_webhooks),
            'expected_webhooks' => count($expected_webhooks),
            'missing_events' => $missing_events,
            'registered_events' => $registered_events,
            'all_registered' => empty($missing_events),
            'data_source' => $data_source,
            'webhooks' => $registered_webhooks,
            'last_registration' => get_option('wc_wms_webhooks_registered_at', ''),
            'registration_errors' => get_option('wc_wms_webhook_registration_errors', [])
        ];
    }
    
    /**
     * Process webhook payload
     */
    public function processWebhookPayload(array $payload): array {
        $group = $payload['group'] ?? '';
        $action = $payload['action'] ?? '';
        $entity_id = $payload['entityId'] ?? '';
        $body = $payload['body'] ?? [];
        
        $this->client->logger()->info('Processing webhook payload', [
            'group' => $group,
            'action' => $action,
            'entity_id' => $entity_id
        ]);
        
        // Use the webhook integrator to route the event
        return $this->client->webhookIntegrator()->routeWebhookEvent($group, $action, $body, $entity_id);
    }
    
    /**
     * Test webhook endpoint connectivity
     */
    public function testWebhookEndpoint(): array {
        $webhook_url = $this->getWebhookUrl() . '/test';
        
        $response = wp_remote_get($webhook_url, [
            'timeout' => 10,
            'user-agent' => 'WC-WMS-Integration-Test/1.0'
        ]);
        
        if (is_wp_error($response)) {
            return [
                'success' => false,
                'error' => $response->get_error_message(),
                'url' => $webhook_url
            ];
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        return [
            'success' => $status_code === 200,
            'status_code' => $status_code,
            'response' => json_decode($body, true),
            'url' => $webhook_url
        ];
    }
    
    /**
     * Validate webhook configuration
     */
    public function validateWebhookConfiguration(): array {
        $webhook_secret = $this->client->config()->getWebhookSecret();
        $webhook_url = $this->getWebhookUrl();
        
        $issues = [];
        
        // Check if webhook secret is configured
        if (empty($webhook_secret)) {
            $issues[] = 'Webhook secret not configured';
        } elseif (strlen($webhook_secret) < 16) {
            $issues[] = 'Webhook secret should be at least 16 characters long';
        }
        
        // Check if site is HTTPS
        if (!is_ssl()) {
            $issues[] = 'HTTPS is required for webhooks';
        }
        
        // Check if REST API is enabled
        if (!get_option('permalink_structure')) {
            $issues[] = 'Pretty permalinks must be enabled for REST API endpoints';
        }
        
        // Test webhook endpoint accessibility
        $test_result = $this->testWebhookEndpoint();
        if (!$test_result['success']) {
            $issues[] = 'Webhook endpoint not accessible: ' . ($test_result['error'] ?? 'Unknown error');
        }
        
        return [
            'valid' => empty($issues),
            'webhook_url' => $webhook_url,
            'webhook_secret_configured' => !empty($webhook_secret),
            'issues' => $issues,
            'requirements' => [
                'HTTPS enabled' => is_ssl(),
                'REST API enabled' => (bool) get_option('permalink_structure'),
                'Webhook secret configured' => !empty($webhook_secret),
                'Endpoint accessible' => empty($issues)
            ]
        ];
    }
    
    /**
     * Generate webhook secret
     */
    public function generateWebhookSecret(int $length = 32): string {
        $secret = wp_generate_password($length, false);
        update_option('wc_wms_integration_webhook_secret', $secret);
        
        $this->client->logger()->info('New webhook secret generated', [
            'length' => strlen($secret)
        ]);
        
        return $secret;
    }
    
    /**
     * Get webhook URL
     */
    private function getWebhookUrl(): string {
        return home_url('/wp-json/wc-wms/v1/webhook');
    }
    
    /**
     * Calculate webhook signature
     */
    private function calculateSignature(string $payload, string $secret): string {
        $digest = hash_hmac('sha256', $payload, $secret, true);
        return base64_encode($digest);
    }
    
    /**
     * Check if webhook exists in array (kept for backward compatibility)
     */
    private function webhookExists(array $webhooks, string $eventKey): bool {
        foreach ($webhooks as $webhook) {
            $group = $webhook['group'] ?? '';
            $action = $webhook['action'] ?? '';
            if ($group . '.' . $action === $eventKey) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Store webhook registration results
     */
    private function storeRegistrationResults(array $results): void {
        // Since we now delete existing webhooks first, we only store registered webhooks
        $normalized_webhooks = [];
        
        // Add registered webhooks
        foreach ($results['registered'] as $webhook) {
            $normalized_webhooks[] = [
                'id' => $webhook['webhook_id'] ?? 'unknown',
                'webhook_id' => $webhook['webhook_id'] ?? 'unknown',
                'group' => $webhook['group'] ?? explode('.', $webhook['event'] ?? '')[0],
                'action' => $webhook['action'] ?? explode('.', $webhook['event'] ?? '')[1],
                'url' => $webhook['url'] ?? $this->getWebhookUrl(),
                'event' => $webhook['event'] ?? ($webhook['group'] . '.' . $webhook['action'])
            ];
        }
        
        // Webhooks are registered fresh after deletion, so no skipped webhooks to track
        
        update_option('wc_wms_registered_webhooks', $normalized_webhooks);
        update_option('wc_wms_webhook_registration_errors', $results['errors']);
        update_option('wc_wms_webhooks_registered_at', current_time('mysql'));
        
        $this->client->logger()->info('Webhook registration data stored', [
            'stored_webhook_count' => count($normalized_webhooks),
            'error_count' => count($results['errors'])
        ]);
    }
    
    /**
     * Clear stored registration results
     */
    private function clearStoredRegistrationResults(): void {
        delete_option('wc_wms_registered_webhooks');
        delete_option('wc_wms_webhook_registration_errors');
        delete_option('wc_wms_webhooks_registered_at');
    }
}
