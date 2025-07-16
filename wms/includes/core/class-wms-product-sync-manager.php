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
     * Constructor
     */
    public function __construct(WC_WMS_Client $client) {
        $this->client = $client;
    }
    
    /**
     * Ensure product exists - SINGLE SOURCE OF TRUTH
     */
    public function ensureProductExists(array $variant, string $fallbackSku = ''): ?WC_Product {
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
     * Find product by SKU - ENHANCED IMPLEMENTATION
     */
    public function findProductBySku(string $sku): ?WC_Product {
        if (empty($sku)) {
            return null;
        }
        
        $this->client->logger()->debug('=== DIAGNOSTIC: Starting enhanced product lookup ===', [
            'target_sku' => $sku,
            'sku_length' => strlen($sku),
            'sku_pattern' => preg_replace('/[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}/', 'UUID', $sku)
        ]);
        
        // Method 1: Direct SKU lookup
        $this->client->logger()->debug('Method 1: Direct SKU lookup', ['sku' => $sku]);
        $productId = wc_get_product_id_by_sku($sku);
        if ($productId) {
            $product = wc_get_product($productId);
            $this->client->logger()->info('✅ SUCCESS: Product found by direct SKU lookup', [
                'method' => 'direct_sku',
                'product_id' => $productId, 
                'product_name' => $product->get_name(),
                'product_sku' => $product->get_sku(),
                'target_sku' => $sku
            ]);
            return $product;
        }
        $this->client->logger()->debug('❌ Method 1 failed: No direct SKU match');
        
        // Method 2: Try to find by WMS article code metadata
        $this->client->logger()->debug('Method 2: WMS article code metadata lookup', ['sku' => $sku]);
        $products = wc_get_products([
            'meta_key' => '_wms_article_code',
            'meta_value' => $sku,
            'limit' => 1,
            'status' => 'publish'
        ]);
        
        if (!empty($products)) {
            $product = $products[0];
            $this->client->logger()->info('✅ SUCCESS: Product found by WMS article code metadata', [
                'method' => 'wms_article_code',
                'product_id' => $product->get_id(),
                'product_name' => $product->get_name(),
                'product_sku' => $product->get_sku(),
                'target_sku' => $sku
            ]);
            return $product;
        }
        $this->client->logger()->debug('❌ Method 2 failed: No WMS article code match');
        
        // Method 3: Get some example WooCommerce SKUs for comparison
        global $wpdb;
        $example_skus = $wpdb->get_results(
            "SELECT pm.meta_value as sku, p.ID, p.post_title 
             FROM {$wpdb->postmeta} pm 
             JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
             WHERE pm.meta_key = '_sku' 
             AND pm.meta_value != '' 
             AND p.post_type = 'product' 
             AND p.post_status = 'publish'
             LIMIT 5",
            ARRAY_A
        );
        
        $this->client->logger()->debug('Current WooCommerce SKU examples for comparison', [
            'target_sku' => $sku,
            'example_wc_skus' => array_column($example_skus, 'sku'),
            'example_product_names' => array_column($example_skus, 'post_title')
        ]);
        
        // Method 4: Try to find by various SKU patterns
        $this->client->logger()->debug('Method 4: Pattern matching attempts');
        $search_patterns = [
            $sku, // Exact match (already tried but documented)
            'WMS_' . $sku, // Add WMS_ prefix
            str_replace('WMS-', 'WMS_', $sku), // Replace dash with underscore
            str_replace('-', '_', $sku), // Replace all dashes with underscores
            str_replace('_', '-', $sku), // Replace all underscores with dashes
        ];
        
        $this->client->logger()->debug('Trying SKU patterns', [
            'original_sku' => $sku,
            'patterns_to_try' => $search_patterns
        ]);
        
        foreach ($search_patterns as $index => $pattern) {
            $this->client->logger()->debug("Pattern {$index}: {$pattern}");
            $productId = wc_get_product_id_by_sku($pattern);
            if ($productId) {
                $product = wc_get_product($productId);
                $this->client->logger()->info('✅ SUCCESS: Product found by pattern matching', [
                    'method' => 'pattern_matching',
                    'product_id' => $productId,
                    'product_name' => $product->get_name(),
                    'product_sku' => $product->get_sku(),
                    'original_sku' => $sku,
                    'matched_pattern' => $pattern,
                    'pattern_index' => $index
                ]);
                return $product;
            }
        }
        $this->client->logger()->debug('❌ Method 4 failed: No pattern matches');
        
        // Method 5: Try to find products by searching in SKU field with LIKE
        $this->client->logger()->debug('Method 5: Partial/LIKE SKU matching');
        $like_sku = '%' . $wpdb->esc_like($sku) . '%';
        $sql = $wpdb->prepare(
            "SELECT post_id, meta_value as found_sku FROM {$wpdb->postmeta} pm 
             JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
             WHERE pm.meta_key = '_sku' 
             AND pm.meta_value LIKE %s 
             AND p.post_type = 'product' 
             AND p.post_status = 'publish'
             LIMIT 3",
            $like_sku
        );
        
        $partial_matches = $wpdb->get_results($sql, ARRAY_A);
        $this->client->logger()->debug('Partial SKU matches found', [
            'target_sku' => $sku,
            'like_pattern' => $like_sku,
            'matches' => $partial_matches
        ]);
        
        if (!empty($partial_matches)) {
            $productId = $partial_matches[0]['post_id'];
            $product = wc_get_product($productId);
            $this->client->logger()->info('✅ SUCCESS: Product found by partial SKU match', [
                'method' => 'partial_match',
                'product_id' => $productId,
                'product_name' => $product->get_name(),
                'product_sku' => $product->get_sku(),
                'target_sku' => $sku,
                'found_sku' => $partial_matches[0]['found_sku']
            ]);
            return $product;
        }
        $this->client->logger()->debug('❌ Method 5 failed: No partial matches');
        
        // Method 6: Try to find by WMS article ID if the SKU looks like a UUID
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $sku)) {
            $this->client->logger()->debug('Method 6: Trying UUID-based article ID lookup', ['sku' => $sku]);
            
            // Try to find product with this WMS article ID
            $products = wc_get_products([
                'meta_key' => '_wms_article_id',
                'meta_value' => $sku,
                'limit' => 1,
                'status' => 'publish'
            ]);
            
            if (!empty($products)) {
                $product = $products[0];
                $this->client->logger()->info('✅ SUCCESS: Product found by WMS article ID', [
                    'method' => 'wms_article_id',
                    'product_id' => $product->get_id(),
                    'product_name' => $product->get_name(),
                    'product_sku' => $product->get_sku(),
                    'target_sku' => $sku,
                    'wms_article_id' => $product->get_meta('_wms_article_id')
                ]);
                return $product;
            }
            $this->client->logger()->debug('❌ Method 6 failed: No WMS article ID match');
        }
        
        $this->client->logger()->warning('❌ FAILED: Product not found by any method', [
            'target_sku' => $sku,
            'methods_tried' => ['direct_sku', 'wms_article_code', 'pattern_matching', 'partial_match', 'wms_article_id'],
            'suggestion' => 'Check if this SKU exists in a different format or if it needs to be imported'
        ]);
        
        return null;
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
     * Create simple product from variant data
     */
    public function createSimpleProductFromVariant(array $variant, string $sku = ''): ?WC_Product {
        try {
            $name = $variant['name'] ?? $variant['description'] ?? $sku ?? 'Unknown Product';
            $finalSku = $sku ?: ($variant['article_code'] ?? $variant['sku'] ?? '');
            
            if (empty($finalSku)) {
                $finalSku = 'WMS_' . ($variant['id'] ?? uniqid());
            }
            
            $this->client->logger()->info('Creating simple product from variant', [
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
            
            $this->client->logger()->info('Created simple product successfully', [
                'product_id' => $product->get_id(),
                'sku' => $finalSku,
                'name' => $name
            ]);
            
            return $product;
            
        } catch (Exception $e) {
            $this->client->logger()->error('Failed to create simple product', [
                'variant' => $variant,
                'sku' => $sku,
                'error' => $e->getMessage()
            ]);
            
            return null;
        }
    }
}
