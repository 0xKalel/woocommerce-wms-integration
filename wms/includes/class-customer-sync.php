<?php
/**
 * Customer Sync Class
 * 
 * Handles synchronization of customers between WMS and WooCommerce
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Customer_Sync {
    
    /**
     * WMS client instance - REQUIRED dependency
     */
    private $wmsClient;
    
    /**
     * Constructor - STRICT dependency injection
     * 
     * @param WC_WMS_Client $wmsClient Required WMS client instance
     */
    public function __construct(WC_WMS_Client $wmsClient) {
        $this->wmsClient = $wmsClient;
        
        // Hook into cron for import-only
        add_action('wc_wms_sync_customers', array($this, 'sync_customers_cron'));
    }
    
    /**
     * Get customers from WMS
     * 
     * @param array $params Query parameters
     * @return array|WP_Error Customer data or error
     * @throws Exception When WMS client is unavailable
     */
    public function getCustomersFromWMS(array $params = []): mixed {
        if (!$this->wmsClient) {
            throw new Exception('WMS client not available');
        }
        
        $this->wmsClient->logger()->info('Getting customers from WMS', ['params' => $params]);
        
        try {
            $result = $this->wmsClient->customers()->getCustomers($params);
            
            $customer_count = is_array($result) ? count($result) : 0;
            $this->wmsClient->logger()->info('Customers retrieved successfully from WMS', [
                'customer_count' => $customer_count
            ]);
            
            return $result;
        } catch (Exception $e) {
            $this->wmsClient->logger()->error('Failed to retrieve customers from WMS', [
                'error' => $e->getMessage()
            ]);
            
            return new WP_Error('wms_error', $e->getMessage());
        }
    }
    
    /**
     * Get single customer from WMS
     * 
     * @param string $customerId WMS customer ID
     * @return array|WP_Error Customer data or error
     * @throws Exception When customer ID is invalid
     */
    public function getCustomerFromWMS(string $customerId): mixed {
        if (empty($customerId)) {
            throw new Exception('Customer ID cannot be empty');
        }
        
        $this->wmsClient->logger()->info('Getting customer from WMS', ['customer_id' => $customerId]);
        
        try {
            $result = $this->wmsClient->customers()->getCustomer($customerId);
            
            $this->wmsClient->logger()->info('Customer retrieved successfully from WMS', [
                'customer_id' => $customerId,
                'customer_name' => $result['name'] ?? 'unknown'
            ]);
            
            return $result;
        } catch (Exception $e) {
            $this->wmsClient->logger()->error('Failed to retrieve customer from WMS', [
                'customer_id' => $customer_id,
                'error' => $e->getMessage()
            ]);
            
            return new WP_Error('wms_error', $e->getMessage());
        }
    }
    
    /**
     * Import customers from WMS to WooCommerce
     * 
     * @param array $params Optional parameters for filtering customers
     * @return array Results of import operation
     */
    public function import_customers_from_wms($params = []) {
        $this->wmsClient->logger()->info('Starting customer import from WMS', $params);
        
        try {
            $result = $this->wmsClient->customerIntegrator()->importCustomersFromWMS($params);
            
            // Update last import timestamp
            update_option('wc_wms_customers_last_import', time());
            
            $this->wmsClient->logger()->info('Customer import completed', $result);
            return $result;
        } catch (Exception $e) {
            $this->wmsClient->logger()->error('Failed to import customers from WMS', [
                'error' => $e->getMessage()
            ]);
            return new WP_Error('wms_error', $e->getMessage());
        }
    }
    
    /**
     * Sync customers via cron (import-only)
     */
    public function sync_customers_cron() {
        if (!apply_filters('wc_wms_customer_auto_sync', false)) {
            return;
        }
        
        $this->wmsClient->logger()->info('Starting scheduled customer import');
        
        try {
            // Import from WMS only (API is read-only)
            $import_result = $this->import_customers_from_wms(['limit' => 50]);
            
            $this->wmsClient->logger()->info('Completed scheduled customer import', [
                'import_result' => $import_result
            ]);
        } catch (Exception $e) {
            $this->wmsClient->logger()->error('Scheduled customer import failed', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Get sync statistics
     * 
     * @return array
     */
    public function get_sync_statistics() {
        return $this->wmsClient->customerIntegrator()->getCustomerIntegrationStats();
    }
    
    /**
     * Link existing WooCommerce customer to WMS customer
     * 
     * @param int $woocommerce_customer_id WooCommerce customer ID
     * @param string $wms_customer_id WMS customer ID
     * @param string $wms_customer_code WMS customer code
     * @return bool Success
     */
    public function link_customer_to_wms($woocommerce_customer_id, $wms_customer_id, $wms_customer_code) {
        try {
            update_user_meta($woocommerce_customer_id, '_wms_customer_id', $wms_customer_id);
            update_user_meta($woocommerce_customer_id, '_wms_customer_code', $wms_customer_code);
            update_user_meta($woocommerce_customer_id, '_wms_synced_at', current_time('mysql'));
            
            $this->wmsClient->logger()->info('Manually linked customer to WMS', [
                'woocommerce_customer_id' => $woocommerce_customer_id,
                'wms_customer_id' => $wms_customer_id,
                'wms_customer_code' => $wms_customer_code
            ]);
            
            return true;
            
        } catch (Exception $e) {
            $this->wmsClient->logger()->error('Failed to link customer to WMS', [
                'woocommerce_customer_id' => $woocommerce_customer_id,
                'wms_customer_id' => $wms_customer_id,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }
}
