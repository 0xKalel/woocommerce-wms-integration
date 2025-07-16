<?php
/**
 * WMS GDPR Integrator
 * 
 * Handles GDPR compliance operations between WooCommerce and WMS
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_GDPR_Integrator implements WC_WMS_GDPR_Integrator_Interface {
    
    /**
     * WMS Client instance
     */
    private $wmsClient;
    
    /**
     * Event Dispatcher instance
     */
    private $eventDispatcher;
    
    /**
     * Constructor
     */
    public function __construct(WC_WMS_Client $wmsClient) {
        $this->wmsClient = $wmsClient;
        $this->eventDispatcher = WC_WMS_Event_Dispatcher::instance();
        
        $this->initGdprHooks();
    }
    
    /**
     * Get integrator name (Interface requirement)
     */
    public function getIntegratorName(): string {
        return 'gdpr';
    }
    
    /**
     * Check if integrator is ready (Interface requirement)
     */
    public function isReady(): bool {
        try {
            // Check if WMS client is available
            if (!$this->wmsClient || !$this->wmsClient->authenticator()->isAuthenticated()) {
                return false;
            }
            
            // Check if GDPR service is available
            if (!$this->wmsClient->gdpr() || !$this->wmsClient->gdpr()->isAvailable()) {
                return false;
            }
            
            // Check if GDPR is supported by WMS
            if (!$this->wmsClient->gdpr()->isGdprSupported()) {
                return false;
            }
            
            return true;
            
        } catch (Exception $e) {
            $this->wmsClient->logger()->error('GDPR integrator readiness check failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Get integrator status (Interface requirement)
     */
    public function getStatus(): array {
        $status = [
            'name' => $this->getIntegratorName(),
            'ready' => $this->isReady(),
            'gdpr_supported' => false,
            'recent_exports' => 0,
            'recent_erasures' => 0,
            'compliance_score' => 0,
            'health_score' => 0,
            'issues' => []
        ];
        
        try {
            // Check GDPR support
            $status['gdpr_supported'] = $this->wmsClient->gdpr()->isGdprSupported();
            
            // Get compliance status
            $complianceStatus = $this->getGdprComplianceStatus();
            $status['compliance_score'] = $complianceStatus['score'] ?? 0;
            
            // Calculate health score (0-100)
            $healthScore = 100;
            
            if (!$status['ready']) {
                $healthScore -= 50;
                $status['issues'][] = 'GDPR integrator not ready';
            }
            
            if (!$status['gdpr_supported']) {
                $healthScore -= 30;
                $status['issues'][] = 'GDPR not supported by WMS';
            }
            
            if ($status['compliance_score'] < 80) {
                $healthScore -= 20;
                $status['issues'][] = 'Low GDPR compliance score';
            }
            
            $status['health_score'] = max(0, $healthScore);
            
        } catch (Exception $e) {
            $status['issues'][] = 'Failed to get GDPR status: ' . $e->getMessage();
            $status['health_score'] = 0;
        }
        
        return $status;
    }
    
    /**
     * Initialize GDPR hooks
     */
    private function initGdprHooks(): void {
        // WordPress GDPR hooks
        add_filter('wp_privacy_personal_data_exporters', [$this, 'registerGdprExporter']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'registerGdprEraser']);
        
        // WooCommerce GDPR hooks
        add_action('woocommerce_privacy_export_customer_personal_data', [$this, 'exportCustomerData'], 10, 3);
        add_action('woocommerce_privacy_erase_customer_personal_data', [$this, 'eraseCustomerData'], 10, 3);
        
        // Custom hooks for WMS integration
        add_action('wc_wms_gdpr_export_request', [$this, 'handleGdprExportRequest'], 10, 2);
        add_action('wc_wms_gdpr_erasure_request', [$this, 'handleGdprErasureRequest'], 10, 2);
    }
    
    /**
     * Register GDPR data exporter
     */
    public function registerGdprExporter(array $exporters): array {
        $exporters['wc-wms-integration'] = [
            'exporter_friendly_name' => __('WMS Integration Data', 'wc-wms-integration'),
            'callback' => [$this, 'exportWmsData']
        ];
        
        return $exporters;
    }
    
    /**
     * Register GDPR data eraser
     */
    public function registerGdprEraser(array $erasers): array {
        $erasers['wc-wms-integration'] = [
            'eraser_friendly_name' => __('WMS Integration Data', 'wc-wms-integration'),
            'callback' => [$this, 'eraseWmsData']
        ];
        
        return $erasers;
    }
    
    /**
     * Export WMS data for GDPR request (Interface requirement)
     */
    public function exportWmsData(string $emailAddress, int $page = 1): array {
        $result = [
            'data' => [],
            'done' => true
        ];
        
        $this->wmsClient->logger()->info('GDPR export request initiated', [
            'email' => $emailAddress,
            'page' => $page
        ]);
        
        try {
            // Find customer by email
            $customer = $this->findCustomerByEmail($emailAddress);
            if (!$customer) {
                return $result;
            }
            
            // Get WMS data for this customer
            $wmsData = $this->getCustomerWmsData($customer);
            
            if (!empty($wmsData)) {
                $result['data'][] = [
                    'group_id' => 'wms_integration',
                    'group_label' => __('WMS Integration', 'wc-wms-integration'),
                    'item_id' => 'wms_customer_data',
                    'data' => $this->formatExportData($wmsData)
                ];
            }
            
            // Request data export from WMS
            $wmsExport = $this->requestWmsDataExport($emailAddress);
            if (!is_wp_error($wmsExport) && !empty($wmsExport)) {
                $result['data'][] = [
                    'group_id' => 'wms_warehouse_data',
                    'group_label' => __('WMS Warehouse Data', 'wc-wms-integration'),
                    'item_id' => 'wms_warehouse_data',
                    'data' => $this->formatExportData($wmsExport)
                ];
            }
            
            $this->eventDispatcher->dispatch('wms.gdpr.export_completed', [
                'email' => $emailAddress,
                'customer_id' => $customer->get_id(),
                'data_groups' => count($result['data'])
            ]);
            
        } catch (Exception $e) {
            $this->wmsClient->logger()->error('GDPR export failed', [
                'email' => $emailAddress,
                'error' => $e->getMessage()
            ]);
            
            $this->eventDispatcher->dispatch('wms.gdpr.export_failed', [
                'email' => $emailAddress,
                'error' => $e->getMessage()
            ]);
        }
        
        return $result;
    }
    
    /**
     * Erase WMS data for GDPR request (Interface requirement)
     */
    public function eraseWmsData(string $emailAddress, int $page = 1): array {
        $result = [
            'items_removed' => false,
            'items_retained' => false,
            'messages' => [],
            'done' => true
        ];
        
        $this->wmsClient->logger()->info('GDPR erasure request initiated', [
            'email' => $emailAddress,
            'page' => $page
        ]);
        
        try {
            // Find customer by email
            $customer = $this->findCustomerByEmail($emailAddress);
            if (!$customer) {
                $result['messages'][] = __('No customer found with this email address.', 'wc-wms-integration');
                return $result;
            }
            
            // Check if customer has WMS data
            $wmsData = $this->getCustomerWmsData($customer);
            if (empty($wmsData)) {
                $result['messages'][] = __('No WMS data found for this customer.', 'wc-wms-integration');
                return $result;
            }
            
            // Request data erasure from WMS
            $wmsErasure = $this->requestWmsDataErasure($emailAddress);
            if (is_wp_error($wmsErasure)) {
                $result['messages'][] = sprintf(
                    __('Failed to request data erasure from WMS: %s', 'wc-wms-integration'),
                    $wmsErasure->get_error_message()
                );
                $result['items_retained'] = true;
                return $result;
            }
            
            // Anonymize customer data in local database
            $anonymized = $this->anonymizeCustomerWmsData($customer);
            if ($anonymized) {
                $result['items_removed'] = true;
                $result['messages'][] = __('WMS customer data has been anonymized.', 'wc-wms-integration');
            } else {
                $result['items_retained'] = true;
                $result['messages'][] = __('Failed to anonymize WMS customer data.', 'wc-wms-integration');
            }
            
            // Remove WMS metadata
            $metaRemoved = $this->removeCustomerWmsMetadata($customer);
            if ($metaRemoved) {
                $result['items_removed'] = true;
                $result['messages'][] = __('WMS metadata has been removed.', 'wc-wms-integration');
            }
            
            $this->wmsClient->logger()->info('GDPR erasure request completed', [
                'email' => $emailAddress,
                'items_removed' => $result['items_removed'],
                'items_retained' => $result['items_retained']
            ]);
            
        } catch (Exception $e) {
            $this->wmsClient->logger()->error('GDPR erasure request failed', [
                'email' => $emailAddress,
                'error' => $e->getMessage()
            ]);
            
            $result['items_retained'] = true;
            $result['messages'][] = sprintf(
                __('Error during data erasure: %s', 'wc-wms-integration'),
                $e->getMessage()
            );
        }
        
        return $result;
    }
    
    /**
     * Get GDPR compliance status (Interface requirement)
     */
    public function getGdprComplianceStatus(): array {
        return [
            'data_exporters_registered' => has_filter('wp_privacy_personal_data_exporters'),
            'data_erasers_registered' => has_filter('wp_privacy_personal_data_erasers'),
            'retention_policy_configured' => !empty(apply_filters('wc_wms_gdpr_retention_period', 0)),
            'wms_gdpr_support' => $this->wmsClient->gdpr()->isGdprSupported(),
            'anonymization_enabled' => true,
            'audit_logging_enabled' => true
        ];
    }
    
    /**
     * Generate GDPR compliance report (Interface requirement)
     */
    public function generateComplianceReport(): array {
        global $wpdb;
        
        $report = [
            'generated_at' => current_time('mysql'),
            'statistics' => [],
            'compliance_status' => $this->getGdprComplianceStatus()
        ];
        
        // Count customers with WMS data
        $customersWithWmsData = $wpdb->get_var(
            "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} 
             WHERE meta_key LIKE '_wms_%'"
        );
        
        // Count orders with WMS data
        $ordersWithWmsData = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} 
             WHERE meta_key = '_wms_order_id'"
        );
        
        // Count anonymized customers
        $anonymizedCustomers = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} 
             WHERE meta_key = '_wms_customer_anonymized'"
        );
        
        $report['statistics'] = [
            'customers_with_wms_data' => intval($customersWithWmsData),
            'orders_with_wms_data' => intval($ordersWithWmsData),
            'anonymized_customers' => intval($anonymizedCustomers),
            'retention_period_days' => apply_filters('wc_wms_gdpr_retention_period', 30)
        ];
        
        return $report;
    }
    
    /**
     * Handle GDPR export request
     */
    public function handleGdprExportRequest(string $emailAddress, array $requestData): array {
        $this->wmsClient->logger()->info('Handling GDPR export request', [
            'email' => $emailAddress,
            'request_data' => $requestData
        ]);
        
        return $this->exportWmsData($emailAddress);
    }
    
    /**
     * Handle GDPR erasure request
     */
    public function handleGdprErasureRequest(string $emailAddress, array $requestData): array {
        $this->wmsClient->logger()->info('Handling GDPR erasure request', [
            'email' => $emailAddress,
            'request_data' => $requestData
        ]);
        
        return $this->eraseWmsData($emailAddress);
    }
    
    /**
     * Export customer data (WooCommerce hook)
     */
    public function exportCustomerData($email_address, $page): array {
        return $this->exportWmsData($email_address, $page);
    }
    
    /**
     * Erase customer data (WooCommerce hook)
     */
    public function eraseCustomerData($email_address, $page): array {
        return $this->eraseWmsData($email_address, $page);
    }
    
    /**
     * Find customer by email address
     */
    private function findCustomerByEmail(string $emailAddress): ?WC_Customer {
        $user = get_user_by('email', $emailAddress);
        
        if ($user) {
            return new WC_Customer($user->ID);
        }
        
        return null;
    }
    
    /**
     * Get customer WMS data
     */
    private function getCustomerWmsData(WC_Customer $customer): array {
        $data = [];
        
        // Get user meta data related to WMS
        $userMeta = get_user_meta($customer->get_id());
        foreach ($userMeta as $key => $value) {
            if (strpos($key, '_wms_') === 0) {
                $data[$key] = is_array($value) ? $value[0] : $value;
            }
        }
        
        // Get orders with WMS data
        $orders = wc_get_orders([
            'customer' => $customer->get_id(),
            'limit' => -1,
            'meta_query' => [
                [
                    'key' => '_wms_order_id',
                    'compare' => 'EXISTS'
                ]
            ]
        ]);
        
        $orderData = [];
        foreach ($orders as $order) {
            $orderMeta = [];
            foreach ($order->get_meta_data() as $meta) {
                if (strpos($meta->key, '_wms_') === 0) {
                    $orderMeta[$meta->key] = $meta->value;
                }
            }
            if (!empty($orderMeta)) {
                $orderData[] = [
                    'order_id' => $order->get_id(),
                    'order_number' => $order->get_order_number(),
                    'wms_data' => $orderMeta
                ];
            }
        }
        
        if (!empty($orderData)) {
            $data['orders'] = $orderData;
        }
        
        return $data;
    }
    
    /**
     * Request WMS data export
     */
    private function requestWmsDataExport(string $emailAddress) {
        try {
            return $this->wmsClient->gdpr()->requestDataExport($emailAddress);
        } catch (Exception $e) {
            return new WP_Error('wms_export_failed', $e->getMessage());
        }
    }
    
    /**
     * Request WMS data erasure
     */
    private function requestWmsDataErasure(string $emailAddress) {
        try {
            return $this->wmsClient->gdpr()->requestDataErasure($emailAddress);
        } catch (Exception $e) {
            return new WP_Error('wms_erasure_failed', $e->getMessage());
        }
    }
    
    /**
     * Anonymize customer WMS data
     */
    private function anonymizeCustomerWmsData(WC_Customer $customer): bool {
        try {
            // Anonymize WMS-specific user meta
            $wmsMetaKeys = ['_wms_customer_id', '_wms_customer_code', '_wms_customer_name'];
            
            foreach ($wmsMetaKeys as $metaKey) {
                update_user_meta($customer->get_id(), $metaKey, 'anonymized_' . wp_generate_password(8, false));
            }
            
            // Mark as anonymized
            update_user_meta($customer->get_id(), '_wms_customer_anonymized', current_time('mysql'));
            
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Remove customer WMS metadata
     */
    private function removeCustomerWmsMetadata(WC_Customer $customer): bool {
        try {
            // Get all user meta
            $userMeta = get_user_meta($customer->get_id());
            
            // Remove WMS-related meta
            foreach ($userMeta as $key => $value) {
                if (strpos($key, '_wms_') === 0) {
                    delete_user_meta($customer->get_id(), $key);
                }
            }
            
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Format export data for WordPress GDPR
     */
    private function formatExportData(array $data): array {
        $formatted = [];
        
        foreach ($data as $key => $value) {
            $label = $this->getFieldLabel($key);
            
            if (is_array($value)) {
                $value = json_encode($value, JSON_PRETTY_PRINT);
            }
            
            $formatted[] = [
                'name' => $label,
                'value' => $value
            ];
        }
        
        return $formatted;
    }
    
    /**
     * Get human-readable field labels
     */
    private function getFieldLabel(string $key): string {
        $labels = [
            'wms_order_id' => __('WMS Order ID', 'wc-wms-integration'),
            'wms_exported_at' => __('Exported to WMS At', 'wc-wms-integration'),
            'wms_shipped_at' => __('Shipped from WMS At', 'wc-wms-integration'),
            'wms_tracking_number' => __('Tracking Number', 'wc-wms-integration'),
            'wms_carrier' => __('Shipping Carrier', 'wc-wms-integration'),
            'wms_customer_id' => __('WMS Customer ID', 'wc-wms-integration'),
            'wms_synced_at' => __('Synced to WMS At', 'wc-wms-integration'),
            'orders' => __('WMS Order Data', 'wc-wms-integration'),
            'customer' => __('WMS Customer Data', 'wc-wms-integration')
        ];
        
        return $labels[$key] ?? ucwords(str_replace('_', ' ', $key));
    }
}
