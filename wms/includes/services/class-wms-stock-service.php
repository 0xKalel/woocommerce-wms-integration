<?php
/**
 * WMS Stock Service
 * 
 * Handles all stock and inventory operations with WMS
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Stock_Service implements WC_WMS_Stock_Service_Interface {
    
    /**
     * WMS client instance
     */
    private $client;
    
    /**
     * Get service name
     */
    public function getServiceName(): string {
        return 'stock';
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
                'stock' => '/wms/stock/',
                'modifications' => WC_WMS_Constants::ENDPOINT_MODIFICATIONS
            ]
        ];
    }
    
    /**
     * Constructor
     */
    public function __construct(WC_WMS_Client $client) {
        $this->client = $client;
    }
    
    /**
     * Get stock from WMS
     */
    public function getStock(array $params = []): array {
        $allowedParams = [
            'article_code' => 'string',
            'ean' => 'string',
            'sku' => 'string',
            'search' => 'string',
            'modified_gte' => 'string',
            'limit' => 'integer',
            'page' => 'integer',
            'from' => 'string',
            'to' => 'string',
            'direction' => 'string',
            'sort' => 'string'
        ];
        
        $filteredParams = [];
        foreach ($params as $key => $value) {
            if (isset($allowedParams[$key]) && !empty($value)) {
                $filteredParams[$key] = $value;
            }
        }
        
        $endpoint = '/wms/stock/';
        if (!empty($filteredParams)) {
            $endpoint .= '?' . http_build_query($filteredParams);
        }
        
        $this->client->logger()->debug('Getting stock from WMS', [
            'endpoint' => $endpoint,
            'params' => $filteredParams
        ]);
        
        return $this->client->makeAuthenticatedRequest('GET', $endpoint);
    }
    
    /**
     * Get batches from WMS
     */
    public function getBatches(array $params = []): array {
        $allowedParams = [
            'article_code' => 'string',
            'ean' => 'string',
            'sku' => 'string',
            'search' => 'string',
            'modified_gte' => 'string',
            'limit' => 'integer',
            'page' => 'integer',
            'from' => 'string',
            'to' => 'string',
            'direction' => 'string',
            'sort' => 'string'
        ];
        
        $filteredParams = [];
        foreach ($params as $key => $value) {
            if (isset($allowedParams[$key]) && !empty($value)) {
                $filteredParams[$key] = $value;
            }
        }
        
        $endpoint = '/wms/batches/';
        if (!empty($filteredParams)) {
            $endpoint .= '?' . http_build_query($filteredParams);
        }
        
        $this->client->logger()->debug('Getting batches from WMS', [
            'endpoint' => $endpoint,
            'params' => $filteredParams
        ]);
        
        return $this->client->makeAuthenticatedRequest('GET', $endpoint);
    }
    
    /**
     * Get modifications from WMS
     */
    public function getModifications(array $params = []): array {
        $endpoint = '/wms/modifications/';
        if (!empty($params)) {
            $endpoint .= '?' . http_build_query($params);
        }
        
        $this->client->logger()->debug('Getting modifications from WMS', [
            'endpoint' => $endpoint,
            'params' => $params
        ]);
        
        return $this->client->makeAuthenticatedRequest('GET', $endpoint);
    }
    
    /**
     * Get single modification from WMS
     */
    public function getModification(string $modificationId): array {
        $this->client->logger()->debug('Getting modification from WMS', [
            'modification_id' => $modificationId
        ]);
        
        return $this->client->makeAuthenticatedRequest('GET', '/wms/modifications/' . $modificationId . '/');
    }
    
    /**
     * Create modification in WMS
     */
    public function createModification(array $modificationData): array {
        $this->client->logger()->info('Creating modification in WMS', [
            'reason' => $modificationData['reason'] ?? 'unknown',
            'line_count' => count($modificationData['modification_lines'] ?? [])
        ]);
        
        $response = $this->client->makeAuthenticatedRequest('POST', '/wms/modifications/', $modificationData);
        
        if (isset($response['id'])) {
            $this->client->logger()->info('Modification created successfully', [
                'modification_id' => $response['id'],
                'reason' => $response['reason'] ?? 'unknown'
            ]);
        }
        
        return $response;
    }
    
    /**
     * Get stock quantity for a variant
     * FIXED: Use proper API parameters, fallback to batch call if needed
     */
    public function getVariantStockQuantity(string $variantId, ?string $sku = null): int {
        try {
            $stockData = null;
            
            // Try to get stock by SKU first (most efficient)
            if (!empty($sku)) {
                $stockData = $this->getStock(['sku' => $sku]);
                
                // If not found by SKU, try by article_code
                if (empty($stockData)) {
                    $stockData = $this->getStock(['article_code' => $sku]);
                }
            }
            
            // If still no data, we need to get all stock and filter by variant_id
            // This is less efficient but sometimes necessary
            if (empty($stockData)) {
                $this->client->logger()->debug('No stock found by SKU, getting all stock data', [
                    'variant_id' => $variantId,
                    'sku' => $sku
                ]);
                
                $allStockData = $this->getAllStockData();
                
                // Filter by variant ID
                foreach ($allStockData as $stockItem) {
                    if (isset($stockItem['variant']['id']) && $stockItem['variant']['id'] === $variantId) {
                        $stockData = [$stockItem];
                        break;
                    }
                }
            }
            
            if (empty($stockData)) {
                $this->client->logger()->debug('No stock data found for variant', [
                    'variant_id' => $variantId,
                    'sku' => $sku
                ]);
                return 0;
            }
            
            $totalStock = 0;
            foreach ($stockData as $stockItem) {
                $quantity = intval(
                    $stockItem['stock_available'] ?? 
                    $stockItem['stock_salable'] ?? 
                    $stockItem['stock_physical'] ?? 
                    $stockItem['quantity'] ?? 
                    0
                );
                $totalStock += $quantity;
            }
            
            $this->client->logger()->debug('Stock quantity calculated', [
                'variant_id' => $variantId,
                'sku' => $sku,
                'total_stock' => $totalStock,
                'records_count' => count($stockData)
            ]);
            
            return $totalStock;
            
        } catch (Exception $e) {
            $this->client->logger()->warning('Failed to get stock quantity for variant', [
                'variant_id' => $variantId,
                'sku' => $sku,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
    
    /**
     * Sync stock levels from WMS to WooCommerce
     */
    public function syncStockFromWms(array $productIds = []): array {
        $this->client->logger()->info('Starting stock sync from WMS', [
            'product_ids' => $productIds
        ]);
        
        $results = [
            'synced' => 0,
            'errors' => 0,
            'error_details' => []
        ];
        
        // Get products to sync
        if (empty($productIds)) {
            $products = wc_get_products([
                'meta_query' => [
                    [
                        'key' => '_wms_variant_id',
                        'compare' => 'EXISTS'
                    ]
                ],
                'limit' => -1
            ]);
        } else {
            $products = array_map('wc_get_product', $productIds);
            $products = array_filter($products);
        }
        
        if (empty($products)) {
            $this->client->logger()->info('No products to sync');
            return $results;
        }
        
        // Get all stock data in a single API call for optimal performance
        try {
            $allStockData = $this->getAllStockData();
            
            if (empty($allStockData)) {
                $this->client->logger()->warning('No stock data received from WMS');
                return $results;
            }
            
            $this->client->logger()->info('Retrieved stock data from WMS', [
                'stock_items_count' => count($allStockData)
            ]);
            
            // Create lookup maps for efficient searching
            $stockByVariantId = [];
            $stockBySku = [];
            $stockByArticleCode = [];
            
            foreach ($allStockData as $stockItem) {
                if (isset($stockItem['variant']['id'])) {
                    $stockByVariantId[$stockItem['variant']['id']] = $stockItem;
                }
                if (!empty($stockItem['sku'])) {
                    $stockBySku[$stockItem['sku']] = $stockItem;
                }
                if (!empty($stockItem['article_code'])) {
                    $stockByArticleCode[$stockItem['article_code']] = $stockItem;
                }
            }
            
            // Process each product
            foreach ($products as $product) {
                $variantId = $product->get_meta('_wms_variant_id');
                $sku = $product->get_sku();
                
                if (empty($variantId)) {
                    continue;
                }
                
                try {
                    // Find stock data for this product
                    $stockItem = null;
                    
                    // Try to find by variant ID first
                    if (isset($stockByVariantId[$variantId])) {
                        $stockItem = $stockByVariantId[$variantId];
                    }
                    // Then by SKU
                    elseif (!empty($sku) && isset($stockBySku[$sku])) {
                        $stockItem = $stockBySku[$sku];
                    }
                    // Finally by article code
                    elseif (!empty($sku) && isset($stockByArticleCode[$sku])) {
                        $stockItem = $stockByArticleCode[$sku];
                    }
                    
                    if ($stockItem) {
                        $stockQuantity = intval(
                            $stockItem['stock_available'] ?? 
                            $stockItem['stock_salable'] ?? 
                            $stockItem['stock_physical'] ?? 
                            $stockItem['quantity'] ?? 
                            0
                        );
                        
                        $product->set_stock_quantity($stockQuantity);
                        $product->set_stock_status($stockQuantity > 0 ? 'instock' : 'outofstock');
                        $product->update_meta_data('_wms_stock_synced_at', current_time('mysql'));
                        $product->save();
                        
                        $results['synced']++;
                        
                        $this->client->logger()->debug('Stock synced for product', [
                            'product_id' => $product->get_id(),
                            'sku' => $sku,
                            'variant_id' => $variantId,
                            'stock_quantity' => $stockQuantity
                        ]);
                    } else {
                        $this->client->logger()->warning('No stock data found for product', [
                            'product_id' => $product->get_id(),
                            'sku' => $sku,
                            'variant_id' => $variantId
                        ]);
                    }
                    
                } catch (Exception $e) {
                    $results['errors']++;
                    $results['error_details'][] = [
                        'product_id' => $product->get_id(),
                        'sku' => $sku,
                        'error' => $e->getMessage()
                    ];
                }
            }
            
        } catch (Exception $e) {
            $this->client->logger()->error('Failed to get stock data from WMS', [
                'error' => $e->getMessage()
            ]);
            
            $results['errors'] = count($products);
            $results['error_details'][] = [
                'error' => 'Failed to get stock data from WMS: ' . $e->getMessage()
            ];
        }
        
        $this->client->logger()->info('Stock sync completed', $results);
        return $results;
    }
    
    /**
     * Create stock correction modification
     */
    public function createStockCorrection(int $productId, int $quantityChange, string $note = ''): array {
        $product = wc_get_product($productId);
        if (!$product) {
            throw new Exception('Product not found');
        }
        
        $wmsVariantId = $product->get_meta('_wms_variant_id');
        if (empty($wmsVariantId)) {
            throw new Exception('Product not synchronized with WMS');
        }
        
        $modificationData = [
            'note' => $note ?: sprintf('Stock correction for %s', $product->get_name()),
            'reason' => 'CORRECTION',
            'modification_lines' => [
                [
                    'quantity' => $quantityChange,
                    'variant' => $wmsVariantId,
                    'location' => null
                ]
            ]
        ];
        
        $this->client->logger()->info('Creating stock correction', [
            'product_id' => $productId,
            'quantity_change' => $quantityChange,
            'note' => $note
        ]);
        
        return $this->createModification($modificationData);
    }
    
    /**
     * Create defective product modification
     */
    public function createDefectiveModification(int $productId, int $quantity, string $note = ''): array {
        $product = wc_get_product($productId);
        if (!$product) {
            throw new Exception('Product not found');
        }
        
        $wmsVariantId = $product->get_meta('_wms_variant_id');
        if (empty($wmsVariantId)) {
            throw new Exception('Product not synchronized with WMS');
        }
        
        $modificationData = [
            'note' => $note ?: sprintf('Defective items for %s', $product->get_name()),
            'reason' => 'DEFECTIVE',
            'modification_lines' => [
                [
                    'quantity' => -abs($quantity), // Always negative for defective
                    'variant' => $wmsVariantId,
                    'location' => null
                ]
            ]
        ];
        
        $this->client->logger()->info('Creating defective modification', [
            'product_id' => $productId,
            'quantity' => $quantity,
            'note' => $note
        ]);
        
        return $this->createModification($modificationData);
    }
    
    /**
     * Create lost product modification
     */
    public function createLostModification(int $productId, int $quantity, string $note = ''): array {
        $product = wc_get_product($productId);
        if (!$product) {
            throw new Exception('Product not found');
        }
        
        $wmsVariantId = $product->get_meta('_wms_variant_id');
        if (empty($wmsVariantId)) {
            throw new Exception('Product not synchronized with WMS');
        }
        
        $modificationData = [
            'note' => $note ?: sprintf('Lost items for %s', $product->get_name()),
            'reason' => 'LOST',
            'modification_lines' => [
                [
                    'quantity' => -abs($quantity), // Always negative for lost
                    'variant' => $wmsVariantId,
                    'location' => null
                ]
            ]
        ];
        
        $this->client->logger()->info('Creating lost modification', [
            'product_id' => $productId,
            'quantity' => $quantity,
            'note' => $note
        ]);
        
        return $this->createModification($modificationData);
    }
    
    /**
     * Get all stock data from WMS with pagination
     * ADDED: New method to efficiently get all stock data
     */
    private function getAllStockData(): array {
        $allStockData = [];
        $page = 1;
        $limit = 100;
        $hasMore = true;
        
        while ($hasMore) {
            $stockData = $this->getStock([
                'limit' => $limit,
                'page' => $page
            ]);
            
            if (empty($stockData)) {
                $hasMore = false;
            } else {
                $allStockData = array_merge($allStockData, $stockData);
                
                // If we got less than the limit, we're done
                if (count($stockData) < $limit) {
                    $hasMore = false;
                } else {
                    $page++;
                }
            }
        }
        
        return $allStockData;
    }
    
    /**
     * Get stock statistics
     */
    public function getStockStatistics(): array {
        global $wpdb;
        
        // Get products with stock management enabled
        $stockManagedProducts = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = '_manage_stock' 
             AND pm.meta_value = 'yes'
             AND p.post_type = 'product'
             AND p.post_status = 'publish'"
        );
        
        // Get products with WMS sync
        $wmsSyncedProducts = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = '_wms_variant_id' 
             AND p.post_type = 'product'
             AND p.post_status = 'publish'"
        );
        
        // Get out of stock products
        $outOfStockProducts = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = '_stock_status' 
             AND pm.meta_value = 'outofstock'
             AND p.post_type = 'product'
             AND p.post_status = 'publish'"
        );
        
        // Get last stock sync time
        $lastStockSync = get_option('wc_wms_stock_last_sync', 0);
        
        return [
            'stock_managed_products' => intval($stockManagedProducts),
            'wms_synced_products' => intval($wmsSyncedProducts),
            'out_of_stock_products' => intval($outOfStockProducts),
            'sync_percentage' => $stockManagedProducts > 0 ? 
                round(($wmsSyncedProducts / $stockManagedProducts) * 100, 2) : 0,
            'last_stock_sync' => $lastStockSync,
            'last_sync_formatted' => $lastStockSync ? date('Y-m-d H:i:s', $lastStockSync) : 'Never'
        ];
    }
    
    /**
     * Get low stock products
     */
    public function getLowStockProducts(int $threshold = 5): array {
        $products = wc_get_products([
            'meta_query' => [
                [
                    'key' => '_manage_stock',
                    'value' => 'yes'
                ],
                [
                    'key' => '_stock',
                    'value' => $threshold,
                    'compare' => '<='
                ]
            ],
            'limit' => 50
        ]);
        
        $lowStockProducts = [];
        foreach ($products as $product) {
            $lowStockProducts[] = [
                'id' => $product->get_id(),
                'name' => $product->get_name(),
                'sku' => $product->get_sku(),
                'stock_quantity' => $product->get_stock_quantity(),
                'stock_status' => $product->get_stock_status(),
                'wms_variant_id' => $product->get_meta('_wms_variant_id')
            ];
        }
        
        return $lowStockProducts;
    }
    
    /**
     * Get stock levels (interface method)
     */
    public function getStockLevels(array $params = []): mixed {
        return $this->getStock($params);
    }
    
    /**
     * Get product stock (interface method)
     */
    public function getProductStock(string $sku): mixed {
        $stockData = $this->getStock(['sku' => $sku]);
        
        if (empty($stockData)) {
            $stockData = $this->getStock(['article_code' => $sku]);
        }
        
        if (empty($stockData)) {
            return new WP_Error('stock_not_found', 'Stock data not found for SKU: ' . $sku);
        }
        
        $stockItem = reset($stockData);
        
        return [
            'sku' => $sku,
            'available_quantity' => intval($stockItem['quantity_available'] ?? $stockItem['quantity'] ?? 0),
            'reserved_quantity' => intval($stockItem['quantity_reserved'] ?? 0),
            'total_quantity' => intval($stockItem['quantity_total'] ?? $stockItem['quantity'] ?? 0),
            'location' => $stockItem['location'] ?? null,
            'last_updated' => $stockItem['updated_at'] ?? current_time('mysql')
        ];
    }
    
    /**
     * Update stock (interface method)
     */
    public function updateStock(string $sku, int $quantity): mixed {
        // Find product using centralized ProductSyncManager
        $product = $this->client->productSyncManager()->findProductBySku($sku);
        
        if (!$product) {
            return new WP_Error('product_not_found', 'Product not found for SKU: ' . $sku);
        }
        
        $currentQuantity = intval($product->get_stock_quantity());
        $quantityChange = $quantity - $currentQuantity;
        
        if ($quantityChange === 0) {
            return ['message' => 'No stock change needed', 'current_quantity' => $currentQuantity];
        }
        
        try {
            // Create stock correction in WMS
            $result = $this->createStockCorrection(
                $product->get_id(),
                $quantityChange,
                sprintf('Stock update via API: %d → %d', $currentQuantity, $quantity)
            );
            
            // Update WooCommerce stock
            $product->set_stock_quantity($quantity);
            $product->set_stock_status($quantity > 0 ? 'instock' : 'outofstock');
            $product->save();
            
            return [
                'message' => 'Stock updated successfully',
                'old_quantity' => $currentQuantity,
                'new_quantity' => $quantity,
                'wms_modification_id' => $result['id'] ?? null
            ];
            
        } catch (Exception $e) {
            return new WP_Error('stock_update_failed', 'Failed to update stock: ' . $e->getMessage());
        }
    }
    
    /**
     * Get stock movements (interface method)
     */
    public function getStockMovements(array $params = []): mixed {
        return $this->getModifications($params);
    }
    
    /**
     * Create stock adjustment (interface method)
     */
    public function createStockAdjustment(array $adjustmentData): mixed {
        $requiredFields = ['reason', 'modification_lines'];
        
        foreach ($requiredFields as $field) {
            if (!isset($adjustmentData[$field])) {
                return new WP_Error('missing_field', 'Required field missing: ' . $field);
            }
        }
        
        // Validate reason
        $allowedReasons = ['CORRECTION', 'DEFECTIVE', 'LOST'];
        if (!in_array($adjustmentData['reason'], $allowedReasons)) {
            return new WP_Error('invalid_reason', 'Invalid reason. Allowed: ' . implode(', ', $allowedReasons));
        }
        
        return $this->createModification($adjustmentData);
    }
    
    /**
     * Get stock summary for dashboard
     */
    public function getStockSummary(): array {
        $summary = $this->getStockStatistics();
        
        // Add WMS connectivity status
        $summary['wms_connection'] = $this->isAvailable();
        
        // Add low stock alerts
        $lowStockProducts = $this->getLowStockProducts(5);
        $summary['low_stock_alerts'] = count($lowStockProducts);
        $summary['low_stock_products'] = array_slice($lowStockProducts, 0, 5); // Top 5
        
        // Add stock value estimation (basic)
        $summary['estimated_stock_value'] = $this->calculateStockValue();
        
        return $summary;
    }
    
    /**
     * Calculate estimated stock value
     */
    private function calculateStockValue(): float {
        global $wpdb;
        
        $query = "
            SELECT SUM(CAST(stock.meta_value AS UNSIGNED) * CAST(price.meta_value AS DECIMAL(10,2))) as total_value
            FROM {$wpdb->postmeta} stock
            INNER JOIN {$wpdb->postmeta} price ON stock.post_id = price.post_id
            INNER JOIN {$wpdb->posts} p ON stock.post_id = p.ID
            WHERE stock.meta_key = '_stock'
            AND price.meta_key = '_price'
            AND p.post_type = 'product'
            AND p.post_status = 'publish'
            AND CAST(stock.meta_value AS UNSIGNED) > 0
        ";
        
        $result = $wpdb->get_var($query);
        return floatval($result ?: 0);
    }
    
    /**
     * Get stock turnover rate
     */
    public function getStockTurnover(int $days = 30): array {
        global $wpdb;
        
        $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        // Get products sold in the period
        $query = $wpdb->prepare("
            SELECT 
                oi.product_id,
                p.post_title as product_name,
                pm_sku.meta_value as sku,
                SUM(oi.product_qty) as total_sold,
                CAST(pm_stock.meta_value AS UNSIGNED) as current_stock
            FROM {$wpdb->prefix}woocommerce_order_items oi
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
            INNER JOIN {$wpdb->posts} o ON oi.order_id = o.ID
            INNER JOIN {$wpdb->posts} p ON oi.product_id = p.ID
            LEFT JOIN {$wpdb->postmeta} pm_sku ON p.ID = pm_sku.post_id AND pm_sku.meta_key = '_sku'
            LEFT JOIN {$wpdb->postmeta} pm_stock ON p.ID = pm_stock.post_id AND pm_stock.meta_key = '_stock'
            WHERE o.post_date >= %s
            AND o.post_status IN ('wc-completed', 'wc-processing')
            AND oim.meta_key = '_product_id'
            GROUP BY oi.product_id
            HAVING total_sold > 0
            ORDER BY total_sold DESC
            LIMIT 20
        ", $since);
        
        $results = $wpdb->get_results($query, ARRAY_A);
        
        // Calculate turnover rates
        foreach ($results as &$result) {
            $currentStock = intval($result['current_stock']);
            $totalSold = intval($result['total_sold']);
            
            if ($currentStock > 0) {
                $result['turnover_rate'] = round($totalSold / $currentStock, 2);
                $result['days_of_stock'] = $currentStock > 0 ? round(($currentStock / $totalSold) * $days, 1) : 0;
            } else {
                $result['turnover_rate'] = 'N/A';
                $result['days_of_stock'] = 0;
            }
        }
        
        return $results;
    }
    
    /**
     * Get stock alerts
     */
    public function getStockAlerts(): array {
        $alerts = [];
        
        // Low stock alerts
        $lowStockProducts = $this->getLowStockProducts(5);
        if (!empty($lowStockProducts)) {
            $alerts[] = [
                'type' => 'low_stock',
                'severity' => 'warning',
                'message' => count($lowStockProducts) . ' products are low in stock',
                'count' => count($lowStockProducts),
                'products' => array_slice($lowStockProducts, 0, 3)
            ];
        }
        
        // Out of stock alerts
        $outOfStockCount = wc_get_products([
            'return' => 'ids',
            'limit' => -1,
            'stock_status' => 'outofstock'
        ]);
        
        if (!empty($outOfStockCount)) {
            $alerts[] = [
                'type' => 'out_of_stock',
                'severity' => 'error',
                'message' => count($outOfStockCount) . ' products are out of stock',
                'count' => count($outOfStockCount)
            ];
        }
        
        // WMS sync issues
        if (!$this->isAvailable()) {
            $alerts[] = [
                'type' => 'wms_connection',
                'severity' => 'critical',
                'message' => 'WMS connection is not available',
                'count' => 1
            ];
        }
        
        return $alerts;
    }
    
    /**
     * Force refresh stock for specific products
     */
    public function forceRefreshStock(array $productIds): array {
        $results = [
            'success' => [],
            'errors' => []
        ];
        
        foreach ($productIds as $productId) {
            $product = wc_get_product($productId);
            if (!$product) {
                $results['errors'][] = "Product {$productId} not found";
                continue;
            }
            
            $variantId = $product->get_meta('_wms_variant_id');
            if (!$variantId) {
                $results['errors'][] = "Product {$productId} not synced with WMS";
                continue;
            }
            
            try {
                $stockQuantity = $this->getVariantStockQuantity($variantId, $product->get_sku());
                
                $oldQuantity = $product->get_stock_quantity();
                $product->set_stock_quantity($stockQuantity);
                $product->set_stock_status($stockQuantity > 0 ? 'instock' : 'outofstock');
                $product->update_meta_data('_wms_stock_synced_at', current_time('mysql'));
                $product->save();
                
                $results['success'][] = [
                    'product_id' => $productId,
                    'sku' => $product->get_sku(),
                    'old_quantity' => $oldQuantity,
                    'new_quantity' => $stockQuantity
                ];
                
            } catch (Exception $e) {
                $results['errors'][] = "Failed to refresh {$productId}: " . $e->getMessage();
            }
        }
        
        return $results;
    }
}