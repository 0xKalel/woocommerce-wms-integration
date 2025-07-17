<?php
/**
 * WMS Product Finder - Simplified and Efficient Product Lookup
 * 
 * Clean implementation following PSR standards and single responsibility principle
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Product_Finder {
    
    private WC_WMS_Client $client;
    private array $cache = [];
    
    public function __construct(WC_WMS_Client $client) {
        $this->client = $client;
    }
    
    /**
     * Find product by SKU using efficient lookup strategies
     * 
     * @param string $sku The SKU to search for
     * @return WC_Product|null Product if found, null otherwise
     */
    public function findProductBySku(string $sku): ?WC_Product {
        if (empty($sku)) {
            return null;
        }
        
        // Check cache first
        if (isset($this->cache[$sku])) {
            return $this->cache[$sku];
        }
        
        $this->client->logger()->debug('Starting product lookup', ['sku' => $sku]);
        
        // Try lookup strategies in order of efficiency
        $strategies = [
            'directSku' => [$this, 'findByDirectSku'],
            'wmsMetadata' => [$this, 'findByWmsMetadata'],
            'normalizedSku' => [$this, 'findByNormalizedSku'],
        ];
        
        foreach ($strategies as $strategy => $method) {
            $product = call_user_func($method, $sku);
            
            if ($product) {
                $this->client->logger()->info('Product found', [
                    'strategy' => $strategy,
                    'sku' => $sku,
                    'product_id' => $product->get_id(),
                    'product_name' => $product->get_name()
                ]);
                
                // Cache the result
                $this->cache[$sku] = $product;
                return $product;
            }
        }
        
        $this->client->logger()->warning('Product not found', [
            'sku' => $sku,
            'strategies_tried' => array_keys($strategies)
        ]);
        
        return null;
    }
    
    /**
     * Find product by direct SKU match
     */
    private function findByDirectSku(string $sku): ?WC_Product {
        $productId = wc_get_product_id_by_sku($sku);
        return $productId ? wc_get_product($productId) : null;
    }
    
    /**
     * Find product by WMS metadata
     */
    private function findByWmsMetadata(string $sku): ?WC_Product {
        $products = wc_get_products([
            'meta_key' => '_wms_article_code',
            'meta_value' => $sku,
            'limit' => 1,
            'status' => 'publish'
        ]);
        
        return !empty($products) ? $products[0] : null;
    }
    
    /**
     * Find product by normalized SKU patterns
     */
    private function findByNormalizedSku(string $sku): ?WC_Product {
        $patterns = $this->generateSkuPatterns($sku);
        
        foreach ($patterns as $pattern) {
            $productId = wc_get_product_id_by_sku($pattern);
            if ($productId) {
                return wc_get_product($productId);
            }
        }
        
        return null;
    }
    
    /**
     * Generate common SKU patterns for normalization
     */
    private function generateSkuPatterns(string $sku): array {
        return [
            str_replace('-', '_', $sku),     // Replace dashes with underscores
            str_replace('_', '-', $sku),     // Replace underscores with dashes
            'WMS_' . $sku,                   // Add WMS prefix
            preg_replace('/^WMS[-_]/', '', $sku) // Remove WMS prefix
        ];
    }
    
    /**
     * Clear cache for testing or memory management
     */
    public function clearCache(): void {
        $this->cache = [];
    }
    
    /**
     * Get cache statistics
     */
    public function getCacheStats(): array {
        return [
            'cached_items' => count($this->cache),
            'memory_usage' => memory_get_usage(),
            'cache_keys' => array_keys($this->cache)
        ];
    }
}
