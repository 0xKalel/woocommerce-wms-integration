<?php
/**
 * WMS Client - Main Entry Point
 * 
 * Coordinates all WMS operations and provides a clean interface
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Client {
    
    /**
     * Core components
     */
    private $config;
    private $httpClient;
    private $authenticator;
    private $logger;
    private $orderStateManager;
    private $productSyncManager;
    
    /**
     * Service instances (lazy loaded)
     */
    private $services = [];
    
    /**
     * Integrator instances (lazy loaded)
     */
    private $integrators = [];
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->logger = WC_WMS_Logger::instance();
        $this->config = new WC_WMS_Config();
        $this->httpClient = new WC_WMS_HTTP_Client($this->config, $this->logger);
        $this->authenticator = new WC_WMS_Authenticator($this->config, $this->httpClient, $this->logger);
        $this->orderStateManager = new WC_WMS_Order_State_Manager($this->logger);
        $this->productSyncManager = new WC_WMS_Product_Sync_Manager($this);
    }
    
    /**
     * Get configuration
     */
    public function config(): WC_WMS_Config {
        return $this->config;
    }
    
    /**
     * Get HTTP client
     */
    public function httpClient(): WC_WMS_HTTP_Client {
        return $this->httpClient;
    }
    
    /**
     * Get authenticator
     */
    public function authenticator(): WC_WMS_Authenticator {
        return $this->authenticator;
    }
    
    /**
     * Get logger
     */
    public function logger(): WC_WMS_Logger {
        return $this->logger;
    }
    
    /**
     * Get order state manager
     */
    public function orderStateManager(): WC_WMS_Order_State_Manager {
        return $this->orderStateManager;
    }
    
    /**
     * Get product sync manager
     */
    public function productSyncManager(): WC_WMS_Product_Sync_Manager {
        return $this->productSyncManager;
    }
    
    /**
     * Get order service
     */
    public function orders(): WC_WMS_Order_Service {
        return $this->services['orders'] ??= new WC_WMS_Order_Service($this);
    }
    
    /**
     * Get product service
     */
    public function products(): WC_WMS_Product_Service {
        return $this->services['products'] ??= new WC_WMS_Product_Service($this);
    }
    
    /**
     * Get stock service
     */
    public function stock(): WC_WMS_Stock_Service {
        return $this->services['stock'] ??= new WC_WMS_Stock_Service($this);
    }
    
    /**
     * Get shipment service
     */
    public function shipments(): WC_WMS_Shipment_Service {
        return $this->services['shipments'] ??= new WC_WMS_Shipment_Service($this);
    }
    
    /**
     * Get customer service
     */
    public function customers(): WC_WMS_Customer_Service {
        return $this->services['customers'] ??= new WC_WMS_Customer_Service($this);
    }
    
    /**
     * Get webhook service
     */
    public function webhooks(): WC_WMS_Webhook_Service {
        return $this->services['webhooks'] ??= new WC_WMS_Webhook_Service($this);
    }
    
    /**
     * Get GDPR service
     */
    public function gdpr(): WC_WMS_GDPR_Service {
        return $this->services['gdpr'] ??= new WC_WMS_GDPR_Service($this);
    }
    
    /**
     * Get location service
     */
    public function locationTypes(): WC_WMS_Location_Service {
        return $this->services['location'] ??= new WC_WMS_Location_Service($this);
    }
    
    /**
     * Get queue service
     */
    public function queueService(): WC_WMS_Queue_Service {
        return $this->services['queue'] ??= new WC_WMS_Queue_Service($this);
    }
    
    /**
     * Get inbound service
     */
    public function inbounds(): WC_WMS_Inbound_Service {
        return $this->services['inbounds'] ??= new WC_WMS_Inbound_Service($this);
    }
    
    /**
     * Get product integrator
     */
    public function productIntegrator(): WC_WMS_Product_Integrator {
        return $this->integrators['product'] ??= new WC_WMS_Product_Integrator($this);
    }
    
    /**
     * Get order integrator
     */
    public function orderIntegrator(): WC_WMS_Order_Integrator {
        return $this->integrators['order'] ??= new WC_WMS_Order_Integrator($this);
    }
    
    /**
     * Get customer integrator
     */
    public function customerIntegrator(): WC_WMS_Customer_Integrator {
        return $this->integrators['customer'] ??= new WC_WMS_Customer_Integrator($this);
    }
    
    /**
     * Get stock integrator
     */
    public function stockIntegrator(): WC_WMS_Stock_Integrator {
        return $this->integrators['stock'] ??= new WC_WMS_Stock_Integrator($this);
    }
    
    /**
     * Get shipment integrator
     */
    public function shipmentIntegrator(): WC_WMS_Shipment_Integrator {
        return $this->integrators['shipment'] ??= new WC_WMS_Shipment_Integrator($this);
    }
    
    /**
     * Get webhook integrator
     */
    public function webhookIntegrator(): WC_WMS_Webhook_Integrator {
        return $this->integrators['webhook'] ??= new WC_WMS_Webhook_Integrator($this);
    }
    
    /**
     * Get GDPR integrator
     */
    public function gdprIntegrator(): WC_WMS_GDPR_Integrator {
        return $this->integrators['gdpr'] ??= new WC_WMS_GDPR_Integrator($this);
    }
    
    /**
     * Make authenticated request
     */
    public function makeAuthenticatedRequest(string $method, string $endpoint, array $data = null, array $extraHeaders = []): array {
        // Ensure authentication
        $this->authenticator->ensureAuthenticated();
        
        // Get authenticated headers
        $headers = array_merge($this->authenticator->getAuthenticatedHeaders(), $extraHeaders);
        
        // Build full endpoint URL
        $fullEndpoint = $this->config->buildEndpoint($endpoint);
        
        // Make request
        return $this->httpClient->request($method, $fullEndpoint, $data, $headers);
    }
    
    /**
     * Make unauthenticated request (for auth endpoints)
     */
    public function makeUnauthenticatedRequest(string $method, string $endpoint, array $data = null, array $extraHeaders = []): array {
        // Build full endpoint URL
        $fullEndpoint = $this->config->buildEndpoint($endpoint);
        
        // Make request
        return $this->httpClient->request($method, $fullEndpoint, $data, $extraHeaders);
    }
    
    /**
     * Test connection to WMS
     */
    public function testConnection(): array {
        $this->logger->info('Testing WMS connection');
        
        try {
            // Test authentication
            $authResult = $this->authenticator->testAuthentication();
            
            if (!$authResult['success']) {
                return [
                    'success' => false,
                    'message' => 'Authentication failed',
                    'error' => $authResult['message'],
                    'test_timestamp' => current_time('mysql')
                ];
            }
            
            // Test API call (get customers with limit 1)
            $testResult = $this->customers()->getCustomers(['limit' => 1]);
            
            $this->logger->info('WMS connection test successful');
            
            // Store successful connection test result
            update_option('wc_wms_last_connection_test', time());
            update_option('wc_wms_connection_status', 'success');
            
            return [
                'success' => true,
                'message' => 'Connection successful',
                'auth_status' => $this->authenticator->getAuthStatus(),
                'rate_limit_status' => $this->httpClient->getRateLimitStatus(),
                'api_info' => $this->config->getEnvironmentInfo(),
                'test_result' => [
                    'customers_count' => is_array($testResult) ? count($testResult) : 0,
                    'endpoint_tested' => '/wms/customers/'
                ],
                'test_timestamp' => current_time('mysql')
            ];
            
        } catch (Exception $e) {
            $this->logger->error('WMS connection test failed', [
                'error' => $e->getMessage()
            ]);
            
            // Store failed connection test result
            update_option('wc_wms_last_connection_test', time());
            update_option('wc_wms_connection_status', 'failed');
            
            return [
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage(),
                'error' => $e->getMessage(),
                'test_timestamp' => current_time('mysql')
            ];
        }
    }
    
    /**
     * Get connection status
     */
    public function getConnectionStatus(): array {
        $lastTest = get_option('wc_wms_last_connection_test', 0);
        $status = get_option('wc_wms_connection_status', 'unknown');
        
        return [
            'status' => $status,
            'last_test' => $lastTest,
            'last_test_formatted' => $lastTest ? date('Y-m-d H:i:s', $lastTest) : 'Never',
            'auth_status' => $this->authenticator->getAuthStatus(),
            'rate_limit_status' => $this->httpClient->getRateLimitStatus(),
            'config_valid' => $this->config->hasValidCredentials(),
            'config_errors' => $this->config->validate()
        ];
    }
    
    /**
     * Get overall system status
     */
    public function getSystemStatus(): array {
        return [
            'connection' => $this->getConnectionStatus(),
            'configuration' => $this->config->toArray(),
            'environment' => $this->config->getEnvironmentInfo(),
            'services' => [
                'loaded' => array_keys($this->services),
                'available' => [
                    'orders', 'products', 'stock', 'shipments', 
                    'customers', 'webhooks', 'gdpr', 'location', 'inbounds'
                ]
            ],
            'integrators' => [
                'loaded' => array_keys($this->integrators),
                'available' => [
                    'product', 'order', 'customer', 'stock', 
                    'webhook', 'gdpr'
                ]
            ]
        ];
    }
    
    /**
     * Reset all services and integrators (useful for testing)
     */
    public function resetServices(): void {
        $this->services = [];
        $this->integrators = [];
        $this->logger->debug('All services and integrators reset');
    }
    
    /**
     * Static factory method
     */
    public static function create(): self {
        return new self();
    }
}