<?php
/**
 * WMS Product Integrator
 * 
 * Integrates WooCommerce products with WMS articles
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Product_Integrator implements WC_WMS_Product_Integrator_Interface {
    
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
        return 'product';
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
            
            // Check if product service is available
            if (!$this->client->products() || !$this->client->products()->isAvailable()) {
                return false;
            }
            
            // Check database connectivity
            global $wpdb;
            if (!$wpdb || $wpdb->last_error) {
                return false;
            }
            
            return true;
            
        } catch (Exception $e) {
            $this->client->logger()->error('Product integrator readiness check failed', [
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
            $syncStats = $this->getProductSyncStatistics();
            $status['products_synced'] = $syncStats['wms_products'] ?? 0;
            $status['last_sync'] = $syncStats['last_sync'] ?? null;
            
            // Calculate pending syncs
            $pendingCount = $this->getPendingSyncCount();
            $status['pending_syncs'] = $pendingCount;
            
            // Calculate health score (0-100)
            $healthScore = 100;
            
            if (!$status['ready']) {
                $healthScore -= 50;
                $status['issues'][] = 'Integrator not ready';
            }
            
            if ($status['pending_syncs'] > 20) {
                $healthScore -= 20;
                $status['issues'][] = "High number of pending syncs: {$status['pending_syncs']}";
            }
            
            if (!$status['last_sync'] || strtotime($status['last_sync']) < strtotime('-24 hours')) {
                $healthScore -= 15;
                $status['issues'][] = 'Product sync not recent';
            }
            
            // Check sync percentage
            $syncPercentage = $syncStats['sync_percentage'] ?? 0;
            if ($syncPercentage < 70) {
                $healthScore -= 15;
                $status['issues'][] = "Low sync percentage: {$syncPercentage}%";
            }
            
            $status['health_score'] = max(0, $healthScore);
            $status['sync_percentage'] = $syncPercentage;
            
        } catch (Exception $e) {
            $status['issues'][] = 'Failed to get status: ' . $e->getMessage();
            $status['health_score'] = 0;
        }
        
        return $status;
    }
    
    /**
     * Sync product to WMS (Interface requirement)
     */
    public function syncProduct(int $productId): mixed {
        $product = wc_get_product($productId);
        if (!$product) {
            return new WP_Error('product_not_found', 'Product not found: ' . $productId);
        }
        
        // Check if product should be synced
        if (!$this->shouldSyncProduct($product)) {
            return new WP_Error('product_not_syncable', 'Product should not be synced: ' . $productId);
        }
        
        try {
            $result = $this->syncProductToWMS($product);
            return $result;
        } catch (Exception $e) {
            return new WP_Error('sync_failed', 'Failed to sync product: ' . $e->getMessage());
        }
    }
    
    /**
     * Sync all products to WMS (Interface requirement)
     */
    public function syncAllProducts(int $batchSize = 50): array {
        return $this->syncAllProductsToWMS();
    }
    
    /**
     * Transform product data for WMS (Interface requirement)
     */
    public function transformProductData(WC_Product $product): array {
        return $this->client->productSyncManager()->transformWooCommerceProduct($product);
    }
    
    /**
     * Check if product should be synced to WMS (Interface requirement)
     */
    public function shouldSyncProduct(WC_Product $product): bool {
        // Don't sync if product is not published
        if ($product->get_status() !== 'publish') {
            return false;
        }
        
        // Don't sync virtual or downloadable products
        if ($product->is_virtual() || $product->is_downloadable()) {
            return false;
        }
        
        // Don't sync if product has no SKU
        if (empty($product->get_sku())) {
            return false;
        }
        
        // Don't sync if explicitly excluded
        if ($product->get_meta('_wms_sync_disabled') === 'yes') {
            return false;
        }
        
        // Check if product needs sync (has changes)
        if (!$this->client->products()->productNeedsSync($product)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Sync WooCommerce product to WMS
     */
    public function syncProductToWMS(WC_Product $product): array {
        $this->client->logger()->info('Syncing WooCommerce product to WMS', [
            'product_id' => $product->get_id(),
            'product_name' => $product->get_name(),
            'product_type' => $product->get_type()
        ]);
        
        // Check if product needs sync
        if (!$this->client->products()->productNeedsSync($product)) {
            $this->client->logger()->debug('Product does not need sync', [
                'product_id' => $product->get_id()
            ]);
            return [
                'action' => 'skipped',
                'reason' => 'Product already synced and up to date'
            ];
        }
        
        // Transform product to WMS format
        $articleData = $this->client->productSyncManager()->transformWooCommerceProduct($product);
        
        // Check if product already exists in WMS
        $wmsArticleId = $product->get_meta('_wms_article_id');
        
        if ($wmsArticleId) {
            // Update existing article
            $result = $this->updateProductInWMS($product, $wmsArticleId, $articleData);
            $result['action'] = 'updated';
        } else {
            // Create new article
            $result = $this->createProductInWMS($product, $articleData);
            $result['action'] = 'created';
        }
        
        return $result;
    }
    
    /**
     * Sync WMS article to WooCommerce product
     */
    public function syncProductFromWMS(array $wmsArticle): array {
        $this->client->logger()->info('Syncing WMS article to WooCommerce', [
            'wms_article_id' => $wmsArticle['id'],
            'article_name' => $wmsArticle['name'],
            'variant_count' => count($wmsArticle['variants'] ?? [])
        ]);
        
        // Get detailed article information if basic data doesn't have variants
        if (!isset($wmsArticle['variants']) || empty($wmsArticle['variants'])) {
            $this->client->logger()->debug('Fetching detailed article info for variants', [
                'article_id' => $wmsArticle['id']
            ]);
            
            try {
                // Try enhanced getArticle with variants
                $detailedArticle = $this->client->products()->getArticle($wmsArticle['id'], true);
                if (isset($detailedArticle['variants']) && !empty($detailedArticle['variants'])) {
                    $wmsArticle = $detailedArticle;
                } else {
                    // Fallback: Try direct variants endpoint
                    $variants = $this->client->products()->getArticleVariants($wmsArticle['id']);
                    if (!empty($variants)) {
                        $wmsArticle['variants'] = $variants;
                    }
                }
            } catch (Exception $e) {
                $this->client->logger()->warning('Failed to get detailed article info', [
                    'article_id' => $wmsArticle['id'],
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        // Handle articles without variants (create simple product)
        if (!isset($wmsArticle['variants']) || empty($wmsArticle['variants'])) {
            return $this->syncSimpleArticleFromWMS($wmsArticle);
        }
        
        // Handle articles with variants
        if (count($wmsArticle['variants']) === 1) {
            // Single variant - create simple product with variant SKU
            return $this->syncSingleVariantArticleFromWMS($wmsArticle);
        } else {
            // Multiple variants - create variable product with variations
            return $this->syncVariableArticleFromWMS($wmsArticle);
        }
    }
    
    /**
     * Sync single variant article from WMS (creates simple product with variant SKU)
     */
    private function syncSingleVariantArticleFromWMS(array $wmsArticle): array {
        $variant = $wmsArticle['variants'][0];
        $sku = $variant['sku'] ?? $variant['article_code'] ?? null;
        
        if (empty($sku)) {
            throw new Exception('Variant has no SKU or article code');
        }
        
        $this->client->logger()->info('Creating simple product from single variant', [
            'article_id' => $wmsArticle['id'],
            'variant_sku' => $sku,
            'variant_name' => $variant['name'] ?? 'Unknown'
        ]);
        
        // Check if WooCommerce product already exists
        $existingProduct = $this->client->productSyncManager()->findProductBySku($sku);
        
        if ($existingProduct) {
            // Update existing product
            $result = $this->updateWooCommerceProductFromWMS($existingProduct, $wmsArticle, $variant);
            $result['action'] = 'updated';
        } else {
            // Create new product
            $result = $this->createWooCommerceProductFromWMS($wmsArticle, $variant);
            $result['action'] = 'created';
        }
        
        return $result;
    }
    
    /**
     * Sync multiple variant article from WMS (creates variable product with variations)
     */
    private function syncVariableArticleFromWMS(array $wmsArticle): array {
        $this->client->logger()->info('Creating variable product from multiple variants', [
            'article_id' => $wmsArticle['id'],
            'article_name' => $wmsArticle['name'],
            'variant_count' => count($wmsArticle['variants'])
        ]);
        
        // Check if WooCommerce product already exists (by article ID)
        $existingProduct = $this->client->productSyncManager()->findProductBySku($wmsArticle['id']);
        
        if ($existingProduct) {
            // Update existing variable product
            return $this->updateVariableProductFromWMS($existingProduct, $wmsArticle);
        } else {
            // Create new variable product
            return $this->createVariableProductFromWMS($wmsArticle);
        }
    }
    
    /**
     * Create variable product from WMS article with multiple variants
     */
    private function createVariableProductFromWMS(array $wmsArticle): array {
        try {
            // Create variable product
            $product = new WC_Product_Variable();
            
            // Set basic product data
            $product->set_name($wmsArticle['name']);
            $product->set_description('');
            $product->set_status('publish');
            $product->set_catalog_visibility('visible');
            
            // Set WMS meta data
            $product->update_meta_data('_wms_article_id', $wmsArticle['id']);
            $product->update_meta_data('_wms_synced_at', current_time('mysql'));
            
            // Save parent product first
            $productId = $product->save();
            
            $this->client->logger()->info('Variable product created', [
                'product_id' => $productId,
                'article_id' => $wmsArticle['id']
            ]);
            
            // Create variations for each variant
            $variationsCreated = 0;
            $variationErrors = [];
            
            foreach ($wmsArticle['variants'] as $index => $variant) {
                try {
                    $this->createProductVariationFromWMS($productId, $wmsArticle, $variant, $index);
                    $variationsCreated++;
                } catch (Exception $e) {
                    $variationErrors[] = [
                        'variant_index' => $index,
                        'variant_sku' => $variant['sku'] ?? $variant['article_code'] ?? 'unknown',
                        'error' => $e->getMessage()
                    ];
                    $this->client->logger()->error('Failed to create variation', [
                        'product_id' => $productId,
                        'variant_index' => $index,
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            // Update parent product after variations are created
            $product = wc_get_product($productId);
            $product->save();
            
            $this->client->logger()->info('Variable product import completed', [
                'product_id' => $productId,
                'article_id' => $wmsArticle['id'],
                'variations_created' => $variationsCreated,
                'variation_errors' => count($variationErrors)
            ]);
            
            return [
                'product_id' => $productId,
                'action' => 'created',
                'product_type' => 'variable',
                'variations_created' => $variationsCreated,
                'variation_errors' => $variationErrors
            ];
            
        } catch (Exception $e) {
            $this->client->logger()->error('Failed to create variable product', [
                'article_id' => $wmsArticle['id'],
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Create product variation from WMS variant
     */
    private function createProductVariationFromWMS(int $parentProductId, array $wmsArticle, array $variant, int $index): int {
        $sku = $variant['sku'] ?? $variant['article_code'] ?? null;
        
        if (empty($sku)) {
            throw new Exception("Variant {$index} has no SKU or article code");
        }
        
        // Create variation
        $variation = new WC_Product_Variation();
        $variation->set_parent_id($parentProductId);
        
        // Set basic variation data
        $variation->set_name($variant['name'] ?? "Variation {$index}");
        $variation->set_sku($sku);
        $variation->set_status('publish');
        
        // Set stock management
        $variation->set_manage_stock(true);
        
        // Get stock quantity from WMS
        try {
            $stockQuantity = $this->client->stock()->getVariantStockQuantity($variant['id'], $sku);
            $variation->set_stock_quantity($stockQuantity);
            $variation->set_stock_status($stockQuantity > 0 ? 'instock' : 'outofstock');
        } catch (Exception $e) {
            // Default to 0 stock if can't get from WMS
            $variation->set_stock_quantity(0);
            $variation->set_stock_status('outofstock');
            $this->client->logger()->warning('Could not get stock for variant', [
                'variant_id' => $variant['id'],
                'sku' => $sku,
                'error' => $e->getMessage()
            ]);
        }
        
        // Set pricing if available
        if (isset($variant['value']) && $variant['value'] > 0) {
            $variation->set_regular_price($variant['value']);
        }
        
        // Set dimensions if available
        if (isset($variant['weight']) && $variant['weight']) {
            $variation->set_weight($variant['weight']);
        }
        if (isset($variant['height']) && $variant['height']) {
            $variation->set_height($variant['height']);
        }
        if (isset($variant['width']) && $variant['width']) {
            $variation->set_width($variant['width']);
        }
        if (isset($variant['depth']) && $variant['depth']) {
            $variation->set_length($variant['depth']);
        }
        
        // Set WMS meta data
        $variation->update_meta_data('_wms_article_id', $wmsArticle['id']);
        $variation->update_meta_data('_wms_variant_id', $variant['id']);
        $variation->update_meta_data('_wms_article_code', $variant['article_code'] ?? '');
        $variation->update_meta_data('_wms_synced_at', current_time('mysql'));
        
        // Save variation
        $variationId = $variation->save();
        
        $this->client->logger()->info('Product variation created', [
            'variation_id' => $variationId,
            'parent_id' => $parentProductId,
            'sku' => $sku,
            'variant_id' => $variant['id']
        ]);
        
        return $variationId;
    }
    
    /**
     * Update variable product from WMS article
     */
    private function updateVariableProductFromWMS(WC_Product $product, array $wmsArticle): array {
        $this->client->logger()->info('Updating existing variable product', [
            'product_id' => $product->get_id(),
            'article_id' => $wmsArticle['id']
        ]);
        
        // Update basic product data
        $product->set_name($wmsArticle['name']);
        $product->update_meta_data('_wms_synced_at', current_time('mysql'));
        $product->save();
        
        // Get existing variations
        $existingVariations = $product->get_children();
        $processedVariantIds = [];
        $variationsUpdated = 0;
        $variationsCreated = 0;
        $variationErrors = [];
        
        // Process each WMS variant
        foreach ($wmsArticle['variants'] as $index => $variant) {
            try {
                $variantId = $variant['id'];
                $sku = $variant['sku'] ?? $variant['article_code'] ?? null;
                
                if (empty($sku)) {
                    throw new Exception("Variant {$index} has no SKU or article code");
                }
                
                // Check if variation already exists
                $existingVariation = $this->findVariationByWmsVariantId($product->get_id(), $variantId);
                
                if ($existingVariation) {
                    // Update existing variation
                    $this->updateProductVariationFromWMS($existingVariation, $wmsArticle, $variant);
                    $variationsUpdated++;
                    $processedVariantIds[] = $variantId;
                } else {
                    // Create new variation
                    $this->createProductVariationFromWMS($product->get_id(), $wmsArticle, $variant, $index);
                    $variationsCreated++;
                    $processedVariantIds[] = $variantId;
                }
                
            } catch (Exception $e) {
                $variationErrors[] = [
                    'variant_index' => $index,
                    'variant_sku' => $variant['sku'] ?? $variant['article_code'] ?? 'unknown',
                    'error' => $e->getMessage()
                ];
                $this->client->logger()->error('Failed to update/create variation', [
                    'product_id' => $product->get_id(),
                    'variant_index' => $index,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        // Remove variations that no longer exist in WMS
        $this->removeObsoleteVariations($product->get_id(), $processedVariantIds);
        
        $this->client->logger()->info('Variable product update completed', [
            'product_id' => $product->get_id(),
            'article_id' => $wmsArticle['id'],
            'variations_updated' => $variationsUpdated,
            'variations_created' => $variationsCreated,
            'variation_errors' => count($variationErrors)
        ]);
        
        return [
            'product_id' => $product->get_id(),
            'action' => 'updated',
            'product_type' => 'variable',
            'variations_updated' => $variationsUpdated,
            'variations_created' => $variationsCreated,
            'variation_errors' => $variationErrors
        ];
    }
    
    /**
     * Find variation by WMS variant ID
     */
    private function findVariationByWmsVariantId(int $parentProductId, string $wmsVariantId): ?WC_Product_Variation {
        $variations = wc_get_products([
            'type' => 'variation',
            'parent' => $parentProductId,
            'meta_query' => [
                [
                    'key' => '_wms_variant_id',
                    'value' => $wmsVariantId,
                    'compare' => '='
                ]
            ],
            'limit' => 1
        ]);
        
        return $variations ? $variations[0] : null;
    }
    
    /**
     * Update product variation from WMS variant
     */
    private function updateProductVariationFromWMS(WC_Product_Variation $variation, array $wmsArticle, array $variant): void {
        $sku = $variant['sku'] ?? $variant['article_code'] ?? null;
        
        if (!empty($sku)) {
            $variation->set_sku($sku);
        }
        
        // Update stock
        try {
            $stockQuantity = $this->client->stock()->getVariantStockQuantity($variant['id'], $sku);
            $variation->set_stock_quantity($stockQuantity);
            $variation->set_stock_status($stockQuantity > 0 ? 'instock' : 'outofstock');
        } catch (Exception $e) {
            $this->client->logger()->warning('Could not update stock for variation', [
                'variation_id' => $variation->get_id(),
                'variant_id' => $variant['id'],
                'error' => $e->getMessage()
            ]);
        }
        
        // Update pricing if available
        if (isset($variant['value']) && $variant['value'] > 0) {
            $variation->set_regular_price($variant['value']);
        }
        
        // Update dimensions if available
        if (isset($variant['weight'])) $variation->set_weight($variant['weight']);
        if (isset($variant['height'])) $variation->set_height($variant['height']);
        if (isset($variant['width'])) $variation->set_width($variant['width']);
        if (isset($variant['depth'])) $variation->set_length($variant['depth']);
        
        // Update WMS meta data
        $variation->update_meta_data('_wms_article_id', $wmsArticle['id']);
        $variation->update_meta_data('_wms_variant_id', $variant['id']);
        $variation->update_meta_data('_wms_article_code', $variant['article_code'] ?? '');
        $variation->update_meta_data('_wms_synced_at', current_time('mysql'));
        
        $variation->save();
        
        $this->client->logger()->info('Product variation updated', [
            'variation_id' => $variation->get_id(),
            'sku' => $sku,
            'variant_id' => $variant['id']
        ]);
    }
    
    /**
     * Remove variations that no longer exist in WMS
     */
    private function removeObsoleteVariations(int $parentProductId, array $processedVariantIds): void {
        $allVariations = wc_get_products([
            'type' => 'variation',
            'parent' => $parentProductId,
            'meta_query' => [
                [
                    'key' => '_wms_variant_id',
                    'compare' => 'EXISTS'
                ]
            ],
            'limit' => -1
        ]);
        
        foreach ($allVariations as $variation) {
            $wmsVariantId = $variation->get_meta('_wms_variant_id');
            
            if (!in_array($wmsVariantId, $processedVariantIds)) {
                $this->client->logger()->info('Removing obsolete variation', [
                    'variation_id' => $variation->get_id(),
                    'parent_id' => $parentProductId,
                    'wms_variant_id' => $wmsVariantId
                ]);
                
                $variation->delete(true);
            }
        }
    }
    
    /**
     * Sync stock from WMS to WooCommerce
     */
    public function syncStockFromWMS(int $productId): bool {
        $product = wc_get_product($productId);
        if (!$product) {
            throw new Exception('Product not found');
        }
        
        $variantId = $product->get_meta('_wms_variant_id');
        if (empty($variantId)) {
            throw new Exception('Product not linked to WMS variant');
        }
        
        $this->client->logger()->info('Syncing stock from WMS', [
            'product_id' => $productId,
            'wms_variant_id' => $variantId
        ]);
        
        // Get stock quantity from WMS
        $stockQuantity = $this->client->stock()->getVariantStockQuantity($variantId, $product->get_sku());
        
        // Update WooCommerce product stock
        $product->set_stock_quantity($stockQuantity);
        $product->set_stock_status($stockQuantity > 0 ? 'instock' : 'outofstock');
        $product->update_meta_data('_wms_stock_synced_at', current_time('mysql'));
        $product->save();
        
        $this->client->logger()->info('Stock synced successfully', [
            'product_id' => $productId,
            'stock_quantity' => $stockQuantity
        ]);
        
        return true;
    }
    
    /**
     * Sync all WooCommerce products to WMS
     */
    public function syncAllProductsToWMS(array $productIds = []): array {
        $this->client->logger()->info('Starting bulk product sync to WMS', [
            'product_ids' => $productIds
        ]);
        
        $results = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'error_details' => []
        ];
        
        // Get products to sync
        if (empty($productIds)) {
            $products = wc_get_products([
                'status' => 'publish',
                'limit' => -1
            ]);
        } else {
            $products = array_map('wc_get_product', $productIds);
            $products = array_filter($products);
        }
        
        foreach ($products as $product) {
            try {
                $result = $this->syncProductToWMS($product);
                
                switch ($result['action']) {
                    case 'created':
                        $results['created']++;
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
                    'product_id' => $product->get_id(),
                    'product_name' => $product->get_name(),
                    'error' => $e->getMessage()
                ];
                
                $this->client->logger()->error('Failed to sync product to WMS', [
                    'product_id' => $product->get_id(),
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        $this->client->logger()->info('Bulk product sync to WMS completed', $results);
        
        return $results;
    }
    
    /**
     * Import articles from WMS (alias for importAllProductsFromWMS)
     */
    public function importArticlesFromWMS(array $params = []): array {
        return $this->importAllProductsFromWMS($params);
    }
    
    /**
     * Import all WMS articles to WooCommerce
     */
    public function importAllProductsFromWMS(array $params = []): array {
        $this->client->logger()->info('Starting bulk product import from WMS', $params);
        
        // Use the enhanced method to get articles with variants
        try {
            $articles = $this->client->products()->getArticlesWithVariants($params);
        } catch (Exception $e) {
            $this->client->logger()->warning('Failed to get articles with variants, falling back to basic articles', [
                'error' => $e->getMessage()
            ]);
            $articles = $this->client->products()->getArticles($params);
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
        
        $this->client->logger()->info('Articles retrieved for processing', [
            'total_articles' => count($articlesData)
        ]);
        
        $results = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'error_details' => []
        ];
        
        foreach ($articlesData as $wmsArticle) {
            try {
                $result = $this->syncProductFromWMS($wmsArticle);
                
                switch ($result['action']) {
                    case 'created':
                        $results['created']++;
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
                    'wms_article_id' => $wmsArticle['id'] ?? 'unknown',
                    'article_name' => $wmsArticle['name'] ?? 'unknown',
                    'error' => $e->getMessage()
                ];
                
                $this->client->logger()->error('Failed to import product from WMS', [
                    'wms_article_id' => $wmsArticle['id'] ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        $this->client->logger()->info('Bulk product import from WMS completed', $results);
        
        return $results;
    }
    
    /**
     * Create product in WMS
     */
    private function createProductInWMS(WC_Product $product, array $articleData): array {
        $response = $this->client->products()->createArticle($articleData);
        
        if (isset($response['id'])) {
            $this->client->products()->markProductAsSynced($product, $response['id']);
            
            // Store variant IDs if available
            if (isset($response['variants']) && !empty($response['variants'])) {
                $variant = $response['variants'][0];
                $product->update_meta_data('_wms_variant_id', $variant['id']);
                $product->update_meta_data('_wms_article_code', $variant['article_code']);
                $product->save();
            }
        }
        
        return $response;
    }
    
    /**
     * Update product in WMS
     */
    private function updateProductInWMS(WC_Product $product, string $wmsArticleId, array $articleData): array {
        $response = $this->client->products()->updateArticle($wmsArticleId, $articleData);
        
        if (isset($response['id'])) {
            $this->client->products()->markProductAsSynced($product, $response['id']);
        }
        
        return $response;
    }
    
    /**
     * Create WooCommerce product from WMS article
     */
    private function createWooCommerceProductFromWMS(array $wmsArticle, array $variant): array {
        $product = new WC_Product_Simple();
        
        // Set basic product data
        $product->set_name($wmsArticle['name']);
        $product->set_description($variant['description'] ?? '');
        $product->set_sku($variant['sku'] ?? $variant['article_code']);
        $product->set_regular_price($variant['value'] ?? 0);
        $product->set_weight($variant['weight'] ?? 0);
        
        // Set dimensions
        if (isset($variant['height'])) $product->set_height($variant['height']);
        if (isset($variant['width'])) $product->set_width($variant['width']);
        if (isset($variant['depth'])) $product->set_length($variant['depth']);
        
        // Set stock management
        $product->set_manage_stock(true);
        $stockQuantity = $this->client->stock()->getVariantStockQuantity($variant['id'], $variant['sku'] ?? null);
        $product->set_stock_quantity($stockQuantity);
        $product->set_stock_status($stockQuantity > 0 ? 'instock' : 'outofstock');
        
        // Set WMS meta data
        $product->update_meta_data('_wms_article_id', $wmsArticle['id']);
        $product->update_meta_data('_wms_variant_id', $variant['id']);
        $product->update_meta_data('_wms_article_code', $variant['article_code']);
        $product->update_meta_data('_wms_synced_at', current_time('mysql'));
        
        // Save product
        $productId = $product->save();
        
        $this->client->logger()->info('WooCommerce product created from WMS', [
            'product_id' => $productId,
            'sku' => $variant['sku'] ?? $variant['article_code'],
            'wms_article_id' => $wmsArticle['id'],
            'stock_quantity' => $stockQuantity
        ]);
        
        return [
            'product_id' => $productId,
            'sku' => $variant['sku'] ?? $variant['article_code'],
            'stock_quantity' => $stockQuantity
        ];
    }
    
    /**
     * Update WooCommerce product from WMS article
     */
    private function updateWooCommerceProductFromWMS(WC_Product $product, array $wmsArticle, array $variant): array {
        // Update basic product data
        $product->set_name($wmsArticle['name']);
        $product->set_description($variant['description'] ?? '');
        $product->set_regular_price($variant['value'] ?? 0);
        $product->set_weight($variant['weight'] ?? 0);
        
        // Update dimensions
        if (isset($variant['height'])) $product->set_height($variant['height']);
        if (isset($variant['width'])) $product->set_width($variant['width']);
        if (isset($variant['depth'])) $product->set_length($variant['depth']);
        
        // Update stock
        $product->set_manage_stock(true);
        $stockQuantity = $this->client->stock()->getVariantStockQuantity($variant['id'], $variant['sku'] ?? null);
        $product->set_stock_quantity($stockQuantity);
        $product->set_stock_status($stockQuantity > 0 ? 'instock' : 'outofstock');
        
        // Update WMS meta data
        $product->update_meta_data('_wms_article_id', $wmsArticle['id']);
        $product->update_meta_data('_wms_variant_id', $variant['id']);
        $product->update_meta_data('_wms_article_code', $variant['article_code']);
        $product->update_meta_data('_wms_synced_at', current_time('mysql'));
        
        // Save product
        $product->save();
        
        $this->client->logger()->info('WooCommerce product updated from WMS', [
            'product_id' => $product->get_id(),
            'sku' => $product->get_sku(),
            'wms_article_id' => $wmsArticle['id'],
            'stock_quantity' => $stockQuantity
        ]);
        
        return [
            'product_id' => $product->get_id(),
            'sku' => $product->get_sku(),
            'stock_quantity' => $stockQuantity
        ];
    }
    
    /**
     * Sync simple article from WMS (no variants)
     */
    private function syncSimpleArticleFromWMS(array $wmsArticle): array {
        $existingProduct = $this->client->productSyncManager()->findProductBySku($wmsArticle['id']);
        
        if (!$existingProduct) {
            $existingProduct = $this->client->products()->findWooCommerceProductByName($wmsArticle['name']);
        }
        
        if ($existingProduct) {
            return $this->updateWooCommerceProductFromSimpleWMS($existingProduct, $wmsArticle);
        } else {
            return $this->createWooCommerceProductFromSimpleWMS($wmsArticle);
        }
    }
    
    /**
     * Create WooCommerce product from simple WMS article
     */
    private function createWooCommerceProductFromSimpleWMS(array $wmsArticle): array {
        $product = new WC_Product_Simple();
        
        // Set basic product data
        $product->set_name($wmsArticle['name']);
        $product->set_description('');
        
        // Generate SKU from article ID
        $sku = 'WMS_' . $wmsArticle['id'];
        $product->set_sku($sku);
        $product->set_regular_price(0);
        
        // Set stock management
        $product->set_manage_stock(true);
        $product->set_stock_quantity(0);
        $product->set_stock_status('outofstock');
        
        // Set WMS meta data
        $product->update_meta_data('_wms_article_id', $wmsArticle['id']);
        $product->update_meta_data('_wms_synced_at', current_time('mysql'));
        
        // Save product
        $productId = $product->save();
        
        $this->client->logger()->info('WooCommerce product created from simple WMS article', [
            'product_id' => $productId,
            'sku' => $sku,
            'wms_article_id' => $wmsArticle['id']
        ]);
        
        return [
            'product_id' => $productId,
            'sku' => $sku,
            'action' => 'created'
        ];
    }
    
    /**
     * Update WooCommerce product from simple WMS article
     */
    private function updateWooCommerceProductFromSimpleWMS(WC_Product $product, array $wmsArticle): array {
        // Update basic product data
        $product->set_name($wmsArticle['name']);
        
        // Update WMS meta data
        $product->update_meta_data('_wms_article_id', $wmsArticle['id']);
        $product->update_meta_data('_wms_synced_at', current_time('mysql'));
        
        // Save product
        $product->save();
        
        $this->client->logger()->info('WooCommerce product updated from simple WMS article', [
            'product_id' => $product->get_id(),
            'sku' => $product->get_sku(),
            'wms_article_id' => $wmsArticle['id']
        ]);
        
        return [
            'product_id' => $product->get_id(),
            'sku' => $product->get_sku(),
            'action' => 'updated'
        ];
    }
    
    /**
     * Get product sync statistics
     */
    public function getProductSyncStatistics(): array {
        global $wpdb;
        
        // Count products with WMS IDs
        $wmsProducts = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = '_wms_article_id' 
             AND p.post_type = 'product'
             AND p.post_status = 'publish'"
        );
        
        // Count total products
        $totalProducts = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             WHERE p.post_type = 'product'
             AND p.post_status = 'publish'"
        );
        
        // Get last sync time
        $lastSync = $wpdb->get_var(
            "SELECT MAX(pm.meta_value) FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = '_wms_synced_at'
             AND p.post_type = 'product'"
        );
        
        return [
            'wms_products' => intval($wmsProducts),
            'total_products' => intval($totalProducts),
            'sync_percentage' => $totalProducts > 0 ? 
                round(($wmsProducts / $totalProducts) * 100, 2) : 0,
            'last_sync' => $lastSync
        ];
    }
    
    /**
     * Get count of products pending sync
     */
    private function getPendingSyncCount(): int {
        global $wpdb;
        
        // Get products that should be synced but aren't
        $query = $wpdb->prepare("
            SELECT COUNT(*) 
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wms_article_id'
            LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_wms_sync_disabled'
            WHERE p.post_type = 'product'
            AND p.post_status = 'publish'
            AND pm.meta_value IS NULL
            AND (pm2.meta_value IS NULL OR pm2.meta_value != 'yes')
            AND p.post_date >= %s
        ", date('Y-m-d H:i:s', strtotime('-30 days')));
        
        return intval($wpdb->get_var($query));
    }
    
    /**
     * Get product category mapping for WMS
     */
    public function getProductCategoryMapping(): array {
        $categories = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false
        ]);
        
        $mapping = [];
        foreach ($categories as $category) {
            $wmsCategory = get_term_meta($category->term_id, '_wms_category_id', true);
            $mapping[] = [
                'wc_category_id' => $category->term_id,
                'wc_category_name' => $category->name,
                'wms_category_id' => $wmsCategory ?: null,
                'product_count' => $category->count
            ];
        }
        
        return $mapping;
    }
    
    /**
     * Get product sync queue status
     */
    public function getSyncQueueStatus(): array {
        global $wpdb;
        
        // Get products by sync status
        $statusQuery = "
            SELECT 
                CASE 
                    WHEN pm_wms.meta_value IS NOT NULL THEN 'synced'
                    WHEN pm_disabled.meta_value = 'yes' THEN 'disabled'
                    ELSE 'pending'
                END as sync_status,
                COUNT(*) as count
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm_wms ON p.ID = pm_wms.post_id AND pm_wms.meta_key = '_wms_article_id'
            LEFT JOIN {$wpdb->postmeta} pm_disabled ON p.ID = pm_disabled.post_id AND pm_disabled.meta_key = '_wms_sync_disabled'
            WHERE p.post_type = 'product'
            AND p.post_status = 'publish'
            GROUP BY sync_status
        ";
        
        $results = $wpdb->get_results($statusQuery, ARRAY_A);
        
        $queueStatus = [
            'synced' => 0,
            'pending' => 0,
            'disabled' => 0,
            'total' => 0
        ];
        
        foreach ($results as $result) {
            $status = $result['sync_status'];
            $count = intval($result['count']);
            $queueStatus[$status] = $count;
            $queueStatus['total'] += $count;
        }
        
        return $queueStatus;
    }
    
    /**
     * Get product sync performance metrics
     */
    public function getSyncPerformanceMetrics(): array {
        global $wpdb;
        
        $since = date('Y-m-d H:i:s', strtotime('-24 hours'));
        
        // Get sync activity in last 24h
        $metricsQuery = $wpdb->prepare("
            SELECT 
                COUNT(*) as synced_products,
                MIN(pm.meta_value) as first_sync,
                MAX(pm.meta_value) as last_sync
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.meta_key = '_wms_synced_at'
            AND pm.meta_value >= %s
            AND p.post_type = 'product'
        ", $since);
        
        $metrics = $wpdb->get_row($metricsQuery, ARRAY_A);
        
        return [
            'period' => '24 hours',
            'synced_products' => intval($metrics['synced_products'] ?? 0),
            'first_sync' => $metrics['first_sync'] ?? null,
            'last_sync' => $metrics['last_sync'] ?? null,
            'sync_rate' => $this->calculateSyncRate()
        ];
    }
    
    /**
     * Calculate current sync rate
     */
    private function calculateSyncRate(): float {
        $stats = $this->getProductSyncStatistics();
        $total = $stats['total_products'];
        $synced = $stats['wms_products'];
        
        return $total > 0 ? round(($synced / $total) * 100, 1) : 0;
    }
    
    /**
     * Get failed sync attempts
     */
    public function getFailedSyncs(): array {
        global $wpdb;
        
        $failedProducts = wc_get_products([
            'meta_query' => [
                [
                    'key' => '_wms_sync_attempts',
                    'value' => 1,
                    'compare' => '>='
                ],
                [
                    'key' => '_wms_article_id',
                    'compare' => 'NOT EXISTS'
                ]
            ],
            'limit' => 20,
            'orderby' => 'date',
            'order' => 'DESC'
        ]);
        
        $failedSyncs = [];
        foreach ($failedProducts as $product) {
            $failedSyncs[] = [
                'product_id' => $product->get_id(),
                'product_name' => $product->get_name(),
                'sku' => $product->get_sku(),
                'price' => $product->get_price(),
                'status' => $product->get_status(),
                'created_date' => $product->get_date_created() ? $product->get_date_created()->format('Y-m-d H:i:s') : '',
                'attempts' => intval($product->get_meta('_wms_sync_attempts')),
                'last_error' => $product->get_meta('_wms_last_sync_error'),
                'last_attempt' => $product->get_meta('_wms_last_sync_attempt')
            ];
        }
        
        return $failedSyncs;
    }
    
    /**
     * Retry failed syncs
     */
    public function retryFailedSyncs(int $limit = 10): array {
        $failedSyncs = $this->getFailedSyncs();
        $results = [
            'attempted' => 0,
            'successful' => 0,
            'failed' => 0,
            'details' => []
        ];
        
        $productsToRetry = array_slice($failedSyncs, 0, $limit);
        
        foreach ($productsToRetry as $failedProduct) {
            $results['attempted']++;
            
            try {
                $product = wc_get_product($failedProduct['product_id']);
                if (!$product) {
                    continue;
                }
                
                // Update attempt count
                $attempts = intval($product->get_meta('_wms_sync_attempts')) + 1;
                $product->update_meta_data('_wms_sync_attempts', $attempts);
                $product->update_meta_data('_wms_last_sync_attempt', current_time('mysql'));
                
                // Try to sync
                $result = $this->syncProductToWMS($product);
                
                if (isset($result['action']) && in_array($result['action'], ['created', 'updated'])) {
                    $results['successful']++;
                    // Clear error flags
                    $product->delete_meta_data('_wms_sync_attempts');
                    $product->delete_meta_data('_wms_last_sync_error');
                    $product->save();
                    
                    $results['details'][] = [
                        'product_id' => $product->get_id(),
                        'status' => 'success',
                        'action' => $result['action']
                    ];
                } else {
                    throw new Exception('Sync did not complete successfully');
                }
                
            } catch (Exception $e) {
                $results['failed']++;
                
                if (isset($product)) {
                    $product->update_meta_data('_wms_last_sync_error', $e->getMessage());
                    $product->save();
                }
                
                $results['details'][] = [
                    'product_id' => $failedProduct['product_id'],
                    'status' => 'failed',
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $results;
    }
    
    /**
     * Get product sync insights
     */
    public function getProductSyncInsights(): array {
        $stats = $this->getProductSyncStatistics();
        $queueStatus = $this->getSyncQueueStatus();
        $performance = $this->getSyncPerformanceMetrics();
        
        $insights = [
            'period' => '30 days',
            'sync_health' => $this->calculateSyncHealth($stats, $queueStatus),
            'recommendations' => $this->generateSyncRecommendations($stats, $queueStatus, $performance)
        ];
        
        return array_merge($insights, [
            'statistics' => $stats,
            'queue_status' => $queueStatus,
            'performance' => $performance
        ]);
    }
    
    /**
     * Calculate sync health score
     */
    private function calculateSyncHealth(array $stats, array $queueStatus): array {
        $healthScore = 100;
        $issues = [];
        
        // Check sync percentage
        if ($stats['sync_percentage'] < 50) {
            $healthScore -= 30;
            $issues[] = 'Low sync percentage (' . $stats['sync_percentage'] . '%)';
        } elseif ($stats['sync_percentage'] < 80) {
            $healthScore -= 15;
            $issues[] = 'Moderate sync percentage (' . $stats['sync_percentage'] . '%)';
        }
        
        // Check pending products
        if ($queueStatus['pending'] > 50) {
            $healthScore -= 20;
            $issues[] = 'High number of pending products (' . $queueStatus['pending'] . ')';
        } elseif ($queueStatus['pending'] > 20) {
            $healthScore -= 10;
            $issues[] = 'Moderate number of pending products (' . $queueStatus['pending'] . ')';
        }
        
        // Check last sync time
        if (!$stats['last_sync'] || strtotime($stats['last_sync']) < strtotime('-24 hours')) {
            $healthScore -= 20;
            $issues[] = 'No recent sync activity';
        }
        
        return [
            'score' => max(0, $healthScore),
            'status' => $healthScore >= 80 ? 'good' : ($healthScore >= 60 ? 'fair' : 'poor'),
            'issues' => $issues
        ];
    }
    
    /**
     * Generate sync recommendations
     */
    private function generateSyncRecommendations(array $stats, array $queueStatus, array $performance): array {
        $recommendations = [];
        
        if ($stats['sync_percentage'] < 70) {
            $recommendations[] = [
                'type' => 'sync_percentage',
                'priority' => 'high',
                'message' => 'Consider running a bulk sync to improve sync coverage',
                'action' => 'Sync all products'
            ];
        }
        
        if ($queueStatus['pending'] > 20) {
            $recommendations[] = [
                'type' => 'pending_products',
                'priority' => 'medium',
                'message' => 'Multiple products are waiting to be synced',
                'action' => 'Process pending syncs'
            ];
        }
        
        if ($performance['synced_products'] === 0) {
            $recommendations[] = [
                'type' => 'no_activity',
                'priority' => 'high',
                'message' => 'No sync activity in the last 24 hours',
                'action' => 'Check system health'
            ];
        }
        
        if (empty($recommendations)) {
            $recommendations[] = [
                'type' => 'good_health',
                'priority' => 'info',
                'message' => 'Product sync is operating normally',
                'action' => 'Continue monitoring'
            ];
        }
        
        return $recommendations;
    }
    
    /**
     * Create products from WMS stock data
     */
    public function createProductsFromStock(int $limit = 50): array {
        $this->client->logger()->info('Creating products from WMS stock data', ['limit' => $limit]);
        
        try {
            $results = [
                'created' => 0,
                'skipped' => 0,
                'errors' => []
            ];
            
            // Get stock items from WMS
            $stockItems = $this->client->stock()->getStockLevels(['limit' => $limit]);
            
            foreach ($stockItems as $stockItem) {
                $sku = $stockItem['sku'] ?? '';
                $articleCode = $stockItem['article_code'] ?? '';
                
                if (empty($sku)) {
                    continue;
                }
                
                // Check if product already exists
                $existingProduct = wc_get_product_id_by_sku($sku);
                if ($existingProduct) {
                    $results['skipped']++;
                    continue;
                }
                
                try {
                    // Create new WooCommerce product
                    $product = new WC_Product_Simple();
                    
                    // Set basic product data
                    $product->set_name($articleCode ?: "Product {$sku}");
                    $product->set_sku($sku);
                    $product->set_status('publish');
                    $product->set_catalog_visibility('visible');
                    $product->set_manage_stock(true);
                    
                    // Set stock data
                    $quantity = $stockItem['stock_available'] ?? $stockItem['stock_physical'] ?? 0;
                    $product->set_stock_quantity(intval($quantity));
                    $product->set_stock_status($quantity > 0 ? 'instock' : 'outofstock');
                    
                    // Add WMS metadata
                    $product->update_meta_data('_wms_article_code', $articleCode);
                    $product->update_meta_data('_wms_created_from_stock', 'yes');
                    $product->update_meta_data('_wms_created_at', current_time('mysql'));
                    
                    // Save product
                    $product->save();
                    
                    $results['created']++;
                    
                    $this->client->logger()->info('Product created from stock data', [
                        'product_id' => $product->get_id(),
                        'sku' => $sku,
                        'article_code' => $articleCode
                    ]);
                    
                } catch (Exception $e) {
                    $results['errors'][] = [
                        'sku' => $sku,
                        'error' => $e->getMessage()
                    ];
                    
                    $this->client->logger()->error('Failed to create product from stock data', [
                        'sku' => $sku,
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            $this->client->logger()->info('Products created from stock data', $results);
            
            return $results;
            
        } catch (Exception $e) {
            $this->client->logger()->error('Failed to create products from stock data', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'created' => 0,
                'skipped' => 0,
                'errors' => [['error' => $e->getMessage()]]
            ];
        }
    }

}