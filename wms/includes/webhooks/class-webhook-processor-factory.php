<?php
/**
 * WMS Webhook Processor Factory
 * 
 * Creates appropriate webhook processors based on webhook group/type
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Webhook_Processor_Factory {
    
    /**
     * WMS client instance
     */
    private $client;
    
    /**
     * Cached processors
     */
    private $processors = [];
    
    /**
     * Constructor
     */
    public function __construct(WC_WMS_Client $client) {
        $this->client = $client;
    }
    
    /**
     * Get processor for webhook group
     */
    public function getProcessor(string $group): WC_WMS_Webhook_Processor {
        if (!isset($this->processors[$group])) {
            $this->processors[$group] = $this->createProcessor($group);
        }
        
        return $this->processors[$group];
    }
    
    /**
     * Create processor instance for group
     */
    private function createProcessor(string $group): WC_WMS_Webhook_Processor {
        switch ($group) {
            case 'order':
                return new WC_WMS_Order_Webhook_Processor($this->client);
                
            case 'stock':
                return new WC_WMS_Stock_Webhook_Processor($this->client);
                
            case 'shipment':
                return new WC_WMS_Shipment_Webhook_Processor($this->client);
                
            case 'inbound':
                return new WC_WMS_Inbound_Webhook_Processor($this->client);
                
            default:
                return new WC_WMS_Generic_Webhook_Processor($this->client);
        }
    }
    
    /**
     * Get all supported webhook groups
     */
    public function getSupportedGroups(): array {
        return [
            'order' => 'Order webhook processor',
            'stock' => 'Stock webhook processor',
            'shipment' => 'Shipment webhook processor',
            'inbound' => 'Inbound webhook processor'
        ];
    }
    
    /**
     * Check if group is supported
     */
    public function isGroupSupported(string $group): bool {
        return array_key_exists($group, $this->getSupportedGroups());
    }
    
    /**
     * Get processor statistics
     */
    public function getProcessorStats(): array {
        $stats = [];
        
        foreach ($this->processors as $group => $processor) {
            $stats[$group] = [
                'class' => get_class($processor),
                'supported_actions' => $processor->getSupportedActions(),
                'last_processed' => $processor->getLastProcessedTime()
            ];
        }
        
        return $stats;
    }
    
    /**
     * Reset all processors (useful for testing)
     */
    public function resetProcessors(): void {
        $this->processors = [];
    }
}
