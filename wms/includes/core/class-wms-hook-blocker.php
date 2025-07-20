<?php
/**
 * WMS Hook Blocker - Nuclear Option for Preventing All Interfering Hooks
 * 
 * This class provides aggressive hook blocking during WMS sync operations
 * to prevent any WordPress or WooCommerce hooks from interfering with sync.
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Hook_Blocker {
    
    /**
     * Singleton instance
     */
    private static $instance = null;
    
    /**
     * Flag to track if global blocking is active
     */
    private static $globalBlockingActive = false;
    
    /**
     * Logger instance
     */
    private $logger;
    
    /**
     * Blocked hooks counter for debugging
     */
    private $blockedHooksCount = 0;
    
    /**
     * Get singleton instance
     */
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        if (class_exists('WC_WMS_Logger')) {
            $this->logger = WC_WMS_Logger::instance();
        }
    }
    
    /**
     * Activate nuclear hook blocking
     */
    public function activateGlobalBlocking(): void {
        if (self::$globalBlockingActive) {
            return;
        }
        
        self::$globalBlockingActive = true;
        $this->blockedHooksCount = 0;
        
        // Add multiple layers of hook blocking
        add_filter('pre_do_action', [$this, 'blockAction'], 999, 2);
        add_filter('pre_apply_filters', [$this, 'blockFilter'], 999, 2);
        add_action('all', [$this, 'interceptAllHooks'], 999);
        
        if ($this->logger) {
            $this->logger->debug('WMS Hook Blocker: Global blocking activated');
        }
    }
    
    /**
     * Deactivate nuclear hook blocking
     */
    public function deactivateGlobalBlocking(): void {
        if (!self::$globalBlockingActive) {
            return;
        }
        
        self::$globalBlockingActive = false;
        
        // Remove hook blocking filters
        remove_filter('pre_do_action', [$this, 'blockAction'], 999);
        remove_filter('pre_apply_filters', [$this, 'blockFilter'], 999);
        remove_action('all', [$this, 'interceptAllHooks'], 999);
        
        if ($this->logger) {
            $this->logger->debug('WMS Hook Blocker: Global blocking deactivated', [
                'total_hooks_blocked' => $this->blockedHooksCount
            ]);
        }
        
        $this->blockedHooksCount = 0;
    }
    
    /**
     * Check if global blocking is active
     */
    public static function isGlobalBlockingActive(): bool {
        return self::$globalBlockingActive;
    }
    
    /**
     * Block actions that could interfere with sync
     */
    public function blockAction($result, $hook_name) {
        if (!self::$globalBlockingActive || !WC_WMS_Order_Sync_Manager::isSyncInProgress()) {
            return $result;
        }
        
        if ($this->shouldBlockHook($hook_name)) {
            $this->blockedHooksCount++;
            
            if ($this->logger && $this->blockedHooksCount <= 10) {
                $this->logger->debug('WMS Hook Blocker: Blocked action', [
                    'hook' => $hook_name,
                    'total_blocked' => $this->blockedHooksCount
                ]);
            }
            
            return false; // Block the action
        }
        
        return $result;
    }
    
    /**
     * Block filters that could interfere with sync
     */
    public function blockFilter($result, $hook_name) {
        if (!self::$globalBlockingActive || !WC_WMS_Order_Sync_Manager::isSyncInProgress()) {
            return $result;
        }
        
        if ($this->shouldBlockHook($hook_name)) {
            $this->blockedHooksCount++;
            return $result; // Return original value without filtering
        }
        
        return $result;
    }
    
    /**
     * Intercept all hooks - nuclear option
     */
    public function interceptAllHooks($hook_name) {
        if (!self::$globalBlockingActive || !WC_WMS_Order_Sync_Manager::isSyncInProgress()) {
            return;
        }
        
        if ($this->shouldBlockHook($hook_name)) {
            // Stop hook execution by removing all callbacks
            global $wp_filter;
            if (isset($wp_filter[$hook_name])) {
                $originalCallbacks = $wp_filter[$hook_name]->callbacks;
                $wp_filter[$hook_name]->callbacks = [];
                
                // Restore after current execution
                wp_schedule_single_event(time() + 1, 'wms_restore_hook_callbacks', [
                    $hook_name,
                    $originalCallbacks
                ]);
            }
        }
    }
    
    /**
     * Determine if a hook should be blocked
     */
    private function shouldBlockHook(string $hook_name): bool {
        // Critical hooks that should NEVER be blocked
        $criticalHooks = [
            'wms_', // Allow our own hooks
            'shutdown',
            'wp_die_handler',
            'wp_fatal_error_handler',
        ];
        
        foreach ($criticalHooks as $critical) {
            if (strpos($hook_name, $critical) !== false) {
                return false;
            }
        }
        
        // Patterns of hooks that should be blocked during sync
        $blockPatterns = [
            'woocommerce_',
            'wc_',
            'save_post',
            'wp_insert_post',
            'updated_post_meta',
            'added_post_meta',
            'clean_post_cache',
            'transition_post_status',
            'wp_update_post',
            'post_updated',
            'elementor/',
            'yoast_',
            'wpseo_',
            'jetpack_',
            '_order_',
            '_product_',
        ];
        
        foreach ($blockPatterns as $pattern) {
            if (strpos($hook_name, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Emergency cleanup - call this if something goes wrong
     */
    public static function emergencyCleanup(): void {
        $instance = self::getInstance();
        $instance->deactivateGlobalBlocking();
        
        // Remove any scheduled hook restoration events
        wp_clear_scheduled_hook('wms_restore_hook_callbacks');
    }
}

// Register emergency cleanup hook
add_action('wms_restore_hook_callbacks', function($hook_name, $callbacks) {
    global $wp_filter;
    if (isset($wp_filter[$hook_name])) {
        $wp_filter[$hook_name]->callbacks = $callbacks;
    }
}, 10, 2);

// Emergency cleanup on WordPress shutdown
add_action('shutdown', function() {
    if (WC_WMS_Hook_Blocker::isGlobalBlockingActive()) {
        WC_WMS_Hook_Blocker::emergencyCleanup();
    }
});
