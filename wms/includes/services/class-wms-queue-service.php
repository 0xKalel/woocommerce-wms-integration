<?php
/**
 * WMS Queue Service
 * 
 * High-level queue operations service using the Queue Manager
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Queue_Service {
    
    /**
     * WMS Client instance
     */
    private $wmsClient;
    
    /**
     * Queue Manager instance
     */
    private $queueManager;
    
    /**
     * Event Dispatcher instance
     */
    private $eventDispatcher;
    
    /**
     * CENTRALIZED ORDER SYNC MANAGER - Single source of truth
     */
    private $orderSyncManager;
    
    /**
     * Constructor
     */
    public function __construct(WC_WMS_Client $wmsClient) {
        $this->wmsClient = $wmsClient;
        $this->queueManager = new WC_WMS_Queue_Manager();
        $this->eventDispatcher = WC_WMS_Event_Dispatcher::instance();
        
        // Initialize centralized order sync manager
        $this->orderSyncManager = new WC_WMS_Order_Sync_Manager($wmsClient);
    }
    
    /**
     * Process order queue
     */
    public function processOrderQueue(int $batchSize = 10): array {
        $results = [
            'processed' => 0,
            'successful' => 0,
            'failed' => 0,
            'errors' => []
        ];
        
        $this->wmsClient->logger()->info('Starting order queue processing', [
            'batch_size' => $batchSize
        ]);
        
        $items = $this->queueManager->getPendingItems('order', $batchSize);
        
        $this->wmsClient->logger()->info('Retrieved pending items from queue', [
            'item_count' => count($items),
            'items' => array_map(function($item) {
                return [
                    'id' => $item['id'],
                    'order_id' => $item['order_id'],
                    'action' => $item['action'],
                    'attempts' => $item['attempts']
                ];
            }, $items)
        ]);
        
        if (empty($items)) {
            $this->wmsClient->logger()->info('No pending order queue items to process');
            return $results;
        }
        
        foreach ($items as $item) {
            $result = $this->processOrderQueueItem($item);
            $results['processed']++;
            
            if ($result['success']) {
                $results['successful']++;
            } else {
                $results['failed']++;
                $results['errors'][] = [
                    'item_id' => $item['id'],
                    'order_id' => $item['order_id'],
                    'error' => $result['error']
                ];
            }
        }
        
        $this->wmsClient->logger()->info('Order queue processing completed', $results);
        
        return $results;
    }
    
    /**
     * Process product queue
     */
    public function processProductQueue(int $batchSize = 20): array {
        $results = [
            'processed' => 0,
            'successful' => 0,
            'failed' => 0,
            'errors' => []
        ];
        
        $this->wmsClient->logger()->info('Starting product queue processing', [
            'batch_size' => $batchSize
        ]);
        
        $items = $this->queueManager->getPendingItems('product', $batchSize);
        
        foreach ($items as $item) {
            $result = $this->processProductQueueItem($item);
            $results['processed']++;
            
            if ($result['success']) {
                $results['successful']++;
            } else {
                $results['failed']++;
                $results['errors'][] = [
                    'item_id' => $item['id'],
                    'product_id' => $item['product_id'],
                    'error' => $result['error']
                ];
            }
        }
        
        $this->wmsClient->logger()->info('Product queue processing completed', $results);
        
        return $results;
    }
    
    /**
     * Queue order for export
     */
    public function queueOrderExport(int $orderId, int $priority = 0): bool {
        $order = wc_get_order($orderId);
        if (!$order) {
            $this->wmsClient->logger()->error('Cannot queue non-existent order', [
                'order_id' => $orderId
            ]);
            return false;
        }
        
        // Check if order should be exported using centralized manager
        if (!$this->orderSyncManager->shouldExportOrder($order)) {
            $this->wmsClient->logger()->info('Order skipped for export by centralized manager', [
                'order_id' => $orderId,
                'reason' => 'Does not meet export criteria',
                'order_status' => $order->get_status(),
            ]);
            return false;
        }
        
        // Check if already exported
        if ($order->get_meta('_wms_order_id')) {
            $this->wmsClient->logger()->debug('Order already exported', [
                'order_id' => $orderId,
                'wms_order_id' => $order->get_meta('_wms_order_id')
            ]);
            return false;
        }
        
        $this->wmsClient->logger()->info('Attempting to queue order for export', [
            'order_id' => $orderId,
            'priority' => $priority,
            'order_status' => $order->get_status()
        ]);
        
        $queued = $this->queueManager->enqueueOrder($orderId, 'export', $priority);
        
        if ($queued) {
            $this->wmsClient->logger()->info('Order successfully queued for export', [
                'order_id' => $orderId,
                'priority' => $priority
            ]);
            
            $this->eventDispatcher->dispatch('wms.order.queued', [
                'order_id' => $orderId,
                'action' => 'export',
                'priority' => $priority
            ]);
        } else {
            $this->wmsClient->logger()->warning('Failed to queue order for export', [
                'order_id' => $orderId,
                'reason' => 'Queue manager returned false (possibly already queued)'
            ]);
        }
        
        return $queued;
    }
    
    /**
     * Queue order for cancellation
     */
    public function queueOrderCancellation(int $orderId, int $priority = 5): bool {
        $order = wc_get_order($orderId);
        if (!$order) {
            return false;
        }
        
        // Check if order was exported to WMS
        $wmsOrderId = $order->get_meta('_wms_order_id');
        if (!$wmsOrderId) {
            $this->wmsClient->logger()->info('Order not sent to WMS, no cancellation needed', [
                'order_id' => $orderId
            ]);
            return false;
        }
        
        $queued = $this->queueManager->enqueueOrder($orderId, 'cancel', $priority);
        
        if ($queued) {
            $this->eventDispatcher->dispatch('wms.order.cancel_queued', [
                'order_id' => $orderId,
                'wms_order_id' => $wmsOrderId,
                'priority' => $priority
            ]);
        }
        
        return $queued;
    }
    
    /**
     * Queue product for sync
     */
    public function queueProductSync(int $productId, int $priority = 0): bool {
        $product = wc_get_product($productId);
        if (!$product) {
            return false;
        }
        
        // Check if product should be synced
        if (!$this->shouldSyncProduct($product)) {
            return false;
        }
        
        $queued = $this->queueManager->enqueueProduct($productId, 'sync', $priority);
        
        if ($queued) {
            $this->eventDispatcher->dispatch('wms.product.queued', [
                'product_id' => $productId,
                'priority' => $priority
            ]);
        }
        
        return $queued;
    }
    
    /**
     * Get queue statistics
     */
    public function getQueueStats(): array {
        return [
            'order_queue' => $this->queueManager->getStats('order'),
            'product_queue' => $this->queueManager->getStats('product'),
            'recent_activity' => $this->queueManager->getRecentActivity(20)
        ];
    }
    
    /**
     * Retry failed items
     */
    public function retryFailedItems(string $type = 'both'): array {
        $results = [];
        
        if ($type === 'order' || $type === 'both') {
            $results['orders'] = $this->queueManager->retryFailedItems('order');
        }
        
        if ($type === 'product' || $type === 'both') {
            $results['products'] = $this->queueManager->retryFailedItems('product');
        }
        
        $this->wmsClient->logger()->info('Failed items retry initiated', $results);
        
        return $results;
    }
    
    /**
     * Force process specific item
     */
    public function forceProcessItem(int $itemId, string $type): bool {
        $forced = $this->queueManager->forceProcessItem($itemId, $type);
        
        if ($forced) {
            $this->wmsClient->logger()->info('Queue item forced for processing', [
                'item_id' => $itemId,
                'type' => $type
            ]);
        }
        
        return $forced;
    }
    
    /**
     * Clean up old queue items
     */
    public function cleanup(): array {
        return $this->queueManager->cleanup();
    }
    
    /**
     * Process single order queue item
     */
    private function processOrderQueueItem(array $item): array {
        $itemId = $item['id'];
        $orderId = $item['order_id'];
        $action = $item['action'];
        
        // Mark as processing
        $this->queueManager->markAsProcessing($itemId, 'order');
        
        try {
            $this->wmsClient->logger()->info('Processing order queue item via centralized manager', [
                'item_id' => $itemId,
                'order_id' => $orderId,
                'action' => $action,
                'attempts' => $item['attempts']
            ]);
            
            $result = null;
            
            switch ($action) {
                case 'export':
                    // USE CENTRALIZED ORDER SYNC MANAGER
                    $result = $this->orderSyncManager->processOrderExport($orderId);
                    break;
                    
                case 'cancel':
                    // USE CENTRALIZED ORDER SYNC MANAGER
                    $result = $this->orderSyncManager->processOrderCancellation($orderId);
                    break;
                    
                case 'sync':
                    // USE CENTRALIZED ORDER SYNC MANAGER
                    $result = $this->orderSyncManager->processManualOrderSync([$orderId]);
                    break;
                    
                default:
                    throw new Exception("Unknown order action: {$action}");
            }
            
            if (is_wp_error($result)) {
                throw new Exception($result->get_error_message());
            }
            
            // Check if result indicates success
            if (is_array($result) && isset($result['success']) && !$result['success']) {
                throw new Exception($result['error'] ?? 'Order processing failed');
            }
            
            // Mark as completed
            $this->queueManager->markAsCompleted($itemId, 'order');
            
            $this->eventDispatcher->dispatch('wms.queue.order_processed', [
                'item_id' => $itemId,
                'order_id' => $orderId,
                'action' => $action,
                'result' => $result
            ]);
            
            return ['success' => true, 'result' => $result];
            
        } catch (Exception $e) {
            $this->queueManager->markAsFailedOrRetry($itemId, $e->getMessage(), 'order');
            
            $this->eventDispatcher->dispatch('wms.queue.order_failed', [
                'item_id' => $itemId,
                'order_id' => $orderId,
                'action' => $action,
                'error' => $e->getMessage(),
                'attempts' => $item['attempts'] + 1
            ]);
            
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Process single product queue item
     */
    private function processProductQueueItem(array $item): array {
        $itemId = $item['id'];
        $productId = $item['product_id'];
        
        // Mark as processing
        $this->queueManager->markAsProcessing($itemId, 'product');
        
        try {
            $this->wmsClient->logger()->info('Processing product queue item', [
                'item_id' => $itemId,
                'product_id' => $productId,
                'attempts' => $item['attempts']
            ]);
            
            $result = $this->wmsClient->productIntegrator()->syncProduct($productId);
            
            if (is_wp_error($result)) {
                throw new Exception($result->get_error_message());
            }
            
            // Mark as completed
            $this->queueManager->markAsCompleted($itemId, 'product');
            
            $this->eventDispatcher->dispatch('wms.queue.product_processed', [
                'item_id' => $itemId,
                'product_id' => $productId,
                'result' => $result
            ]);
            
            return ['success' => true, 'result' => $result];
            
        } catch (Exception $e) {
            $this->queueManager->markAsFailedOrRetry($itemId, $e->getMessage(), 'product');
            
            $this->eventDispatcher->dispatch('wms.queue.product_failed', [
                'item_id' => $itemId,
                'product_id' => $productId,
                'error' => $e->getMessage(),
                'attempts' => $item['attempts'] + 1
            ]);
            
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Check if product should be synced
     */
    private function shouldSyncProduct(WC_Product $product): bool {
        // Skip virtual/downloadable products
        if ($product->is_virtual() || $product->is_downloadable()) {
            return false;
        }
        
        // Skip products without SKU (configurable)
        $requireSku = apply_filters('wc_wms_require_sku_for_sync', true);
        if ($requireSku && !$product->get_sku()) {
            return false;
        }
        
        return apply_filters('wc_wms_should_sync_product', true, $product);
    }
    
    /**
     * Check if order has physical products
     */
    private function hasPhysicalProducts(WC_Order $order): bool {
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && !$product->is_virtual() && !$product->is_downloadable()) {
                return true;
            }
        }
        return false;
    }
}