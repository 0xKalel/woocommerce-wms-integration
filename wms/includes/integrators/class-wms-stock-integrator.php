<?php
/**
 * WMS Stock Integrator
 * 
 * Handles all stock synchronization logic between WooCommerce and WMS
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Stock_Integrator implements WC_WMS_Stock_Integrator_Interface {
    
    /**
     * WMS Client instance
     */
    private $wmsClient;
    
    /**
     * Product sync manager instance
     */
    private $productSyncManager;
    
    /**
     * Event Dispatcher instance
     */
    private $eventDispatcher;
    
    /**
     * Constructor
     */
    public function __construct(WC_WMS_Client $wmsClient) {
        $this->wmsClient = $wmsClient;
        $this->productSyncManager = new WC_WMS_Product_Sync_Manager($wmsClient);
        $this->eventDispatcher = WC_WMS_Event_Dispatcher::instance();
    }
    
    /**
     * Get integrator name (Interface requirement)
     */
    public function getIntegratorName(): string {
        return 'stock';
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
            
            // Check if stock service is available
            if (!$this->wmsClient->stock() || !$this->wmsClient->stock()->isAvailable()) {
                return false;
            }
            
            // Check database connectivity
            global $wpdb;
            if (!$wpdb || $wpdb->last_error) {
                return false;
            }
            
            return true;
            
        } catch (Exception $e) {
            $this->wmsClient->logger()->error('Stock integrator readiness check failed', [
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
            'last_sync' => null,
            'products_synced' => 0,
            'pending_syncs' => 0,
            'sync_errors' => 0,
            'health_score' => 0,
            'issues' => []
        ];
        
        try {
            // Get sync statistics
            $syncStats = $this->getStockSyncStats();
            $status['products_synced'] = $syncStats['products_with_wms_data'] ?? 0;
            $status['last_sync'] = $syncStats['last_sync'] ?? null;
            
            // Skip expensive discrepancy check for status (performance optimization)
            $status['sync_errors'] = 0; // Will be calculated on-demand
            
            // Calculate health score (0-100)
            $healthScore = 100;
            
            if (!$status['ready']) {
                $healthScore -= 50;
                $status['issues'][] = 'Integrator not ready';
            }
            
            // Skip sync_errors health impact for performance
            // if ($status['sync_errors'] > 0) {
            
            if (!$status['last_sync'] || strtotime($status['last_sync']) < strtotime('-1 hour')) {
                $healthScore -= 20;
                $status['issues'][] = 'Stock sync not recent';
            }
            
            $status['health_score'] = max(0, $healthScore);
            
        } catch (Exception $e) {
            $status['issues'][] = 'Failed to get status: ' . $e->getMessage();
            $status['health_score'] = 0;
        }
        
        return $status;
    }
    
    /**
     * Sync stock from webhook data
     */
    public function syncStockFromWebhook(array $webhookData): array {
        $results = [
            'updated' => 0,
            'errors' => [],
            'details' => []
        ];
        
        $this->wmsClient->logger()->info('Processing stock webhook', [
            'data_keys' => array_keys($webhookData)
        ]);
        
        // Handle single product update
        if (isset($webhookData['sku']) || isset($webhookData['article_code'])) {
            $result = $this->updateProductStock($webhookData);
            if (is_wp_error($result)) {
                $results['errors'][] = $result->get_error_message();
            } else {
                $results['updated']++;
                $results['details'][] = $result;
            }
        }
        
        // Handle bulk updates
        if (isset($webhookData['items']) && is_array($webhookData['items'])) {
            foreach ($webhookData['items'] as $itemData) {
                $result = $this->updateProductStock($itemData);
                if (is_wp_error($result)) {
                    $results['errors'][] = $result->get_error_message();
                } else {
                    $results['updated']++;
                    $results['details'][] = $result;
                }
            }
        }
        
        // Handle stock updates array
        if (isset($webhookData['updates']) && is_array($webhookData['updates'])) {
            foreach ($webhookData['updates'] as $updateData) {
                $result = $this->updateProductStock($updateData);
                if (is_wp_error($result)) {
                    $results['errors'][] = $result->get_error_message();
                } else {
                    $results['updated']++;
                    $results['details'][] = $result;
                }
            }
        }
        
        $this->wmsClient->logger()->info('Stock webhook processing completed', [
            'updated' => $results['updated'],
            'errors_count' => count($results['errors'])
        ]);
        
        return $results;
    }
    
    /**
     * Update single product stock
     */
    public function updateProductStock(array $stockData): mixed {
        $sku = $stockData['sku'] ?? $stockData['article_code'] ?? '';
        $quantity = $stockData['stock_physical'] ?? $stockData['available_quantity'] ?? $stockData['quantity'] ?? null;
        $stockStatus = $stockData['stock_status'] ?? null;
        $reservedQuantity = $stockData['reserved_quantity'] ?? 0;
        $location = $stockData['location'] ?? '';
        
        if (empty($sku)) {
            return new WP_Error('missing_sku', 'SKU not provided in stock update');
        }
        
        if ($quantity === null) {
            return new WP_Error('missing_quantity', 'Stock quantity not provided');
        }
        
        // Use centralized product lookup
        $product = $this->productSyncManager->findProductBySku($sku);
        
        if (!$product) {
            return new WP_Error('product_not_found', "Product not found for SKU: {$sku}");
        }
        
        $previousQuantity = $product->get_stock_quantity();
        $previousStatus = $product->get_stock_status();
        
        try {
            // Update stock quantity
            $product->set_stock_quantity(intval($quantity));
            $product->set_manage_stock(true);
            
            // Update stock status if provided
            if ($stockStatus) {
                $wc_status = $this->mapStockStatus($stockStatus);
                $product->set_stock_status($wc_status);
            } else {
                // Auto-determine status based on quantity
                $product->set_stock_status($quantity > 0 ? 'instock' : 'outofstock');
            }
            
            // Store additional WMS data
            $product->update_meta_data('_wms_stock_last_updated', current_time('mysql'));
            $product->update_meta_data('_wms_reserved_quantity', intval($reservedQuantity));
            
            if ($location) {
                $product->update_meta_data('_wms_stock_location', sanitize_text_field($location));
            }
            
            // Save product
            $product->save();
            
            $result = [
                'sku' => $sku,
                'product_id' => $product->get_id(),
                'previous_quantity' => $previousQuantity,
                'updated_quantity' => intval($quantity),
                'previous_status' => $previousStatus,
                'updated_status' => $product->get_stock_status(),
                'reserved_quantity' => intval($reservedQuantity),
                'location' => $location
            ];
            
            $this->wmsClient->logger()->debug('Product stock updated', $result);
            
            // Dispatch event
            $this->eventDispatcher->dispatch('wms.stock.updated', [
                'sku' => $sku,
                'product_id' => $product->get_id(),
                'quantity' => intval($quantity),
                'previous_quantity' => $previousQuantity,
                'stock_status' => $product->get_stock_status()
            ]);
            
            return $result;
            
        } catch (Exception $e) {
            $error = new WP_Error('stock_update_failed', "Failed to update stock for SKU {$sku}: " . $e->getMessage());
            
            $this->wmsClient->logger()->error('Stock update failed', [
                'sku' => $sku,
                'product_id' => $product->get_id(),
                'error' => $e->getMessage()
            ]);
            
            return $error;
        }
    }
    
    /**
     * Diagnostic method to analyze stock/product mismatch
     */
    public function diagnoseStockProductMismatch(): array {
        $this->wmsClient->logger()->info('Starting stock/product mismatch diagnosis');
        
        $diagnosis = [
            'woocommerce_products' => [],
            'wms_stock_items' => [],
            'analysis' => [],
            'recommendations' => []
        ];
        
        try {
            // Get all WooCommerce products with WMS data
            $products = wc_get_products([
                'limit' => 50,
                'meta_key' => '_wms_article_id',
                'status' => 'publish'
            ]);
            
            foreach ($products as $product) {
                $diagnosis['woocommerce_products'][] = [
                    'id' => $product->get_id(),
                    'name' => $product->get_name(),
                    'sku' => $product->get_sku(),
                    'wms_article_id' => $product->get_meta('_wms_article_id'),
                    'stock_quantity' => $product->get_stock_quantity(),
                    'stock_status' => $product->get_stock_status()
                ];
            }
            
            // Get WMS stock data
            $stockData = $this->wmsClient->stock()->getStockLevels(['limit' => 50]);
            
            if (!is_wp_error($stockData)) {
                foreach ($stockData as $stockItem) {
                    $diagnosis['wms_stock_items'][] = [
                        'id' => $stockItem['id'] ?? 'N/A',
                        'article_code' => $stockItem['article_code'] ?? 'N/A',
                        'sku' => $stockItem['sku'] ?? 'N/A',
                        'stock_physical' => $stockItem['stock_physical'] ?? 0,
                        'stock_available' => $stockItem['stock_available'] ?? 0,
                        'ean' => $stockItem['ean'] ?? 'N/A'
                    ];
                }
            }
            
            // Analysis
            $diagnosis['analysis'] = [
                'total_wc_products' => count($diagnosis['woocommerce_products']),
                'total_stock_items' => count($diagnosis['wms_stock_items']),
                'sku_formats' => [
                    'wc_sku_examples' => array_slice(array_column($diagnosis['woocommerce_products'], 'sku'), 0, 3),
                    'stock_sku_examples' => array_slice(array_column($diagnosis['wms_stock_items'], 'sku'), 0, 3)
                ],
                'matches_found' => 0,
                'match_details' => []
            ];
            
            // Try to find matches
            foreach ($diagnosis['wms_stock_items'] as $stockItem) {
                $stockSku = $stockItem['sku'];
                $product = $this->productSyncManager->findProductBySku($stockSku);
                
                if ($product) {
                    $diagnosis['analysis']['matches_found']++;
                    $diagnosis['analysis']['match_details'][] = [
                        'stock_sku' => $stockSku,
                        'product_id' => $product->get_id(),
                        'product_sku' => $product->get_sku(),
                        'product_name' => $product->get_name()
                    ];
                }
            }
            
            // Recommendations
            if ($diagnosis['analysis']['matches_found'] === 0) {
                $diagnosis['recommendations'][] = 'No matches found between stock data and products. This suggests WMS stock and articles APIs are returning data for different product sets.';
                $diagnosis['recommendations'][] = 'Check your WMS configuration to ensure stock and articles are properly linked.';
                $diagnosis['recommendations'][] = 'Consider importing additional products or checking if stock data is for different articles.';
            } elseif ($diagnosis['analysis']['matches_found'] < count($diagnosis['wms_stock_items']) / 2) {
                $diagnosis['recommendations'][] = 'Partial matches found. Some stock items match products, but many do not.';
                $diagnosis['recommendations'][] = 'Review SKU mapping strategy or import missing products.';
            } else {
                $diagnosis['recommendations'][] = 'Good match rate found. Stock sync should work for most products.';
            }
            
        } catch (Exception $e) {
            $diagnosis['error'] = $e->getMessage();
            $this->wmsClient->logger()->error('Stock diagnosis failed', ['error' => $e->getMessage()]);
        }
        
        $this->wmsClient->logger()->info('Stock/product mismatch diagnosis completed', $diagnosis['analysis']);
        
        return $diagnosis;
    }
    
    /**
     * Sync all stock from WMS
     */
    public function syncAllStock(int $batchSize = 100): array {
        $results = [
            'processed' => 0,
            'updated' => 0,
            'errors' => [],
            'total_batches' => 0
        ];
        
        $this->wmsClient->logger()->info('Starting full stock sync from WMS');
        
        try {
            $offset = 0;
            $hasMore = true;
            
            while ($hasMore) {
                $stockData = $this->wmsClient->stock()->getStockLevels([
                    'limit' => $batchSize,
                    'offset' => $offset
                ]);
                
                if (is_wp_error($stockData)) {
                    $results['errors'][] = "Batch {$results['total_batches']}: " . $stockData->get_error_message();
                    break;
                }
                
                if (empty($stockData)) {
                    $hasMore = false;
                    break;
                }
                
                $batchResult = $this->processBatchStockUpdates($stockData);
                $results['processed'] += $batchResult['processed'];
                $results['updated'] += $batchResult['updated'];
                $results['errors'] = array_merge($results['errors'], $batchResult['errors']);
                $results['total_batches']++;
                
                $offset += $batchSize;
                
                // Prevent memory issues
                if ($results['total_batches'] % 10 === 0) {
                    $this->wmsClient->logger()->info('Stock sync progress', [
                        'batches_processed' => $results['total_batches'],
                        'total_processed' => $results['processed'],
                        'total_updated' => $results['updated']
                    ]);
                }
                
                // Check if we got less than requested (end of data)
                if (count($stockData) < $batchSize) {
                    $hasMore = false;
                }
            }
            
        } catch (Exception $e) {
            $results['errors'][] = 'Critical error during stock sync: ' . $e->getMessage();
            $this->wmsClient->logger()->error('Stock sync failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
        
        $this->wmsClient->logger()->info('Full stock sync completed', $results);
        
        return $results;
    }
    
    /**
     * Process batch of stock updates
     */
    private function processBatchStockUpdates(array $stockData): array {
        $results = [
            'processed' => 0,
            'updated' => 0,
            'errors' => []
        ];
        
        $this->wmsClient->logger()->info('=== DIAGNOSTIC: Processing stock batch ===', [
            'total_stock_items' => count($stockData),
            'sample_stock_item' => !empty($stockData) ? $stockData[0] : null
        ]);
        
        foreach ($stockData as $index => $stockItem) {
            $results['processed']++;
            
            $this->wmsClient->logger()->debug("Processing stock item {$index}", [
                'stock_item_data' => $stockItem,
                'available_fields' => array_keys($stockItem),
                'sku_field' => $stockItem['sku'] ?? 'NOT_SET',
                'article_code_field' => $stockItem['article_code'] ?? 'NOT_SET',
                'id_field' => $stockItem['id'] ?? 'NOT_SET'
            ]);
            
            $result = $this->updateProductStock($stockItem);
            if (is_wp_error($result)) {
                $this->wmsClient->logger()->warning("Stock update failed for item {$index}", [
                    'error' => $result->get_error_message(),
                    'stock_item' => $stockItem
                ]);
                $results['errors'][] = $result->get_error_message();
            } else {
                $this->wmsClient->logger()->info("Stock update successful for item {$index}", [
                    'result' => $result
                ]);
                $results['updated']++;
            }
        }
        
        $this->wmsClient->logger()->info('Stock batch processing completed', [
            'processed' => $results['processed'],
            'updated' => $results['updated'],
            'errors_count' => count($results['errors'])
        ]);
        
        return $results;
    }
    
    /**
     * Get stock discrepancies between WooCommerce and WMS
     */
    public function getStockDiscrepancies(int $limit = 50): array {
        $discrepancies = [];
        
        // Get products with WMS data
        $products = wc_get_products([
            'limit' => $limit,
            'meta_key' => '_wms_stock_last_updated',
            'status' => 'publish'
        ]);
        
        foreach ($products as $product) {
            $sku = $product->get_sku();
            if (!$sku) {
                continue;
            }
            
            try {
                // Get current WMS stock
                $wmsStock = $this->wmsClient->stock()->getProductStock($sku);
                
                if (is_wp_error($wmsStock)) {
                    continue;
                }
                
                $wcQuantity = $product->get_stock_quantity();
                $wmsQuantity = $wmsStock['available_quantity'] ?? $wmsStock['stock_physical'] ?? 0;
                
                if ($wcQuantity != $wmsQuantity) {
                    $discrepancies[] = [
                        'sku' => $sku,
                        'product_id' => $product->get_id(),
                        'wc_quantity' => $wcQuantity,
                        'wms_quantity' => $wmsQuantity,
                        'difference' => $wcQuantity - $wmsQuantity,
                        'last_updated' => $product->get_meta('_wms_stock_last_updated')
                    ];
                }
                
            } catch (Exception $e) {
                $this->wmsClient->logger()->warning('Failed to check stock discrepancy', [
                    'sku' => $sku,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return $discrepancies;
    }
    
    /**
     * Fix stock discrepancies by updating WooCommerce with WMS data
     */
    public function fixStockDiscrepancies(array $skus = []): array {
        $results = [
            'fixed' => 0,
            'errors' => []
        ];
        
        $discrepancies = $this->getStockDiscrepancies();
        
        foreach ($discrepancies as $discrepancy) {
            // Filter by SKUs if provided
            if (!empty($skus) && !in_array($discrepancy['sku'], $skus)) {
                continue;
            }
            
            // Update WooCommerce with WMS quantity
            $stockData = [
                'sku' => $discrepancy['sku'],
                'quantity' => $discrepancy['wms_quantity']
            ];
            
            $result = $this->updateProductStock($stockData);
            
            if (is_wp_error($result)) {
                $results['errors'][] = "Failed to fix {$discrepancy['sku']}: " . $result->get_error_message();
            } else {
                $results['fixed']++;
            }
        }
        
        $this->wmsClient->logger()->info('Stock discrepancies fix completed', $results);
        
        return $results;
    }
    
    /**
     * Get stock sync statistics
     */
    public function getStockSyncStats(): array {
        $stats = [];
        
        // Count products with WMS stock data
        $productsWithWmsData = wc_get_products([
            'return' => 'ids',
            'limit' => -1,
            'meta_key' => '_wms_stock_last_updated'
        ]);
        
        $stats['products_with_wms_data'] = count($productsWithWmsData);
        
        // Get last sync times
        $lastUpdated = get_posts([
            'post_type' => 'product',
            'meta_key' => '_wms_stock_last_updated',
            'meta_compare' => 'EXISTS',
            'orderby' => 'meta_value',
            'order' => 'DESC',
            'posts_per_page' => 1,
            'fields' => 'ids'
        ]);
        
        if (!empty($lastUpdated)) {
            $product = wc_get_product($lastUpdated[0]);
            $stats['last_sync'] = $product->get_meta('_wms_stock_last_updated');
        }
        
        // Count out of stock products
        $outOfStock = wc_get_products([
            'return' => 'ids',
            'limit' => -1,
            'stock_status' => 'outofstock'
        ]);
        
        $stats['out_of_stock_count'] = count($outOfStock);
        
        return $stats;
    }
    
    /**
     * Map WMS stock status to WooCommerce stock status
     */
    private function mapStockStatus(string $wmsStatus): string {
        $statusMap = [
            'in_stock' => 'instock',
            'out_of_stock' => 'outofstock',
            'on_backorder' => 'onbackorder',
            'available' => 'instock',
            'unavailable' => 'outofstock',
            'backorder' => 'onbackorder'
        ];
        
        return $statusMap[strtolower($wmsStatus)] ?? 'instock';
    }
    
    /**
     * Get stock insights for business intelligence
     */
    public function getStockInsights(): array {
        try {
            $stats = $this->getStockSyncStats();
            // Skip expensive discrepancy check for performance
            $discrepancies = []; // Will be calculated on-demand
            
            // Calculate stock health metrics
            $totalProducts = wp_count_posts('product')->publish ?? 0;
            $productsWithStock = $stats['products_with_wms_data'] ?? 0;
            $outOfStockCount = $stats['out_of_stock_count'] ?? 0;
            
            // Calculate stock coverage percentage
            $stockCoverage = $totalProducts > 0 ? round(($productsWithStock / $totalProducts) * 100, 1) : 0;
            
            // Calculate stock health score (skip discrepancy for performance)
            $discrepancyCount = 0; // Disabled for performance
            $discrepancyRate = 0; // Disabled for performance
            
            // Get low stock alerts
            $lowStockProducts = $this->getLowStockProducts();
            
            // Calculate insights
            $insights = [
                'overview' => [
                    'total_products' => $totalProducts,
                    'products_with_wms_stock' => $productsWithStock,
                    'stock_coverage_percentage' => $stockCoverage,
                    'out_of_stock_count' => $outOfStockCount,
                    'last_sync' => $stats['last_sync'] ?? null
                ],
                'health' => [
                    'discrepancy_count' => $discrepancyCount,
                    'discrepancy_rate' => $discrepancyRate,
                    'health_status' => $this->calculateStockHealthStatus($discrepancyRate, $stockCoverage),
                    'sync_freshness' => $this->calculateSyncFreshness($stats['last_sync'] ?? null)
                ],
                'alerts' => [
                    'low_stock_count' => count($lowStockProducts),
                    'critical_discrepancies' => [], // Disabled for performance
                    'sync_issues' => $this->getSyncIssues()
                ],
                'recommendations' => $this->generateStockRecommendations($stockCoverage, $discrepancyRate, $stats)
            ];
            
            return $insights;
            
        } catch (Exception $e) {
            $this->wmsClient->logger()->error('Failed to generate stock insights', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'error' => 'Failed to generate stock insights: ' . $e->getMessage(),
                'timestamp' => current_time('mysql')
            ];
        }
    }
    
    /**
     * Get products with low stock levels
     */
    private function getLowStockProducts(int $threshold = 5): array {
        $products = wc_get_products([
            'limit' => 50,
            'stock_status' => 'instock',
            'manage_stock' => true
        ]);
        
        $lowStockProducts = [];
        
        foreach ($products as $product) {
            $stockQuantity = $product->get_stock_quantity();
            if ($stockQuantity !== null && $stockQuantity <= $threshold && $stockQuantity > 0) {
                $lowStockProducts[] = [
                    'id' => $product->get_id(),
                    'sku' => $product->get_sku(),
                    'name' => $product->get_name(),
                    'quantity' => $stockQuantity
                ];
            }
        }
        
        return $lowStockProducts;
    }
    
    /**
     * Get critical stock discrepancies
     */
    private function getCriticalDiscrepancies(array $discrepancies): array {
        return array_filter($discrepancies, function($discrepancy) {
            return abs($discrepancy['difference']) > 10; // More than 10 units difference
        });
    }
    
    /**
     * Get sync issues
     */
    private function getSyncIssues(): array {
        $issues = [];
        
        // Check for old sync data
        $oldSyncThreshold = strtotime('-24 hours');
        $oldSyncProducts = get_posts([
            'post_type' => 'product',
            'meta_query' => [
                [
                    'key' => '_wms_stock_last_updated',
                    'value' => date('Y-m-d H:i:s', $oldSyncThreshold),
                    'compare' => '<',
                    'type' => 'DATETIME'
                ]
            ],
            'posts_per_page' => 10,
            'fields' => 'ids'
        ]);
        
        if (!empty($oldSyncProducts)) {
            $issues[] = [
                'type' => 'stale_sync',
                'count' => count($oldSyncProducts),
                'message' => count($oldSyncProducts) . ' products have not been synced in 24+ hours'
            ];
        }
        
        return $issues;
    }
    
    /**
     * Calculate stock health status
     */
    private function calculateStockHealthStatus(float $discrepancyRate, float $stockCoverage): string {
        if ($discrepancyRate > 20 || $stockCoverage < 50) {
            return 'critical';
        } elseif ($discrepancyRate > 10 || $stockCoverage < 80) {
            return 'warning';
        } elseif ($discrepancyRate > 5 || $stockCoverage < 95) {
            return 'good';
        } else {
            return 'excellent';
        }
    }
    
    /**
     * Calculate sync freshness
     */
    private function calculateSyncFreshness(?string $lastSync): array {
        if (!$lastSync) {
            return [
                'status' => 'never',
                'hours_ago' => null,
                'message' => 'No sync data available'
            ];
        }
        
        $lastSyncTime = strtotime($lastSync);
        $hoursAgo = round((time() - $lastSyncTime) / 3600, 1);
        
        if ($hoursAgo < 1) {
            $status = 'fresh';
            $message = 'Recently synced';
        } elseif ($hoursAgo < 6) {
            $status = 'recent';
            $message = 'Synced within 6 hours';
        } elseif ($hoursAgo < 24) {
            $status = 'stale';
            $message = 'Sync is getting old';
        } else {
            $status = 'very_stale';
            $message = 'Sync is very old';
        }
        
        return [
            'status' => $status,
            'hours_ago' => $hoursAgo,
            'message' => $message
        ];
    }
    
    /**
     * Generate stock recommendations
     */
    private function generateStockRecommendations(float $stockCoverage, float $discrepancyRate, array $stats): array {
        $recommendations = [];
        
        if ($stockCoverage < 80) {
            $recommendations[] = [
                'type' => 'coverage',
                'priority' => 'high',
                'message' => 'Consider syncing more products with WMS stock data',
                'action' => 'Run full stock sync'
            ];
        }
        
        if ($discrepancyRate > 10) {
            $recommendations[] = [
                'type' => 'discrepancy',
                'priority' => 'high',
                'message' => 'High discrepancy rate detected between WooCommerce and WMS',
                'action' => 'Fix stock discrepancies'
            ];
        }
        
        $lastSync = $stats['last_sync'] ?? null;
        if (!$lastSync || strtotime($lastSync) < strtotime('-6 hours')) {
            $recommendations[] = [
                'type' => 'sync_frequency',
                'priority' => 'medium',
                'message' => 'Stock sync is not recent enough',
                'action' => 'Increase sync frequency or run manual sync'
            ];
        }
        
        if (empty($recommendations)) {
            $recommendations[] = [
                'type' => 'success',
                'priority' => 'info',
                'message' => 'Stock synchronization is working well',
                'action' => 'Continue monitoring'
            ];
        }
        
        return $recommendations;
    }
    
    /**
     * Schedule stock sync cron job
     */
    public function scheduleStockSync(): void {
        if (!wp_next_scheduled('wc_wms_sync_stock')) {
            wp_schedule_event(time(), 'hourly', 'wc_wms_sync_stock');
            $this->wmsClient->logger()->info('Stock sync cron job scheduled');
        }
    }
    
    /**
     * Unschedule stock sync cron job
     */
    public function unscheduleStockSync(): void {
        $timestamp = wp_next_scheduled('wc_wms_sync_stock');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'wc_wms_sync_stock');
            $this->wmsClient->logger()->info('Stock sync cron job unscheduled');
        }
    }
}