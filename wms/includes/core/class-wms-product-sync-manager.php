<?php
/**
 * WMS Product Sync Manager - CENTRALIZED PRODUCT SYNCHRONIZATION
 * 
 * Single source of truth for ALL product sync operations:
 * - Product finding
 * - Product creation
 * - Variant synchronization
 * - SKU management
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Product_Sync_Manager {
    
    /**
     * WMS client instance
     */
    private $client;
    
    /**
     * Product finder instance for efficient lookups
     */
    private $productFinder;
    
    /**
     * Static flag to track if WMS sync is in progress
     */
    private static $syncInProgress = false;
    
    /**
     * Constructor
     */
    public function __construct(WC_WMS_Client $client) {
        $this->client = $client;
    }
    
    /**
     * Check if WMS sync is currently in progress
     */
    public static function isSyncInProgress(): bool {
        return self::$syncInProgress;
    }
    
    /**
     * Mark WMS sync as in progress
     */
    private function markSyncInProgress(): void {
        self::$syncInProgress = true;
    }
    
    /**
     * Mark WMS sync as completed
     */
    private function markSyncCompleted(): void {
        self::$syncInProgress = false;
    }
    
    // ===== VALIDATION HELPERS =====
    
    /**
     * Validate required string parameter
     * 
     * @param string $value Value to validate
     * @param string $paramName Parameter name for error messages
     * @throws Exception When value is empty or invalid
     */
    private function validateRequiredString(string $value, string $paramName): void {
        if (empty($value)) {
            throw new Exception("{$paramName} cannot be empty");
        }
    }
    
    /**
     * Validate required array parameter
     * 
     * @param array $value Value to validate
     * @param string $paramName Parameter name for error messages
     * @throws Exception When value is empty or invalid
     */
    private function validateRequiredArray(array $value, string $paramName): void {
        if (empty($value)) {
            throw new Exception("{$paramName} cannot be empty");
        }
    }
    
    // ===== LOGGING HELPERS =====
    
    /**
     * Log function entry with parameters
     * 
     * @param string $functionName Name of the function
     * @param array $parameters Parameters passed to function
     */
    private function logFunctionEntry(string $functionName, array $parameters = []): void {
        $this->client->logger()->debug("Starting {$functionName}", $parameters);
    }
    
    /**
     * Log function success with results
     * 
     * @param string $functionName Name of the function
     * @param array $results Results from function
     */
    private function logFunctionSuccess(string $functionName, array $results = []): void {
        $this->client->logger()->info("Successfully completed {$functionName}", $results);
    }
    
    /**
     * Log function error with details
     * 
     * @param string $functionName Name of the function
     * @param Exception $exception Exception that occurred
     * @param array $context Additional context
     */
    private function logFunctionError(string $functionName, Exception $exception, array $context = []): void {
        $this->client->logger()->error("Error in {$functionName}", array_merge([
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ], $context));
    }
    
    // ===== CORE METHODS =====
    
    /**
     * Ensure product exists - SINGLE SOURCE OF TRUTH
     */
    public function ensureProductExists(array $variant, string $fallbackSku = ''): ?WC_Product {
        $this->validateRequiredArray($variant, 'Variant data');
        
        $articleCode = $variant['article_code'] ?? $variant['sku'] ?? $fallbackSku;
        $variantId = $variant['id'] ?? '';
        
        $this->client->logger()->debug('Ensuring product exists', [
            'article_code' => $articleCode,
            'variant_id' => $variantId,
            'fallback_sku' => $fallbackSku
        ]);
        
        // First try: Find existing product by SKU
        if (!empty($articleCode)) {
            $product = $this->findProductBySku($articleCode);
            if ($product) {
                $this->client->logger()->debug('Found existing product by SKU', [
                    'sku' => $articleCode,
                    'product_id' => $product->get_id()
                ]);
                return $product;
            }
        }
        
        // Also try the fallback SKU if different
        if (!empty($fallbackSku) && $fallbackSku !== $articleCode) {
            $product = $this->findProductBySku($fallbackSku);
            if ($product) {
                $this->client->logger()->debug('Found existing product by fallback SKU', [
                    'sku' => $fallbackSku,
                    'product_id' => $product->get_id()
                ]);
                return $product;
            }
        }
        
        // Second try: Sync from WMS if variant ID exists
        if (!empty($variantId)) {
            $product = $this->syncProductFromWMS($variantId);
            if ($product) {
                return $product;
            }
        }
        
        // Third try: Create simple product from variant data
        if (!empty($articleCode) || !empty($variant['name'])) {
            return $this->createSimpleProductFromVariant($variant, $articleCode);
        }
        
        $this->client->logger()->warning('Could not ensure product exists', [
            'variant' => $variant,
            'fallback_sku' => $fallbackSku
        ]);
        
        return null;
    }
    
    /**
     * Find product by SKU using multiple search methods
     * 
     * This method implements a comprehensive product lookup strategy:
     * 1. Direct SKU lookup using WooCommerce native function
     * 2. WMS article code metadata lookup
     * 3. Parent product lookup for variations
     * 4. Post title fallback search
     * 
     * @param string $sku Product SKU to search for
     * @return WC_Product|null Found product or null if not found
     * @throws Exception When SKU is invalid
     * 
     * @since 1.0.0
     */
    /**
     * Find product by SKU using efficient lookup strategies
     * 
     * @param string $sku Product SKU to search for
     * @return WC_Product|null Found product or null if not found
     * @throws Exception When SKU is invalid
     * 
     * @since 1.0.0
     */
    public function findProductBySku(string $sku): ?WC_Product {
        $this->validateRequiredString($sku, 'SKU');
        
        // Use the dedicated Product Finder class for clean, efficient lookups
        if (!isset($this->productFinder)) {
            $this->productFinder = new WC_WMS_Product_Finder($this->client);
        }
        
        return $this->productFinder->findProductBySku($sku);
    }
    
    /**
     * Sync product from WMS - SINGLE METHOD
     */
    public function syncProductFromWMS(string $variantId): ?WC_Product {
        try {
            $this->client->logger()->info('Syncing product from WMS', [
                'variant_id' => $variantId
            ]);
            
            // Get full variant details from WMS
            $wmsVariant = $this->client->products()->getVariant($variantId);
            
            if (empty($wmsVariant)) {
                $this->client->logger()->warning('Variant not found in WMS', [
                    'variant_id' => $variantId
                ]);
                return null;
            }
            
            // If variant has article data, use existing product integrator
            if (isset($wmsVariant['article'])) {
                $productIntegrator = new WC_WMS_Product_Integrator($this->client);
                $syncResult = $productIntegrator->syncProductFromWMS($wmsVariant['article']);
                
                // Check if sync was successful and has product_id
                if (!empty($syncResult['product_id'])) {
                    // Find the specific variant product
                    $articleCode = $wmsVariant['article_code'] ?? $wmsVariant['sku'] ?? '';
                    
                    $product = wc_get_product($syncResult['product_id']);
                    if ($product) {
                        // For variable products, try to find the specific variation
                        if ($product->is_type('variable')) {
                            $variations = $product->get_available_variations();
                            foreach ($variations as $variation) {
                                $variationProduct = wc_get_product($variation['variation_id']);
                                if ($variationProduct && $variationProduct->get_sku() === $articleCode) {
                                    $this->client->logger()->info('Product variation synced via article integrator', [
                                        'variant_id' => $variantId,
                                        'product_id' => $variationProduct->get_id(),
                                        'sku' => $articleCode
                                    ]);
                                    return $variationProduct;
                                }
                            }
                        } else {
                            // For simple products, check if SKU matches
                            if ($product->get_sku() === $articleCode) {
                                $this->client->logger()->info('Product synced via article integrator', [
                                    'variant_id' => $variantId,
                                    'product_id' => $product->get_id(),
                                    'sku' => $articleCode
                                ]);
                                return $product;
                            }
                        }
                    }
                }
            }
            
            // Fallback: Create simple product from variant data
            return $this->createSimpleProductFromVariant($wmsVariant, $wmsVariant['article_code'] ?? '');
            
        } catch (Exception $e) {
            $this->client->logger()->error('Failed to sync product from WMS', [
                'variant_id' => $variantId,
                'error' => $e->getMessage()
            ]);
            
            return null;
        }
    }
    
    /**
     * Create simple product from WMS variant data
     * 
     * Creates a new WooCommerce simple product from WMS variant data with:
     * - Basic product information (name, description, SKU)
     * - Pricing and stock management
     * - Physical dimensions and weight
     * - WMS-specific metadata for synchronization
     * - Product attributes and custom fields
     * 
     * @param array $variant WMS variant data containing product information
     * @param string $sku Optional SKU override, falls back to variant data
     * @return WC_Product|null Created product or null on failure
     * @throws Exception When variant data is invalid or product creation fails
     * 
     * @since 1.0.0
     */
    public function createSimpleProductFromVariant(array $variant, string $sku = ''): ?WC_Product {
        $this->validateRequiredArray($variant, 'Variant data');
        
        try {
            $name = $variant['name'] ?? $variant['description'] ?? $sku ?? 'Unknown Product';
            $finalSku = $sku ?: ($variant['article_code'] ?? $variant['sku'] ?? '');
            
            if (empty($finalSku)) {
                $finalSku = 'WMS_' . ($variant['id'] ?? uniqid());
            }
            
            $this->logFunctionEntry('createSimpleProductFromVariant', [
                'name' => $name,
                'sku' => $finalSku,
                'variant_id' => $variant['id'] ?? ''
            ]);
            
            $product = new WC_Product_Simple();
            
            // Basic product data
            $product->set_name($name);
            $product->set_sku($finalSku);
            $product->set_description($variant['description'] ?? $name);
            $product->set_short_description($variant['description'] ?? $name);
            $product->set_status('publish');
            $product->set_catalog_visibility('visible');
            
            // Pricing
            $value = $variant['value'] ?? 0;
            $product->set_price($value);
            $product->set_regular_price($value);
            
            // Stock management
            $product->set_manage_stock(true);
            $product->set_stock_status('instock');
            
            // Dimensions
            if (isset($variant['weight'])) {
                $product->set_weight($variant['weight']);
            }
            if (isset($variant['length'])) {
                $product->set_length($variant['length']);
            }
            if (isset($variant['width'])) {
                $product->set_width($variant['width']);
            }
            if (isset($variant['height'])) {
                $product->set_height($variant['height']);
            }
            
            // WMS metadata
            $product->update_meta_data('_wms_variant_id', $variant['id'] ?? '');
            $product->update_meta_data('_wms_synced', 'yes');
            $product->update_meta_data('_wms_sync_date', current_time('mysql'));
            $product->update_meta_data('_wms_sync_method', 'simple_creation');
            
            // Additional WMS fields
            if (isset($variant['ean'])) {
                $product->update_meta_data('_wms_ean', $variant['ean']);
            }
            if (isset($variant['hs_tariff_code'])) {
                $product->update_meta_data('_wms_hs_tariff_code', $variant['hs_tariff_code']);
            }
            if (isset($variant['country_of_origin'])) {
                $product->update_meta_data('_wms_country_of_origin', $variant['country_of_origin']);
            }
            
            $product->save();
            
            $this->logFunctionSuccess('createSimpleProductFromVariant', [
                'product_id' => $product->get_id(),
                'sku' => $finalSku,
                'name' => $name
            ]);
            
            return $product;
            
        } catch (Exception $e) {
            $this->logFunctionError('createSimpleProductFromVariant', $e, [
                'variant' => $variant,
                'sku' => $sku
            ]);
            
            return null;
        }
    }
    
    /**
     * Transform WooCommerce product to WMS article format
     */
    public function transformWooCommerceProduct(WC_Product $product): array {
        $this->client->logger()->debug('Transforming WooCommerce product to WMS format', [
            'product_id' => $product->get_id(),
            'product_type' => $product->get_type()
        ]);
        
        $articleData = [
            'name' => $product->get_name(),
            'variants' => []
        ];
        
        if ($product->is_type('simple')) {
            $articleData['variants'][] = $this->transformSimpleProduct($product);
        } elseif ($product->is_type('variable')) {
            $variation_ids = $product->get_children();
            foreach ($variation_ids as $variation_id) {
                $variationProduct = wc_get_product($variation_id);
                if ($variationProduct) {
                    $articleData['variants'][] = $this->transformVariationProduct($variationProduct);
                }
            }
        }
        
        $this->client->logger()->debug('WooCommerce product transformed to WMS format', [
            'product_id' => $product->get_id(),
            'variant_count' => count($articleData['variants'])
        ]);
        
        return $articleData;
    }
    
    /**
     * Transform simple product to variant data
     */
    private function transformSimpleProduct(WC_Product $product): array {
        $sku = $product->get_sku();
        if (empty($sku)) {
            $sku = 'WC_' . $product->get_id();
            $product->set_sku($sku);
            $product->save();
        }
        
        $variantData = [
            'name' => $product->get_name(),
            'article_code' => $sku,
            'description' => $product->get_description(),
            'ean' => $product->get_meta('_ean') ?: null,
            'sku' => $sku,
            'hs_tariff_code' => $product->get_meta('_hs_tariff_code') ?: null,
            'height' => $product->get_height() ? floatval($product->get_height()) : null,
            'depth' => $product->get_length() ? floatval($product->get_length()) : null,
            'width' => $product->get_width() ? floatval($product->get_width()) : null,
            'weight' => $product->get_weight() ? floatval($product->get_weight()) : null,
            'expirable' => $product->get_meta('_expirable') === 'yes',
            'country_of_origin' => $product->get_meta('_country_of_origin') ?: null,
            'using_serial_numbers' => $product->get_meta('_using_serial_numbers') === 'yes',
            'value' => floatval($product->get_price())
        ];
        
        return array_filter($variantData, function($value) {
            return $value !== null;
        });
    }
    
    /**
     * Transform variation product to variant data
     */
    private function transformVariationProduct(WC_Product_Variation $variation): array {
        $sku = $variation->get_sku();
        if (empty($sku)) {
            $sku = 'WC_' . $variation->get_id();
            $variation->set_sku($sku);
            $variation->save();
        }
        
        $variantData = [
            'name' => $variation->get_name(),
            'article_code' => $sku,
            'description' => $variation->get_description(),
            'ean' => $variation->get_meta('_ean') ?: null,
            'sku' => $sku,
            'hs_tariff_code' => $variation->get_meta('_hs_tariff_code') ?: null,
            'height' => $variation->get_height() ? floatval($variation->get_height()) : null,
            'depth' => $variation->get_length() ? floatval($variation->get_length()) : null,
            'width' => $variation->get_width() ? floatval($variation->get_width()) : null,
            'weight' => $variation->get_weight() ? floatval($variation->get_weight()) : null,
            'expirable' => $variation->get_meta('_expirable') === 'yes',
            'country_of_origin' => $variation->get_meta('_country_of_origin') ?: null,
            'using_serial_numbers' => $variation->get_meta('_using_serial_numbers') === 'yes',
            'value' => floatval($variation->get_price())
        ];
        
        return array_filter($variantData, function($value) {
            return $value !== null;
        });
    }
    
    /**
     * Import articles from WMS and create/update WooCommerce products
     */
    public function importArticlesFromWms(array $params = []): mixed {
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
            
            // PREVENT CIRCULAR SYNC WITH SIMPLE FLAG
            $this->markSyncInProgress();
            
            $imported = 0;
            $updated = 0;
            $skipped = 0;
            $errors = 0;
            $error_details = [];
            
            try {
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
            } finally {
                // ALWAYS CLEAR FLAG EVEN IF ERRORS OCCUR
                $this->markSyncCompleted();
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
     * Import a single article from WMS
     */
    private function importSingleArticle(array $article): array {
        // Extract article data
        $articleName = $article['name'] ?? 'Unnamed Article';
        $articleId = $article['id'] ?? null;
        $variants = $article['variants'] ?? [];
        
        if (empty($variants)) {
            throw new Exception('No variants found for article');
        }
        
        $this->client->logger()->debug('Processing article', [
            'article_id' => $articleId,
            'article_name' => $articleName,
            'variants_count' => count($variants)
        ]);
        
        // Create simple products for each variant
        // Variable product support can be added in future versions
        $primaryVariant = reset($variants);
        
        $sku = $primaryVariant['article_code'] ?? $primaryVariant['sku'] ?? null;
        if (empty($sku)) {
            throw new Exception('SKU not found in variant data');
        }
        
        // Check if product already exists
        $existingProduct = $this->findProductBySku($sku);
        
        if ($existingProduct) {
            // Check if product actually needs updating using our own centralized method
            if ($this->productNeedsUpdate($existingProduct, $article, $primaryVariant)) {
                $this->updateWooCommerceProduct($existingProduct, $article, $primaryVariant);
                return [
                    'action' => 'updated',
                    'product_id' => $existingProduct->get_id(),
                    'sku' => $sku
                ];
            } else {
                // Product is already up-to-date
                return [
                    'action' => 'skipped',
                    'product_id' => $existingProduct->get_id(),
                    'sku' => $sku
                ];
            }
        } else {
            // Create new product
            $product = $this->createSimpleProductFromVariant($primaryVariant, $sku);
            
            if ($product) {
                return [
                    'action' => 'created',
                    'product_id' => $product->get_id(),
                    'sku' => $sku
                ];
            } else {
                throw new Exception('Failed to create product from variant');
            }
        }
    }
    
    /**
     * Get articles with variants from WMS
     */
    private function getArticlesWithVariants(array $params = []): mixed {
        return $this->client->products()->getArticlesWithVariants($params);
    }
    
    /**
     * Check if product needs updating from WMS data
     * 
     * Compares current WooCommerce product data with WMS data to determine
     * if an update is needed based on multiple criteria:
     * - Basic product information (name, description)
     * - Pricing changes
     * - Stock quantity changes
     * - Physical dimensions and weight
     * - WMS-specific metadata
     * - Product attributes and custom fields
     * 
     * @param WC_Product $product WooCommerce product to check
     * @param array $article WMS article data
     * @param array $variant WMS variant data
     * @return bool True if product needs update, false otherwise
     * @throws Exception When product or data is invalid
     * 
     * @since 1.0.0
     */
    public function productNeedsUpdate(WC_Product $product, array $article, array $variant): bool {
        if (!$product || !$product->get_id()) {
            throw new Exception('Invalid product provided for update check');
        }
        
        $this->validateRequiredArray($article, 'Article data');
        $this->validateRequiredArray($variant, 'Variant data');
        
        $this->logFunctionEntry('productNeedsUpdate', [
            'product_id' => $product->get_id(),
            'article_id' => $article['id'] ?? 'unknown',
            'variant_id' => $variant['id'] ?? 'unknown'
        ]);
        
        // Check if basic product information has changed
        if ($product->get_name() !== ($article['name'] ?? '')) {
            $this->client->logger()->debug('Product name changed', [
                'current' => $product->get_name(),
                'new' => $article['name'] ?? ''
            ]);
            return true;
        }
        
        if ($product->get_description() !== ($variant['description'] ?? '')) {
            $this->client->logger()->debug('Product description changed');
            return true;
        }
        
        // Check if price has changed
        if (!empty($variant['value']) && floatval($product->get_price()) !== floatval($variant['value'])) {
            $this->client->logger()->debug('Product price changed', [
                'current' => $product->get_price(),
                'new' => $variant['value']
            ]);
            return true;
        }
        
        // Check if stock has changed
        if (isset($variant['stock_physical']) && intval($product->get_stock_quantity()) !== intval($variant['stock_physical'])) {
            $this->client->logger()->debug('Product stock changed', [
                'current' => $product->get_stock_quantity(),
                'new' => $variant['stock_physical']
            ]);
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
        $this->client->logger()->debug('No product changes detected');
        return false;
    }
    
    /**
     * Update WooCommerce product with WMS data
     * 
     * Comprehensive product update that handles:
     * - Basic product information (name, description, price)
     * - Stock management and inventory
     * - Physical dimensions and weight
     * - WMS-specific metadata synchronization
     * - Product attributes and custom fields
     * 
     * @param WC_Product $product WooCommerce product to update
     * @param array $article WMS article data
     * @param array $variant WMS variant data
     * @return void
     * @throws Exception When product or data is invalid
     * 
     * @since 1.0.0
     */
    public function updateWooCommerceProduct(WC_Product $product, array $article, array $variant): void {
        if (!$product || !$product->get_id()) {
            throw new Exception('Invalid product provided for update');
        }
        
        $this->validateRequiredArray($article, 'Article data');
        $this->validateRequiredArray($variant, 'Variant data');
        
        $this->logFunctionEntry('updateWooCommerceProduct', [
            'product_id' => $product->get_id(),
            'article_id' => $article['id'] ?? 'unknown',
            'variant_id' => $variant['id'] ?? 'unknown'
        ]);
        
        try {
            // Update basic product information
            if (!empty($article['name'])) {
                $product->set_name($article['name']);
            }
            
            if (!empty($variant['description'])) {
                $product->set_description($variant['description']);
                $product->set_short_description($variant['description']);
            }
            
            // Update pricing
            if (!empty($variant['value'])) {
                $product->set_price($variant['value']);
                $product->set_regular_price($variant['value']);
            }
            
            // Update stock
            if (isset($variant['stock_physical'])) {
                $product->set_manage_stock(true);
                $product->set_stock_quantity($variant['stock_physical']);
                $product->set_stock_status($variant['stock_physical'] > 0 ? 'instock' : 'outofstock');
            }
            
            // Update dimensions
            if (!empty($variant['weight'])) {
                $product->set_weight($variant['weight']);
            }
            if (!empty($variant['height'])) {
                $product->set_height($variant['height']);
            }
            if (!empty($variant['width'])) {
                $product->set_width($variant['width']);
            }
            if (!empty($variant['depth'])) {
                $product->set_length($variant['depth']);
            }
            
            // Update WMS metadata
            if (!empty($article['id'])) {
                $product->update_meta_data('_wms_article_id', $article['id']);
            }
            if (!empty($variant['id'])) {
                $product->update_meta_data('_wms_variant_id', $variant['id']);
            }
            
            // Update WMS-specific fields
            if (!empty($variant['ean'])) {
                $product->update_meta_data('_ean', $variant['ean']);
            }
            if (!empty($variant['hs_tariff_code'])) {
                $product->update_meta_data('_hs_tariff_code', $variant['hs_tariff_code']);
            }
            if (!empty($variant['country_of_origin'])) {
                $product->update_meta_data('_country_of_origin', $variant['country_of_origin']);
            }
            if (isset($variant['expirable'])) {
                $product->update_meta_data('_expirable', $variant['expirable'] ? 'yes' : 'no');
            }
            if (isset($variant['using_serial_numbers'])) {
                $product->update_meta_data('_using_serial_numbers', $variant['using_serial_numbers'] ? 'yes' : 'no');
            }
            
            // Update sync metadata
            $product->update_meta_data('_wms_synced', 'yes');
            $product->update_meta_data('_wms_sync_date', current_time('mysql'));
            $product->update_meta_data('_wms_sync_method', 'update');
            
            // Save product
            $product->save();
            
            $this->logFunctionSuccess('updateWooCommerceProduct', [
                'product_id' => $product->get_id(),
                'article_id' => $article['id'] ?? 'unknown',
                'variant_id' => $variant['id'] ?? 'unknown'
            ]);
            
        } catch (Exception $e) {
            $this->logFunctionError('updateWooCommerceProduct', $e, [
                'product_id' => $product->get_id(),
                'article' => $article,
                'variant' => $variant
            ]);
            throw $e;
        }
    }
    
    /**
     * Mark product as synced with WMS
     * 
     * @param WC_Product $product Product to mark as synced
     * @param string $wmsArticleId WMS article ID to associate
     * @throws Exception When product is invalid
     */
    public function markProductAsSynced(WC_Product $product, string $wmsArticleId): void {
        if (!$product || !$product->get_id()) {
            throw new Exception('Invalid product provided for sync marking');
        }
        
        $this->validateRequiredString($wmsArticleId, 'WMS article ID');
        
        $product->update_meta_data('_wms_article_id', $wmsArticleId);
        $product->update_meta_data('_wms_synced_at', current_time('mysql'));
        $product->save();
        
        $this->logFunctionSuccess('markProductAsSynced', [
            'product_id' => $product->get_id(),
            'wms_article_id' => $wmsArticleId
        ]);
    }
}
