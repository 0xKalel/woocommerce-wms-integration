<?php
/**
 * WMS Shipment Service
 * 
 * Handles all shipment operations with WMS
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Shipment_Service {
    
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
     * Check if shipment service is available
     */
    public function isAvailable(): bool {
        try {
            // Check if client is authenticated
            if (!$this->client || !$this->client->authenticator()->isAuthenticated()) {
                return false;
            }
            
            // Check if we can make a basic request to the shipments endpoint
            $testResponse = $this->client->makeAuthenticatedRequest('GET', '/wms/shipments/?limit=1');
            return !is_wp_error($testResponse);
            
        } catch (Exception $e) {
            $this->client->logger()->error('Shipment service availability check failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Get shipments from WMS
     */
    public function getShipments(array $params = []): array {
        $endpoint = '/wms/shipments/';
        if (!empty($params)) {
            $endpoint .= '?' . http_build_query($params);
        }
        
        // Add expand header for detailed shipment data
        $extraHeaders = [
            'Expand' => 'shipment_lines,shipment_labels,shipping_address,shipping_method,serial_numbers,variant,return_label'
        ];
        
        $this->client->logger()->debug('Getting shipments from WMS', [
            'endpoint' => $endpoint,
            'params' => $params
        ]);
        
        $response = $this->client->makeAuthenticatedRequest('GET', $endpoint, null, $extraHeaders);
        
        $shipmentCount = is_array($response) ? count($response) : 0;
        $this->client->logger()->info('Shipments retrieved successfully', [
            'shipment_count' => $shipmentCount
        ]);
        
        return $response;
    }
    
    /**
     * Get single shipment from WMS
     */
    public function getShipment(string $shipmentId, ?string $expandGroups = null): array {
        $defaultExpand = 'shipment_lines,shipment_labels,shipping_address,shipping_method,serial_numbers,variant,return_label,documents';
        $expand = $expandGroups ?: $defaultExpand;
        
        $extraHeaders = [
            'Expand' => $expand
        ];
        
        $this->client->logger()->debug('Getting shipment from WMS', [
            'shipment_id' => $shipmentId,
            'expand' => $expand
        ]);
        
        $response = $this->client->makeAuthenticatedRequest('GET', '/wms/shipments/' . $shipmentId . '/', null, $extraHeaders);
        
        if (isset($response['id'])) {
            $this->client->logger()->info('Shipment retrieved successfully', [
                'shipment_id' => $shipmentId,
                'reference' => $response['reference'] ?? 'unknown',
                'status' => $response['status'] ?? 'unknown'
            ]);
        }
        
        return $response;
    }
    
    /**
     * Get shipments by order external reference
     */
    public function getShipmentsByOrder(string $orderExternalReference, ?string $expandGroups = null): array {
        $params = [
            'order_external_reference' => $orderExternalReference
        ];
        
        $endpoint = '/wms/shipments/?' . http_build_query($params);
        
        $extraHeaders = [];
        if ($expandGroups) {
            $extraHeaders['Expand'] = $expandGroups;
        }
        
        $this->client->logger()->debug('Getting shipments by order reference', [
            'order_external_reference' => $orderExternalReference,
            'expand' => $expandGroups
        ]);
        
        return $this->client->makeAuthenticatedRequest('GET', $endpoint, null, $extraHeaders);
    }
    
    /**
     * Get return label for shipment
     */
    public function getReturnLabel(string $shipmentId): array {
        $this->client->logger()->debug('Getting return label for shipment', [
            'shipment_id' => $shipmentId
        ]);
        
        return $this->client->makeAuthenticatedRequest('GET', '/wms/shipments/' . $shipmentId . '/return-label/');
    }
    
    /**
     * Create return label for shipment
     */
    public function createReturnLabel(string $shipmentId): array {
        $this->client->logger()->info('Creating return label for shipment', [
            'shipment_id' => $shipmentId
        ]);
        
        $response = $this->client->makeAuthenticatedRequest('POST', '/wms/shipments/' . $shipmentId . '/return-label/');
        
        if (isset($response['url'])) {
            $this->client->logger()->info('Return label created successfully', [
                'shipment_id' => $shipmentId,
                'return_label_url' => $response['url']
            ]);
        }
        
        return $response;
    }
    
    /**
     * Get shipping methods from WMS
     */
    public function getShippingMethods(array $params = []): array {
        $endpoint = '/wms/shippingmethods/';
        if (!empty($params)) {
            $endpoint .= '?' . http_build_query($params);
        }
        
        $extraHeaders = [
            'Expand' => 'administration_code'
        ];
        
        $this->client->logger()->debug('Getting shipping methods from WMS', [
            'endpoint' => $endpoint,
            'params' => $params
        ]);
        
        return $this->client->makeAuthenticatedRequest('GET', $endpoint, null, $extraHeaders);
    }
    
    /**
     * Get single shipping method
     */
    public function getShippingMethod(string $shippingMethodId): array {
        $extraHeaders = [
            'Expand' => 'administration_code'
        ];
        
        $this->client->logger()->debug('Getting shipping method from WMS', [
            'shipping_method_id' => $shippingMethodId
        ]);
        
        return $this->client->makeAuthenticatedRequest('GET', '/wms/shippingmethods/' . $shippingMethodId . '/', null, $extraHeaders);
    }
    
    /**
     * Search shipping methods by criteria
     */
    public function searchShippingMethods(array $criteria = []): array {
        $allowedParams = [
            'code' => 'string',
            'shipper' => 'string',
            'shipper_code' => 'string',
            'shipping_software' => 'string',
            'limit' => 'integer',
            'page' => 'integer',
            'from' => 'date',
            'to' => 'date',
            'direction' => 'string',
            'sort' => 'string'
        ];
        
        $params = [];
        foreach ($criteria as $key => $value) {
            if (isset($allowedParams[$key]) && !empty($value)) {
                $params[$key] = $value;
            }
        }
        
        $this->client->logger()->info('Searching shipping methods', [
            'criteria' => $params
        ]);
        
        return $this->getShippingMethods($params);
    }
    
    /**
     * Sync shipping methods from WMS to WordPress options
     */
    public function syncShippingMethods(): array {
        $this->client->logger()->info('Syncing shipping methods from WMS');
        
        $shippingMethods = $this->getShippingMethods(['limit' => 100]);
        
        $methodMapping = [];
        $methodOptions = [];
        
        foreach ($shippingMethods as $method) {
            $methodId = $method['id'];
            $methodCode = $method['code'];
            $methodName = $method['description'] ?? $methodCode;
            
            $methodMapping[$methodCode] = $methodId;
            $methodOptions[$methodId] = [
                'id' => $methodId,
                'code' => $methodCode,
                'name' => $methodName,
                'shipper' => $method['shipper'],
                'shipper_code' => $method['shipper_code'],
                'shipping_software' => $method['shipping_software']
            ];
        }
        
        // Update WordPress options
        update_option('wc_wms_shipping_methods', $methodOptions);
        update_option('wc_wms_shipping_method_mapping', $methodMapping);
        update_option('wc_wms_shipping_methods_synced_at', current_time('mysql'));
        
        $this->client->logger()->info('Shipping methods synced successfully', [
            'count' => count($methodOptions),
            'methods' => array_keys($methodMapping)
        ]);
        
        return [
            'success' => true,
            'count' => count($methodOptions),
            'methods' => $methodOptions
        ];
    }
    
    /**
     * Get order shipments from WMS
     */
    public function getOrderShipments(string $orderExternalReference): array {
        $params = [
            'order_external_reference' => $orderExternalReference,
            'limit' => 10
        ];
        
        return $this->getShipments($params);
    }
    
    /**
     * Get recent shipments
     */
    public function getRecentShipments(int $days = 7, int $limit = 50): array {
        $fromDate = date('Y-m-d', strtotime("-{$days} days"));
        $toDate = date('Y-m-d');
        
        $params = [
            'from' => $fromDate,
            'to' => $toDate,
            'limit' => $limit,
            'sort' => 'createdAt',
            'direction' => 'desc'
        ];
        
        return $this->getShipments($params);
    }
    
    /**
     * Get shipment status statistics
     */
    public function getShipmentStatusStats(int $days = 30): array {
        $shipments = $this->getRecentShipments($days, 1000);
        
        $stats = [];
        foreach ($shipments as $shipment) {
            // Since shipments don't have status field, we infer status from existence
            $status = 'shipped'; // If shipment exists, it's shipped
            $stats[$status] = ($stats[$status] ?? 0) + 1;
        }
        
        return $stats;
    }
    
    /**
     * Get shipment statistics
     */
    public function getShipmentStatistics(): array {
        // Get orders with shipment data
        global $wpdb;
        
        $shippedOrders = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = '_wms_shipment_id' 
             AND p.post_type = 'shop_order'
             AND p.post_status IN ('wc-completed', 'wc-processing')"
        );
        
        $totalOrders = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             WHERE p.post_type = 'shop_order'
             AND p.post_status IN ('wc-completed', 'wc-processing')"
        );
        
        $lastShipmentSync = get_option('wc_wms_shipments_last_sync', 0);
        
        return [
            'shipped_orders' => intval($shippedOrders),
            'total_orders' => intval($totalOrders),
            'shipping_percentage' => $totalOrders > 0 ? 
                round(($shippedOrders / $totalOrders) * 100, 2) : 0,
            'last_sync' => $lastShipmentSync,
            'last_sync_formatted' => $lastShipmentSync ? date('Y-m-d H:i:s', $lastShipmentSync) : 'Never'
        ];
    }
}