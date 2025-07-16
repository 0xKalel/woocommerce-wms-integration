<?php
/**
 * WMS GDPR Service
 * 
 * Handles GDPR compliance operations with WMS
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_GDPR_Service {
    
    /**
     * WMS client instance
     */
    private $client;
    
    /**
     * Constructor
     */
    public function __construct(WC_WMS_Client $client) {
        $this->client = $client;
    }
    
    /**
     * Request person data export from WMS
     */
    public function requestPersonData(string $email): array {
        $this->client->logger()->info('Requesting person data export from WMS', [
            'email' => $email
        ]);
        
        $data = [
            'email' => $email
        ];
        
        $response = $this->client->makeAuthenticatedRequest('POST', '/wms/gdpr/request-person-data/', $data);
        
        if (isset($response['request_id'])) {
            $this->client->logger()->info('Person data export request successful', [
                'email' => $email,
                'request_id' => $response['request_id']
            ]);
        }
        
        return $response;
    }
    
    /**
     * Redact person data from WMS (IRREVERSIBLE!)
     */
    public function redactPersonData(string $email): array {
        $this->client->logger()->warning('Requesting person data redaction from WMS (IRREVERSIBLE)', [
            'email' => $email
        ]);
        
        $data = [
            'email' => $email
        ];
        
        $response = $this->client->makeAuthenticatedRequest('POST', '/wms/gdpr/redact-person-data/', $data);
        
        if (isset($response['redacted']) && $response['redacted']) {
            $this->client->logger()->warning('Person data redaction completed', [
                'email' => $email,
                'redaction_id' => $response['redaction_id'] ?? 'unknown'
            ]);
        }
        
        return $response;
    }
    
    /**
     * Handle GDPR data request (integrates with WooCommerce GDPR)
     */
    public function handleGdprDataRequest(string $email, string $requestType = 'export'): array {
        $this->client->logger()->info('Processing GDPR request', [
            'email' => $email,
            'type' => $requestType
        ]);
        
        $validTypes = ['export', 'redact', 'delete'];
        
        if (!in_array($requestType, $validTypes)) {
            throw new Exception('Invalid GDPR request type: ' . $requestType);
        }
        
        try {
            switch ($requestType) {
                case 'export':
                    $result = $this->requestPersonData($email);
                    break;
                    
                case 'redact':
                case 'delete':
                    $result = $this->redactPersonData($email);
                    break;
                    
                default:
                    throw new Exception('Invalid GDPR request type');
            }
            
            $this->client->logger()->info('GDPR request processed successfully', [
                'email' => $email,
                'type' => $requestType,
                'response' => $result
            ]);
            
            return $result;
            
        } catch (Exception $e) {
            $this->client->logger()->error('GDPR request failed', [
                'email' => $email,
                'type' => $requestType,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Get GDPR request status
     */
    public function getGdprRequestStatus(string $requestId): array {
        $this->client->logger()->debug('Getting GDPR request status', [
            'request_id' => $requestId
        ]);
        
        return $this->client->makeAuthenticatedRequest('GET', '/wms/gdpr/request-status/' . $requestId . '/');
    }
    
    /**
     * Export customer data from WMS for GDPR compliance
     */
    public function exportCustomerData(string $email): array {
        $this->client->logger()->info('Exporting customer data for GDPR compliance', [
            'email' => $email
        ]);
        
        $exportData = [
            'email' => $email,
            'exported_at' => current_time('mysql'),
            'wms_data' => null,
            'woocommerce_data' => null
        ];
        
        try {
            // Request data export from WMS
            $wmsResult = $this->requestPersonData($email);
            $exportData['wms_data'] = $wmsResult;
            
            // Get WooCommerce customer data
            $wcCustomer = get_user_by('email', $email);
            if ($wcCustomer) {
                $exportData['woocommerce_data'] = $this->getWooCommerceCustomerData($wcCustomer);
            }
            
            $this->client->logger()->info('Customer data exported successfully', [
                'email' => $email,
                'has_wms_data' => !empty($exportData['wms_data']),
                'has_wc_data' => !empty($exportData['woocommerce_data'])
            ]);
            
            return $exportData;
            
        } catch (Exception $e) {
            $this->client->logger()->error('Failed to export customer data', [
                'email' => $email,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Delete customer data for GDPR compliance
     */
    public function deleteCustomerData(string $email, bool $includeWms = false): array {
        $this->client->logger()->warning('Deleting customer data for GDPR compliance', [
            'email' => $email,
            'include_wms' => $includeWms
        ]);
        
        $deletionResults = [
            'email' => $email,
            'deleted_at' => current_time('mysql'),
            'wms_deleted' => false,
            'woocommerce_deleted' => false,
            'errors' => []
        ];
        
        try {
            // Delete from WMS if requested
            if ($includeWms) {
                try {
                    $wmsResult = $this->redactPersonData($email);
                    $deletionResults['wms_deleted'] = isset($wmsResult['redacted']) && $wmsResult['redacted'];
                } catch (Exception $e) {
                    $deletionResults['errors'][] = 'WMS deletion failed: ' . $e->getMessage();
                }
            }
            
            // Delete from WooCommerce
            $wcCustomer = get_user_by('email', $email);
            if ($wcCustomer) {
                try {
                    $this->anonymizeWooCommerceCustomer($wcCustomer);
                    $deletionResults['woocommerce_deleted'] = true;
                } catch (Exception $e) {
                    $deletionResults['errors'][] = 'WooCommerce deletion failed: ' . $e->getMessage();
                }
            }
            
            $this->client->logger()->warning('Customer data deletion completed', [
                'email' => $email,
                'wms_deleted' => $deletionResults['wms_deleted'],
                'woocommerce_deleted' => $deletionResults['woocommerce_deleted'],
                'errors' => $deletionResults['errors']
            ]);
            
            return $deletionResults;
            
        } catch (Exception $e) {
            $this->client->logger()->error('Failed to delete customer data', [
                'email' => $email,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Get WooCommerce customer data
     */
    private function getWooCommerceCustomerData(WP_User $user): array {
        $customer = new WC_Customer($user->ID);
        
        $customerData = [
            'basic_info' => [
                'id' => $user->ID,
                'email' => $user->user_email,
                'username' => $user->user_login,
                'display_name' => $user->display_name,
                'first_name' => $customer->get_first_name(),
                'last_name' => $customer->get_last_name(),
                'date_created' => $user->user_registered,
                'last_login' => get_user_meta($user->ID, 'last_login', true)
            ],
            'addresses' => [
                'billing' => [
                    'first_name' => $customer->get_billing_first_name(),
                    'last_name' => $customer->get_billing_last_name(),
                    'company' => $customer->get_billing_company(),
                    'address_1' => $customer->get_billing_address_1(),
                    'address_2' => $customer->get_billing_address_2(),
                    'city' => $customer->get_billing_city(),
                    'state' => $customer->get_billing_state(),
                    'postcode' => $customer->get_billing_postcode(),
                    'country' => $customer->get_billing_country(),
                    'phone' => $customer->get_billing_phone()
                ],
                'shipping' => [
                    'first_name' => $customer->get_shipping_first_name(),
                    'last_name' => $customer->get_shipping_last_name(),
                    'company' => $customer->get_shipping_company(),
                    'address_1' => $customer->get_shipping_address_1(),
                    'address_2' => $customer->get_shipping_address_2(),
                    'city' => $customer->get_shipping_city(),
                    'state' => $customer->get_shipping_state(),
                    'postcode' => $customer->get_shipping_postcode(),
                    'country' => $customer->get_shipping_country()
                ]
            ],
            'wms_data' => [
                'customer_id' => get_user_meta($user->ID, '_wms_customer_id', true),
                'customer_code' => get_user_meta($user->ID, '_wms_customer_code', true),
                'synced_at' => get_user_meta($user->ID, '_wms_synced_at', true)
            ],
            'orders' => $this->getCustomerOrders($user->ID)
        ];
        
        return $customerData;
    }
    
    /**
     * Get customer orders
     */
    private function getCustomerOrders(int $customerId): array {
        $orders = wc_get_orders([
            'customer_id' => $customerId,
            'limit' => -1
        ]);
        
        $orderData = [];
        foreach ($orders as $order) {
            $orderData[] = [
                'id' => $order->get_id(),
                'date' => $order->get_date_created()->format('Y-m-d H:i:s'),
                'status' => $order->get_status(),
                'total' => $order->get_total(),
                'currency' => $order->get_currency(),
                'payment_method' => $order->get_payment_method(),
                'wms_external_reference' => $order->get_meta('_wms_external_reference'),
                'wms_shipment_id' => $order->get_meta('_wms_shipment_id')
            ];
        }
        
        return $orderData;
    }
    
    /**
     * Anonymize WooCommerce customer data
     */
    private function anonymizeWooCommerceCustomer(WP_User $user): void {
        $customer = new WC_Customer($user->ID);
        
        // Generate anonymous data
        $anonymousId = 'anonymous_' . wp_generate_password(8, false);
        $anonymousEmail = $anonymousId . '@anonymous.local';
        
        // Update user data
        wp_update_user([
            'ID' => $user->ID,
            'user_email' => $anonymousEmail,
            'user_login' => $anonymousId,
            'display_name' => 'Anonymous User',
            'first_name' => '',
            'last_name' => ''
        ]);
        
        // Clear customer data
        $customer->set_first_name('');
        $customer->set_last_name('');
        $customer->set_billing_first_name('');
        $customer->set_billing_last_name('');
        $customer->set_billing_company('');
        $customer->set_billing_address_1('');
        $customer->set_billing_address_2('');
        $customer->set_billing_city('');
        $customer->set_billing_state('');
        $customer->set_billing_postcode('');
        $customer->set_billing_country('');
        $customer->set_billing_phone('');
        $customer->set_shipping_first_name('');
        $customer->set_shipping_last_name('');
        $customer->set_shipping_company('');
        $customer->set_shipping_address_1('');
        $customer->set_shipping_address_2('');
        $customer->set_shipping_city('');
        $customer->set_shipping_state('');
        $customer->set_shipping_postcode('');
        $customer->set_shipping_country('');
        $customer->save();
        
        // Clear WMS metadata
        delete_user_meta($user->ID, '_wms_customer_id');
        delete_user_meta($user->ID, '_wms_customer_code');
        delete_user_meta($user->ID, '_wms_customer_name');
        delete_user_meta($user->ID, '_wms_synced_at');
        
        // Add anonymization metadata
        update_user_meta($user->ID, '_anonymized_at', current_time('mysql'));
        update_user_meta($user->ID, '_anonymized_by', 'gdpr_request');
        
        $this->client->logger()->info('WooCommerce customer anonymized', [
            'user_id' => $user->ID,
            'original_email' => $user->user_email,
            'anonymous_id' => $anonymousId
        ]);
    }
    
    /**
     * Get GDPR compliance statistics
     */
    public function getGdprStatistics(): array {
        global $wpdb;
        
        // Count anonymized customers
        $anonymizedCustomers = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} um
             WHERE um.meta_key = '_anonymized_at'"
        );
        
        // Count customers with WMS data
        $wmsCustomers = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} um
             WHERE um.meta_key = '_wms_customer_id'"
        );
        
        // Get recent anonymization activity
        $recentAnonymizations = $wpdb->get_results(
            "SELECT COUNT(*) as count, DATE(FROM_UNIXTIME(meta_value)) as date
             FROM {$wpdb->usermeta} um
             WHERE um.meta_key = '_anonymized_at'
             AND um.meta_value > UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 30 DAY))
             GROUP BY DATE(FROM_UNIXTIME(meta_value))
             ORDER BY date DESC"
        );
        
        return [
            'anonymized_customers' => intval($anonymizedCustomers),
            'wms_customers' => intval($wmsCustomers),
            'recent_anonymizations' => $recentAnonymizations,
            'gdpr_enabled' => apply_filters('wc_wms_gdpr_enabled', true)
        ];
    }
}