<?php
/**
 * WMS Product Service
 * 
 * Handles all product/article operations with WMS
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Product_Service implements WC_WMS_Product_Service_Interface {
    
    /**
     * WMS client instance
     */
    private $client;
    
    /**
     * Get service name
     */
    public function getServiceName(): string {
        return 'product';
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
                'articles' => WC_WMS_Constants::ENDPOINT_ARTICLES,
                'variants' => WC_WMS_Constants::ENDPOINT_VARIANTS
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
     * Get articles from WMS
     */
    public function getArticles(array $params = []): array {
        $allowedParams = [
            'article_code' => 'string',
            'ean' => 'string',
            'sku' => 'string',
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
        
        $endpoint = '/wms/articles/';
        if (!empty($filteredParams)) {
            $endpoint .= '?' . http_build_query($filteredParams);
        }
        
        $response = $this->client->makeAuthenticatedRequest('GET', $endpoint);
        
        // Handle different response types
        $articlesData = [];
        if (isset($response['data']) && is_array($response['data'])) {
            $articlesData = $response['data'];
        } elseif (is_array($response) && !isset($response['success']) && !isset($response['message'])) {
            $articlesData = $response;
        }
        
        return $articlesData;
    }
    
    /**
     * Get single article from WMS
     */
    public function getArticle(string $articleId, bool $includeVariants = true): array {
        $this->client->logger()->debug('Getting article from WMS', [
            'article_id' => $articleId,
            'include_variants' => $includeVariants
        ]);
        
        $extraHeaders = [];
        if ($includeVariants) {
            $extraHeaders['Expand'] = 'variants';
        }
        
        $response = $this->client->makeAuthenticatedRequest('GET', '/wms/articles/' . $articleId . '/', null, $extraHeaders);
        
        return $response;
    }
    
    /**
     * Create article in WMS
     */
    public function createArticle(array $articleData): array {
        $response = $this->client->makeAuthenticatedRequest('POST', '/wms/articles/', $articleData);
        
        return $response;
    }
    
    /**
     * Update article in WMS
     */
    public function updateArticle(string $articleId, array $articleData): array {
        $response = $this->client->makeAuthenticatedRequest('PATCH', '/wms/articles/' . $articleId . '/', $articleData);
        
        return $response;
    }
    
    /**
     * Create articles in bulk
     */
    public function createArticlesBulk(array $articlesData): array {
        $requestData = [
            'articles' => $articlesData
        ];
        
        $this->client->logger()->info('Creating articles in bulk', [
            'article_count' => count($articlesData)
        ]);
        
        $response = $this->client->makeAuthenticatedRequest('POST', '/wms/articles/bulks/', $requestData);
        
        $this->client->logger()->info('Bulk articles created successfully', [
            'created_count' => is_array($response) ? count($response) : 'unknown'
        ]);
        
        return $response;
    }
    
    /**
     * Search articles by criteria
     */
    public function searchArticles(array $criteria): array {
        $this->client->logger()->info('Searching articles', [
            'criteria' => $criteria
        ]);
        
        return $this->getArticles($criteria);
    }
    
    /**
     * Get articles with expanded variants
     */
    public function getArticlesWithVariants(array $params = []): array {
        $endpoint = '/wms/articles/';
        if (!empty($params)) {
            $endpoint .= '?' . http_build_query($params);
        }
        
        $extraHeaders = [
            'Expand' => 'variants'
        ];
        
        $this->client->logger()->debug('Getting articles with variants from WMS', [
            'endpoint' => $endpoint,
            'params' => $params
        ]);
        
        return $this->client->makeAuthenticatedRequest('GET', $endpoint, null, $extraHeaders);
    }
    
    /**
     * Get article variants from WMS
     */
    public function getArticleVariants(string $articleId): array {
        $this->client->logger()->debug('Getting article variants from WMS', [
            'article_id' => $articleId
        ]);
        
        $response = $this->client->makeAuthenticatedRequest('GET', '/wms/articles/' . $articleId . '/variants/');
        
        $variants = [];
        if (isset($response['results']) && is_array($response['results'])) {
            $variants = $response['results'];
        } elseif (is_array($response)) {
            $variants = $response;
        }
        
        $this->client->logger()->info('Article variants retrieved', [
            'article_id' => $articleId,
            'variant_count' => count($variants)
        ]);
        
        return $variants;
    }
    
    /**
     * Get variants from WMS
     */
    public function getVariants(array $params = []): array {
        $endpoint = '/wms/variants/';
        if (!empty($params)) {
            $endpoint .= '?' . http_build_query($params);
        }
        
        $this->client->logger()->debug('Getting variants from WMS', [
            'endpoint' => $endpoint,
            'params' => $params
        ]);
        
        return $this->client->makeAuthenticatedRequest('GET', $endpoint);
    }
    
    /**
     * Find WooCommerce product by name
     * 
     * @param string $name Product name to search for
     * @return WC_Product|null Found product or null if not found
     * @throws Exception When search parameters are invalid
     */
    public function findProductByName(string $name): ?WC_Product {
        if (empty($name)) {
            throw new Exception('Product name cannot be empty');
        }
        
        $this->client->logger()->debug('Searching for product by name', [
            'name' => $name
        ]);
        
        $products = wc_get_products([
            'name' => $name,
            'limit' => 1
        ]);
        
        $product = !empty($products) ? $products[0] : null;
        
        if ($product) {
            $this->client->logger()->info('Product found by name', [
                'name' => $name,
                'product_id' => $product->get_id(),
                'sku' => $product->get_sku()
            ]);
        } else {
            $this->client->logger()->debug('No product found by name', [
                'name' => $name
            ]);
        }
        
        return $product;
    }
    
    /**
     * Get product statistics
     */
    public function getProductStats(): array {
        // Get WooCommerce product counts
        $wcProductCounts = wp_count_posts('product');
        $wcVariationCounts = wp_count_posts('product_variation');
        
        // Get WMS synced product counts
        global $wpdb;
        $wmsSyncedProducts = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = '_wms_article_id' 
             AND p.post_type = 'product'
             AND p.post_status = 'publish'"
        );
        
        return [
            'woocommerce' => [
                'products' => intval($wcProductCounts->publish ?? 0),
                'variations' => intval($wcVariationCounts->publish ?? 0),
                'drafts' => intval($wcProductCounts->draft ?? 0),
                'total' => intval($wcProductCounts->publish ?? 0) + intval($wcVariationCounts->publish ?? 0)
            ],
            'wms_synced' => [
                'products' => intval($wmsSyncedProducts),
                'percentage' => $wcProductCounts->publish > 0 ? 
                    round(($wmsSyncedProducts / $wcProductCounts->publish) * 100, 2) : 0
            ]
        ];
    }
    
    /**
     * Get recent articles from WMS
     */
    public function getRecentArticles(int $days = 7, int $limit = 50): array {
        $fromDate = date('Y-m-d', strtotime("-{$days} days"));
        $toDate = date('Y-m-d');
        
        return $this->getArticles([
            'from' => $fromDate,
            'to' => $toDate,
            'limit' => $limit,
            'sort' => 'createdAt',
            'direction' => 'desc'
        ]);
    }
    
    /**
     * Check if product needs WMS sync
     */
    public function productNeedsSync(WC_Product $product): bool {
        $wmsArticleId = $product->get_meta('_wms_article_id');
        $lastSyncedAt = $product->get_meta('_wms_synced_at');
        $productModified = $product->get_date_modified();
        
        // If never synced
        if (empty($wmsArticleId) || empty($lastSyncedAt)) {
            return true;
        }
        
        // If product was modified after last sync
        if ($productModified && $productModified->getTimestamp() > strtotime($lastSyncedAt)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if product needs updating from WMS data
     */
    public function productNeedsUpdate(WC_Product $product, array $article, array $variant): bool {
        // Check if basic product information has changed
        if ($product->get_name() !== ($article['name'] ?? '')) {
            return true;
        }
        
        if ($product->get_description() !== ($variant['description'] ?? '')) {
            return true;
        }
        
        // Check if price has changed
        if (!empty($variant['value']) && floatval($product->get_price()) !== floatval($variant['value'])) {
            return true;
        }
        
        // Check if stock has changed
        if (isset($variant['stock_physical']) && intval($product->get_stock_quantity()) !== intval($variant['stock_physical'])) {
            return true;
        }
        
        // Check if dimensions have changed
        if (!empty($variant['weight']) && floatval($product->get_weight()) !== floatval($variant['weight'])) {
            return true;
        }
        
        if (!empty($variant['height']) && floatval($product->get_height()) !== floatval($variant['height'])) {
            return true;
        }
        
        if (!empty($variant['width']) && floatval($product->get_width()) !== floatval($variant['width'])) {
            return true;
        }
        
        if (!empty($variant['depth']) && floatval($product->get_length()) !== floatval($variant['depth'])) {
            return true;
        }
        
        // Check if WMS article ID has changed
        if (!empty($article['id']) && $product->get_meta('_wms_article_id') !== $article['id']) {
            return true;
        }
        
        // Check if WMS variant ID has changed
        if (!empty($variant['id']) && $product->get_meta('_wms_variant_id') !== $variant['id']) {
            return true;
        }
        
        // Check if EAN has changed
        if (!empty($variant['ean']) && $product->get_meta('_ean') !== $variant['ean']) {
            return true;
        }
        
        // Check if country of origin has changed
        if (!empty($variant['country_of_origin']) && $product->get_meta('_country_of_origin') !== $variant['country_of_origin']) {
            return true;
        }
        
        // Check if HS tariff code has changed
        if (!empty($variant['hs_tariff_code']) && $product->get_meta('_hs_tariff_code') !== $variant['hs_tariff_code']) {
            return true;
        }
        
        // Check if expirable status has changed
        if (isset($variant['expirable'])) {
            $currentExpirable = $product->get_meta('_expirable') === 'yes';
            if ($currentExpirable !== $variant['expirable']) {
                return true;
            }
        }
        
        // Check if serial number usage has changed
        if (isset($variant['using_serial_numbers'])) {
            $currentSerialNumbers = $product->get_meta('_using_serial_numbers') === 'yes';
            if ($currentSerialNumbers !== $variant['using_serial_numbers']) {
                return true;
            }
        }
        
        // If we get here, no changes were detected
        return false;
    }
    
    /**
     * Mark product as synced
     */
    public function markProductAsSynced(WC_Product $product, string $wmsArticleId): void {
        $product->update_meta_data('_wms_article_id', $wmsArticleId);
        $product->update_meta_data('_wms_synced_at', current_time('mysql'));
        $product->save();
        
        $this->client->logger()->debug('Product marked as synced', [
            'product_id' => $product->get_id(),
            'wms_article_id' => $wmsArticleId
        ]);
    }
    
    /**
     * Import a single article from WMS
     */
    private function importSingleArticle(array $article): array {
        try {
            // Set default params
            $defaultParams = [
                'limit' => 500,
                'page' => 1
            ];
            $params = array_merge($defaultParams, $params);
            
            $this->client->logger()->info('Starting article import from WMS', [
                'params' => $params
            ]);
            
            // Get articles from WMS with expanded variants
            $articles = $this->getArticlesWithVariants($params);
            
            if (is_wp_error($articles)) {
                $this->client->logger()->error('Failed to retrieve articles from WMS', [
                    'error_message' => $articles->get_error_message(),
                    'error_code' => $articles->get_error_code(),
                    'params' => $params
                ]);
                return $articles;
            }
            
            // Handle different response structures
            $articlesData = [];
            if (isset($articles['results']) && is_array($articles['results'])) {
                $articlesData = $articles['results'];
            } elseif (isset($articles['data']) && is_array($articles['data'])) {
                $articlesData = $articles['data'];
            } elseif (is_array($articles)) {
                $articlesData = $articles;
            }
            
            $this->client->logger()->info('Retrieved articles from WMS', [
                'total_articles' => count($articlesData)
            ]);
            
            $imported = 0;
            $updated = 0;
            $skipped = 0;
            $errors = 0;
            $error_details = [];
            
            foreach ($articlesData as $article) {
                try {
                    $result = $this->importSingleArticle($article);
                    
                    if ($result['action'] === 'created') {
                        $imported++;
                    } elseif ($result['action'] === 'updated') {
                        $updated++;
                    } elseif ($result['action'] === 'skipped') {
                        $skipped++;
                    }
                    
                    $this->client->logger()->debug('Article processed successfully', [
                        'article_name' => $article['name'] ?? 'unknown',
                        'action' => $result['action'],
                        'product_id' => $result['product_id']
                    ]);
                    
                } catch (Exception $e) {
                    $errors++;
                    $error_msg = $e->getMessage();
                    $error_details[] = sprintf('Article "%s": %s', 
                        $article['name'] ?? 'unknown', 
                        $error_msg
                    );
                    
                    $this->client->logger()->error('Failed to import article', [
                        'article_name' => $article['name'] ?? 'unknown',
                        'error' => $error_msg
                    ]);
                }
            }
            
            $this->client->logger()->info('Article import completed', [
                'imported' => $imported,
                'updated' => $updated,
                'skipped' => $skipped,
                'errors' => $errors,
                'total_processed' => count($articlesData)
            ]);
            
            return [
                'imported' => $imported,
                'updated' => $updated,
                'skipped' => $skipped,
                'errors' => $errors,
                'error_details' => $error_details,
                'total_articles' => count($articlesData)
            ];
            
        } catch (Exception $e) {
            $this->client->logger()->error('Article import failed with exception', [
                'error' => $e->getMessage()
            ]);
            
            return new WP_Error('import_failed', 'Import failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Sync individual product to WMS
     */
    public function syncProductToWms(int $productId): mixed {
        try {
            $product = wc_get_product($productId);
            if (!$product) {
                return new WP_Error('product_not_found', 'Product not found');
            }
            
            $this->client->logger()->info('Syncing product to WMS', [
                'product_id' => $productId,
                'sku' => $product->get_sku(),
                'name' => $product->get_name()
            ]);
            
            // Transform WooCommerce product to WMS format
            $articleData = $this->client->productSyncManager()->transformWooCommerceProduct($product);
            
            // Check if product already exists in WMS
            $wmsArticleId = $product->get_meta('_wms_article_id');
            
            if ($wmsArticleId) {
                // Update existing article
                $result = $this->updateArticle($wmsArticleId, $articleData);
                
                if (!is_wp_error($result)) {
                    $this->markProductAsSynced($product, $wmsArticleId);
                    $this->client->logger()->info('Product updated in WMS', [
                        'product_id' => $productId,
                        'wms_article_id' => $wmsArticleId
                    ]);
                }
            } else {
                // Create new article
                $result = $this->createArticle($articleData);
                
                if (!is_wp_error($result) && isset($result['id'])) {
                    $this->markProductAsSynced($product, $result['id']);
                    $this->client->logger()->info('Product created in WMS', [
                        'product_id' => $productId,
                        'wms_article_id' => $result['id']
                    ]);
                }
            }
            
            return $result;
            
        } catch (Exception $e) {
            $this->client->logger()->error('Product sync failed', [
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
            
            return new WP_Error('sync_failed', 'Sync failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Get single variant from WMS
     */
    public function getVariant(string $variantId): mixed {
        $this->client->logger()->debug('Getting variant from WMS', [
            'variant_id' => $variantId
        ]);
        
        $response = $this->client->makeAuthenticatedRequest('GET', '/wms/variants/' . $variantId . '/');
        
        if (isset($response['id'])) {
            $this->client->logger()->info('Variant retrieved successfully', [
                'variant_id' => $variantId,
                'article_code' => $response['article_code'] ?? 'unknown'
            ]);
        }
        
        return $response;
    }
    
    /**
     * Create variant in WMS
     */
    public function createVariant(array $variantData): mixed {
        $this->client->logger()->info('Creating variant in WMS', [
            'article_code' => $variantData['article_code'] ?? 'unknown'
        ]);
        
        $response = $this->client->makeAuthenticatedRequest('POST', '/wms/variants/', $variantData);
        
        if (isset($response['id'])) {
            $this->client->logger()->info('Variant created successfully', [
                'variant_id' => $response['id'],
                'article_code' => $response['article_code'] ?? 'unknown'
            ]);
        }
        
        return $response;
    }
    
    /**
     * Update variant in WMS
     */
    public function updateVariant(string $variantId, array $variantData): mixed {
        $this->client->logger()->info('Updating variant in WMS', [
            'variant_id' => $variantId,
            'article_code' => $variantData['article_code'] ?? 'unknown'
        ]);
        
        $response = $this->client->makeAuthenticatedRequest('PATCH', '/wms/variants/' . $variantId . '/', $variantData);
        
        if (isset($response['id'])) {
            $this->client->logger()->info('Variant updated successfully', [
                'variant_id' => $variantId,
                'article_code' => $response['article_code'] ?? 'unknown'
            ]);
        }
        
        return $response;
    }
    
    /**
     * Get product summary for dashboard
     */
    public function getProductSummary(): array {
        $summary = [];
        
        // Get basic statistics
        $summary['wms_connection'] = $this->isAvailable();
        
        // Get product counts
        $totalProducts = wp_count_posts('product');
        $summary['total_products'] = $totalProducts->publish ?? 0;
        
        // Get WMS sync status
        global $wpdb;
        $wmsSyncedProducts = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = '_wms_article_id' 
             AND p.post_type = 'product'
             AND p.post_status = 'publish'"
        );
        
        $summary['wms_synced_products'] = intval($wmsSyncedProducts);
        $summary['sync_percentage'] = $summary['total_products'] > 0 ? 
            round(($summary['wms_synced_products'] / $summary['total_products']) * 100, 1) : 0;
        
        // Get recent activity
        $recentSyncs = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = '_wms_synced_at'
             AND pm.meta_value >= %s
             AND p.post_type = 'product'",
            date('Y-m-d H:i:s', strtotime('-24 hours'))
        ));
        
        $summary['recent_syncs'] = intval($recentSyncs);
        
        return $summary;
    }
    
    /**
     * Get product performance metrics
     */
    public function getProductPerformance(): array {
        global $wpdb;
        
        $performance = [
            'sync_coverage' => 0,
            'avg_sync_time' => null,
            'successful_syncs_24h' => 0,
            'failed_syncs_24h' => 0,
            'top_categories' => []
        ];
        
        // Calculate sync coverage
        $totalProducts = wp_count_posts('product')->publish ?? 0;
        $syncedProducts = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = '_wms_article_id' 
             AND p.post_type = 'product'
             AND p.post_status = 'publish'"
        );
        
        $performance['sync_coverage'] = $totalProducts > 0 ? 
            round((intval($syncedProducts) / $totalProducts) * 100, 1) : 0;
        
        // Get recent sync activity
        $recentSyncs = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = '_wms_synced_at'
             AND pm.meta_value >= %s
             AND p.post_type = 'product'",
            date('Y-m-d H:i:s', strtotime('-24 hours'))
        ));
        
        $performance['successful_syncs_24h'] = intval($recentSyncs);
        
        // Get failed syncs
        $failedSyncs = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = '_wms_last_sync_error'
             AND pm.meta_value != ''
             AND p.post_type = 'product'
             AND p.post_date >= %s",
            date('Y-m-d H:i:s', strtotime('-24 hours'))
        ));
        
        $performance['failed_syncs_24h'] = intval($failedSyncs);
        
        // Get top product categories by sync rate
        $performance['top_categories'] = $this->getTopCategoriesBySyncRate();
        
        return $performance;
    }
    
    /**
     * Get top categories by sync rate
     */
    private function getTopCategoriesBySyncRate(): array {
        global $wpdb;
        
        $query = "
            SELECT 
                t.name as category_name,
                COUNT(p.ID) as total_products,
                SUM(CASE WHEN pm.meta_value IS NOT NULL THEN 1 ELSE 0 END) as synced_products,
                ROUND((SUM(CASE WHEN pm.meta_value IS NOT NULL THEN 1 ELSE 0 END) / COUNT(p.ID)) * 100, 1) as sync_rate
            FROM {$wpdb->terms} t
            INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
            INNER JOIN {$wpdb->term_relationships} tr ON tt.term_taxonomy_id = tr.term_taxonomy_id
            INNER JOIN {$wpdb->posts} p ON tr.object_id = p.ID
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wms_article_id'
            WHERE tt.taxonomy = 'product_cat'
            AND p.post_type = 'product'
            AND p.post_status = 'publish'
            GROUP BY t.term_id, t.name
            HAVING total_products > 0
            ORDER BY sync_rate DESC, total_products DESC
            LIMIT 10
        ";
        
        return $wpdb->get_results($query, ARRAY_A);
    }
    
    /**
     * Get product alerts
     */
    public function getProductAlerts(): array {
        $alerts = [];
        $summary = $this->getProductSummary();
        
        // Low sync percentage alert
        if ($summary['sync_percentage'] < 50) {
            $alerts[] = [
                'type' => 'low_sync_rate',
                'severity' => 'warning',
                'message' => "Only {$summary['sync_percentage']}% of products are synced with WMS",
                'count' => $summary['total_products'] - $summary['wms_synced_products']
            ];
        }
        
        // No recent activity alert
        if ($summary['recent_syncs'] === 0) {
            $alerts[] = [
                'type' => 'no_recent_activity',
                'severity' => 'warning',
                'message' => 'No product syncs in the last 24 hours',
                'count' => 1
            ];
        }
        
        // Check for products without SKUs
        $productsWithoutSku = wc_get_products([
            'return' => 'ids',
            'limit' => -1,
            'meta_query' => [
                [
                    'key' => '_sku',
                    'compare' => 'NOT EXISTS'
                ]
            ]
        ]);
        
        if (!empty($productsWithoutSku)) {
            $alerts[] = [
                'type' => 'missing_sku',
                'severity' => 'error',
                'message' => count($productsWithoutSku) . ' products are missing SKUs',
                'count' => count($productsWithoutSku)
            ];
        }
        
        // WMS connectivity check
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
     * Validate product data before sending to WMS
     */
    public function validateProductData(array $productData): array {
        $errors = [];
        
        // Required fields
        $requiredFields = ['name', 'variants'];
        foreach ($requiredFields as $field) {
            if (empty($productData[$field])) {
                $errors[] = "Missing required field: {$field}";
            }
        }
        
        // Validate name
        if (isset($productData['name']) && strlen($productData['name']) > 255) {
            $errors[] = 'Product name must be less than 255 characters';
        }
        
        // Validate variants
        if (isset($productData['variants']) && is_array($productData['variants'])) {
            if (empty($productData['variants'])) {
                $errors[] = 'Product must have at least one variant';
            } else {
                foreach ($productData['variants'] as $index => $variant) {
                    if (empty($variant['article_code'])) {
                        $errors[] = "Variant {$index}: Missing article_code";
                    }
                    if (empty($variant['sku'])) {
                        $errors[] = "Variant {$index}: Missing SKU";
                    }
                    if (isset($variant['weight']) && (!is_numeric($variant['weight']) || $variant['weight'] < 0)) {
                        $errors[] = "Variant {$index}: Weight must be a non-negative number";
                    }
                    if (isset($variant['value']) && (!is_numeric($variant['value']) || $variant['value'] < 0)) {
                        $errors[] = "Variant {$index}: Value must be a non-negative number";
                    }
                }
            }
        }
        
        // Validate category if provided
        if (isset($productData['category']) && !empty($productData['category'])) {
            if (!is_string($productData['category'])) {
                $errors[] = 'Category must be a string';
            }
        }
        
        return $errors;
    }
    
    /**
     * Get product sync readiness check
     */
    public function checkSyncReadiness(): array {
        $checks = [
            'wms_connection' => $this->isAvailable(),
            'api_credentials' => $this->client->config()->hasValidCredentials(),
            'authentication' => $this->client->authenticator()->isAuthenticated(),
            'rate_limits' => true
        ];
        
        // Check rate limit status
        $rateLimitStatus = $this->client->httpClient()->getRateLimitStatus();
        if (isset($rateLimitStatus['remaining']) && $rateLimitStatus['remaining'] < 10) {
            $checks['rate_limits'] = false;
        }
        
        // Check for products ready to sync
        $readyProducts = wc_get_products([
            'return' => 'ids',
            'limit' => 1,
            'status' => 'publish',
            'meta_query' => [
                [
                    'key' => '_wms_article_id',
                    'compare' => 'NOT EXISTS'
                ],
                [
                    'key' => '_sku',
                    'compare' => 'EXISTS'
                ]
            ]
        ]);
        
        $checks['products_ready'] = !empty($readyProducts);
        
        $allReady = array_reduce($checks, function($carry, $check) {
            return $carry && $check;
        }, true);
        
        $checks['overall_ready'] = $allReady;
        
        return $checks;
    }
    
    /**
     * Get product catalog insights
     */
    public function getCatalogInsights(): array {
        global $wpdb;
        
        $insights = [];
        
        // Get product distribution by type
        $typeQuery = "
            SELECT 
                pm.meta_value as product_type,
                COUNT(*) as count
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.meta_key = '_product_type'
            AND p.post_type = 'product'
            AND p.post_status = 'publish'
            GROUP BY pm.meta_value
            ORDER BY count DESC
        ";
        
        $insights['product_types'] = $wpdb->get_results($typeQuery, ARRAY_A);
        
        // Get sync status distribution
        $syncQuery = "
            SELECT 
                CASE 
                    WHEN pm_wms.meta_value IS NOT NULL THEN 'synced'
                    WHEN pm_sku.meta_value IS NULL OR pm_sku.meta_value = '' THEN 'no_sku'
                    WHEN pm_virtual.meta_value = 'yes' THEN 'virtual'
                    ELSE 'pending'
                END as sync_status,
                COUNT(*) as count
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm_wms ON p.ID = pm_wms.post_id AND pm_wms.meta_key = '_wms_article_id'
            LEFT JOIN {$wpdb->postmeta} pm_sku ON p.ID = pm_sku.post_id AND pm_sku.meta_key = '_sku'
            LEFT JOIN {$wpdb->postmeta} pm_virtual ON p.ID = pm_virtual.post_id AND pm_virtual.meta_key = '_virtual'
            WHERE p.post_type = 'product'
            AND p.post_status = 'publish'
            GROUP BY sync_status
            ORDER BY count DESC
        ";
        
        $insights['sync_distribution'] = $wpdb->get_results($syncQuery, ARRAY_A);
        
        // Get price range analysis
        $priceQuery = "
            SELECT 
                COUNT(*) as total_products,
                AVG(CAST(pm.meta_value AS DECIMAL(10,2))) as avg_price,
                MIN(CAST(pm.meta_value AS DECIMAL(10,2))) as min_price,
                MAX(CAST(pm.meta_value AS DECIMAL(10,2))) as max_price
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.meta_key = '_price'
            AND pm.meta_value != ''
            AND CAST(pm.meta_value AS DECIMAL(10,2)) > 0
            AND p.post_type = 'product'
            AND p.post_status = 'publish'
        ";
        
        $insights['price_analysis'] = $wpdb->get_row($priceQuery, ARRAY_A);
        
        return $insights;
    }
    
    /**
     * Force refresh product data from WMS
     */
    public function forceRefreshProducts(array $productIds): array {
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
            
            $wmsArticleId = $product->get_meta('_wms_article_id');
            if (!$wmsArticleId) {
                $results['errors'][] = "Product {$productId} not synced with WMS";
                continue;
            }
            
            try {
                // Get latest data from WMS
                $wmsArticle = $this->getArticle($wmsArticleId);
                
                if (empty($wmsArticle)) {
                    throw new Exception('Article not found in WMS');
                }
                
                // Update product with latest WMS data
                if (isset($wmsArticle['name'])) {
                    $product->set_name($wmsArticle['name']);
                }
                
                // Update meta
                $product->update_meta_data('_wms_synced_at', current_time('mysql'));
                $product->update_meta_data('_wms_article_data', json_encode($wmsArticle));
                $product->save();
                
                $results['success'][] = [
                    'product_id' => $productId,
                    'product_name' => $product->get_name(),
                    'wms_article_id' => $wmsArticleId
                ];
                
            } catch (Exception $e) {
                $results['errors'][] = "Failed to refresh {$productId}: " . $e->getMessage();
            }
        }
        
        return $results;
    }
}