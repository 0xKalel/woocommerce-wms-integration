<?php
/**
 * WMS Service Container
 * 
 * Manages dependency injection for all WMS services
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Service_Container {
    
    /**
     * Service instances
     */
    private static $services = [];
    
    /**
     * WMS Client instance
     */
    private static $wmsClient = null;
    
    /**
     * Initialize the container
     */
    public static function init() {
        if (self::$wmsClient === null) {
            self::$wmsClient = new WC_WMS_Client();
            
            // Log only once when the client is actually created
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('WMS Service Container: WMS Client initialized');
            }
        }
    }
    
    /**
     * Get WMS Client instance
     */
    public static function getWmsClient(): WC_WMS_Client {
        self::init();
        return self::$wmsClient;
    }
    
    /**
     * Get Product Sync service
     */
    public static function getProductSync(): WC_WMS_Product_Sync {
        if (!isset(self::$services['product_sync'])) {
            self::$services['product_sync'] = new WC_WMS_Product_Sync(self::getWmsClient());
        }
        return self::$services['product_sync'];
    }
    
    /**
     * Get Customer Sync service
     */
    public static function getCustomerSync(): WC_WMS_Customer_Sync {
        if (!isset(self::$services['customer_sync'])) {
            self::$services['customer_sync'] = new WC_WMS_Customer_Sync(self::getWmsClient());
        }
        return self::$services['customer_sync'];
    }
    
    /**
     * Get Inbound service
     */
    public static function getInboundService(): WC_WMS_Inbound_Service {
        if (!isset(self::$services['inbound_service'])) {
            self::$services['inbound_service'] = self::getWmsClient()->inbounds();
        }
        return self::$services['inbound_service'];
    }
    
    /**
     * Clear all service instances (for testing)
     */
    public static function clearServices() {
        self::$services = [];
        self::$wmsClient = null;
    }
}
