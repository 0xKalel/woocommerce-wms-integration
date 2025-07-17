<?php
/**
 * WMS AJAX Handlers
 * 
 * Handles all AJAX requests for WMS integration
 * Separate from business logic - only handles HTTP concerns
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Ajax_Handlers {
    
    /**
     * Register all AJAX handlers
     */
    public static function register() {
        // Product sync handlers
        add_action('wp_ajax_wc_wms_sync_all_products', [self::class, 'sync_all_products']);
        add_action('wp_ajax_wc_wms_sync_everything', [self::class, 'sync_everything']);
        
        // Customer sync handlers
        add_action('wp_ajax_wc_wms_import_customers', [self::class, 'import_customers']);
        add_action('wp_ajax_wc_wms_get_customer_stats', [self::class, 'get_customer_stats']);
        
        // Order sync handlers
        add_action('wp_ajax_wc_wms_sync_orders', [self::class, 'sync_orders']);
        
        // Location type handlers
        add_action('wp_ajax_wc_wms_sync_location_types', [self::class, 'sync_location_types']);
        add_action('wp_ajax_wc_wms_get_location_types', [self::class, 'get_location_types']);
        add_action('wp_ajax_wc_wms_get_location_type_stats', [self::class, 'get_location_type_stats']);
        
        // Stock sync handlers
        add_action('wp_ajax_wc_wms_sync_all_stock', [self::class, 'sync_all_stock']);
        add_action('wp_ajax_wc_wms_diagnose_stock_mismatch', [self::class, 'diagnose_stock_mismatch']);
        add_action('wp_ajax_wc_wms_create_products_from_stock', [self::class, 'create_products_from_stock']);
        
        // Webhook handlers
        add_action('wp_ajax_wc_wms_register_webhooks', [self::class, 'register_webhooks']);
        add_action('wp_ajax_wc_wms_check_webhook_status', [self::class, 'check_webhook_status']);
        add_action('wp_ajax_wc_wms_delete_all_webhooks', [self::class, 'delete_all_webhooks']);
        add_action('wp_ajax_wc_wms_validate_webhook_config', [self::class, 'validate_webhook_config']);
        add_action('wp_ajax_wc_wms_generate_webhook_secret', [self::class, 'generate_webhook_secret']);
        add_action('wp_ajax_wc_wms_check_logging_security', [self::class, 'check_logging_security']);
        
        // Inbound handlers
        add_action('wp_ajax_wc_wms_get_inbounds', [self::class, 'get_inbounds']);
        add_action('wp_ajax_wc_wms_get_inbound_details', [self::class, 'get_inbound_details']);
        add_action('wp_ajax_wc_wms_create_inbound', [self::class, 'create_inbound']);
        add_action('wp_ajax_wc_wms_update_inbound', [self::class, 'update_inbound']);
        add_action('wp_ajax_wc_wms_cancel_inbound', [self::class, 'cancel_inbound']);
        add_action('wp_ajax_wc_wms_get_inbound_stats', [self::class, 'get_inbound_stats']);
        add_action('wp_ajax_wc_wms_sync_inbounds', [self::class, 'sync_inbounds']);
        
        // Shipment handlers
        add_action('wp_ajax_wc_wms_sync_shipments', [self::class, 'sync_shipments']);
        
        // Shipping method handlers
        add_action('wp_ajax_wc_wms_sync_shipping_methods', [self::class, 'sync_shipping_methods']);
        add_action('wp_ajax_wc_wms_get_shipping_methods', [self::class, 'get_shipping_methods']);
        add_action('wp_ajax_wc_wms_save_shipping_mappings', [self::class, 'save_shipping_mappings']);
        
        // Initial sync management
        add_action('wp_ajax_wc_wms_reset_initial_sync', [self::class, 'reset_initial_sync']);
    }
    
    /**
     * Create WooCommerce products from WMS stock items that don't have corresponding articles
     */
    public static function create_products_from_stock() {
        self::verify_request();
        
        try {
            $client = WC_WMS_Service_Container::getWmsClient();
            
            if (!$client->authenticator()->isAuthenticated()) {
                $client->authenticator()->authenticate();
            }
            
            // Use product integrator to create products from stock
            $results = $client->productIntegrator()->createProductsFromStock(50);
            
            wp_send_json_success([
                'message' => sprintf(
                    'Created %d products, skipped %d existing products. %d errors.',
                    $results['created'],
                    $results['skipped'],
                    count($results['errors'])
                ),
                'created' => $results['created'],
                'skipped' => $results['skipped'],
                'errors' => $results['errors']
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error('Failed to create products from stock: ' . $e->getMessage());
        }
    }
    
    /**
     * Sync all products - Import articles from WMS
     */
    public static function sync_all_products() {
        self::verify_request();
        
        // Check if initial sync is completed before allowing manual product sync
        if (!get_option('wc_wms_initial_sync_completed', false)) {
            wp_send_json_error([
                'message' => 'Product sync is disabled until initial sync is completed. Please run "Sync Everything" first.',
                'code' => 'initial_sync_required'
            ]);
            return;
        }
        
        try {
            $productSync = WC_WMS_Service_Container::getProductSync();
            $result = $productSync->import_articles_from_wms(['limit' => 100]);
            
            if (is_wp_error($result)) {
                wp_send_json_error('Article import failed: ' . $result->get_error_message());
            } else {
                // Mark products as exported and store stats
                update_option('wc_wms_products_exported', true);
                update_option('wc_wms_products_exported_at', current_time('mysql'));
                update_option('wc_wms_products_export_stats', [
                    'created' => $result['created'] ?? 0,
                    'updated' => $result['updated'] ?? 0,
                    'skipped' => $result['skipped'] ?? 0,
                    'errors' => $result['errors'] ?? 0,
                    'timestamp' => current_time('mysql')
                ]);
                
                wp_send_json_success([
                    'message' => sprintf('Imported %d articles, updated %d articles, skipped %d articles', 
                        $result['created'] ?? 0, $result['updated'] ?? 0, $result['skipped'] ?? 0),
                    'imported' => $result['created'] ?? 0,
                    'updated' => $result['updated'] ?? 0,
                    'skipped' => $result['skipped'] ?? 0,
                    'errors' => $result['errors'] ?? 0
                ]);
            }
        } catch (Exception $e) {
            wp_send_json_error('Article import failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Sync everything - Complete system synchronization
     */
    public static function sync_everything() {
        self::verify_request();
        
        try {
            $results = [];
            
            // Get services from container - use only new clean architecture
            $client = WC_WMS_Service_Container::getWmsClient();
            $productSync = WC_WMS_Service_Container::getProductSync();
            $customerSync = WC_WMS_Service_Container::getCustomerSync();
            
            // Test connection using WMS client
            $connection_test = $client->testConnection();
            $results['connection'] = $connection_test['success'] ? 'Success' : 'Failed: ' . $connection_test['message'];
            
            // Sync shipping methods using shipment integrator
            try {
                $shipping_result = $client->shipmentIntegrator()->syncShippingMethods();
                if (!is_wp_error($shipping_result)) {
                    $results['shipping_methods'] = sprintf('Synced %d methods', $shipping_result['count'] ?? 0);
                    update_option('wc_wms_shipping_methods_synced_at', current_time('mysql'));
                } else {
                    $results['shipping_methods'] = 'Failed: ' . $shipping_result->get_error_message();
                }
            } catch (Exception $e) {
                $results['shipping_methods'] = 'Failed: ' . $e->getMessage();
            }
            
            // Sync location types
            try {
                $client = WC_WMS_Service_Container::getWmsClient();
                $location_result = $client->locationTypes()->syncLocationTypes();
                if ($location_result['success']) {
                    $results['location_types'] = sprintf('%d types (%d pickable, %d transport)', 
                        $location_result['total_count'],
                        $location_result['pickable_count'],
                        $location_result['transport_count']);
                } else {
                    $results['location_types'] = 'Failed to sync location types';
                }
            } catch (Exception $e) {
                $results['location_types'] = 'Failed: ' . $e->getMessage();
            }
            
            // Register webhooks (delete existing first, then register fresh)
            try {
                $client = WC_WMS_Service_Container::getWmsClient();
                $webhook_result = $client->webhookIntegrator()->registerAllWebhooks();
                
                if ($webhook_result['success']) {
                    $registered_count = count($webhook_result['registered'] ?? []);
                    $deleted_count = count($webhook_result['deletion_results']['deleted'] ?? []);
                    $results['webhooks'] = sprintf('%d registered (deleted %d existing)', $registered_count, $deleted_count);
                    
                    // Update webhook registration timestamp and registered webhooks
                    update_option('wc_wms_webhooks_registered_at', current_time('mysql'));
                    if (!empty($webhook_result['registered'])) {
                        update_option('wc_wms_registered_webhooks', $webhook_result['registered']);
                    }
                } else {
                    $results['webhooks'] = 'Failed: ' . ($webhook_result['error'] ?? 'Unknown error');
                }
            } catch (Exception $e) {
                $results['webhooks'] = 'Failed: ' . $e->getMessage();
            }
            
            // Sync products - Import articles FROM WMS
            try {
                $product_result = $productSync->import_articles_from_wms(['limit' => 100]);
                if (is_wp_error($product_result)) {
                    $results['products'] = 'Failed: ' . $product_result->get_error_message();
                } else {
                    $results['products'] = sprintf('%d imported, %d updated, %d skipped', 
                        $product_result['created'] ?? 0, 
                        $product_result['updated'] ?? 0,
                        $product_result['skipped'] ?? 0);
                    
                    // Mark products as exported and store stats
                    update_option('wc_wms_products_exported', true);
                    update_option('wc_wms_products_exported_at', current_time('mysql'));
                    update_option('wc_wms_products_export_stats', [
                        'created' => $product_result['created'] ?? 0,
                        'updated' => $product_result['updated'] ?? 0,
                        'skipped' => $product_result['skipped'] ?? 0,
                        'errors' => $product_result['errors'] ?? 0,
                        'timestamp' => current_time('mysql')
                    ]);
                }
            } catch (Exception $e) {
                $results['products'] = 'Failed: ' . $e->getMessage();
            }
            
            // Sync stock levels
            try {
                $stock_result = $client->stockIntegrator()->syncAllStock(50); // Smaller batch size
                $results['stock'] = sprintf('%d processed, %d updated', 
                    $stock_result['processed'] ?? 0,
                    $stock_result['updated'] ?? 0);
                
                if (!empty($stock_result['errors'])) {
                    $results['stock'] .= sprintf(' (%d errors)', count($stock_result['errors']));
                }
                
                // Mark stock sync as completed if successful
                if ($stock_result && isset($stock_result['processed']) && $stock_result['processed'] > 0) {
                    update_option('wc_wms_stock_synced', true);
                    update_option('wc_wms_stock_synced_at', current_time('mysql'));
                    update_option('wc_wms_stock_sync_stats', [
                        'processed' => $stock_result['processed'] ?? 0,
                        'updated' => $stock_result['updated'] ?? 0,
                        'errors' => count($stock_result['errors'] ?? []),
                        'timestamp' => current_time('mysql')
                    ]);
                }
            } catch (Exception $e) {
                $results['stock'] = 'Failed: ' . $e->getMessage();
            }
            
            // Import customers
            $customer_result = $customerSync->import_customers_from_wms(['limit' => 50]);
            if (is_wp_error($customer_result)) {
                $results['customers'] = 'Failed: ' . $customer_result->get_error_message();
            } else {
                $results['customers'] = sprintf('%d imported, %d updated', 
                    $customer_result['imported'], $customer_result['updated']);
                
                // Update customer import timestamp
                update_option('wc_wms_customers_last_import', time());
            }
            
            // Sync orders from WMS - USING CENTRALIZED MANAGER
            try {
                $client = WC_WMS_Service_Container::getWmsClient();
                
                // Use centralized order sync manager
                $orderSyncManager = new WC_WMS_Order_Sync_Manager($client);
                $orderResult = $orderSyncManager->processManualOrderSync();
                
                if (isset($orderResult['errors']) && !empty($orderResult['errors'])) {
                    $results['orders'] = sprintf('Partial success: %d created, %d updated, %d errors', 
                        $orderResult['created'], $orderResult['updated'], count($orderResult['errors']));
                    
                    // Log order_lines specific errors
                    $client->logger()->warning('Manual order sync completed with errors', [
                        'total_fetched' => $orderResult['total_fetched'] ?? 0,
                        'created' => $orderResult['created'] ?? 0,
                        'updated' => $orderResult['updated'] ?? 0,
                        'errors' => count($orderResult['errors'] ?? []),
                        'first_error' => $orderResult['errors'][0] ?? null
                    ]);
                } else {
                    $results['orders'] = sprintf('%d created, %d updated', 
                        $orderResult['created'] ?? 0, $orderResult['updated'] ?? 0);
                    
                    // Log successful order_lines processing
                    $client->logger()->info('Order sync completed successfully - verifying order_lines', [
                        'total_fetched' => $orderResult['total_fetched'] ?? 0,
                        'created' => $orderResult['created'] ?? 0,
                        'updated' => $orderResult['updated'] ?? 0
                    ]);
                }
                
                // Update order sync timestamp
                update_option('wc_wms_orders_last_sync', time());
                
            } catch (Exception $e) {
                $results['orders'] = 'Failed: ' . $e->getMessage();
                
                // Enhanced error logging for order_lines issues
                $client->logger()->error('Order sync failed completely', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
            
            // Sync inbounds from WMS
            try {
                $inboundService = WC_WMS_Service_Container::getInboundService();
                
                $params = [
                    'limit' => 50,
                    'from' => date('Y-m-d', strtotime('-30 days')),
                    'sort' => 'inboundDate',
                    'direction' => 'desc'
                ];
                
                $inbounds = $inboundService->getInbounds($params);
                
                if (is_wp_error($inbounds)) {
                    $results['inbounds'] = 'Failed: ' . $inbounds->get_error_message();
                } else {
                    $total_synced = count($inbounds);
                    $completed_count = 0;
                    
                    foreach ($inbounds as $inbound) {
                        if (isset($inbound['status']) && $inbound['status'] === 'completed') {
                            $completed_count++;
                        }
                    }
                    
                    $results['inbounds'] = sprintf('%d synced (%d completed)', $total_synced, $completed_count);
                    
                    // Update inbound sync tracking
                    update_option('wc_wms_inbounds_last_sync', time());
                    update_option('wc_wms_inbounds_synced_count', $total_synced);
                }
                
            } catch (Exception $e) {
                $results['inbounds'] = 'Failed: ' . $e->getMessage();
            }
            
            // Sync shipments from WMS
            try {
                $wmsClient = WC_WMS_Service_Container::getWmsClient();
                $shipmentIntegrator = $wmsClient->shipmentIntegrator();
                
                // Use the existing getRecentShipments method
                $shipments = $shipmentIntegrator->getRecentShipments(3, 50);
                
                if (empty($shipments)) {
                    $results['shipments'] = 'No recent shipments found';
                } else {
                    $total_synced = count($shipments);
                    $orders_updated = 0;
                    
                    foreach ($shipments as $shipment) {
                        try {
                            // Use the webhook processor to update orders
                            $result = $shipmentIntegrator->processShipmentWebhook($shipment);
                            if ($result['success']) {
                                $orders_updated++;
                            }
                        } catch (Exception $e) {
                            // Log error but continue processing
                            error_log('Shipment sync error: ' . $e->getMessage());
                        }
                    }
                    
                    $results['shipments'] = sprintf('%d synced (%d orders updated)', $total_synced, $orders_updated);
                    
                    // Update shipment sync tracking
                    update_option('wc_wms_shipments_last_sync', time());
                    update_option('wc_wms_shipments_synced_count', $total_synced);
                }
                
            } catch (Exception $e) {
                $results['shipments'] = 'Failed: ' . $e->getMessage();
            }
            
            // Stock sync via integrators
            $results['note'] = 'Stock sync included in process above';
            
            // Mark initial sync as completed
            update_option('wc_wms_initial_sync_completed', true);
            update_option('wc_wms_initial_sync_completed_at', current_time('mysql'));
            
            // Log the completion
            error_log('WMS Integration: Initial sync completed successfully - automatic processes now enabled');
            
            wp_send_json_success($results);
        } catch (Exception $e) {
            wp_send_json_error('Sync everything failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Sync orders from WMS
     */
    public static function sync_orders() {
        self::verify_request();
        
        // Check if initial sync is completed before allowing manual order sync
        if (!get_option('wc_wms_initial_sync_completed', false)) {
            wp_send_json_error([
                'message' => 'Order sync is disabled until initial sync is completed. Please run "Sync Everything" first.',
                'code' => 'initial_sync_required'
            ]);
            return;
        }
        
        try {
            $client = WC_WMS_Service_Container::getWmsClient();
            
            // Use centralized order sync manager
            $orderSyncManager = new WC_WMS_Order_Sync_Manager($client);
            $result = $orderSyncManager->processManualOrderSync();
            
            if (isset($result['errors']) && !empty($result['errors'])) {
                wp_send_json_error([
                    'message' => sprintf('Partial success: %d orders created, %d updated, %d errors', 
                        $result['created'], $result['updated'], count($result['errors'])),
                    'created' => $result['created'],
                    'updated' => $result['updated'],
                    'errors' => $result['errors'],
                    'error_count' => count($result['errors'])
                ]);
            } else {
                // Update order sync timestamp
                update_option('wc_wms_orders_last_sync', time());
                
                wp_send_json_success([
                    'message' => sprintf('Synced %d orders from WMS (%d created, %d updated)', 
                        $result['total_fetched'], $result['created'], $result['updated']),
                    'total_fetched' => $result['total_fetched'],
                    'created' => $result['created'],
                    'updated' => $result['updated'],
                    'skipped' => $result['skipped']
                ]);
            }
        } catch (Exception $e) {
            wp_send_json_error('Order sync failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Import customers
     */
    public static function import_customers() {
        self::verify_request();
        
        // Check if initial sync is completed before allowing manual customer import
        if (!get_option('wc_wms_initial_sync_completed', false)) {
            wp_send_json_error([
                'message' => 'Customer import is disabled until initial sync is completed. Please run "Sync Everything" first.',
                'code' => 'initial_sync_required'
            ]);
            return;
        }
        
        try {
            $customerSync = WC_WMS_Service_Container::getCustomerSync();
            $result = $customerSync->import_customers_from_wms(['limit' => 100]);
            
            if (is_wp_error($result)) {
                wp_send_json_error([
                    'message' => $result->get_error_message(),
                    'code' => $result->get_error_code()
                ]);
            } else {
                // Update customer import timestamp
                update_option('wc_wms_customers_last_import', time());
                
                wp_send_json_success([
                    'message' => sprintf('Imported %d customers, updated %d customers, %d errors', 
                        $result['imported'], $result['updated'], $result['errors']),
                    'imported' => $result['imported'],
                    'updated' => $result['updated'],
                    'errors' => $result['errors'],
                    'error_details' => $result['error_details']
                ]);
            }
        } catch (Exception $e) {
            wp_send_json_error('Customer import failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Sync all stock from WMS
     */
    public static function sync_all_stock() {
        self::verify_request();
        
        // Check if initial sync is completed before allowing manual stock sync
        if (!get_option('wc_wms_initial_sync_completed', false)) {
            wp_send_json_error([
                'message' => 'Stock sync is disabled until initial sync is completed. Please run "Sync Everything" first.',
                'code' => 'initial_sync_required'
            ]);
            return;
        }
        
        try {
            $client = WC_WMS_Service_Container::getWmsClient();
            $result = $client->stockIntegrator()->syncAllStock(100);
            
            // Mark stock sync as completed and store stats
            if ($result && isset($result['processed']) && $result['processed'] > 0) {
                update_option('wc_wms_stock_synced', true);
                update_option('wc_wms_stock_synced_at', current_time('mysql'));
                update_option('wc_wms_stock_sync_stats', [
                    'processed' => $result['processed'] ?? 0,
                    'updated' => $result['updated'] ?? 0,
                    'errors' => count($result['errors'] ?? []),
                    'timestamp' => current_time('mysql')
                ]);
            }
            
            wp_send_json_success([
                'message' => sprintf('Processed %d stock items, updated %d products', 
                    $result['processed'] ?? 0, $result['updated'] ?? 0),
                'processed' => $result['processed'] ?? 0,
                'updated' => $result['updated'] ?? 0,
                'errors' => $result['errors'] ?? [],
                'error_count' => count($result['errors'] ?? [])
            ]);
        } catch (Exception $e) {
            wp_send_json_error('Stock sync failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Diagnose stock/product mismatch
     */
    public static function diagnose_stock_mismatch() {
        self::verify_request();
        
        try {
            $client = WC_WMS_Service_Container::getWmsClient();
            $diagnosis = $client->stockIntegrator()->diagnoseStockProductMismatch();
            
            wp_send_json_success([
                'message' => 'Stock diagnosis completed',
                'diagnosis' => $diagnosis,
                'summary' => sprintf(
                    'Found %d WooCommerce products and %d stock items. Matches: %d/%d',
                    $diagnosis['analysis']['total_wc_products'] ?? 0,
                    $diagnosis['analysis']['total_stock_items'] ?? 0,
                    $diagnosis['analysis']['matches_found'] ?? 0,
                    $diagnosis['analysis']['total_stock_items'] ?? 0
                )
            ]);
        } catch (Exception $e) {
            wp_send_json_error('Stock diagnosis failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Get customer statistics
     */
    public static function get_customer_stats() {
        self::verify_request();
        
        try {
            $customerSync = WC_WMS_Service_Container::getCustomerSync();
            $stats = $customerSync->get_sync_statistics();
            
            wp_send_json_success($stats);
        } catch (Exception $e) {
            wp_send_json_error('Failed to get customer stats: ' . $e->getMessage());
        }
    }
    
    /**
     * Register webhooks
     */
    public static function register_webhooks() {
        self::verify_request();
        
        // Check if initial sync is completed before allowing webhook registration
        if (!get_option('wc_wms_initial_sync_completed', false)) {
            wp_send_json_error([
                'message' => 'Webhook registration is disabled until initial sync is completed. Please run "Sync Everything" first.',
                'code' => 'initial_sync_required'
            ]);
            return;
        }
        
        try {
            $client = WC_WMS_Service_Container::getWmsClient();
            $result = $client->webhookIntegrator()->registerAllWebhooks();
            
            if (is_wp_error($result)) {
                wp_send_json_error([
                    'message' => $result->get_error_message(),
                    'code' => $result->get_error_code()
                ]);
            } else {
                // Check if we have any activity (registered or skipped webhooks)
                $has_activity = (count($result['registered']) > 0 || count($result['skipped']) > 0);
                $has_errors = !empty($result['errors']);
                
                // Update webhook registration timestamp and registered webhooks for all successful cases
                if ($has_activity || !$has_errors) {
                    update_option('wc_wms_webhooks_registered_at', current_time('mysql'));
                    if (!empty($result['registered'])) {
                        update_option('wc_wms_registered_webhooks', $result['registered']);
                    }
                }
                
                if ($has_activity && !$has_errors) {
                    wp_send_json_success([
                        'message' => $result['summary'],
                        'registered' => $result['registered'],
                        'skipped' => $result['skipped'],
                        'count' => count($result['registered'])
                    ]);
                } else if ($has_activity && $has_errors) {
                    wp_send_json_success([
                        'message' => $result['summary'] . ' (some webhooks had issues)',
                        'registered' => $result['registered'],
                        'skipped' => $result['skipped'],
                        'errors' => $result['errors'],
                        'partial_success' => true,
                        'count' => count($result['registered'])
                    ]);
                } else if (!$has_activity && !$has_errors) {
                    wp_send_json_success([
                        'message' => 'All webhooks are already registered with WMS',
                        'registered' => $result['registered'],
                        'skipped' => $result['skipped']
                    ]);
                } else {
                    wp_send_json_error([
                        'message' => 'Failed to register webhooks: ' . implode(', ', $result['errors']),
                        'errors' => $result['errors'],
                        'registered' => $result['registered']
                    ]);
                }
            }
        } catch (Exception $e) {
            wp_send_json_error('Webhook registration failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Check webhook status
     */
    public static function check_webhook_status() {
        self::verify_request();
        
        try {
            $client = WC_WMS_Service_Container::getWmsClient();
            $status = $client->webhookIntegrator()->getWebhookRegistrationStatus();
            
            wp_send_json_success($status);
        } catch (Exception $e) {
            wp_send_json_error('Failed to check webhook status: ' . $e->getMessage());
        }
    }
    
    /**
     * Delete all webhooks
     */
    public static function delete_all_webhooks() {
        self::verify_request();
        
        try {
            $client = WC_WMS_Service_Container::getWmsClient();
            $result = $client->webhookIntegrator()->deleteAllWebhooks();
            
            if (is_wp_error($result)) {
                wp_send_json_error([
                    'message' => $result->get_error_message(),
                    'code' => $result->get_error_code()
                ]);
            } else {
                wp_send_json_success($result);
            }
        } catch (Exception $e) {
            wp_send_json_error('Failed to delete webhooks: ' . $e->getMessage());
        }
    }
    
    /**
     * Validate webhook config
     */
    public static function validate_webhook_config() {
        self::verify_request();
        
        try {
            $client = WC_WMS_Service_Container::getWmsClient();
            $validation = $client->webhookIntegrator()->validateWebhookConfiguration();
            
            wp_send_json_success($validation);
        } catch (Exception $e) {
            wp_send_json_error('Webhook config validation failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Generate webhook secret
     */
    public static function generate_webhook_secret() {
        self::verify_request();
        
        try {
            $client = WC_WMS_Service_Container::getWmsClient();
            $secret = $client->webhooks()->generateWebhookSecret();
            
            wp_send_json_success([
                'message' => 'New webhook secret generated',
                'secret_length' => strlen($secret)
            ]);
        } catch (Exception $e) {
            wp_send_json_error('Failed to generate webhook secret: ' . $e->getMessage());
        }
    }
    
    /**
     * Sync location types from WMS
     */
    public static function sync_location_types() {
        self::verify_request();
        
        // Check if initial sync is completed before allowing manual location type sync
        if (!get_option('wc_wms_initial_sync_completed', false)) {
            wp_send_json_error([
                'message' => 'Location type sync is disabled until initial sync is completed. Please run "Sync Everything" first.',
                'code' => 'initial_sync_required'
            ]);
            return;
        }
        
        try {
            $client = WC_WMS_Service_Container::getWmsClient();
            $result = $client->locationTypes()->syncLocationTypes();
            
            if ($result['success']) {
                wp_send_json_success([
                    'message' => sprintf(
                        'Successfully synced %d location types (%d pickable, %d transport)',
                        $result['total_count'],
                        $result['pickable_count'],
                        $result['transport_count']
                    ),
                    'result' => $result
                ]);
            } else {
                wp_send_json_error([
                    'message' => 'Failed to sync location types',
                    'result' => $result
                ]);
            }
        } catch (Exception $e) {
            wp_send_json_error('Location types sync failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Get location types
     */
    public static function get_location_types() {
        self::verify_request();
        
        try {
            $client = WC_WMS_Service_Container::getWmsClient();
            
            // Get filter parameters
            $pickable_only = isset($_POST['pickable_only']) && $_POST['pickable_only'] === 'true';
            $code = sanitize_text_field($_POST['code'] ?? '');
            
            $criteria = [];
            if ($pickable_only) {
                $criteria['pickable'] = true;
            }
            if (!empty($code)) {
                $criteria['code'] = $code;
            }
            
            $locationTypes = empty($criteria) ? 
                $client->locationTypes()->getLocationTypes() : 
                $client->locationTypes()->searchLocationTypes($criteria);
            
            wp_send_json_success([
                'location_types' => $locationTypes,
                'count' => count($locationTypes),
                'filters_applied' => $criteria
            ]);
        } catch (Exception $e) {
            wp_send_json_error('Failed to get location types: ' . $e->getMessage());
        }
    }
    
    /**
     * Get location type statistics
     */
    public static function get_location_type_stats() {
        self::verify_request();
        
        try {
            $client = WC_WMS_Service_Container::getWmsClient();
            $stats = $client->locationTypes()->getLocationTypeStatistics();
            
            wp_send_json_success($stats);
        } catch (Exception $e) {
            wp_send_json_error('Failed to get location type stats: ' . $e->getMessage());
        }
    }
    
    /**
     * Sync shipping methods from WMS
     */
    public static function sync_shipping_methods() {
        self::verify_request();
        
        // Check if initial sync is completed before allowing manual shipping method sync
        if (!get_option('wc_wms_initial_sync_completed', false)) {
            wp_send_json_error([
                'message' => 'Shipping method sync is disabled until initial sync is completed. Please run "Sync Everything" first.',
                'code' => 'initial_sync_required'
            ]);
            return;
        }
        
        try {
            $client = WC_WMS_Service_Container::getWmsClient();
            $result = $client->shipmentIntegrator()->syncShippingMethods();
            
            if (is_wp_error($result)) {
                wp_send_json_error([
                    'message' => $result->get_error_message(),
                    'code' => $result->get_error_code()
                ]);
            } else {
                // Update shipping methods sync timestamp
                update_option('wc_wms_shipping_methods_synced_at', current_time('mysql'));
                
                wp_send_json_success([
                    'message' => sprintf('Successfully synced %d shipping methods from WMS', $result['count'] ?? 0),
                    'synced' => $result['count'] ?? 0,
                    'methods' => $result['methods'] ?? [],
                    'synced_at' => current_time('mysql')
                ]);
            }
        } catch (Exception $e) {
            wp_send_json_error('Shipping methods sync failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Get shipping methods (both WMS and WooCommerce)
     */
    public static function get_shipping_methods() {
        self::verify_request();
        
        try {
            $client = WC_WMS_Service_Container::getWmsClient();
            
            // Get WMS shipping methods
            $wmsShippingMethods = get_option('wc_wms_shipping_methods', []);
            
            // Get WooCommerce shipping methods
            $wcShippingMethods = [];
            $shipping_zones = WC_Shipping_Zones::get_zones();
            
            foreach ($shipping_zones as $zone) {
                foreach ($zone['shipping_methods'] as $method) {
                    $wcShippingMethods[] = [
                        'id' => $method->get_id(),
                        'instance_id' => $method->get_instance_id(),
                        'title' => $method->get_title(),
                        'method_title' => $method->get_method_title(),
                        'zone_id' => $zone['zone_id'],
                        'zone_name' => $zone['zone_name']
                    ];
                }
            }
            
            // Get current mappings
            $currentMappings = get_option('wc_wms_shipping_method_uuid_mapping', []);
            $defaultShippingMethod = get_option('wc_wms_default_shipping_method_uuid', '');
            
            wp_send_json_success([
                'wms_methods' => $wmsShippingMethods,
                'wc_methods' => $wcShippingMethods,
                'current_mappings' => $currentMappings,
                'default_method' => $defaultShippingMethod,
                'last_synced' => get_option('wc_wms_shipping_methods_synced_at', '')
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error('Failed to get shipping methods: ' . $e->getMessage());
        }
    }
    
    /**
     * Save shipping method mappings
     */
    public static function save_shipping_mappings() {
        self::verify_request();
        
        try {
            $mappings = [];
            if (!empty($_POST['mappings'])) {
                $rawMappings = json_decode(stripslashes($_POST['mappings']), true);
                if (is_array($rawMappings)) {
                    foreach ($rawMappings as $wcMethod => $wmsMethod) {
                        $mappings[sanitize_text_field($wcMethod)] = sanitize_text_field($wmsMethod);
                    }
                }
            }
            
            $defaultMethod = sanitize_text_field($_POST['default_method'] ?? '');
            
            // Save mappings
            update_option('wc_wms_shipping_method_uuid_mapping', $mappings);
            
            if (!empty($defaultMethod)) {
                update_option('wc_wms_default_shipping_method_uuid', $defaultMethod);
            }
            
            // Log the update
            $client = WC_WMS_Service_Container::getWmsClient();
            $client->logger()->info('Shipping method mappings updated', [
                'mappings_count' => count($mappings),
                'default_method' => $defaultMethod
            ]);
            
            wp_send_json_success([
                'message' => sprintf('Saved %d shipping method mappings', count($mappings)),
                'mappings_count' => count($mappings),
                'default_method' => $defaultMethod,
                'saved_at' => current_time('mysql')
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error('Failed to save shipping mappings: ' . $e->getMessage());
        }
    }
    
    /**
     * Get inbounds with filtering
     */
    public static function get_inbounds() {
        self::verify_request();
        
        try {
            $inboundService = WC_WMS_Service_Container::getInboundService();
            
            $params = [
                'limit' => intval($_POST['limit'] ?? 10),
                'page' => intval($_POST['page'] ?? 1)
            ];
            
            // Add optional filters
            if (!empty($_POST['status'])) {
                $params['status'] = sanitize_text_field($_POST['status']);
            }
            if (!empty($_POST['from'])) {
                $params['from'] = sanitize_text_field($_POST['from']);
            }
            if (!empty($_POST['to'])) {
                $params['to'] = sanitize_text_field($_POST['to']);
            }
            if (!empty($_POST['reference'])) {
                $params['reference'] = sanitize_text_field($_POST['reference']);
            }
            
            $inbounds = $inboundService->getInbounds($params);
            
            if (is_wp_error($inbounds)) {
                wp_send_json_error([
                    'message' => $inbounds->get_error_message(),
                    'code' => $inbounds->get_error_code()
                ]);
            }
            
            wp_send_json_success($inbounds);
            
        } catch (Exception $e) {
            wp_send_json_error('Failed to fetch inbounds: ' . $e->getMessage());
        }
    }
    
    /**
     * Get inbound details
     */
    public static function get_inbound_details() {
        self::verify_request();
        
        $inboundId = sanitize_text_field($_POST['inbound_id'] ?? '');
        
        if (empty($inboundId)) {
            wp_send_json_error('Inbound ID is required');
        }
        
        try {
            $inboundService = WC_WMS_Service_Container::getInboundService();
            $inbound = $inboundService->getInbound($inboundId);
            
            if (is_wp_error($inbound)) {
                wp_send_json_error([
                    'message' => $inbound->get_error_message(),
                    'code' => $inbound->get_error_code()
                ]);
            }
            
            wp_send_json_success($inbound);
            
        } catch (Exception $e) {
            wp_send_json_error('Failed to fetch inbound details: ' . $e->getMessage());
        }
    }
    
    /**
     * Create new inbound
     */
    public static function create_inbound() {
        self::verify_request();
        
        try {
            $inboundData = [
                'external_reference' => sanitize_text_field($_POST['external_reference'] ?? ''),
                'inbound_date' => sanitize_text_field($_POST['inbound_date'] ?? ''),
                'note' => sanitize_textarea_field($_POST['note'] ?? ''),
                'is_return' => !empty($_POST['is_return']) && $_POST['is_return'] == 1
            ];
            
            // Parse inbound lines
            $inboundLines = json_decode(stripslashes($_POST['inbound_lines'] ?? '[]'), true);
            
            if (empty($inboundLines)) {
                wp_send_json_error('At least one inbound line is required');
            }
            
            // Sanitize inbound lines
            $sanitizedLines = [];
            foreach ($inboundLines as $line) {
                $sanitizedLines[] = [
                    'article_code' => sanitize_text_field($line['article_code'] ?? ''),
                    'quantity' => intval($line['quantity'] ?? 0),
                    'packing_slip' => intval($line['packing_slip'] ?? $line['quantity'] ?? 0)
                ];
            }
            
            $inboundData['inbound_lines'] = $sanitizedLines;
            
            $inboundService = WC_WMS_Service_Container::getInboundService();
            $result = $inboundService->createInbound($inboundData);
            
            if (is_wp_error($result)) {
                wp_send_json_error([
                    'message' => $result->get_error_message(),
                    'code' => $result->get_error_code()
                ]);
            }
            
            wp_send_json_success([
                'message' => 'Inbound created successfully',
                'inbound' => $result,
                'reference' => $result['reference'] ?? 'N/A'
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error('Failed to create inbound: ' . $e->getMessage());
        }
    }
    
    /**
     * Update inbound
     */
    public static function update_inbound() {
        self::verify_request();
        
        $inboundId = sanitize_text_field($_POST['inbound_id'] ?? '');
        
        if (empty($inboundId)) {
            wp_send_json_error('Inbound ID is required');
        }
        
        try {
            $updateData = [];
            
            if (!empty($_POST['note'])) {
                $updateData['note'] = sanitize_textarea_field($_POST['note']);
            }
            if (!empty($_POST['inbound_date'])) {
                $updateData['inbound_date'] = sanitize_text_field($_POST['inbound_date']);
            }
            
            if (empty($updateData)) {
                wp_send_json_error('No data to update');
            }
            
            $inboundService = WC_WMS_Service_Container::getInboundService();
            $result = $inboundService->updateInbound($inboundId, $updateData);
            
            if (is_wp_error($result)) {
                wp_send_json_error([
                    'message' => $result->get_error_message(),
                    'code' => $result->get_error_code()
                ]);
            }
            
            wp_send_json_success([
                'message' => 'Inbound updated successfully',
                'inbound' => $result
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error('Failed to update inbound: ' . $e->getMessage());
        }
    }
    
    /**
     * Cancel inbound
     */
    public static function cancel_inbound() {
        self::verify_request();
        
        $inboundId = sanitize_text_field($_POST['inbound_id'] ?? '');
        
        if (empty($inboundId)) {
            wp_send_json_error('Inbound ID is required');
        }
        
        try {
            $inboundService = WC_WMS_Service_Container::getInboundService();
            $result = $inboundService->cancelInbound($inboundId);
            
            if (is_wp_error($result)) {
                wp_send_json_error([
                    'message' => $result->get_error_message(),
                    'code' => $result->get_error_code()
                ]);
            }
            
            wp_send_json_success([
                'message' => 'Inbound cancelled successfully',
                'inbound' => $result
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error('Failed to cancel inbound: ' . $e->getMessage());
        }
    }
    
    /**
     * Get inbound statistics
     */
    public static function get_inbound_stats() {
        self::verify_request();
        
        try {
            $days = intval($_POST['days'] ?? 30);
            
            $inboundService = WC_WMS_Service_Container::getInboundService();
            $stats = $inboundService->getInboundStats($days);
            
            wp_send_json_success($stats);
            
        } catch (Exception $e) {
            wp_send_json_error('Failed to get inbound stats: ' . $e->getMessage());
        }
    }
    
    /**
     * Sync inbounds from WMS
     */
    public static function sync_inbounds() {
        self::verify_request();
        
        try {
            $inboundService = WC_WMS_Service_Container::getInboundService();
            
            // Get recent inbounds from WMS (last 30 days by default)
            $params = [
                'limit' => 100, // Sync more inbounds at once
                'from' => date('Y-m-d', strtotime('-30 days')), // Last 30 days
                'sort' => 'inboundDate',
                'direction' => 'desc'
            ];
            
            $inbounds = $inboundService->getInbounds($params);
            
            if (is_wp_error($inbounds)) {
                wp_send_json_error([
                    'message' => 'Failed to fetch inbounds from WMS: ' . $inbounds->get_error_message(),
                    'code' => $inbounds->get_error_code()
                ]);
                return;
            }
            
            // Process and count the inbounds
            $total_synced = count($inbounds);
            $new_inbounds = 0;
            $updated_inbounds = 0;
            $completed_inbounds = 0;
            
            foreach ($inbounds as $inbound) {
                // Count by status
                if (isset($inbound['status'])) {
                    if ($inbound['status'] === 'completed') {
                        $completed_inbounds++;
                    }
                }
                
                // Check if this is a new or updated inbound
                // For simplicity, we'll count all as "synced" since this is primarily
                // for tracking and visibility rather than local storage
                if (isset($inbound['inbound_date'])) {
                    $inbound_date = strtotime($inbound['inbound_date']);
                    $recent_threshold = strtotime('-7 days');
                    
                    if ($inbound_date > $recent_threshold) {
                        $new_inbounds++;
                    } else {
                        $updated_inbounds++;
                    }
                }
            }
            
            // Update sync tracking options
            update_option('wc_wms_inbounds_last_sync', time());
            update_option('wc_wms_inbounds_synced_count', $total_synced);
            
            // Prepare success response
            $response = [
                'message' => 'Successfully synced inbounds from WMS',
                'total_synced' => $total_synced,
                'new_inbounds' => $new_inbounds,
                'updated_inbounds' => $updated_inbounds,
                'completed_inbounds' => $completed_inbounds,
                'sync_timestamp' => time()
            ];
            
            wp_send_json_success($response);
            
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => 'Failed to sync inbounds: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Sync shipments from WMS
     */
    public static function sync_shipments() {
        self::verify_request();
        
        try {
            $wmsClient = WC_WMS_Service_Container::getWmsClient();
            $shipmentIntegrator = $wmsClient->shipmentIntegrator();
            
            // Get recent shipments from WMS (last 3 days by default)
            $params = [
                'limit' => 100,
                'from' => date('Y-m-d', strtotime('-3 days')),
                'sort' => 'created_at',
                'direction' => 'desc'
            ];
            
            $shipments = $shipmentIntegrator->getShipments($params);
            
            if (is_wp_error($shipments)) {
                wp_send_json_error([
                    'message' => 'Failed to fetch shipments from WMS: ' . $shipments->get_error_message(),
                    'code' => $shipments->get_error_code()
                ]);
                return;
            }
            
            // Process shipments and update related orders
            $total_synced = count($shipments);
            $orders_updated = 0;
            $tracking_numbers_added = 0;
            
            foreach ($shipments as $shipment) {
                try {
                    // Use the webhook processor to update orders
                    $result = $shipmentIntegrator->processShipmentWebhook($shipment);
                    if ($result['success']) {
                        $orders_updated++;
                        if (!empty($shipment['tracking_number'])) {
                            $tracking_numbers_added++;
                        }
                    }
                } catch (Exception $e) {
                    // Log error but continue processing
                    error_log('Shipment sync error: ' . $e->getMessage());
                }
            }
            
            // Update sync tracking options
            update_option('wc_wms_shipments_last_sync', time());
            update_option('wc_wms_shipments_synced_count', $total_synced);
            
            // Prepare success response
            $response = [
                'message' => 'Successfully synced shipments from WMS',
                'total_synced' => $total_synced,
                'orders_updated' => $orders_updated,
                'tracking_numbers_added' => $tracking_numbers_added,
                'sync_timestamp' => time(),
                'sync_period' => '3 days'
            ];
            
            wp_send_json_success($response);
            
        } catch (Exception $e) {
            wp_send_json_error([
                'message' => 'Failed to sync shipments: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Check logging security
     */
    public static function check_logging_security() {
        self::verify_request();
        
        try {
            $client = WC_WMS_Service_Container::getWmsClient();
            $security_check = $client->webhookIntegrator()->checkLoggingSecurity();
            
            wp_send_json_success($security_check);
        } catch (Exception $e) {
            wp_send_json_error('Security check failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Reset initial sync flag (for testing/debugging)
     */
    public static function reset_initial_sync() {
        self::verify_request();
        
        try {
            // Reset the initial sync flag
            delete_option('wc_wms_initial_sync_completed');
            delete_option('wc_wms_initial_sync_completed_at');
            
            // Log the reset
            error_log('WMS Integration: Initial sync flag reset - automatic processes disabled until next sync');
            
            wp_send_json_success([
                'message' => 'Initial sync flag reset successfully. Automatic processes are now disabled until next sync.',
                'reset_at' => current_time('mysql')
            ]);
            
        } catch (Exception $e) {
            wp_send_json_error('Failed to reset initial sync flag: ' . $e->getMessage());
        }
    }
    
    /**
     * Verify AJAX request (authentication and authorization)
     */
    private static function verify_request() {
        check_admin_referer('wc_wms_admin_actions', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access');
            wp_die();
        }
    }
}
