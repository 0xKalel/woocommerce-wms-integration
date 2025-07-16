<?php
/**
 * WMS Authentication Handler
 * 
 * Manages authentication tokens and login/refresh logic
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Authenticator {
    
    /**
     * Configuration instance
     */
    private $config;
    
    /**
     * HTTP client instance
     */
    private $httpClient;
    
    /**
     * Logger instance
     */
    private $logger;
    
    /**
     * Authentication tokens
     */
    private $accessToken;
    private $refreshToken;
    private $tokenExpiresAt;
    
    /**
     * Constructor
     */
    public function __construct(WC_WMS_Config $config, WC_WMS_HTTP_Client $httpClient, WC_WMS_Logger $logger) {
        $this->config = $config;
        $this->httpClient = $httpClient;
        $this->logger = $logger;
        
        // Load stored tokens
        $this->loadStoredTokens();
    }
    
    /**
     * Authenticate with username/password
     */
    public function authenticate(): bool {
        if (!$this->config->hasValidCredentials()) {
            throw new Exception('WMS credentials not configured');
        }
        
        $endpoint = $this->config->buildEndpoint('/wms/auth/login/');
        
        $data = [
            'username' => $this->config->getUsername(),
            'password' => $this->config->getPassword()
        ];
        
        $headers = [
            'X-Wms-Code' => $this->config->getWmsCode(),
            'X-Customer-Code' => $this->config->getCustomerCode()
        ];
        
        $this->logger->debug('Making authentication request', [
            'endpoint' => $endpoint,
            'username' => $this->config->getUsername(),
            'wms_code' => $this->config->getWmsCode(),
            'customer_code' => $this->config->getCustomerCode()
        ]);
        
        try {
            $response = $this->httpClient->request('POST', $endpoint, $data, $headers);
            
            if (isset($response['token'])) {
                $this->accessToken = $response['token'];
                $this->refreshToken = $response['refresh_token'] ?? '';
                $this->tokenExpiresAt = $response['exp'] ?? (time() + 3600);
                
                $this->storeTokens();
                
                $this->logger->info('Authentication successful', [
                    'expires_at' => date('Y-m-d H:i:s', $this->tokenExpiresAt),
                    'has_refresh_token' => !empty($this->refreshToken)
                ]);
                
                return true;
            }
            
            $this->logger->error('Authentication failed - no token in response', [
                'response' => $response
            ]);
            throw new Exception('Authentication failed: No token received');
            
        } catch (Exception $e) {
            $this->logger->error('Authentication failed', [
                'error' => $e->getMessage()
            ]);
            throw new Exception('Authentication failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Refresh access token
     */
    public function refreshAccessToken(): bool {
        if (empty($this->refreshToken)) {
            $this->logger->info('No refresh token available, performing full authentication');
            return $this->authenticate();
        }
        
        $endpoint = $this->config->buildEndpoint('/wms/auth/refresh/');
        
        $data = [
            'refresh_token' => $this->refreshToken
        ];
        
        $headers = [
            'X-Wms-Code' => $this->config->getWmsCode(),
            'X-Customer-Code' => $this->config->getCustomerCode()
        ];
        
        $this->logger->debug('Refreshing access token', [
            'endpoint' => $endpoint
        ]);
        
        try {
            $response = $this->httpClient->request('POST', $endpoint, $data, $headers);
            
            if (isset($response['token'])) {
                $this->accessToken = $response['token'];
                $this->refreshToken = $response['refresh_token'] ?? $this->refreshToken;
                $this->tokenExpiresAt = $response['exp'] ?? (time() + 3600);
                
                $this->storeTokens();
                
                $this->logger->info('Token refreshed successfully', [
                    'expires_at' => date('Y-m-d H:i:s', $this->tokenExpiresAt)
                ]);
                
                return true;
            }
            
            $this->logger->warning('Token refresh failed - no token in response, attempting full authentication');
            return $this->authenticate();
            
        } catch (Exception $e) {
            $this->logger->warning('Token refresh failed, attempting full authentication', [
                'error' => $e->getMessage()
            ]);
            return $this->authenticate();
        }
    }
    
    /**
     * Ensure valid authentication
     */
    public function ensureAuthenticated(): bool {
        // Check if token is expired or will expire in next 5 minutes
        if (empty($this->accessToken) || $this->tokenExpiresAt < (time() + WC_WMS_Constants::TOKEN_REFRESH_BUFFER)) {
            $this->logger->debug('Token expired or about to expire, refreshing', [
                'current_time' => time(),
                'expires_at' => $this->tokenExpiresAt,
                'buffer' => WC_WMS_Constants::TOKEN_REFRESH_BUFFER
            ]);
            
            return $this->refreshAccessToken();
        }
        
        return true;
    }
    
    /**
     * Get current access token
     */
    public function getAccessToken(): ?string {
        return $this->accessToken;
    }
    
    /**
     * Get authenticated headers
     */
    public function getAuthenticatedHeaders(): array {
        if (empty($this->accessToken)) {
            throw new Exception('No access token available. Please authenticate first.');
        }
        
        return $this->config->getAuthenticatedHeaders($this->accessToken);
    }
    
    /**
     * Check if currently authenticated
     */
    public function isAuthenticated(): bool {
        return !empty($this->accessToken) && $this->tokenExpiresAt > time();
    }
    
    /**
     * Get authentication status
     */
    public function getAuthStatus(): array {
        return [
            'authenticated' => $this->isAuthenticated(),
            'token_expires_at' => $this->tokenExpiresAt,
            'expires_in_seconds' => max(0, $this->tokenExpiresAt - time()),
            'has_refresh_token' => !empty($this->refreshToken),
            'expires_soon' => $this->tokenExpiresAt < (time() + WC_WMS_Constants::TOKEN_REFRESH_BUFFER)
        ];
    }
    
    /**
     * Clear authentication
     */
    public function clearAuthentication(): void {
        $this->accessToken = '';
        $this->refreshToken = '';
        $this->tokenExpiresAt = 0;
        
        $this->clearStoredTokens();
        
        $this->logger->info('Authentication cleared');
    }
    
    /**
     * Test authentication
     */
    public function testAuthentication(): array {
        $this->logger->info('Testing authentication');
        
        try {
            $result = $this->authenticate();
            
            if ($result) {
                $this->logger->info('Authentication test successful');
                return [
                    'success' => true,
                    'message' => 'Authentication successful',
                    'auth_status' => $this->getAuthStatus(),
                    'test_timestamp' => current_time('mysql')
                ];
            } else {
                throw new Exception('Authentication failed');
            }
            
        } catch (Exception $e) {
            $this->logger->error('Authentication test failed', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => 'Authentication failed: ' . $e->getMessage(),
                'error' => $e->getMessage(),
                'test_timestamp' => current_time('mysql')
            ];
        }
    }
    
    /**
     * Load stored tokens from database
     */
    private function loadStoredTokens(): void {
        $this->accessToken = get_option('wc_wms_integration_access_token', '');
        $this->refreshToken = get_option('wc_wms_integration_refresh_token', '');
        $this->tokenExpiresAt = get_option('wc_wms_integration_token_expires_at', 0);
    }
    
    /**
     * Store tokens in database
     */
    private function storeTokens(): void {
        update_option('wc_wms_integration_access_token', $this->accessToken);
        update_option('wc_wms_integration_refresh_token', $this->refreshToken);
        update_option('wc_wms_integration_token_expires_at', $this->tokenExpiresAt);
    }
    
    /**
     * Clear stored tokens from database
     */
    private function clearStoredTokens(): void {
        delete_option('wc_wms_integration_access_token');
        delete_option('wc_wms_integration_refresh_token');
        delete_option('wc_wms_integration_token_expires_at');
    }
    
    /**
     * Get token expiration info
     */
    public function getTokenExpirationInfo(): array {
        return [
            'expires_at' => $this->tokenExpiresAt,
            'expires_in_seconds' => max(0, $this->tokenExpiresAt - time()),
            'expires_in_minutes' => max(0, floor(($this->tokenExpiresAt - time()) / 60)),
            'expires_soon' => $this->tokenExpiresAt < (time() + WC_WMS_Constants::TOKEN_REFRESH_BUFFER),
            'is_expired' => $this->tokenExpiresAt <= time()
        ];
    }
}