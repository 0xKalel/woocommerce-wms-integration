<?php
/**
 * WMS Customer Service
 * 
 * Handles all customer operations with WMS
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Customer_Service implements WC_WMS_Customer_Service_Interface {
    
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
     * Get service name (Interface requirement)
     */
    public function getServiceName(): string {
        return 'customer';
    }
    
    /**
     * Check if service is available (Interface requirement)
     */
    public function isAvailable(): bool {
        return $this->client->config()->hasValidCredentials();
    }
    
    /**
     * Get service configuration (Interface requirement)
     */
    public function getConfig(): array {
        return [
            'service_name' => $this->getServiceName(),
            'is_available' => $this->isAvailable(),
            'endpoints' => [
                'customers' => '/wms/customers/',
                'customer' => '/wms/customers/{id}/',
                'search' => '/wms/customers/?search={query}'
            ],
            'capabilities' => [
                'read_customers' => true,
                'create_customers' => false, // Read-only API
                'update_customers' => false, // Read-only API
                'delete_customers' => false  // Read-only API
            ],
            'limits' => [
                'max_per_request' => 100,
                'default_limit' => 50
            ]
        ];
    }
    
    /**
     * Get customers from WMS
     */
    public function getCustomers(array $params = []): array {
        $allowedParams = [
            'limit' => 'integer',
            'page' => 'integer',
            'from' => 'date',
            'to' => 'date',
            'direction' => 'string',
            'sort' => 'string'
        ];
        
        $filteredParams = [];
        foreach ($params as $key => $value) {
            if (isset($allowedParams[$key]) && !empty($value)) {
                $filteredParams[$key] = $value;
            }
        }
        
        $endpoint = '/wms/customers/';
        if (!empty($filteredParams)) {
            $endpoint .= '?' . http_build_query($filteredParams);
        }
        
        $this->client->logger()->debug('Getting customers from WMS', [
            'endpoint' => $endpoint,
            'params' => $filteredParams
        ]);
        
        $response = $this->client->makeAuthenticatedRequest('GET', $endpoint);
        
        $customerCount = is_array($response) ? count($response) : 0;
        $this->client->logger()->info('Customers retrieved successfully', [
            'customer_count' => $customerCount
        ]);
        
        return $response;
    }
    
    /**
     * Get single customer from WMS
     */
    public function getCustomer(string $customerId): array {
        $this->client->logger()->debug('Getting customer from WMS', [
            'customer_id' => $customerId
        ]);
        
        $response = $this->client->makeAuthenticatedRequest('GET', '/wms/customers/' . $customerId . '/');
        
        if (isset($response['id'])) {
            $this->client->logger()->info('Customer retrieved successfully', [
                'customer_id' => $customerId,
                'customer_name' => $response['name'] ?? 'unknown'
            ]);
        }
        
        return $response;
    }
    
    /**
     * Search customers by criteria
     */
    public function searchCustomers(array $criteria): array {
        $this->client->logger()->info('Searching customers', [
            'criteria' => $criteria
        ]);
        
        return $this->getCustomers($criteria);
    }
    
    /**
     * Get customer by code
     */
    public function getCustomerByCode(string $customerCode): ?array {
        $this->client->logger()->debug('Getting customer by code', [
            'customer_code' => $customerCode
        ]);
        
        $customers = $this->getCustomers(['limit' => 100]);
        
        foreach ($customers as $customer) {
            if (isset($customer['code']) && $customer['code'] === $customerCode) {
                $this->client->logger()->debug('Customer found by code', [
                    'customer_code' => $customerCode,
                    'customer_id' => $customer['id'] ?? 'unknown'
                ]);
                return $customer;
            }
        }
        
        $this->client->logger()->debug('No customer found by code', [
            'customer_code' => $customerCode
        ]);
        
        return null;
    }
    
    /**
     * Find WooCommerce customer by WMS ID
     */
    public function findWooCommerceCustomerByWmsId(string $wmsCustomerId): ?WP_User {
        $users = get_users([
            'meta_key' => '_wms_customer_id',
            'meta_value' => $wmsCustomerId,
            'number' => 1
        ]);
        
        return !empty($users) ? $users[0] : null;
    }
    
    /**
     * Find WooCommerce customer by customer code
     */
    public function findWooCommerceCustomerByCode(string $customerCode): ?WP_User {
        $users = get_users([
            'meta_key' => '_wms_customer_code',
            'meta_value' => $customerCode,
            'number' => 1
        ]);
        
        return !empty($users) ? $users[0] : null;
    }
    
    /**
     * Generate customer email from WMS data
     */
    public function generateCustomerEmail(array $wmsCustomer): string {
        if (!empty($wmsCustomer['email'])) {
            return $wmsCustomer['email'];
        }
        
        $domain = $this->client->config()->getCustomerEmailDomain();
        return strtolower($wmsCustomer['code']) . '@' . $domain;
    }
    
    /**
     * Extract first name from full name
     */
    public function extractFirstName(string $fullName): string {
        $parts = explode(' ', trim($fullName));
        return $parts[0] ?? '';
    }
    
    /**
     * Extract last name from full name
     */
    public function extractLastName(string $fullName): string {
        $parts = explode(' ', trim($fullName));
        if (count($parts) > 1) {
            array_shift($parts);
            return implode(' ', $parts);
        }
        return '';
    }
    
    /**
     * Create WooCommerce customer from WMS data
     */
    public function createWooCommerceCustomer(array $wmsCustomer): array {
        $this->client->logger()->info('Creating WooCommerce customer from WMS data', [
            'wms_customer_id' => $wmsCustomer['id'],
            'customer_name' => $wmsCustomer['name']
        ]);
        
        $email = $this->generateCustomerEmail($wmsCustomer);
        
        if (email_exists($email)) {
            throw new Exception('Customer email already exists in WooCommerce');
        }
        
        $userData = [
            'user_login' => $wmsCustomer['code'],
            'user_email' => $email,
            'user_pass' => wp_generate_password(),
            'display_name' => $wmsCustomer['name'],
            'role' => 'customer'
        ];
        
        $userId = wp_insert_user($userData);
        
        if (is_wp_error($userId)) {
            throw new Exception($userId->get_error_message());
        }
        
        // Add WMS metadata
        update_user_meta($userId, '_wms_customer_id', $wmsCustomer['id']);
        update_user_meta($userId, '_wms_customer_code', $wmsCustomer['code']);
        update_user_meta($userId, '_wms_customer_name', $wmsCustomer['name']);
        update_user_meta($userId, '_wms_synced_at', current_time('mysql'));
        update_user_meta($userId, '_wms_created_at', $wmsCustomer['created_at'] ?? current_time('mysql'));
        
        // Set WooCommerce customer data
        $customer = new WC_Customer($userId);
        $customer->set_first_name($this->extractFirstName($wmsCustomer['name']));
        $customer->set_last_name($this->extractLastName($wmsCustomer['name']));
        $customer->save();
        
        $this->client->logger()->info('WooCommerce customer created successfully', [
            'user_id' => $userId,
            'wms_customer_id' => $wmsCustomer['id'],
            'customer_code' => $wmsCustomer['code']
        ]);
        
        return [
            'user_id' => $userId,
            'wms_customer_id' => $wmsCustomer['id'],
            'customer_code' => $wmsCustomer['code']
        ];
    }
    
    /**
     * Update WooCommerce customer from WMS data
     */
    public function updateWooCommerceCustomer(WP_User $user, array $wmsCustomer): array {
        $this->client->logger()->debug('Updating WooCommerce customer from WMS data', [
            'user_id' => $user->ID,
            'wms_customer_id' => $wmsCustomer['id']
        ]);
        
        // Check if data has actually changed
        $currentDisplayName = $user->display_name;
        $currentWmsName = get_user_meta($user->ID, '_wms_customer_name', true);
        
        $customer = new WC_Customer($user->ID);
        $currentFirstName = $customer->get_first_name();
        $currentLastName = $customer->get_last_name();
        
        $newFirstName = $this->extractFirstName($wmsCustomer['name']);
        $newLastName = $this->extractLastName($wmsCustomer['name']);
        
        $hasChanges = (
            $currentDisplayName !== $wmsCustomer['name'] ||
            $currentWmsName !== $wmsCustomer['name'] ||
            $currentFirstName !== $newFirstName ||
            $currentLastName !== $newLastName
        );
        
        if (!$hasChanges) {
            $this->client->logger()->debug('No changes detected for WooCommerce customer', [
                'user_id' => $user->ID,
                'wms_customer_id' => $wmsCustomer['id']
            ]);
            
            return [
                'user_id' => $user->ID,
                'wms_customer_id' => $wmsCustomer['id'],
                'customer_code' => $wmsCustomer['code'],
                'changed' => false
            ];
        }
        
        // Update user data
        $userData = [
            'ID' => $user->ID,
            'display_name' => $wmsCustomer['name']
        ];
        
        $result = wp_update_user($userData);
        if (is_wp_error($result)) {
            throw new Exception($result->get_error_message());
        }
        
        // Update WMS metadata
        update_user_meta($user->ID, '_wms_customer_name', $wmsCustomer['name']);
        update_user_meta($user->ID, '_wms_synced_at', current_time('mysql'));
        
        // Update WooCommerce customer data
        $customer->set_first_name($newFirstName);
        $customer->set_last_name($newLastName);
        $customer->save();
        
        $this->client->logger()->info('WooCommerce customer updated successfully', [
            'user_id' => $user->ID,
            'wms_customer_id' => $wmsCustomer['id'],
            'customer_code' => $wmsCustomer['code']
        ]);
        
        return [
            'user_id' => $user->ID,
            'wms_customer_id' => $wmsCustomer['id'],
            'customer_code' => $wmsCustomer['code'],
            'changed' => true
        ];
    }
    
    /**
     * Link existing WooCommerce customer to WMS
     */
    public function linkWooCommerceCustomerToWms(WP_User $user, array $wmsCustomer): array {
        $this->client->logger()->info('Linking WooCommerce customer to WMS', [
            'user_id' => $user->ID,
            'wms_customer_id' => $wmsCustomer['id']
        ]);
        
        update_user_meta($user->ID, '_wms_customer_id', $wmsCustomer['id']);
        update_user_meta($user->ID, '_wms_customer_code', $wmsCustomer['code']);
        update_user_meta($user->ID, '_wms_customer_name', $wmsCustomer['name']);
        update_user_meta($user->ID, '_wms_synced_at', current_time('mysql'));
        
        $this->client->logger()->info('WooCommerce customer linked to WMS successfully', [
            'user_id' => $user->ID,
            'wms_customer_id' => $wmsCustomer['id'],
            'customer_code' => $wmsCustomer['code']
        ]);
        
        return [
            'user_id' => $user->ID,
            'wms_customer_id' => $wmsCustomer['id'],
            'customer_code' => $wmsCustomer['code']
        ];
    }
    
    /**
     * Get customer sync statistics
     */
    public function getSyncStatistics(): array {
        global $wpdb;
        
        // Count customers with WMS IDs
        $importedCustomers = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} um
             INNER JOIN {$wpdb->users} u ON um.user_id = u.ID
             INNER JOIN {$wpdb->usermeta} um2 ON u.ID = um2.user_id
             WHERE um.meta_key = '_wms_customer_id' 
             AND um2.meta_key = '{$wpdb->prefix}capabilities' 
             AND um2.meta_value LIKE '%customer%'"
        );
        
        // Count total customers
        $totalCustomers = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->users} u
             INNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id
             WHERE um.meta_key = '{$wpdb->prefix}capabilities' 
             AND um.meta_value LIKE '%customer%'"
        );
        
        // Get last import timestamp
        $lastImport = get_option('wc_wms_customers_last_import', 0);
        
        return [
            'imported_customers' => intval($importedCustomers),
            'total_customers' => intval($totalCustomers),
            'sync_percentage' => $totalCustomers > 0 ? 
                round(($importedCustomers / $totalCustomers) * 100, 2) : 0,
            'last_import' => $lastImport,
            'last_import_formatted' => $lastImport ? date('Y-m-d H:i:s', $lastImport) : 'Never',
            'api_note' => 'WMS Customers API is read-only. Customers must be managed in WMS system.'
        ];
    }
    
    /**
     * Get recent customers from WMS
     */
    public function getRecentCustomers(int $days = 30, int $limit = 50): array {
        $fromDate = date('Y-m-d', strtotime("-{$days} days"));
        $toDate = date('Y-m-d');
        
        return $this->getCustomers([
            'from' => $fromDate,
            'to' => $toDate,
            'limit' => $limit,
            'sort' => 'createdAt',
            'direction' => 'desc'
        ]);
    }
    
    /**
     * Check if customer needs sync
     */
    public function customerNeedsSync(WP_User $user): bool {
        $wmsCustomerId = get_user_meta($user->ID, '_wms_customer_id', true);
        $lastSyncedAt = get_user_meta($user->ID, '_wms_synced_at', true);
        
        // If never synced
        if (empty($wmsCustomerId) || empty($lastSyncedAt)) {
            return true;
        }
        
        // Check if user was modified after last sync
        $userModified = get_user_meta($user->ID, 'last_activity', true);
        if ($userModified && $userModified > strtotime($lastSyncedAt)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Mark customer as synced
     */
    public function markCustomerAsSynced(WP_User $user, string $wmsCustomerId): void {
        update_user_meta($user->ID, '_wms_customer_id', $wmsCustomerId);
        update_user_meta($user->ID, '_wms_synced_at', current_time('mysql'));
        
        $this->client->logger()->debug('Customer marked as synced', [
            'user_id' => $user->ID,
            'wms_customer_id' => $wmsCustomerId
        ]);
    }
    
    /**
     * Create customer in WMS (Interface requirement)
     * Note: WMS Customer API is read-only, customers must be managed in WMS system
     */
    public function createCustomer(array $customerData): mixed {
        $this->client->logger()->warning('Attempted to create customer in read-only WMS Customer API', [
            'customer_data' => $customerData
        ]);
        
        return new WP_Error(
            'wms_customer_readonly', 
            'WMS Customer API is read-only. Customers must be created in the WMS system.',
            ['status' => 405]
        );
    }
    
    /**
     * Update customer in WMS (Interface requirement)
     * Note: WMS Customer API is read-only, customers must be managed in WMS system
     */
    public function updateCustomer(string $customerId, array $customerData): mixed {
        $this->client->logger()->warning('Attempted to update customer in read-only WMS Customer API', [
            'customer_id' => $customerId,
            'customer_data' => $customerData
        ]);
        
        return new WP_Error(
            'wms_customer_readonly',
            'WMS Customer API is read-only. Customers must be updated in the WMS system.',
            ['status' => 405]
        );
    }
}