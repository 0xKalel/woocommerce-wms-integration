<?php
/**
 * WMS Rate Limiter
 * 
 * Intelligent rate limiting for WMS API requests with adaptive throttling
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Rate_Limiter {
    
    /**
     * Logger instance
     */
    private $logger;
    
    /**
     * Cache key prefix
     */
    private $cache_prefix = 'wc_wms_rate_limit_';
    
    /**
     * Rate limiting configuration
     */
    private $config = [
        'default_limit' => 3600,        // Requests per hour
        'window_size' => 3600,          // 1 hour window
        'burst_limit' => 100,           // Burst requests per minute
        'burst_window' => 60,           // 1 minute burst window
        'adaptive_threshold' => 0.8,    // When to start adaptive throttling
        'backoff_multiplier' => 1.5,    // Backoff multiplier for adaptive mode
        'max_backoff' => 300            // Maximum backoff in seconds
    ];
    
    /**
     * Current rate limit status
     */
    private $status = [
        'remaining' => null,
        'reset_time' => null,
        'is_limited' => false,
        'adaptive_mode' => false,
        'backoff_until' => null
    ];
    
    /**
     * Singleton instance
     */
    private static $instance = null;
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->logger = WC_WMS_Logger::instance();
        $this->loadStatus();
    }
    
    /**
     * Get singleton instance
     */
    public static function instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Check if request is allowed
     */
    public function isAllowed(string $endpoint = 'default'): bool {
        $now = time();
        
        // Check if we're in backoff period
        if ($this->status['backoff_until'] && $now < $this->status['backoff_until']) {
            $this->logger->debug('Request blocked - in backoff period', [
                'endpoint' => $endpoint,
                'backoff_until' => $this->status['backoff_until'],
                'seconds_remaining' => $this->status['backoff_until'] - $now
            ]);
            return false;
        }
        
        // Check burst limit
        if (!$this->checkBurstLimit($endpoint)) {
            $this->logger->warning('Request blocked - burst limit exceeded', [
                'endpoint' => $endpoint
            ]);
            return false;
        }
        
        // Check hourly limit
        if (!$this->checkHourlyLimit($endpoint)) {
            $this->logger->warning('Request blocked - hourly limit exceeded', [
                'endpoint' => $endpoint
            ]);
            return false;
        }
        
        return true;
    }
    
    /**
     * Record a successful request
     */
    public function recordRequest(string $endpoint = 'default'): void {
        $now = time();
        
        // Record for burst limit tracking
        $burstKey = $this->cache_prefix . 'burst_' . $endpoint . '_' . floor($now / $this->config['burst_window']);
        $burstCount = get_transient($burstKey) ?: 0;
        set_transient($burstKey, $burstCount + 1, $this->config['burst_window']);
        
        // Record for hourly limit tracking
        $hourlyKey = $this->cache_prefix . 'hourly_' . $endpoint . '_' . floor($now / $this->config['window_size']);
        $hourlyCount = get_transient($hourlyKey) ?: 0;
        set_transient($hourlyKey, $hourlyCount + 1, $this->config['window_size']);
        
        // Update remaining count if we have API info
        if ($this->status['remaining'] !== null) {
            $this->status['remaining'] = max(0, $this->status['remaining'] - 1);
        }
        
        $this->saveStatus();
        
        $this->logger->debug('Request recorded', [
            'endpoint' => $endpoint,
            'burst_count' => $burstCount + 1,
            'hourly_count' => $hourlyCount + 1,
            'remaining' => $this->status['remaining']
        ]);
    }
    
    /**
     * Update rate limit info from API response headers
     */
    public function updateFromHeaders(array $headers): void {
        $updated = false;
        
        // Common header patterns
        $remainingHeaders = ['X-RateLimit-Remaining', 'X-Rate-Limit-Remaining', 'RateLimit-Remaining'];
        $resetHeaders = ['X-RateLimit-Reset', 'X-Rate-Limit-Reset', 'RateLimit-Reset'];
        $limitHeaders = ['X-RateLimit-Limit', 'X-Rate-Limit-Limit', 'RateLimit-Limit'];
        
        foreach ($remainingHeaders as $header) {
            if (isset($headers[$header])) {
                $this->status['remaining'] = intval($headers[$header]);
                $updated = true;
                break;
            }
        }
        
        foreach ($resetHeaders as $header) {
            if (isset($headers[$header])) {
                // Can be timestamp or seconds until reset
                $resetValue = intval($headers[$header]);
                if ($resetValue > time()) {
                    $this->status['reset_time'] = $resetValue;
                } else {
                    $this->status['reset_time'] = time() + $resetValue;
                }
                $updated = true;
                break;
            }
        }
        
        foreach ($limitHeaders as $header) {
            if (isset($headers[$header])) {
                $this->config['default_limit'] = intval($headers[$header]);
                $updated = true;
                break;
            }
        }
        
        if ($updated) {
            $this->checkAdaptiveMode();
            $this->saveStatus();
            
            $this->logger->debug('Rate limit status updated from headers', [
                'remaining' => $this->status['remaining'],
                'reset_time' => $this->status['reset_time'],
                'adaptive_mode' => $this->status['adaptive_mode']
            ]);
        }
    }
    
    /**
     * Handle rate limit exceeded response
     */
    public function handleRateLimitExceeded(array $headers = []): int {
        $this->status['is_limited'] = true;
        $this->status['remaining'] = 0;
        
        // Update from headers if available
        $this->updateFromHeaders($headers);
        
        // Calculate backoff time
        $backoffTime = $this->calculateBackoffTime();
        $this->status['backoff_until'] = time() + $backoffTime;
        
        $this->saveStatus();
        
        $this->logger->warning('Rate limit exceeded', [
            'backoff_time' => $backoffTime,
            'backoff_until' => $this->status['backoff_until'],
            'reset_time' => $this->status['reset_time']
        ]);
        
        return $backoffTime;
    }
    
    /**
     * Reset rate limiting (e.g., when reset time is reached)
     */
    public function reset(): void {
        $this->status = [
            'remaining' => $this->config['default_limit'],
            'reset_time' => time() + $this->config['window_size'],
            'is_limited' => false,
            'adaptive_mode' => false,
            'backoff_until' => null
        ];
        
        $this->saveStatus();
        
        $this->logger->info('Rate limit reset');
    }
    
    /**
     * Get current rate limit status
     */
    public function getStatus(): array {
        $now = time();
        
        return [
            'remaining' => $this->status['remaining'],
            'reset_time' => $this->status['reset_time'],
            'reset_in_seconds' => $this->status['reset_time'] ? max(0, $this->status['reset_time'] - $now) : null,
            'is_limited' => $this->status['is_limited'],
            'adaptive_mode' => $this->status['adaptive_mode'],
            'backoff_until' => $this->status['backoff_until'],
            'backoff_remaining' => $this->status['backoff_until'] ? max(0, $this->status['backoff_until'] - $now) : 0,
            'can_make_request' => $this->isAllowed()
        ];
    }
    
    /**
     * Get wait time before next request
     */
    public function getWaitTime(): int {
        $now = time();
        
        // Check backoff first
        if ($this->status['backoff_until'] && $now < $this->status['backoff_until']) {
            return $this->status['backoff_until'] - $now;
        }
        
        // Check if we need to wait for reset
        if ($this->status['is_limited'] && $this->status['reset_time']) {
            return max(0, $this->status['reset_time'] - $now);
        }
        
        return 0;
    }
    
    /**
     * Check if we should enter adaptive mode
     */
    private function checkAdaptiveMode(): void {
        if ($this->status['remaining'] === null) {
            return;
        }
        
        $usageRatio = 1 - ($this->status['remaining'] / $this->config['default_limit']);
        
        if ($usageRatio >= $this->config['adaptive_threshold']) {
            $this->status['adaptive_mode'] = true;
            $this->logger->info('Entering adaptive rate limiting mode', [
                'usage_ratio' => $usageRatio,
                'remaining' => $this->status['remaining']
            ]);
        }
    }
    
    /**
     * Check burst limit
     */
    private function checkBurstLimit(string $endpoint): bool {
        $now = time();
        $burstKey = $this->cache_prefix . 'burst_' . $endpoint . '_' . floor($now / $this->config['burst_window']);
        $burstCount = get_transient($burstKey) ?: 0;
        
        return $burstCount < $this->config['burst_limit'];
    }
    
    /**
     * Check hourly limit
     */
    private function checkHourlyLimit(string $endpoint): bool {
        // If we have API-provided remaining count, use that
        if ($this->status['remaining'] !== null) {
            return $this->status['remaining'] > 0;
        }
        
        // Otherwise use local tracking
        $now = time();
        $hourlyKey = $this->cache_prefix . 'hourly_' . $endpoint . '_' . floor($now / $this->config['window_size']);
        $hourlyCount = get_transient($hourlyKey) ?: 0;
        
        return $hourlyCount < $this->config['default_limit'];
    }
    
    /**
     * Calculate backoff time with adaptive logic
     */
    private function calculateBackoffTime(): int {
        $baseBackoff = 60; // 1 minute base
        
        if ($this->status['adaptive_mode']) {
            $baseBackoff *= $this->config['backoff_multiplier'];
        }
        
        // If we have reset time, use that
        if ($this->status['reset_time']) {
            $timeToReset = $this->status['reset_time'] - time();
            $backoff = min($timeToReset, $this->config['max_backoff']);
            return max($baseBackoff, $backoff);
        }
        
        return min($baseBackoff, $this->config['max_backoff']);
    }
    
    /**
     * Load status from options
     */
    private function loadStatus(): void {
        $saved = get_option('wc_wms_rate_limit_status', []);
        
        $this->status = array_merge($this->status, $saved);
        
        // Check if reset time has passed
        if ($this->status['reset_time'] && time() >= $this->status['reset_time']) {
            $this->reset();
        }
    }
    
    /**
     * Save status to options
     */
    private function saveStatus(): void {
        update_option('wc_wms_rate_limit_status', $this->status);
    }
    
    /**
     * Get configuration
     */
    public function getConfig(): array {
        return $this->config;
    }
    
    /**
     * Update configuration
     */
    public function updateConfig(array $config): void {
        $this->config = array_merge($this->config, $config);
        
        $this->logger->info('Rate limiter configuration updated', $config);
    }
    
    /**
     * Clear all rate limiting data
     */
    public function clearAll(): void {
        // Clear all transients
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            '_transient_' . $this->cache_prefix . '%'
        ));
        
        // Clear status
        delete_option('wc_wms_rate_limit_status');
        
        // Reset to defaults
        $this->status = [
            'remaining' => null,
            'reset_time' => null,
            'is_limited' => false,
            'adaptive_mode' => false,
            'backoff_until' => null
        ];
        
        $this->logger->info('Rate limiter data cleared');
    }
}