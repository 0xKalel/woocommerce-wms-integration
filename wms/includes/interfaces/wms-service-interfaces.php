<?php
/**
 * WMS Service Interface Contracts
 * 
 * Defines contracts for all WMS services ensuring consistent implementation
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Base WMS Service Interface
 */
interface WC_WMS_Service_Interface {
    
    /**
     * Get service name
     */
    public function getServiceName(): string;
    
    /**
     * Check if service is available
     */
    public function isAvailable(): bool;
    
    /**
     * Get service configuration
     */
    public function getConfig(): array;
}

/**
 * Order Service Interface
 */
interface WC_WMS_Order_Service_Interface extends WC_WMS_Service_Interface {
    
    public function createOrder(array $orderData): mixed;
    public function getOrder(string $orderId, array $expand = []): mixed;
    public function updateOrder(string $orderId, array $orderData): mixed;
    public function cancelOrder(string $orderId): mixed;
    public function getOrders(array $params = []): mixed;
    public function getOrderStatus(string $orderId): mixed;
}

/**
 * Product Service Interface
 */
interface WC_WMS_Product_Service_Interface extends WC_WMS_Service_Interface {
    
    public function getArticles(array $params = []): mixed;
    public function getArticle(string $articleId): mixed;
    public function createArticle(array $articleData): mixed;
    public function updateArticle(string $articleId, array $articleData): mixed;
    public function getVariants(array $params = []): mixed;
    public function getVariant(string $variantId): mixed;
    public function createVariant(array $variantData): mixed;
    public function updateVariant(string $variantId, array $variantData): mixed;
}

/**
 * Stock Service Interface
 */
interface WC_WMS_Stock_Service_Interface extends WC_WMS_Service_Interface {
    
    public function getStockLevels(array $params = []): mixed;
    public function getProductStock(string $sku): mixed;
    public function updateStock(string $sku, int $quantity): mixed;
    public function getStockMovements(array $params = []): mixed;
    public function createStockAdjustment(array $adjustmentData): mixed;
}

/**
 * Customer Service Interface
 */
interface WC_WMS_Customer_Service_Interface extends WC_WMS_Service_Interface {
    
    public function getCustomers(array $params = []): mixed;
    public function getCustomer(string $customerId): mixed;
    public function createCustomer(array $customerData): mixed;
    public function updateCustomer(string $customerId, array $customerData): mixed;
}

/**
 * Shipment Service Interface
 */
interface WC_WMS_Shipment_Service_Interface extends WC_WMS_Service_Interface {
    
    public function getShipments(array $params = []): mixed;
    public function getShipment(string $shipmentId): mixed;
    public function createShipment(array $shipmentData): mixed;
    public function updateShipment(string $shipmentId, array $shipmentData): mixed;
    public function getShippingMethods(array $params = []): mixed;
    public function getTrackingInfo(string $trackingNumber): mixed;
}

/**
 * Inbound Service Interface
 */
interface WC_WMS_Inbound_Service_Interface extends WC_WMS_Service_Interface {
    
    public function getInbounds(array $params = []): mixed;
    public function getInbound(string $inboundId): mixed;
    public function createInbound(array $inboundData): mixed;
    public function updateInbound(string $inboundId, array $inboundData): mixed;
    public function cancelInbound(string $inboundId): mixed;
    public function getInboundStats(int $days = 30): array;
    public function getQualityControls(string $inboundId): mixed;
}

/**
 * Webhook Service Interface
 */
interface WC_WMS_Webhook_Service_Interface extends WC_WMS_Service_Interface {
    
    public function getWebhooks(array $params = []): mixed;
    public function createWebhook(array $webhookData): mixed;
    public function updateWebhook(string $webhookId, array $webhookData): mixed;
    public function deleteWebhook(string $webhookId): mixed;
    public function getWebhookConfig(): array;
    public function validateWebhookSignature(string $payload, string $signature): bool;
}

/**
 * GDPR Service Interface
 */
interface WC_WMS_GDPR_Service_Interface extends WC_WMS_Service_Interface {
    
    public function requestDataExport(string $emailAddress): mixed;
    public function requestDataErasure(string $emailAddress): mixed;
    public function getDataExportStatus(string $requestId): mixed;
    public function getDataErasureStatus(string $requestId): mixed;
    public function isGdprSupported(): bool;
}

/**
 * Queue Service Interface
 */
interface WC_WMS_Queue_Service_Interface extends WC_WMS_Service_Interface {
    
