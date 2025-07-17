<?php
/**
 * WMS Plugin Loader
 * 
 * Handles loading of all plugin files and component initialization
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Plugin_Loader {
    
    /**
     * Load all plugin includes
     */
    public static function loadIncludes(): void {
        // Load interfaces FIRST - before any classes that implement them
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/interfaces/wms-service-interfaces.php';
        
        // Core classes
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/class-wms-constants.php';
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/class-logger.php';
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/class-environment-validator.php';
        
        // Core architecture components
        self::loadCoreComponents();
        
        // Services
        self::loadServices();
        
        // Integrators
        self::loadIntegrators();
        
        // Webhook system
        self::loadWebhookSystem();
        
        // Support classes
        self::loadSupportClasses();
        
        // Admin components
        self::loadAdminComponents();
        
        // Development tools
        self::loadDevelopmentTools();
    }
    
    /**
     * Load core architecture components
     */
    private static function loadCoreComponents(): void {
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/core/class-wms-config.php';
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/core/class-wms-rate-limiter.php';
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/core/class-wms-http-client.php';
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/core/class-wms-authenticator.php';
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/core/class-wms-event-dispatcher.php';
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/core/class-wms-queue-manager.php';
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/core/class-wms-webhook-queue-manager.php';
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/core/class-wms-order-state-manager.php';
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/core/class-wms-database-manager.php';
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/core/class-wms-cron-manager.php';
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/core/class-wms-cron-handler.php';
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/core/class-wms-admin-manager.php';
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/core/class-wms-client.php';
        
        // Centralized sync managers - ADDED
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/core/class-wms-order-sync-manager.php';
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/core/class-wms-product-sync-manager.php';
    }
    
    /**
     * Load services
     */
    private static function loadServices(): void {
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/services/class-wms-order-service.php';
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/services/class-wms-product-service.php';
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/services/class-wms-customer-service.php';
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/services/class-wms-stock-service.php';
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/services/class-wms-shipment-service.php';
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/services/class-wms-location-service.php';
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/services/class-wms-webhook-service.php';
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/services/class-wms-inbound-service.php';
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/services/class-wms-gdpr-service.php';
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/services/class-wms-queue-service.php';
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/services/class-wms-logging-service.php';
    }
    
    /**
     * Load integrators
     */
    private static function loadIntegrators(): void {
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/integrators/class-wms-product-integrator.php';
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/integrators/class-wms-order-integrator.php';
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/integrators/class-wms-customer-integrator.php';
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/integrators/class-wms-stock-integrator.php';
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/integrators/class-wms-shipment-integrator.php';
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/integrators/class-wms-webhook-integrator.php';
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/integrators/class-wms-gdpr-integrator.php';
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/integrators/class-wms-order-hooks.php';
    }
    
    /**
     * Load webhook system
     */
    private static function loadWebhookSystem(): void {
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/webhooks/class-webhook-validator.php';
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/webhooks/class-webhook-processors.php';
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/webhooks/class-webhook-processor-factory.php';
    }
    
    /**
     * Load support classes
     */
    private static function loadSupportClasses(): void {
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/class-wms-service-container.php';
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/class-wms-ajax-handlers.php';
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/class-product-sync.php';
        include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/class-customer-sync.php';
    }
    
    /**
     * Load admin components
     */
    private static function loadAdminComponents(): void {
        if (is_admin()) {
            include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'admin-page-handler.php';
        }
    }
    
    /**
     * Load development tools (only in debug mode)
     */
    private static function loadDevelopmentTools(): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/class-wms-test-helper.php';
            include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/debug-queue-check.php';
            include_once WC_WMS_INTEGRATION_PLUGIN_DIR . 'includes/debug-manual-queue.php';
        }
    }
    
    /**
     * Initialize core components
     */
    public static function initializeComponents(): void {
        // Prevent multiple initializations within the same request
        static $components_initialized = false;
        if ($components_initialized) {
            return;
        }
        
        // Initialize service container
        WC_WMS_Service_Container::init();
        
        // Initialize WMS Client and integrators
        $wmsClient = WC_WMS_Service_Container::getWmsClient();
        $wmsClient->orderIntegrator();
        $wmsClient->webhookIntegrator();
        $wmsClient->stockIntegrator();
        $wmsClient->shipmentIntegrator();
        $wmsClient->productIntegrator();
        $wmsClient->customerIntegrator();
        
        // Initialize GDPR integrator (if enabled)
        if (apply_filters('wc_wms_gdpr_enabled', true)) {
            $wmsClient->gdprIntegrator();
        }
        
        // Initialize order hooks handler (proper singleton pattern)
        WC_WMS_Order_Hooks::getInstance($wmsClient);
        
        // Register AJAX handlers
        WC_WMS_Ajax_Handlers::register();
        
        // Mark as initialized
        $components_initialized = true;
    }
}
