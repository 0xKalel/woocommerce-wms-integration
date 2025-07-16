<?php
/**
 * WMS Order Service
 * 
 * Handles all order-related operations with WMS
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Order_Service implements WC_WMS_Order_Service_Interface {
    
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
     * Get service name
     */
    public function getServiceName(): string {
        return 'order';
    }
    
    /**
     * Check if service is available
     */
    public function isAvailable(): bool {
        return $this->client->config()->hasValidCredentials();
    }
    
    /**
     * Get service configuration
     */
    public function getConfig(): array {
        return [
            'service_name' => $this->getServiceName(),
            'is_available' => $this->isAvailable(),
            'endpoints' => [
                'orders' => WC_WMS_Constants::ENDPOINT_ORDERS,
                'create' => WC_WMS_Constants::ENDPOINT_ORDERS,
                'update' => WC_WMS_Constants::ENDPOINT_ORDERS . '{id}/',
                'cancel' => WC_WMS_Constants::ENDPOINT_ORDERS . '{id}/cancel/'
            ]
        ];
    }
    
    /**
     * Create order in WMS
     */
    public function createOrder(array $orderData): array {
        $this->client->logger()->info('Creating order in WMS', [
            'external_reference' => $orderData['external_reference'] ?? 'unknown',
            'line_count' => count($orderData['order_lines'] ?? [])
        ]);
        
        $response = $this->client->makeAuthenticatedRequest('POST', '/wms/orders/', $orderData);
        
        if (isset($response['id'])) {
            $this->client->logger()->info('Order created successfully in WMS', [
                'wms_order_id' => $response['id'],
                'reference' => $response['reference'] ?? 'unknown',
                'external_reference' => $response['external_reference'] ?? 'unknown'
            ]);
        }
        
        return $response;
    }
    
    /**
     * Get order from WMS with expand support - Following API docs exactly
     */
    public function getOrder(string $orderId, array $expand = ['order_lines', 'meta_data']): array {
        $this->client->logger()->info('Getting order from WMS - API compliant method', [
            'order_id' => $orderId,
            'expand' => $expand
        ]);
        
        // Build endpoint (no query parameters)
        $endpoint = '/wms/orders/' . $orderId . '/';
        
        // API docs: Make sure you add 'meta_data' within the expand header
        // Use HTTP HEADER, not query parameter!
        $headers = [];
        if (!empty($expand)) {
            $expandParam = is_array($expand) ? implode(',', $expand) : $expand;
            $headers['Expand'] = $expandParam;
        }
        
        $this->client->logger()->info('WMS API request with proper expand HEADER format', [
            'endpoint' => $endpoint,
            'expand_header' => $headers['Expand'] ?? 'none',
            'full_url' => $this->client->config()->getApiUrl() . $endpoint
        ]);
        
        $response = $this->client->makeAuthenticatedRequest('GET', $endpoint, null, $headers);
        
        // Analyze response according to API expectations
        if (isset($response['id'])) {
            $hasOrderLines = isset($response['order_lines']);
            $orderLinesCount = $hasOrderLines ? count($response['order_lines']) : 0;
            $hasMetaData = isset($response['meta_data']);
            
            $this->client->logger()->info('WMS API Response Analysis - API Compliant', [
                'wms_order_id' => $response['id'],
                'reference' => $response['reference'] ?? 'unknown',
                'status' => $response['status'] ?? 'unknown',
                'has_order_lines' => $hasOrderLines,
                'order_lines_count' => $orderLinesCount,
                'has_meta_data' => $hasMetaData,
                'response_keys' => array_keys($response),
                'expand_requested' => $expand
            ]);
            
            // Success case - log structure for debugging
            if ($hasOrderLines && $orderLinesCount > 0) {
                $this->client->logger()->info('SUCCESS: Order lines found via API compliant request', [
                    'order_id' => $orderId,
                    'order_lines_count' => $orderLinesCount
                ]);
                
                $this->logOrderLineStructure($response, $orderId);
                return $response;
            }
            
            // Warning case - no order lines
            if (!$hasOrderLines) {
                $this->client->logger()->warning('API returned order WITHOUT order_lines field', [
                    'order_id' => $orderId,
                    'endpoint' => $endpoint,
                    'expand_requested' => $expand,
                    'response_keys' => array_keys($response),
                    'possible_causes' => [
                        'Order has no line items',
                        'Expand parameter not working',
                        'API permissions issue',
                        'API version mismatch'
                    ]
                ]);
            } else {
                $this->client->logger()->warning('API returned order with EMPTY order_lines array', [
                    'order_id' => $orderId,
                    'endpoint' => $endpoint,
                    'expand_requested' => $expand
                ]);
            }
            
        } else {
            $this->client->logger()->error('WMS API returned invalid response structure', [
                'order_id' => $orderId,
                'endpoint' => $endpoint,
                'response_type' => gettype($response),
                'response_keys' => is_array($response) ? array_keys($response) : 'not_array'
            ]);
        }
        
        return $response;
    }
    
    /**
     * Log order line structure for debugging
     */
    private function logOrderLineStructure(array $response, string $orderId): void {
        if (!empty($response['order_lines'])) {
            $firstLine = $response['order_lines'][0] ?? null;
            if ($firstLine) {
                $this->client->logger()->debug('Order line structure analysis', [
                    'order_id' => $orderId,
                    'total_lines' => count($response['order_lines']),
                    'first_line_keys' => array_keys($firstLine),
                    'has_variant' => isset($firstLine['variant']),
                    'variant_keys' => isset($firstLine['variant']) ? array_keys($firstLine['variant']) : null,
                    'article_code' => $firstLine['variant']['article_code'] ?? 'missing',
                    'sku' => $firstLine['variant']['sku'] ?? 'missing',
                    'quantity' => $firstLine['quantity'] ?? 'missing',
                    'description' => $firstLine['description'] ?? 'missing'
                ]);
            }
        }
    }
    
    /**
     * Update order in WMS
     */
    public function updateOrder(string $orderId, array $orderData): array {
        $this->client->logger()->info('Updating order in WMS', [
            'order_id' => $orderId,
            'external_reference' => $orderData['external_reference'] ?? 'unknown'
        ]);
        
        $response = $this->client->makeAuthenticatedRequest('PATCH', '/wms/orders/' . $orderId . '/', $orderData);
        
        if (isset($response['id'])) {
            $this->client->logger()->info('Order updated successfully in WMS', [
                'wms_order_id' => $response['id'],
                'reference' => $response['reference'] ?? 'unknown'
            ]);
        }
        
        return $response;
    }
    
    /**
     * Cancel order in WMS
     */
    public function cancelOrder(string $orderId): array {
        $this->client->logger()->info('Cancelling order in WMS', [
            'order_id' => $orderId
        ]);
        
        $response = $this->client->makeAuthenticatedRequest('PATCH', '/wms/orders/' . $orderId . '/cancel/');
        
        $this->client->logger()->info('Order cancelled successfully in WMS', [
            'wms_order_id' => $orderId,
            'response' => $response
        ]);
        
        return $response;
    }
    
    /**
     * Get orders from WMS
     */
    public function getOrders(array $params = []): array {
        $this->client->logger()->debug('Getting orders from WMS', [
            'params' => $params
        ]);
        
        $endpoint = '/wms/orders/';
        if (!empty($params)) {
            $endpoint .= '?' . http_build_query($params);
        }
        
        $response = $this->client->makeAuthenticatedRequest('GET', $endpoint);
        
        $orderCount = is_array($response) ? count($response) : 0;
        $this->client->logger()->debug('Orders retrieved successfully from WMS', [
            'order_count' => $orderCount
        ]);
        
        return $response;
    }
    
    /**
     * Search orders by criteria
     */
    public function searchOrders(array $criteria): array {
        $allowedParams = [
            'reference' => 'string',
            'external_reference' => 'string',
            'status' => 'string',
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
        
        $this->client->logger()->info('Searching orders in WMS', [
            'criteria' => $params
        ]);
        
        return $this->getOrders($params);
    }
    
    /**
     * Get order by external reference
     */
    public function getOrderByExternalReference(string $externalReference): ?array {
        $this->client->logger()->debug('Getting order by external reference', [
            'external_reference' => $externalReference
        ]);
        
        $orders = $this->searchOrders([
            'external_reference' => $externalReference,
            'limit' => 1
        ]);
        
        if (is_array($orders) && !empty($orders)) {
            $order = reset($orders);
            $this->client->logger()->debug('Order found by external reference', [
                'external_reference' => $externalReference,
                'wms_order_id' => $order['id'] ?? 'unknown'
            ]);
            return $order;
        }
        
        $this->client->logger()->debug('No order found by external reference', [
            'external_reference' => $externalReference
        ]);
        
        return null;
    }
    
    /**
     * Transform WooCommerce order to WMS format
     */
    public function transformWooCommerceOrder(WC_Order $order): array {
        $this->client->logger()->debug('Transforming WooCommerce order to WMS format', [
            'order_id' => $order->get_id(),
            'order_number' => $order->get_order_number()
        ]);
        
        // Get WMS customer UUID (must be created first)
        $customerId = $this->getWMSCustomerId($order);
        
        // Get shipping address in correct format
        $shippingAddress = $this->getWMSShippingAddress($order);
        
        // Get order lines in correct format
        $orderLines = $this->getWMSOrderLines($order);
        
        // Get shipping method UUID
        $shippingMethodId = $this->getWMSShippingMethodId($order);
        
        // Build order data according to API spec - MINIMAL PAYLOAD to avoid "extra fields" error
        $orderData = [
            // Required fields
            'customer' => $customerId,
            'order_lines' => $orderLines,
            'requested_delivery_date' => $this->getRequestedDeliveryDate($order),
            'external_reference' => (string) $order->get_order_number(),
            'shipping_method' => $shippingMethodId,
            'shipping_address' => $shippingAddress,
            
            // Minimal optional fields (only include what's shown in API examples)
            'note' => $order->get_customer_note() ?: null,
        ];
        
        // Remove null values
        $orderData = array_filter($orderData, function($value) {
            return $value !== null;
        });
        
        $this->client->logger()->debug('WooCommerce order transformed to WMS format', [
            'order_id' => $order->get_id(),
            'external_reference' => $orderData['external_reference'],
            'line_count' => count($orderData['order_lines'])
        ]);
        
        return $orderData;
    }
    
    /**
     * Get WMS customer UUID from configuration
     */
    private function getWMSCustomerId(WC_Order $order): string {
        // Get the configured WMS customer ID (your business partner account)
        $wmsCustomerId = get_option('wc_wms_integration_customer_id');
        
        if (empty($wmsCustomerId)) {
            throw new Exception('WMS customer ID not configured. Please configure your WMS customer ID in the settings.');
        }
        
        // Validate UUID format
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $wmsCustomerId)) {
            throw new Exception('Invalid WMS customer ID format. Must be a valid UUID.');
        }
        
        return $wmsCustomerId;
    }
    
    /**
     * Get shipping address in WMS format
     */
    private function getWMSShippingAddress(WC_Order $order): array {
        $street = $order->get_shipping_address_1();
        $streetNumber = '';
        $streetName = $street;
        $streetNumberAddition = null;
        
        // Try to extract street number (basic implementation)
        if (preg_match('/^(\d+)\s*([a-zA-Z]?)\s+(.+)$/', $street, $matches)) {
            $streetNumber = $matches[1];
            $streetNumberAddition = !empty($matches[2]) ? $matches[2] : null;
            $streetName = $matches[3];
        }
        
        // Simplified shipping address to match API documentation examples
        return [
            'addressed_to' => trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()),
            'street' => $streetName ?: $street,
            'street_number' => $streetNumber,
            'zipcode' => $order->get_shipping_postcode(),
            'city' => $order->get_shipping_city(),
            'country' => $order->get_shipping_country()
        ];
    }
    

    
    /**
     * Get order lines in WMS format
     */
    private function getWMSOrderLines(WC_Order $order): array {
        $orderLines = [];
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) continue;
            
            // Get product SKU for article_code
            $sku = $product->get_sku();
            if (empty($sku)) {
                $sku = 'WC_' . $product->get_id();
            }
            
            $orderLines[] = [
                'article_code' => $sku, // Use SKU as article_code
                'quantity' => $item->get_quantity(),
                'description' => $product->get_name()
                // Order meta data excluded to prevent API compatibility issues
            ];
        }
        
        return $orderLines;
    }
    

    

    
    /**
     * Get shipping method ID from WooCommerce order
     */
    private function getWMSShippingMethodId(WC_Order $order): ?string {
        $shippingMethods = $order->get_shipping_methods();
        if (empty($shippingMethods)) {
            // Use default shipping method if no shipping method on order
            return get_option('wc_wms_default_shipping_method_uuid', null);
        }
        
        $shippingMethod = reset($shippingMethods);
        $methodKey = $shippingMethod->get_method_id() . ':' . $shippingMethod->get_instance_id();
        
        // Get shipping method mapping from existing admin system
        $shippingMapping = get_option('wc_wms_shipping_method_uuid_mapping', []);
        
        // Return the mapped WMS UUID if exists, otherwise use default
        return $shippingMapping[$methodKey] ?? get_option('wc_wms_default_shipping_method_uuid', null);
    }
    

    
    /**
     * Get requested delivery date
     */
    private function getRequestedDeliveryDate(WC_Order $order): string {
        // Check if customer requested specific delivery date
        $requestedDate = $order->get_meta('_requested_delivery_date');
        if ($requestedDate) {
            return date('Y-m-d', strtotime($requestedDate));
        }
        
        // Check for delivery date from checkout fields
        $deliveryDate = $order->get_meta('_delivery_date');
        if ($deliveryDate) {
            return date('Y-m-d', strtotime($deliveryDate));
        }
        
        // Default to next business day
        $tomorrow = strtotime('+1 day');
        
        // Skip weekends
        while (date('N', $tomorrow) >= 6) {
            $tomorrow = strtotime('+1 day', $tomorrow);
        }
        
        return date('Y-m-d', $tomorrow);
    }
    
    /**
     * Get order language
     */
    private function getOrderLanguage(WC_Order $order): string {
        // Check order meta for language
        $language = $order->get_meta('_order_language');
        if ($language) {
            return $language;
        }
        
        // Check customer language
        $customerId = $order->get_customer_id();
        if ($customerId) {
            $language = get_user_meta($customerId, '_language', true);
            if ($language) {
                return $language;
            }
        }
        
        // Default to site language
        return substr(get_locale(), 0, 2) ?: 'en';
    }
    
    /**
     * Get order meta data for WMS
     */
    private function getOrderMetaData(WC_Order $order): array {
        $metaData = [];
        
        // Add order status
        $metaData['wc_order_status'] = $order->get_status();
        
        // Add payment method
        $metaData['payment_method'] = $order->get_payment_method();
        
        // Add order total
        $metaData['order_total'] = $order->get_total();
        
        // Add customer ID
        if ($order->get_customer_id()) {
            $metaData['wc_customer_id'] = $order->get_customer_id();
        }
        
        // Add custom meta fields
        $customMeta = $order->get_meta('_wms_meta_data');
        if ($customMeta && is_array($customMeta)) {
            $metaData = array_merge($metaData, $customMeta);
        }
        
        return $metaData;
    }
    
    /**
     * Get item meta data
     */
    private function getItemMetaData(WC_Order_Item $item): array {
        $metaData = [];
        
        foreach ($item->get_meta_data() as $meta) {
            $metaData[$meta->key] = $meta->value;
        }
        
        return $metaData;
    }
    
    /**
     * Get recent orders
     */
    public function getRecentOrders(int $days = 7, int $limit = 50): array {
        $fromDate = date('Y-m-d', strtotime("-{$days} days"));
        $toDate = date('Y-m-d');
        
        return $this->getOrders([
            'from' => $fromDate,
            'to' => $toDate,
            'limit' => $limit,
            'sort' => 'createdAt',
            'direction' => 'desc'
        ]);
    }
    
    /**
     * Get order status statistics
     */
    public function getOrderStatusStats(int $days = 30): array {
        $orders = $this->getRecentOrders($days, 1000);
        
        $stats = [];
        foreach ($orders as $order) {
            $status = $order['status'] ?? 'unknown';
            $stats[$status] = ($stats[$status] ?? 0) + 1;
        }
        
        return $stats;
    }
    
    /**
     * Get order status from WMS
     */
    public function getOrderStatus(string $orderId): mixed {
        $this->client->logger()->debug('Getting order status from WMS', [
            'order_id' => $orderId
        ]);
        
        $response = $this->client->makeAuthenticatedRequest('GET', '/wms/orders/' . $orderId . '/status/');
        
        return $response['status'] ?? null;
    }
    
    /**
     * Get order summary for dashboard
     */
    public function getOrderSummary(): array {
        $summary = [];
        
        // Get basic statistics
        $summary['wms_connection'] = $this->isAvailable();
        $summary['recent_orders'] = count($this->getRecentOrders(7, 100));
        
        // Get status distribution
        $statusStats = $this->getOrderStatusStats(7);
        $summary['status_distribution'] = $statusStats;
        
        // Calculate total orders in last 24h
        $todayOrders = $this->getRecentOrders(1, 100);
        $summary['orders_today'] = count($todayOrders);
        
        // Calculate pending orders
        $pendingStatuses = ['pending', 'processing', 'on_hold'];
        $pendingCount = 0;
        foreach ($pendingStatuses as $status) {
            $pendingCount += $statusStats[$status] ?? 0;
        }
        $summary['pending_orders'] = $pendingCount;
        
        return $summary;
    }
    
    /**
     * Get order processing performance
     */
    public function getProcessingPerformance(): array {
        $recentOrders = $this->getRecentOrders(30, 500);
        
        $performance = [
            'total_orders' => count($recentOrders),
            'avg_processing_time' => 0,
            'fastest_processing' => null,
            'slowest_processing' => null,
            'completion_rate' => 0
        ];
        
        if (empty($recentOrders)) {
            return $performance;
        }
        
        $processingTimes = [];
        $completedCount = 0;
        
        foreach ($recentOrders as $order) {
            $createdAt = $order['created_at'] ?? null;
            $updatedAt = $order['updated_at'] ?? null;
            $status = $order['status'] ?? '';
            
            if ($createdAt && $updatedAt) {
                $processingTime = strtotime($updatedAt) - strtotime($createdAt);
                $processingTimes[] = $processingTime;
            }
            
            if (in_array($status, ['completed', 'shipped', 'delivered'])) {
                $completedCount++;
            }
        }
        
        if (!empty($processingTimes)) {
            $performance['avg_processing_time'] = round(array_sum($processingTimes) / count($processingTimes) / 3600, 1); // hours
            $performance['fastest_processing'] = round(min($processingTimes) / 3600, 1); // hours
            $performance['slowest_processing'] = round(max($processingTimes) / 3600, 1); // hours
        }
        
        $performance['completion_rate'] = round(($completedCount / count($recentOrders)) * 100, 1);
        
        return $performance;
    }
    
    /**
     * Validate order data before sending to WMS
     */
    public function validateOrderData(array $orderData): array {
        $errors = [];
        
        // Required fields according to WMS API
        $requiredFields = [
            'external_reference',
            'customer',
            'order_lines',
            'requested_delivery_date',
            'shipping_address',
            'shipping_method'
        ];
        
        foreach ($requiredFields as $field) {
            if (empty($orderData[$field])) {
                $errors[] = "Missing required field: {$field}";
            }
        }
        
        // Validate customer is UUID
        if (isset($orderData['customer']) && !is_string($orderData['customer'])) {
            $errors[] = 'Customer must be a UUID string';
        }
        
        // Validate shipping_method is UUID
        if (isset($orderData['shipping_method']) && !is_string($orderData['shipping_method'])) {
            $errors[] = 'Shipping method must be a UUID string';
        }
        
        // Validate order lines
        if (isset($orderData['order_lines']) && is_array($orderData['order_lines'])) {
            if (empty($orderData['order_lines'])) {
                $errors[] = 'Order must have at least one line item';
            } else {
                foreach ($orderData['order_lines'] as $index => $line) {
                    if (empty($line['article_code'])) {
                        $errors[] = "Order line {$index}: Missing article_code";
                    }
                    if (empty($line['quantity']) || $line['quantity'] <= 0) {
                        $errors[] = "Order line {$index}: Invalid quantity";
                    }
                }
            }
        }
        
        // Validate shipping address
        if (isset($orderData['shipping_address']) && is_array($orderData['shipping_address'])) {
            $shippingRequiredFields = ['addressed_to', 'street', 'city', 'zipcode', 'country'];
            foreach ($shippingRequiredFields as $field) {
                if (empty($orderData['shipping_address'][$field])) {
                    $errors[] = "Shipping address missing required field: {$field}";
                }
            }
        }
        
        // Validate requested_delivery_date format
        if (isset($orderData['requested_delivery_date'])) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $orderData['requested_delivery_date'])) {
                $errors[] = 'Requested delivery date must be in Y-m-d format';
            }
        }
        
        // Validate order_amount is in cents (integer)
        if (isset($orderData['order_amount']) && !is_int($orderData['order_amount'])) {
            $errors[] = 'Order amount must be an integer (cents)';
        }
        
        return $errors;
    }
    
    /**
     * Get order export readiness check
     */
    public function checkExportReadiness(): array {
        $checks = [
            'wms_connection' => $this->isAvailable(),
            'api_credentials' => $this->client->config()->hasValidCredentials(),
            'authentication' => $this->client->authenticator()->isAuthenticated(),
            'rate_limits' => true // Assume OK unless we detect issues
        ];
        
        // Check rate limit status
        $rateLimitStatus = $this->client->httpClient()->getRateLimitStatus();
        if (isset($rateLimitStatus['remaining']) && $rateLimitStatus['remaining'] < 10) {
            $checks['rate_limits'] = false;
        }
        
        $allReady = array_reduce($checks, function($carry, $check) {
            return $carry && $check;
        }, true);
        
        $checks['overall_ready'] = $allReady;
        
        return $checks;
    }
}