    public function processOrderQueue(int $batchSize = 10): array;
    public function processProductQueue(int $batchSize = 20): array;
    public function queueOrderExport(int $orderId, int $priority = 0): bool;
    public function queueOrderCancellation(int $orderId, int $priority = 5): bool;
    public function queueProductSync(int $productId, int $priority = 0): bool;
    public function getQueueStats(): array;
    public function retryFailedItems(string $type = 'both'): array;
    public function cleanup(): array;
}

/**
 * Logging Service Interface
 */
interface WC_WMS_Logging_Service_Interface extends WC_WMS_Service_Interface {
    
    public function debug(string $message, array $context = []): void;
    public function info(string $message, array $context = []): void;
    public function warning(string $message, array $context = []): void;
    public function error(string $message, array $context = []): void;
    public function critical(string $message, array $context = []): void;
    public function logApiCall(string $method, string $endpoint, array $requestData = null, $response = null, float $executionTime = null, string $error = null): void;
    public function logWebhook(string $webhookType, array $payload, string $webhookId = null, bool $processed = false, string $error = null): void;
    public function trackPerformance(string $operation, float $executionTime, string $context = ''): void;
    public function getPerformanceStats(): array;
    public function cleanup(): array;
}

/**
 * Integrator Interface
 */
interface WC_WMS_Integrator_Interface {
    
    /**
     * Get integrator name
     */
    public function getIntegratorName(): string;
    
    /**
     * Check if integrator is ready
     */
    public function isReady(): bool;
    
    /**
     * Get integrator status
     */
    public function getStatus(): array;
}

/**
 * Order Integrator Interface
 */
interface WC_WMS_Order_Integrator_Interface extends WC_WMS_Integrator_Interface {
    
    public function exportOrder(int $orderId): mixed;
    public function cancelOrder(int $orderId): mixed;
    public function transformOrderData(WC_Order $order): array;
    public function shouldExportOrder(WC_Order $order): bool;
}

/**
 * Product Integrator Interface
 */
interface WC_WMS_Product_Integrator_Interface extends WC_WMS_Integrator_Interface {
    
    public function syncProduct(int $productId): mixed;
    public function syncAllProducts(int $batchSize = 50): array;
    public function transformProductData(WC_Product $product): array;
    public function shouldSyncProduct(WC_Product $product): bool;
}

/**
 * Customer Integrator Interface
 */
interface WC_WMS_Customer_Integrator_Interface extends WC_WMS_Integrator_Interface {
    
    public function syncCustomer(int $customerId): mixed;
    public function importCustomersFromWms(array $params = []): mixed;
    public function transformCustomerData(WC_Customer $customer): array;
    public function shouldSyncCustomer(WC_Customer $customer): bool;
}

/**
 * Stock Integrator Interface
 */
interface WC_WMS_Stock_Integrator_Interface extends WC_WMS_Integrator_Interface {
    
    public function syncStockFromWebhook(array $webhookData): array;
    public function updateProductStock(array $stockData): mixed;
    public function syncAllStock(int $batchSize = 100): array;
    public function getStockDiscrepancies(int $limit = 50): array;
    public function fixStockDiscrepancies(array $skus = []): array;
}

/**
 * Webhook Integrator Interface
 */
interface WC_WMS_Webhook_Integrator_Interface extends WC_WMS_Integrator_Interface {
    
    public function handleWebhook(WP_REST_Request $request): WP_REST_Response;
    public function routeWebhookEvent(string $group, string $action, array $body, string $entityId = null): array;
    public function getWebhookUrls(): array;
}

/**
 * Shipment Integrator Interface
 */
interface WC_WMS_Shipment_Integrator_Interface extends WC_WMS_Integrator_Interface {
    
    public function processShipmentWebhook(array $shipmentData): array;
    public function updateOrderWithShipmentData(WC_Order $order, array $shipmentData): bool;
    public function getOrderShipments(WC_Order $order): array;
    public function syncShipmentTracking(string $shipmentId): array;
    public function createReturnLabel(string $shipmentId): array;
    public function getShipmentStatus(string $shipmentId): string;
    public function getShippingMethods(): array;
    public function syncShippingMethods(): array;
    public function getShipmentStatistics(): array;
    public function getShipmentStatusStats(int $days = 30): array;
    public function getRecentShipments(int $days = 7, int $limit = 50): array;
    public function getShipments(array $params = []): array;
    public function validateShipmentData(array $shipmentData): array;
}

/**
 * GDPR Integrator Interface
 */
interface WC_WMS_GDPR_Integrator_Interface extends WC_WMS_Integrator_Interface {
    
    public function exportWmsData(string $emailAddress, int $page = 1): array;
    public function eraseWmsData(string $emailAddress, int $page = 1): array;
    public function getGdprComplianceStatus(): array;
    public function generateComplianceReport(): array;
}