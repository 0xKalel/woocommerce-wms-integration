<?php
/**
 * Plugin Name: WooCommerce WMS Integration
 * Plugin URI: https://example.com/wc-wms-integration
 * Description: Integrates WooCommerce with eWarehousing Solutions WMS for order fulfillment and inventory sync.
 * Version: 1.2.1
 * Author: Your Name
 * Author URI: https://example.com
 * License: GPL v2 or later
 * Text Domain: wc-wms-integration
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * WC requires at least: 6.0
 * WC tested up to: 8.5
 *
 * @package WC_WMS_Integration
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WC_WMS_INTEGRATION_VERSION', '1.2.1');
define('WC_WMS_INTEGRATION_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WC_WMS_INTEGRATION_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WC_WMS_INTEGRATION_PLUGIN_FILE', __FILE__);

// Load constants early (needed for cron intervals during activation)
require_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/class-wms-constants.php';

/**
 * Declare HPOS compatibility before WooCommerce initialization
 */
add_action('before_woocommerce_init', function() { 
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

/**
 * Main WC_WMS_Integration class - Clean and focused
 */
class WC_WMS_Integration {
    
    /**
     * Plugin instance
     */
    private static $instance = null;
    
    /**
     * Get plugin instance
     */
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor - Clean initialization
     */
    private function __construct() {
        // Basic WordPress hooks
        add_action('init', [$this, 'init']);
        add_action('plugins_loaded', [$this, 'plugins_loaded']);
        
        // Register activation and deactivation hooks
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        
        // Initialize cron system after core classes are loaded
        add_action('init', [$this, 'initCronSystem']);
    }
    
    /**
     * Initialize plugin
     */
    public function init(): void {
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            return; // Admin manager will handle the notice
        }
        
        // Load plugin files
        $this->loadFiles();
        
        // Initialize admin interface
        WC_WMS_Admin_Manager::init();
        
        // Initialize components
        WC_WMS_Plugin_Loader::initializeComponents();
        
        // Flush rewrite rules if needed
        $this->maybeFlushRewriteRules();
    }
    
    /**
     * Initialize cron system after classes are loaded
     */
    public function initCronSystem(): void {
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            return;
        }
        
        // Register cron schedules and handlers
        add_filter('cron_schedules', ['WC_WMS_Cron_Manager', 'addCustomIntervals']);
        WC_WMS_Cron_Handler::registerHooks();
        
        // Ensure cron jobs are scheduled
        add_action('wp_loaded', ['WC_WMS_Cron_Manager', 'ensureJobsScheduled']);
    }
    
    /**
     * Plugin loaded - load text domain
     */
    public function plugins_loaded(): void {
        load_plugin_textdomain('wc-wms-integration', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }
    
    /**
     * Load plugin files
     */
    private function loadFiles(): void {
        // Load core managers first
        require_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/core/class-wms-database-manager.php';
        require_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/core/class-wms-cron-manager.php';
        require_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/core/class-wms-cron-handler.php';
        require_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/core/class-wms-admin-manager.php';
        require_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/core/class-wms-plugin-loader.php';
        
        // Load all other plugin files
        WC_WMS_Plugin_Loader::loadIncludes();
    }
    
    /**
     * Plugin activation
     */
    public function activate(): void {
        // Load necessary classes for activation
        $this->loadFiles();
        
        // Create database tables
        WC_WMS_Database_Manager::createTables();
        
        // Initialize options
        WC_WMS_Admin_Manager::initializeOptions();
        
        // Schedule cron jobs
        WC_WMS_Cron_Manager::scheduleJobs();
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate(): void {
        // Load necessary classes for deactivation
        $this->loadFiles();
        
        // Clear scheduled cron jobs
        WC_WMS_Cron_Manager::clearJobs();
        
        // Clean up database if needed
        WC_WMS_Database_Manager::cleanup();
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Maybe flush rewrite rules
     */
    private function maybeFlushRewriteRules(): void {
        $version = get_option('wc_wms_integration_version');
        if ($version !== WC_WMS_INTEGRATION_VERSION) {
            flush_rewrite_rules();
            update_option('wc_wms_integration_version', WC_WMS_INTEGRATION_VERSION);
        }
    }
}

// Initialize plugin
WC_WMS_Integration::instance();
