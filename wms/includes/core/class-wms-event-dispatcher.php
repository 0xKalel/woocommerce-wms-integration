<?php
/**
 * WMS Event Dispatcher
 * 
 * Modern event system for decoupled component communication
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Event_Dispatcher {
    
    /**
     * Registered event listeners
     */
    private $listeners = [];
    
    /**
     * Logger instance
     */
    private $logger;
    
    /**
     * Singleton instance
     */
    private static $instance = null;
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->logger = WC_WMS_Logger::instance();
    }
    
    /**
     * Get singleton instance
     */
    public static function instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Add event listener
     */
    public function listen(string $eventName, callable $listener, int $priority = 10): void {
        if (!isset($this->listeners[$eventName])) {
            $this->listeners[$eventName] = [];
        }
        
        $this->listeners[$eventName][] = [
            'callback' => $listener,
            'priority' => $priority
        ];
        
        // Sort by priority (higher priority first)
        usort($this->listeners[$eventName], function($a, $b) {
            return $b['priority'] - $a['priority'];
        });
        
        $this->logger->debug('Event listener registered', [
            'event' => $eventName,
            'priority' => $priority
        ]);
    }
    
    /**
     * Dispatch event to all listeners
     */
    public function dispatch(string $eventName, array $data = []): WC_WMS_Event_Result {
        $event = new WC_WMS_Event($eventName, $data);
        $results = [];
        
        $this->logger->debug('Dispatching event', [
            'event' => $eventName,
            'data_keys' => array_keys($data)
        ]);
        
        if (!isset($this->listeners[$eventName])) {
            $this->logger->debug('No listeners for event', ['event' => $eventName]);
            return new WC_WMS_Event_Result($event, []);
        }
        
        foreach ($this->listeners[$eventName] as $listener) {
            try {
                $startTime = microtime(true);
                $result = call_user_func($listener['callback'], $event);
                $executionTime = microtime(true) - $startTime;
                
                $results[] = [
                    'result' => $result,
                    'execution_time' => $executionTime,
                    'success' => true
                ];
                
                // If event is stopped, don't continue
                if ($event->isStopped()) {
                    $this->logger->debug('Event propagation stopped', [
                        'event' => $eventName,
                        'stopped_after' => count($results) . ' listeners'
                    ]);
                    break;
                }
                
            } catch (Exception $e) {
                $results[] = [
                    'result' => null,
                    'execution_time' => 0,
                    'success' => false,
                    'error' => $e->getMessage()
                ];
                
                $this->logger->error('Event listener failed', [
                    'event' => $eventName,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }
        
        $this->logger->debug('Event dispatched', [
            'event' => $eventName,
            'listeners_executed' => count($results),
            'total_time' => array_sum(array_column($results, 'execution_time'))
        ]);
        
        return new WC_WMS_Event_Result($event, $results);
    }
    
    /**
     * Remove all listeners for an event
     */
    public function removeListeners(string $eventName): void {
        unset($this->listeners[$eventName]);
        $this->logger->debug('Event listeners removed', ['event' => $eventName]);
    }
    
    /**
     * Get all registered events
     */
    public function getRegisteredEvents(): array {
        return array_keys($this->listeners);
    }
    
    /**
     * Get listener count for event
     */
    public function getListenerCount(string $eventName): int {
        return count($this->listeners[$eventName] ?? []);
    }
    
    /**
     * Register default WMS events
     */
    public function registerDefaultListeners(): void {
        // Order events
        $this->listen('wms.order.created', [$this, 'handleOrderCreated']);
        $this->listen('wms.order.exported', [$this, 'handleOrderExported']);
        $this->listen('wms.order.failed', [$this, 'handleOrderFailed']);
        $this->listen('wms.order.shipped', [$this, 'handleOrderShipped']);
        
        // Product events
        $this->listen('wms.product.synced', [$this, 'handleProductSynced']);
        $this->listen('wms.product.sync_failed', [$this, 'handleProductSyncFailed']);
        
        // Stock events
        $this->listen('wms.stock.updated', [$this, 'handleStockUpdated']);
        
        // Webhook events
        $this->listen('wms.webhook.received', [$this, 'handleWebhookReceived']);
        $this->listen('wms.webhook.processed', [$this, 'handleWebhookProcessed']);
        $this->listen('wms.webhook.failed', [$this, 'handleWebhookFailed']);
        
        $this->logger->info('Default WMS event listeners registered');
    }
    
    /**
     * Default event handlers
     */
    public function handleOrderCreated(WC_WMS_Event $event): void {
        $data = $event->getData();
        $this->logger->info('Order created event handled', [
            'order_id' => $data['order_id'] ?? null
        ]);
    }
    
    public function handleOrderExported(WC_WMS_Event $event): void {
        $data = $event->getData();
        $this->logger->info('Order exported event handled', [
            'order_id' => $data['order_id'] ?? null,
            'wms_order_id' => $data['wms_order_id'] ?? null
        ]);
    }
    
    public function handleOrderFailed(WC_WMS_Event $event): void {
        $data = $event->getData();
        $this->logger->error('Order export failed event handled', [
            'order_id' => $data['order_id'] ?? null,
            'error' => $data['error'] ?? null
        ]);
    }
    
    public function handleOrderShipped(WC_WMS_Event $event): void {
        $data = $event->getData();
        $this->logger->info('Order shipped event handled', [
            'order_id' => $data['order_id'] ?? null,
            'tracking_number' => $data['tracking_number'] ?? null
        ]);
    }
    
    public function handleProductSynced(WC_WMS_Event $event): void {
        $data = $event->getData();
        $this->logger->debug('Product synced event handled', [
            'product_id' => $data['product_id'] ?? null
        ]);
    }
    
    public function handleProductSyncFailed(WC_WMS_Event $event): void {
        $data = $event->getData();
        $this->logger->warning('Product sync failed event handled', [
            'product_id' => $data['product_id'] ?? null,
            'error' => $data['error'] ?? null
        ]);
    }
    
    public function handleStockUpdated(WC_WMS_Event $event): void {
        $data = $event->getData();
        $this->logger->debug('Stock updated event handled', [
            'sku' => $data['sku'] ?? null,
            'quantity' => $data['quantity'] ?? null
        ]);
    }
    
    public function handleWebhookReceived(WC_WMS_Event $event): void {
        $data = $event->getData();
        $this->logger->debug('Webhook received event handled', [
            'webhook_type' => $data['webhook_type'] ?? null,
            'webhook_id' => $data['webhook_id'] ?? null
        ]);
    }
    
    public function handleWebhookProcessed(WC_WMS_Event $event): void {
        $data = $event->getData();
        $this->logger->debug('Webhook processed event handled', [
            'webhook_type' => $data['webhook_type'] ?? null,
            'processing_time' => $data['processing_time'] ?? null
        ]);
    }
    
    public function handleWebhookFailed(WC_WMS_Event $event): void {
        $data = $event->getData();
        $this->logger->error('Webhook failed event handled', [
            'webhook_type' => $data['webhook_type'] ?? null,
            'error' => $data['error'] ?? null
        ]);
    }
}

/**
 * Event class
 */
class WC_WMS_Event {
    
    private $name;
    private $data;
    private $stopped = false;
    private $timestamp;
    
    public function __construct(string $name, array $data = []) {
        $this->name = $name;
        $this->data = $data;
        $this->timestamp = microtime(true);
    }
    
    public function getName(): string {
        return $this->name;
    }
    
    public function getData(): array {
        return $this->data;
    }
    
    public function get(string $key, $default = null) {
        return $this->data[$key] ?? $default;
    }
    
    public function set(string $key, $value): void {
        $this->data[$key] = $value;
    }
    
    public function stopPropagation(): void {
        $this->stopped = true;
    }
    
    public function isStopped(): bool {
        return $this->stopped;
    }
    
    public function getTimestamp(): float {
        return $this->timestamp;
    }
}

/**
 * Event result class
 */
class WC_WMS_Event_Result {
    
    private $event;
    private $results;
    
    public function __construct(WC_WMS_Event $event, array $results) {
        $this->event = $event;
        $this->results = $results;
    }
    
    public function getEvent(): WC_WMS_Event {
        return $this->event;
    }
    
    public function getResults(): array {
        return $this->results;
    }
    
    public function hasErrors(): bool {
        foreach ($this->results as $result) {
            if (!$result['success']) {
                return true;
            }
        }
        return false;
    }
    
    public function getErrors(): array {
        $errors = [];
        foreach ($this->results as $result) {
            if (!$result['success'] && isset($result['error'])) {
                $errors[] = $result['error'];
            }
        }
        return $errors;
    }
    
    public function getSuccessCount(): int {
        return count(array_filter($this->results, function($result) {
            return $result['success'];
        }));
    }
    
    public function getFailureCount(): int {
        return count(array_filter($this->results, function($result) {
            return !$result['success'];
        }));
    }
    
    public function getTotalExecutionTime(): float {
        return array_sum(array_column($this->results, 'execution_time'));
    }
}