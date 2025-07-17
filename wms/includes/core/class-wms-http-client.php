<?php
/**
 * WMS HTTP Client
 * 
 * Handles all HTTP requests, rate limiting, and error handling
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_HTTP_Client {
    
    /**
     * Configuration instance
     */
    private $config;
    
    /**
     * Logger instance
     */
    private $logger;
    
    /**
     * Rate limiting tracking
     */
    private $rate_limit_remaining;
    private $rate_limit_reset;
    private $rate_limit_exceeded_at;
    
    /**
     * Constructor
     */
    public function __construct(WC_WMS_Config $config, WC_WMS_Logger $logger) {
        $this->config = $config;
        $this->logger = $logger;
        
        // Load rate limiting state
        $this->rate_limit_remaining = get_option('wc_wms_rate_limit_remaining', 3600);
        $this->rate_limit_reset = get_option('wc_wms_rate_limit_reset', 0);
        $this->rate_limit_exceeded_at = get_option('wc_wms_rate_limit_exceeded_at', 0);
    }
    
    /**
     * Make HTTP request
     */
    public function request(string $method, string $endpoint, array $data = null, array $headers = [], int $retryCount = 0): array {
        $maxRetries = 3;
        
        // Check rate limiting
        if ($this->isRateLimited()) {
            $waitTime = $this->getRateLimitWaitTime();
            $this->logger->warning("Rate limit exceeded, waiting {$waitTime} seconds", [
                'remaining' => $this->rate_limit_remaining,
                'reset_at' => date('Y-m-d H:i:s', $this->rate_limit_reset)
            ]);
            
            if ($waitTime > WC_WMS_Constants::RATE_LIMIT_WAIT_MAX) {
                throw new Exception('Rate limit exceeded, please try again later');
            }
            
            sleep($waitTime);
        }
        
        $backoffSeconds = pow(2, $retryCount);
        
        // Prepare request arguments
        $args = [
            'method' => $method,
            'headers' => array_merge($this->config->getDefaultHeaders(), $headers),
            'timeout' => $this->config->getRequestTimeout(),
            'user-agent' => $this->config->getUserAgent()
        ];
        
        if ($data && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $args['body'] = json_encode($data);
        }
        
        // Log the request
        $this->logger->debug('Making HTTP request', [
            'method' => $method,
            'endpoint' => $endpoint,
            'data' => $data,
            'retry_count' => $retryCount
        ]);
        
        // Make the request
        $response = wp_remote_request($endpoint, $args);
        
        // Handle WordPress HTTP errors
        if (is_wp_error($response)) {
            $errorMessage = $response->get_error_message();
            $this->logger->log_api_request($method, $endpoint, $data, null, $errorMessage);
            
            // Retry on network errors
            if ($retryCount < $maxRetries) {
                $this->logger->warning("Request failed, retrying in {$backoffSeconds} seconds", [
                    'error' => $errorMessage,
                    'retry_count' => $retryCount + 1
                ]);
                sleep($backoffSeconds);
                return $this->request($method, $endpoint, $data, $headers, $retryCount + 1);
            }
            
            throw new Exception($errorMessage);
        }
        
        $statusCode = wp_remote_retrieve_response_code($response);
        $responseBody = wp_remote_retrieve_body($response);
        
        // Handle rate limiting headers
        $this->updateRateLimitInfo($response);
        
        // Parse JSON response
        $parsedResponse = json_decode($responseBody, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $parsedResponse = $responseBody;
        }
        
        // Log the response
        $this->logger->log_api_request($method, $endpoint, $data, $parsedResponse, null);
        
        // Handle specific status codes
        $result = $this->handleStatusCode($statusCode, $parsedResponse, $response, $method, $endpoint, $data, $headers, $retryCount);
        
        return $result;
    }
    
    /**
     * Handle specific status codes
     */
    private function handleStatusCode(int $statusCode, $parsedResponse, $response, string $method, string $endpoint, ?array $data, array $headers, int $retryCount): array {
        $maxRetries = 3;
        
        switch ($statusCode) {
            case WC_WMS_Constants::HTTP_OK:
                $this->logger->debug('HTTP request successful', [
                    'status_code' => $statusCode,
                    'endpoint' => $endpoint
                ]);
                return $parsedResponse;
                
            case WC_WMS_Constants::HTTP_CREATED:
                $this->logger->info('Resource created successfully', [
                    'status_code' => $statusCode,
                    'endpoint' => $endpoint,
                    'method' => $method
                ]);
                return $parsedResponse;
                
            case WC_WMS_Constants::HTTP_NO_CONTENT:
                if (in_array($method, ['GET', 'HEAD'])) {
                    $this->logger->debug('Request successful - no content returned', [
                        'status_code' => $statusCode,
                        'endpoint' => $endpoint,
                        'method' => $method
                    ]);
                    return ['success' => true, 'message' => 'Request completed successfully', 'data' => []];
                } else {
                    $this->logger->info('Resource processed successfully', [
                        'status_code' => $statusCode,
                        'endpoint' => $endpoint,
                        'method' => $method
                    ]);
                    return ['success' => true, 'message' => 'Operation completed successfully'];
                }
                
            case WC_WMS_Constants::HTTP_BAD_REQUEST:
                $errorMessage = $this->extractErrorMessage($parsedResponse, 'Bad Request: Invalid data provided');
                $this->logger->error('Bad Request - Invalid data', [
                    'status_code' => $statusCode,
                    'endpoint' => $endpoint,
                    'response' => $parsedResponse,
                    'request_data' => $data
                ]);
                throw new Exception($errorMessage);
                
            case WC_WMS_Constants::HTTP_UNAUTHORIZED:
                $this->logger->error('Unauthorized - Authentication failed', [
                    'status_code' => $statusCode,
                    'endpoint' => $endpoint
                ]);
                throw new Exception('Authentication failed. Please check your credentials.');
                
            case WC_WMS_Constants::HTTP_FORBIDDEN:
                $this->logger->error('Forbidden - No access to resource', [
                    'status_code' => $statusCode,
                    'endpoint' => $endpoint
                ]);
                throw new Exception('Access denied. You do not have permission to access this resource.');
                
            case WC_WMS_Constants::HTTP_NOT_FOUND:
                $this->logger->warning('Resource not found', [
                    'status_code' => $statusCode,
                    'endpoint' => $endpoint
                ]);
                throw new Exception('The requested resource was not found.');
                
            case WC_WMS_Constants::HTTP_METHOD_NOT_ALLOWED:
                $this->logger->error('Method not allowed', [
                    'status_code' => $statusCode,
                    'method' => $method,
                    'endpoint' => $endpoint
                ]);
                throw new Exception("HTTP method '{$method}' is not allowed for this resource.");
                
            case WC_WMS_Constants::HTTP_TOO_MANY_REQUESTS:
                $this->handleRateLimitExceeded($response);
                
                if ($retryCount < $maxRetries) {
                    $retryAfter = wp_remote_retrieve_header($response, 'Retry-After') ?: 60;
                    $this->logger->warning("Rate limit exceeded, retrying after {$retryAfter} seconds", [
                        'retry_count' => $retryCount + 1
                    ]);
                    sleep($retryAfter);
                    return $this->request($method, $endpoint, $data, $headers, $retryCount + 1);
                }
                
                throw new Exception('Rate limit exceeded after retries');
                
            default:
                // Handle 5xx server errors
                if ($statusCode >= WC_WMS_Constants::HTTP_SERVER_ERROR_START) {
                    return $this->handleServerError($statusCode, $parsedResponse, $method, $endpoint, $data, $headers, $retryCount);
                }
                
                // Handle other client errors (4xx)
                if ($statusCode >= 400) {
                    return $this->handleClientError($statusCode, $parsedResponse, $endpoint);
                }
                
                // Handle other success codes (2xx)
                if ($statusCode >= 200 && $statusCode < 300) {
                    $this->logger->debug('HTTP request successful', [
                        'status_code' => $statusCode,
                        'endpoint' => $endpoint
                    ]);
                    return $parsedResponse;
                }
                
                // Unexpected status code
                $this->logger->error('Unexpected status code received', [
                    'status_code' => $statusCode,
                    'endpoint' => $endpoint,
                    'response' => $parsedResponse
                ]);
                throw new Exception("Unexpected status code: {$statusCode}");
        }
    }
    
    /**
     * Handle server errors with retry logic
     */
    private function handleServerError(int $statusCode, $parsedResponse, string $method, string $endpoint, ?array $data, array $headers, int $retryCount): array {
        $maxRetries = 3;
        
        $this->logger->error('Server error encountered', [
            'status_code' => $statusCode,
            'endpoint' => $endpoint,
            'response' => $parsedResponse,
            'retry_count' => $retryCount
        ]);
        
        if ($retryCount < $maxRetries) {
            $backoffSeconds = pow(2, $retryCount);
            $this->logger->warning("Server error {$statusCode}, retrying in {$backoffSeconds} seconds", [
                'retry_count' => $retryCount + 1,
                'backoff_seconds' => $backoffSeconds
            ]);
            sleep($backoffSeconds);
            return $this->request($method, $endpoint, $data, $headers, $retryCount + 1);
        }
        
        throw new Exception("Server error occurred (Status: {$statusCode}). Please try again later.");
    }
    
    /**
     * Handle client errors
     */
    private function handleClientError(int $statusCode, $parsedResponse, string $endpoint): array {
        $errorMessage = $this->extractErrorMessage($parsedResponse, "Client error (Status: {$statusCode})");
        
        $this->logger->error('Client error encountered', [
            'status_code' => $statusCode,
            'endpoint' => $endpoint,
            'response' => $parsedResponse
        ]);
        
        throw new Exception($errorMessage);
    }
    
    /**
     * Extract error message from response
     */
    private function extractErrorMessage($parsedResponse, string $defaultMessage = 'An error occurred'): string {
        if (is_array($parsedResponse)) {
            $errorFields = ['message', 'error', 'detail', 'error_description', 'description'];
            
            foreach ($errorFields as $field) {
                if (isset($parsedResponse[$field]) && !empty($parsedResponse[$field])) {
                    return $parsedResponse[$field];
                }
            }
            
            // Check for errors array
            if (isset($parsedResponse['errors']) && is_array($parsedResponse['errors'])) {
                $errors = [];
                foreach ($parsedResponse['errors'] as $error) {
                    if (is_string($error)) {
                        $errors[] = $error;
                    } elseif (is_array($error) && isset($error['message'])) {
                        $errors[] = $error['message'];
                    }
                }
                if (!empty($errors)) {
                    return implode('; ', $errors);
                }
            }
        }
        
        return $defaultMessage;
    }
    
    /**
     * Check if rate limited
     */
    private function isRateLimited(): bool {
        if ($this->rate_limit_exceeded_at > 0 && time() < $this->rate_limit_reset) {
            return true;
        }
        
        if ($this->rate_limit_remaining <= WC_WMS_Constants::RATE_LIMIT_THRESHOLD) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Get rate limit wait time
     */
    private function getRateLimitWaitTime(): int {
        if ($this->rate_limit_reset > time()) {
            return $this->rate_limit_reset - time();
        }
        
        return 60; // Default to 1 minute
    }
    
    /**
     * Update rate limit info from response headers
     */
    private function updateRateLimitInfo($response): void {
        $remaining = wp_remote_retrieve_header($response, 'X-RateLimit-Remaining');
        $reset = wp_remote_retrieve_header($response, 'X-RateLimit-Reset');
        
        if ($remaining !== '') {
            $this->rate_limit_remaining = intval($remaining);
            update_option('wc_wms_rate_limit_remaining', $this->rate_limit_remaining);
        }
        
        if ($reset !== '') {
            $this->rate_limit_reset = intval($reset);
            update_option('wc_wms_rate_limit_reset', $this->rate_limit_reset);
        }
    }
    
    /**
     * Handle rate limit exceeded
     */
    private function handleRateLimitExceeded($response): void {
        $this->rate_limit_exceeded_at = time();
        update_option('wc_wms_rate_limit_exceeded_at', $this->rate_limit_exceeded_at);
        
        $retryAfter = wp_remote_retrieve_header($response, 'Retry-After');
        if ($retryAfter) {
            $this->rate_limit_reset = time() + intval($retryAfter);
            update_option('wc_wms_rate_limit_reset', $this->rate_limit_reset);
        }
        
        $this->logger->error('Rate limit exceeded', [
            'remaining' => $this->rate_limit_remaining,
            'reset_at' => date('Y-m-d H:i:s', $this->rate_limit_reset),
            'retry_after' => $retryAfter
        ]);
    }
    
    /**
     * Get rate limit status
     */
    public function getRateLimitStatus(): array {
        return [
            'remaining' => $this->rate_limit_remaining,
            'reset_at' => $this->rate_limit_reset,
            'is_limited' => $this->isRateLimited(),
            'wait_time' => $this->isRateLimited() ? $this->getRateLimitWaitTime() : 0
        ];
    }
}