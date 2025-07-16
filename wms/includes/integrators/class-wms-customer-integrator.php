<?php
/**
 * WMS Customer Integrator
 * 
 * Integrates WooCommerce customers with WMS
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Customer_Integrator implements WC_WMS_Customer_Integrator_Interface {
    
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
     * Get integrator name (Interface requirement)
     */
    public function getIntegratorName(): string {
        return 'customer';
    }
    
    /**
     * Check if integrator is ready (Interface requirement)
     */
    public function isReady(): bool {
        try {
            // Check if WMS client is available
            if (!$this->client || !$this->client->authenticator()->isAuthenticated()) {
                return false;
            }
            
            // Check if customer service is available
            if (!$this->client->customers() || !$this->client->customers()->isAvailable()) {
                return false;
            }
            
            // Check database connectivity
            global $wpdb;
            if (!$wpdb || $wpdb->last_error) {
                return false;
            }
            
            return true;
            
        } catch (Exception $e) {
            $this->client->logger()->error('Customer integrator readiness check failed', [
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
            'last_import' => null,
            'customers_synced' => 0,
            'pending_syncs' => 0,
            'import_errors' => 0,
            'health_score' => 0,
            'issues' => []
        ];
        
        try {
            // Get sync statistics
            $syncStats = $this->getCustomerIntegrationStats();
            $status['customers_synced'] = $syncStats['wms_customers'] ?? 0;
            $status['last_import'] = $syncStats['integration_stats']['last_import'] ?? null;
            
            // Calculate health score (0-100)
            $healthScore = 100;
            
            if (!$status['ready']) {
                $healthScore -= 50;
                $status['issues'][] = 'Integrator not ready';
            }
            
            if (!$status['last_import'] || $status['last_import'] < strtotime('-7 days')) {
                $healthScore -= 20;
                $status['issues'][] = 'No recent customer import';
            }
            
            $status['health_score'] = max(0, $healthScore);
            
        } catch (Exception $e) {
            $status['issues'][] = 'Failed to get status: ' . $e->getMessage();
            $status['health_score'] = 0;
        }
        
        return $status;
    }
    
    /**
     * Import customers from WMS to WooCommerce
     */
    public function importCustomersFromWMS(array $params = []): array {
        $this->client->logger()->info('Starting customer import from WMS', $params);
        
        $customers = $this->client->customers()->getCustomers($params);
        
        $results = [
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'error_details' => []
        ];
        
        foreach ($customers as $wmsCustomer) {
            try {
                $result = $this->importSingleCustomer($wmsCustomer);
                
                switch ($result['action']) {
                    case 'imported':
                        $results['imported']++;
                        break;
                    case 'updated':
                        $results['updated']++;
                        break;
                    case 'skipped':
                        $results['skipped']++;
                        break;
                }
                
            } catch (Exception $e) {
                $results['errors']++;
                $results['error_details'][] = [
                    'customer_id' => $wmsCustomer['id'] ?? 'unknown',
                    'customer_name' => $wmsCustomer['name'] ?? 'unknown',
                    'error' => $e->getMessage()
                ];
                
                $this->client->logger()->error('Failed to import customer from WMS', [
                    'wms_customer_id' => $wmsCustomer['id'] ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        // Update last import timestamp
        update_option('wc_wms_customers_last_import', time());
        
        $this->client->logger()->info('Customer import completed', $results);
        
        return $results;
    }
    
    /**
     * Import single customer from WMS
     */
    public function importSingleCustomer(array $wmsCustomer): array {
        $this->client->logger()->debug('Processing customer import', [
            'wms_customer_id' => $wmsCustomer['id'],
            'customer_name' => $wmsCustomer['name']
        ]);
        
        // Check if customer already exists in WooCommerce
        $existingCustomer = $this->client->customers()->findWooCommerceCustomerByWmsId($wmsCustomer['id']);
        
        if ($existingCustomer) {
            // Update existing customer
            $result = $this->client->customers()->updateWooCommerceCustomer($existingCustomer, $wmsCustomer);
            $result['action'] = ($result['changed'] ?? true) ? 'updated' : 'skipped';
            return $result;
        }
        
        // Try to find by customer code
        $existingCustomer = $this->client->customers()->findWooCommerceCustomerByCode($wmsCustomer['code']);
        
        if ($existingCustomer) {
            // Link existing customer to WMS
            $result = $this->client->customers()->linkWooCommerceCustomerToWms($existingCustomer, $wmsCustomer);
            $result['action'] = 'updated'; // Always updated when linking to WMS
            return $result;
        }
        
        // Create new customer
        $result = $this->client->customers()->createWooCommerceCustomer($wmsCustomer);
        $result['action'] = 'imported';
        return $result;
    }
    
    /**
     * Sync customer data between WMS and WooCommerce
     */
    public function syncCustomerData(WP_User $user): array {
        $this->client->logger()->info('Syncing customer data', [
            'user_id' => $user->ID,
            'user_email' => $user->user_email
        ]);
        
        $wmsCustomerId = get_user_meta($user->ID, '_wms_customer_id', true);
        
        if (empty($wmsCustomerId)) {
            throw new Exception('User is not linked to WMS customer');
        }
        
        try {
            // Get customer data from WMS
            $wmsCustomer = $this->client->customers()->getCustomer($wmsCustomerId);
            
            // Update WooCommerce customer with WMS data
            $result = $this->client->customers()->updateWooCommerceCustomer($user, $wmsCustomer);
            
            $this->client->logger()->info('Customer data synced successfully', [
                'user_id' => $user->ID,
                'wms_customer_id' => $wmsCustomerId,
                'changed' => $result['changed'] ?? false
            ]);
            
            return $result;
            
        } catch (Exception $e) {
            $this->client->logger()->error('Failed to sync customer data', [
                'user_id' => $user->ID,
                'wms_customer_id' => $wmsCustomerId,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Link WooCommerce customer to WMS customer
     */
    public function linkCustomerToWMS(int $woocommerceCustomerId, string $wmsCustomerId): array {
        $this->client->logger()->info('Linking WooCommerce customer to WMS', [
            'woocommerce_customer_id' => $woocommerceCustomerId,
            'wms_customer_id' => $wmsCustomerId
        ]);
        
        $user = get_user_by('ID', $woocommerceCustomerId);
        if (!$user) {
            throw new Exception('WooCommerce customer not found');
        }
        
        // Get WMS customer data
        $wmsCustomer = $this->client->customers()->getCustomer($wmsCustomerId);
        
        // Link the customer
        $result = $this->client->customers()->linkWooCommerceCustomerToWms($user, $wmsCustomer);
        
        $this->client->logger()->info('Customer linked to WMS successfully', [
            'woocommerce_customer_id' => $woocommerceCustomerId,
            'wms_customer_id' => $wmsCustomerId,
            'customer_code' => $wmsCustomer['code']
        ]);
        
        return $result;
    }
    
    /**
     * Unlink WooCommerce customer from WMS
     */
    public function unlinkCustomerFromWMS(int $woocommerceCustomerId): bool {
        $this->client->logger()->info('Unlinking WooCommerce customer from WMS', [
            'woocommerce_customer_id' => $woocommerceCustomerId
        ]);
        
        $user = get_user_by('ID', $woocommerceCustomerId);
        if (!$user) {
            throw new Exception('WooCommerce customer not found');
        }
        
        // Remove WMS metadata
        delete_user_meta($user->ID, '_wms_customer_id');
        delete_user_meta($user->ID, '_wms_customer_code');
        delete_user_meta($user->ID, '_wms_customer_name');
        delete_user_meta($user->ID, '_wms_synced_at');
        delete_user_meta($user->ID, '_wms_created_at');
        
        // Add unlink metadata
        update_user_meta($user->ID, '_wms_unlinked_at', current_time('mysql'));
        
        $this->client->logger()->info('Customer unlinked from WMS successfully', [
            'woocommerce_customer_id' => $woocommerceCustomerId
        ]);
        
        return true;
    }
    
    /**
     * Get customer sync status
     */
    public function getCustomerSyncStatus(int $woocommerceCustomerId): array {
        $user = get_user_by('ID', $woocommerceCustomerId);
        if (!$user) {
            throw new Exception('WooCommerce customer not found');
        }
        
        $wmsCustomerId = get_user_meta($user->ID, '_wms_customer_id', true);
        $wmsCustomerCode = get_user_meta($user->ID, '_wms_customer_code', true);
        $lastSyncedAt = get_user_meta($user->ID, '_wms_synced_at', true);
        $createdAt = get_user_meta($user->ID, '_wms_created_at', true);
        $unlinkedAt = get_user_meta($user->ID, '_wms_unlinked_at', true);
        
        $status = [
            'user_id' => $user->ID,
            'user_email' => $user->user_email,
            'is_linked' => !empty($wmsCustomerId),
            'wms_customer_id' => $wmsCustomerId,
            'wms_customer_code' => $wmsCustomerCode,
            'last_synced_at' => $lastSyncedAt,
            'created_at' => $createdAt,
            'unlinked_at' => $unlinkedAt,
            'needs_sync' => $this->client->customers()->customerNeedsSync($user)
        ];
        
        return $status;
    }
    
    /**
     * Bulk sync customers from WMS
     */
    public function bulkSyncCustomersFromWMS(array $customerIds = []): array {
        $this->client->logger()->info('Starting bulk customer sync from WMS', [
            'customer_ids' => $customerIds
        ]);
        
        $results = [
            'synced' => 0,
            'errors' => 0,
            'error_details' => []
        ];
        
        // Get customers to sync
        if (empty($customerIds)) {
            $users = get_users([
                'meta_query' => [
                    [
                        'key' => '_wms_customer_id',
                        'compare' => 'EXISTS'
                    ]
                ],
                'number' => 100
            ]);
        } else {
            $users = array_map('get_user_by', array_fill(0, count($customerIds), 'ID'), $customerIds);
            $users = array_filter($users);
        }
        
        foreach ($users as $user) {
            try {
                $result = $this->syncCustomerData($user);
                
                if ($result['changed'] ?? false) {
                    $results['synced']++;
                }
                
            } catch (Exception $e) {
                $results['errors']++;
                $results['error_details'][] = [
                    'user_id' => $user->ID,
                    'user_email' => $user->user_email,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        $this->client->logger()->info('Bulk customer sync from WMS completed', $results);
        
        return $results;
    }
    
    /**
     * Find matching customers between WMS and WooCommerce
     */
    public function findMatchingCustomers(): array {
        $this->client->logger()->info('Finding matching customers between WMS and WooCommerce');
        
        // Get all WMS customers
        $wmsCustomers = $this->client->customers()->getCustomers(['limit' => 1000]);
        
        // Get all WooCommerce customers
        $wcCustomers = get_users([
            'role' => 'customer',
            'number' => 1000
        ]);
        
        $matches = [];
        $potentialMatches = [];
        
        foreach ($wmsCustomers as $wmsCustomer) {
            $wmsEmail = $wmsCustomer['email'] ?? '';
            $wmsCode = $wmsCustomer['code'] ?? '';
            $wmsName = $wmsCustomer['name'] ?? '';
            
            foreach ($wcCustomers as $wcCustomer) {
                $wcEmail = $wcCustomer->user_email;
                $wcDisplayName = $wcCustomer->display_name;
                
                // Exact email match
                if (!empty($wmsEmail) && $wmsEmail === $wcEmail) {
                    $matches[] = [
                        'type' => 'exact_email',
                        'wms_customer' => $wmsCustomer,
                        'wc_customer' => $wcCustomer,
                        'confidence' => 100
                    ];
                    continue 2; // Skip to next WMS customer
                }
                
                // Name similarity match
                if (!empty($wmsName) && !empty($wcDisplayName)) {
                    $similarity = 0;
                    similar_text(strtolower($wmsName), strtolower($wcDisplayName), $similarity);
                    
                    if ($similarity > 80) {
                        $potentialMatches[] = [
                            'type' => 'name_similarity',
                            'wms_customer' => $wmsCustomer,
                            'wc_customer' => $wcCustomer,
                            'confidence' => $similarity
                        ];
                    }
                }
            }
        }
        
        // Sort potential matches by confidence
        usort($potentialMatches, function($a, $b) {
            return $b['confidence'] - $a['confidence'];
        });
        
        $this->client->logger()->info('Customer matching completed', [
            'exact_matches' => count($matches),
            'potential_matches' => count($potentialMatches)
        ]);
        
        return [
            'exact_matches' => $matches,
            'potential_matches' => array_slice($potentialMatches, 0, 50), // Limit to top 50
            'stats' => [
                'wms_customers' => count($wmsCustomers),
                'wc_customers' => count($wcCustomers),
                'exact_matches' => count($matches),
                'potential_matches' => count($potentialMatches)
            ]
        ];
    }
    
    /**
     * Auto-link matching customers
     */
    public function autoLinkMatchingCustomers(array $matches): array {
        $this->client->logger()->info('Auto-linking matching customers', [
            'match_count' => count($matches)
        ]);
        
        $results = [
            'linked' => 0,
            'errors' => 0,
            'error_details' => []
        ];
        
        foreach ($matches as $match) {
            try {
                $wmsCustomer = $match['wms_customer'];
                $wcCustomer = $match['wc_customer'];
                
                // Only auto-link exact matches
                if ($match['type'] === 'exact_email' && $match['confidence'] >= 100) {
                    $this->linkCustomerToWMS($wcCustomer->ID, $wmsCustomer['id']);
                    $results['linked']++;
                }
                
            } catch (Exception $e) {
                $results['errors']++;
                $results['error_details'][] = [
                    'wms_customer_id' => $match['wms_customer']['id'] ?? 'unknown',
                    'wc_customer_id' => $match['wc_customer']->ID ?? 'unknown',
                    'error' => $e->getMessage()
                ];
            }
        }
        
        $this->client->logger()->info('Auto-linking completed', $results);
        
        return $results;
    }
    
    /**
     * Get customer integration statistics
     */
    public function getCustomerIntegrationStats(): array {
        return array_merge(
            $this->client->customers()->getSyncStatistics(),
            [
                'integration_stats' => [
                    'last_import' => get_option('wc_wms_customers_last_import', 0),
                    'last_sync' => get_option('wc_wms_customers_last_sync', 0),
                    'auto_sync_enabled' => apply_filters('wc_wms_customer_auto_sync', false)
                ]
            ]
        );
    }
    
    /**
     * Sync customer to WMS (Interface requirement)
     */
    public function syncCustomer(int $customerId): mixed {
        $customer = new WC_Customer($customerId);
        if (!$customer->get_id()) {
            return new WP_Error('customer_not_found', 'Customer not found: ' . $customerId);
        }
        
        // Check if customer should be synced
        if (!$this->shouldSyncCustomer($customer)) {
            return new WP_Error('customer_not_syncable', 'Customer should not be synced: ' . $customerId);
        }
        
        try {
            // Transform customer data for WMS
            $customerData = $this->transformCustomerData($customer);
            
            // Check if customer already exists in WMS
            $wmsCustomerId = get_user_meta($customer->get_id(), '_wms_customer_id', true);
            
            if ($wmsCustomerId) {
                // Update existing customer in WMS
                $result = $this->client->customers()->updateCustomer($wmsCustomerId, $customerData);
                $result['action'] = 'updated';
            } else {
                // Create new customer in WMS
                $result = $this->client->customers()->createCustomer($customerData);
                $result['action'] = 'created';
                
                // Store WMS customer ID
                if (isset($result['id'])) {
                    update_user_meta($customer->get_id(), '_wms_customer_id', $result['id']);
                    update_user_meta($customer->get_id(), '_wms_synced_at', current_time('mysql'));
                }
            }
            
            return $result;
            
        } catch (Exception $e) {
            return new WP_Error('sync_failed', 'Failed to sync customer: ' . $e->getMessage());
        }
    }
    
    /**
     * Transform WooCommerce customer data for WMS (Interface requirement)
     */
    public function transformCustomerData(WC_Customer $customer): array {
        $customerData = [
            'external_reference' => (string) $customer->get_id(),
            'code' => 'WC_' . $customer->get_id(),
            'name' => trim($customer->get_first_name() . ' ' . $customer->get_last_name()),
            'first_name' => $customer->get_first_name(),
            'last_name' => $customer->get_last_name(),
            'email' => $customer->get_email(),
            'phone' => $customer->get_billing_phone(),
            'company' => $customer->get_billing_company(),
            'billing_address' => [
                'street1' => $customer->get_billing_address_1(),
                'street2' => $customer->get_billing_address_2(),
                'city' => $customer->get_billing_city(),
                'state' => $customer->get_billing_state(),
                'postal_code' => $customer->get_billing_postcode(),
                'country' => $customer->get_billing_country()
            ],
            'shipping_address' => [
                'street1' => $customer->get_shipping_address_1() ?: $customer->get_billing_address_1(),
                'street2' => $customer->get_shipping_address_2() ?: $customer->get_billing_address_2(),
                'city' => $customer->get_shipping_city() ?: $customer->get_billing_city(),
                'state' => $customer->get_shipping_state() ?: $customer->get_billing_state(),
                'postal_code' => $customer->get_shipping_postcode() ?: $customer->get_billing_postcode(),
                'country' => $customer->get_shipping_country() ?: $customer->get_billing_country()
            ],
            'date_created' => $customer->get_date_created() ? $customer->get_date_created()->format('Y-m-d H:i:s') : null,
            'last_order_date' => $customer->get_last_order() ? $customer->get_last_order()->get_date_created()->format('Y-m-d H:i:s') : null,
            'order_count' => $customer->get_order_count(),
            'total_spent' => floatval($customer->get_total_spent()),
            'tags' => $this->getCustomerTags($customer)
        ];
        
        // Remove empty values
        $customerData = array_filter($customerData, function($value) {
            return $value !== null && $value !== '';
        });
        
        return $customerData;
    }
    
    /**
     * Check if customer should be synced to WMS (Interface requirement)
     */
    public function shouldSyncCustomer(WC_Customer $customer): bool {
        // Don't sync if customer doesn't have an email
        if (empty($customer->get_email())) {
            return false;
        }
        
        // Don't sync if customer has no orders
        if ($customer->get_order_count() === 0) {
            return false;
        }
        
        // Don't sync if explicitly disabled
        if (get_user_meta($customer->get_id(), '_wms_sync_disabled', true) === 'yes') {
            return false;
        }
        
        // Don't sync if customer needs sync check fails
        if (!$this->client->customers()->customerNeedsSync($customer)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Get customer tags for WMS
     */
    private function getCustomerTags(WC_Customer $customer): array {
        $tags = [];
        
        // Add customer role as tag
        $user = get_user_by('ID', $customer->get_id());
        if ($user && !empty($user->roles)) {
            $tags[] = 'role:' . implode(',', $user->roles);
        }
        
        // Add order count tier as tag
        $orderCount = $customer->get_order_count();
        if ($orderCount >= 50) {
            $tags[] = 'tier:vip';
        } elseif ($orderCount >= 10) {
            $tags[] = 'tier:regular';
        } else {
            $tags[] = 'tier:new';
        }
        
        // Add spending tier as tag
        $totalSpent = floatval($customer->get_total_spent());
        if ($totalSpent >= 1000) {
            $tags[] = 'spending:high';
        } elseif ($totalSpent >= 250) {
            $tags[] = 'spending:medium';
        } else {
            $tags[] = 'spending:low';
        }
        
        // Add custom tags from customer meta
        $customTags = get_user_meta($customer->get_id(), '_wms_tags', true);
        if ($customTags && is_array($customTags)) {
            $tags = array_merge($tags, $customTags);
        }
        
        return $tags;
    }
}