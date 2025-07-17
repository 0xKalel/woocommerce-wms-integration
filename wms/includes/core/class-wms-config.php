<?php
/**
 * WMS Configuration Manager
 * 
 * Handles all configuration settings for WMS integration
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Config {
    
    /**
     * Configuration cache
     */
    private $config_cache = [];
    
    /**
     * Get API base URL
     */
    public function getApiUrl(): string {
        return $this->getCachedOption('wc_wms_integration_api_url', 
            getenv('WMS_API_URL') ?: 'https://eu-dev.middleware.ewarehousing-solutions.com/');
    }
    
    /**
     * Get API username
     */
    public function getUsername(): string {
        return $this->getCachedOption('wc_wms_integration_username', '');
    }
    
    /**
     * Get API password
     */
    public function getPassword(): string {
        return $this->getCachedOption('wc_wms_integration_password', '');
    }
    
    /**
     * Get WMS code
     */
    public function getWmsCode(): string {
        return $this->getCachedOption('wc_wms_integration_wms_code', '');
    }
    
    /**
     * Get customer code
     */
    public function getCustomerCode(): string {
        return $this->getCachedOption('wc_wms_integration_customer_id', '');
    }
    
    /**
     * Get webhook secret
     */
    public function getWebhookSecret(): string {
        return $this->getCachedOption('wc_wms_integration_webhook_secret', '');
    }
    
    /**
     * Get customer email domain
     */
    public function getCustomerEmailDomain(): string {
        return $this->getCachedOption('wc_wms_customer_email_domain', 'wms.local');
    }
    
    /**
     * Check if credentials are configured
     */
    public function hasValidCredentials(): bool {
        return !empty($this->getUsername()) && 
               !empty($this->getPassword()) && 
               !empty($this->getCustomerCode()) && 
               !empty($this->getWmsCode());
    }
    
    /**
     * Get all configuration as array
     */
    public function toArray(): array {
        return [
            'api_url' => $this->getApiUrl(),
            'username' => $this->getUsername(),
            'password' => $this->getPassword(),
            'wms_code' => $this->getWmsCode(),
            'customer_code' => $this->getCustomerCode(),
            'webhook_secret' => $this->getWebhookSecret(),
            'customer_email_domain' => $this->getCustomerEmailDomain(),
            'has_valid_credentials' => $this->hasValidCredentials()
        ];
    }
    
    /**
     * Update configuration
     */
    public function updateConfig(array $config): bool {
        $updated = false;
        
        if (isset($config['api_url'])) {
            update_option('wc_wms_integration_api_url', $config['api_url']);
            $updated = true;
        }
        
        if (isset($config['username'])) {
            update_option('wc_wms_integration_username', $config['username']);
            $updated = true;
        }
        
        if (isset($config['password'])) {
            update_option('wc_wms_integration_password', $config['password']);
            $updated = true;
        }
        
        if (isset($config['wms_code'])) {
            update_option('wc_wms_integration_wms_code', $config['wms_code']);
            $updated = true;
        }
        
        if (isset($config['customer_code'])) {
            update_option('wc_wms_integration_customer_id', $config['customer_code']);
            $updated = true;
        }
        
        if (isset($config['webhook_secret'])) {
            update_option('wc_wms_integration_webhook_secret', $config['webhook_secret']);
            $updated = true;
        }
        
        if (isset($config['customer_email_domain'])) {
            update_option('wc_wms_customer_email_domain', $config['customer_email_domain']);
            $updated = true;
        }
        
        if ($updated) {
            $this->clearCache();
        }
        
        return $updated;
    }
    
    /**
     * Build API endpoint URL
     */
    public function buildEndpoint(string $path): string {
        return rtrim($this->getApiUrl(), '/') . '/' . ltrim($path, '/');
    }
    
    /**
     * Get request timeout
     */
    public function getRequestTimeout(): int {
        return WC_WMS_Constants::REQUEST_TIMEOUT;
    }
    
    /**
     * Get user agent string
     */
    public function getUserAgent(): string {
        return 'WooCommerce-WMS-Integration/' . WC_WMS_INTEGRATION_VERSION;
    }
    
    /**
     * Get default headers
     */
    public function getDefaultHeaders(): array {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => $this->getUserAgent()
        ];
    }
    
    /**
     * Get authenticated headers
     */
    public function getAuthenticatedHeaders(string $token): array {
        return array_merge($this->getDefaultHeaders(), [
            'Authorization' => 'Bearer ' . $token,
            'X-Customer-Code' => $this->getCustomerCode(),
            'X-Wms-Code' => $this->getWmsCode()
        ]);
    }
    
    /**
     * Get cached option value
     */
    private function getCachedOption(string $key, $default = null) {
        if (!isset($this->config_cache[$key])) {
            $this->config_cache[$key] = get_option($key, $default);
        }
        return $this->config_cache[$key];
    }
    
    /**
     * Clear configuration cache
     */
    private function clearCache(): void {
        $this->config_cache = [];
    }
    
    /**
     * Validate configuration
     */
    public function validate(): array {
        $errors = [];
        
        if (empty($this->getApiUrl())) {
            $errors[] = 'API URL is required';
        }
        
        if (empty($this->getUsername())) {
            $errors[] = 'Username is required';
        }
        
        if (empty($this->getPassword())) {
            $errors[] = 'Password is required';
        }
        
        if (empty($this->getWmsCode())) {
            $errors[] = 'WMS Code is required';
        }
        
        if (empty($this->getCustomerCode())) {
            $errors[] = 'Customer Code is required';
        }
        
        // Validate URL format
        if (!filter_var($this->getApiUrl(), FILTER_VALIDATE_URL)) {
            $errors[] = 'API URL must be a valid URL';
        }
        
        return $errors;
    }
    
    /**
     * Get environment info
     */
    public function getEnvironmentInfo(): array {
        return [
            'environment' => $this->getWmsCode(),
            'api_url' => $this->getApiUrl(),
            'customer_code' => $this->getCustomerCode(),
            'php_version' => PHP_VERSION,
            'wordpress_version' => get_bloginfo('version'),
            'woocommerce_version' => defined('WC_VERSION') ? WC_VERSION : 'not installed',
            'plugin_version' => WC_WMS_INTEGRATION_VERSION
        ];
    }
}