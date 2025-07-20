<?php
/**
 * Synchronization tab template
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Global database access
global $wpdb;
?>

<div id="synchronization-tab" class="tab-content" style="display: none;">
    <h2><?php _e('🔄 Synchronization', 'wc-wms-integration'); ?></h2>
    <p class="description"><?php _e('Import products and stock from WMS to keep your WooCommerce store up to date. WMS is the source of truth.', 'wc-wms-integration'); ?></p>
    
    <!-- Initial Sync Status -->
    <div class="initial-sync-status" style="margin: 20px 0;">
        <?php
        $initial_sync_completed = WC_WMS_Admin_Manager::isInitialSyncCompleted();
        $initial_sync_time = WC_WMS_Admin_Manager::getInitialSyncCompletedAt();
        
        if ($initial_sync_completed) {
            echo '<div class="notice notice-success inline" style="margin: 0; padding: 10px;">';
            echo '<p><strong>✅ Initial Sync Completed</strong><br>';
            if ($initial_sync_time) {
                echo 'Completed on: ' . date('Y-m-d H:i:s', strtotime($initial_sync_time)) . '<br>';
            }
            echo 'Automatic synchronization processes are now active.</p>';
            echo '</div>';
        } else {
            echo '<div class="notice notice-warning inline" style="margin: 0; padding: 10px;">';
            echo '<p><strong>⚠️ Initial Sync Required</strong><br>';
            echo 'Automatic processes (webhooks, orders, stock sync) are disabled until you complete the initial sync.<br>';
            echo 'Click "Sync Everything" below to enable automatic synchronization.</p>';
            echo '</div>';
        }
        ?>
    </div>
    
    <!-- Sync Status Cards -->
    <div class="sync-status-cards" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin: 20px 0;">
        
        <!-- Shipping Methods Status -->
        <div class="sync-card" style="border: 1px solid #ddd; padding: 15px; border-radius: 5px; background: #f9f9f9;">
            <h4 style="margin: 0 0 10px 0;"><?php _e('🚚 Shipping Methods', 'wc-wms-integration'); ?></h4>
            <?php 
            $shipping_sync_time = get_option('wc_wms_shipping_methods_synced_at', 0);
            $shipping_methods = get_option('wc_wms_shipping_methods', []);
            if ($shipping_sync_time) {
                $hours_ago = round((time() - strtotime($shipping_sync_time)) / 3600, 1);
                if ($hours_ago < 1) {
                    $status = '✅ Synced ' . round($hours_ago * 60) . ' minutes ago';
                } elseif ($hours_ago < 24) {
                    $status = ($hours_ago < 2) ? '✅ Synced ' . $hours_ago . ' hours ago' : '⚠️ Synced ' . $hours_ago . ' hours ago';
                } else {
                    $status = '❌ Synced ' . round($hours_ago / 24, 1) . ' days ago';
                }
                $count = count($shipping_methods);
                echo '<p>' . $status . '<br><small>' . $count . ' methods available</small></p>';
            } else {
                echo '<p>❌ Never synced</p>';
            }
            ?>
            <button type="button" class="button" onclick="syncShippingMethods()"><?php _e('Sync Now', 'wc-wms-integration'); ?></button>
        </div>
        
        <!-- Location Types Status -->
        <div class="sync-card" style="border: 1px solid #ddd; padding: 15px; border-radius: 5px; background: #f9f9f9;">
            <h4 style="margin: 0 0 10px 0;"><?php _e('🏢 Location Types', 'wc-wms-integration'); ?></h4>
            <?php 
            $location_sync_time = get_option('wc_wms_location_types_synced_at', 0);
            $location_types = get_option('wc_wms_location_types', []);
            $pickable_types = get_option('wc_wms_pickable_location_types', []);
            $transport_types = get_option('wc_wms_transport_location_types', []);
            
            if ($location_sync_time) {
                $hours_ago = round((time() - strtotime($location_sync_time)) / 3600, 1);
                if ($hours_ago < 1) {
                    $status = '✅ Synced ' . round($hours_ago * 60) . ' minutes ago';
                } elseif ($hours_ago < 24) {
                    $status = ($hours_ago < 2) ? '✅ Synced ' . $hours_ago . ' hours ago' : '⚠️ Synced ' . $hours_ago . ' hours ago';
                } else {
                    $status = '❌ Synced ' . round($hours_ago / 24, 1) . ' days ago';
                }
                $total_count = count($location_types);
                $pickable_count = count($pickable_types);
                $transport_count = count($transport_types);
                echo '<p>' . $status . '<br><small>' . $total_count . ' types (' . $pickable_count . ' pickable, ' . $transport_count . ' transport)</small></p>';
            } else {
                echo '<p>❌ Never synced</p>';
            }
            ?>
            <button type="button" class="button" onclick="syncLocationTypes()"><?php _e('Sync Now', 'wc-wms-integration'); ?></button>
        </div>
        
        <!-- Stock Status -->
        <div class="sync-card" style="border: 1px solid #ddd; padding: 15px; border-radius: 5px; background: #f9f9f9;">
            <h4 style="margin: 0 0 10px 0;"><?php _e('📦 Product Stock', 'wc-wms-integration'); ?></h4>
            <?php 
            $stock_sync_time = get_option('wc_wms_stock_synced_at', '');
            if ($stock_sync_time) {
                $stock_timestamp = strtotime($stock_sync_time);
                $hours_ago = round((time() - $stock_timestamp) / 3600, 1);
                if ($hours_ago < 1) {
                    $status = '✅ Synced ' . round($hours_ago * 60) . ' minutes ago';
                } elseif ($hours_ago < 24) {
                    $status = ($hours_ago < 2) ? '✅ Synced ' . $hours_ago . ' hours ago' : '⚠️ Synced ' . $hours_ago . ' hours ago';
                } else {
                    $status = '❌ Synced ' . round($hours_ago / 24, 1) . ' days ago';
                }
                echo '<p>' . $status . '</p>';
            } else {
                echo '<p>❌ Never synced</p>';
            }
            ?>
            <button type="button" class="button" onclick="syncAllStock()"><?php _e('Sync Now', 'wc-wms-integration'); ?></button>
            <p class="description"><small>🕐 Auto-sync: Every hour</small></p>
        </div>
        
        <!-- Orders Status -->
        <div class="sync-card" style="border: 1px solid #ddd; padding: 15px; border-radius: 5px; background: #f9f9f9;">
            <h4 style="margin: 0 0 10px 0;"><?php _e('📋 Order Queue', 'wc-wms-integration'); ?></h4>
            <?php 
            $table_name = $wpdb->prefix . 'wc_wms_integration_queue';
            $pending_orders = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'pending'");
            $failed_orders = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'failed'");
            
            if ($failed_orders > 0) {
                echo '<p>❌ ' . $failed_orders . ' failed orders<br><small>' . $pending_orders . ' pending orders</small></p>';
            } elseif ($pending_orders > 0) {
                echo '<p>⚠️ ' . $pending_orders . ' pending orders</p>';
            } else {
                echo '<p>✅ No pending orders</p>';
            }
            ?>
            <button type="button" class="button" onclick="retryFailedOrders()"><?php _e('Retry Failed', 'wc-wms-integration'); ?></button>
        </div>
        
        <!-- Articles Import Status -->
        <div class="sync-card" style="border: 1px solid #ddd; padding: 15px; border-radius: 5px; background: #f9f9f9;">
            <h4 style="margin: 0 0 10px 0;"><?php _e('📦 Articles from WMS', 'wc-wms-integration'); ?></h4>
            <?php 
            $import_sync_time = get_option('wc_wms_products_exported_at', '');
            if ($import_sync_time) {
                $import_timestamp = strtotime($import_sync_time);
                $hours_ago = round((time() - $import_timestamp) / 3600, 1);
                if ($hours_ago < 1) {
                    $status = '✅ Imported ' . round($hours_ago * 60) . ' minutes ago';
                } elseif ($hours_ago < 24) {
                    $status = ($hours_ago < 2) ? '✅ Imported ' . $hours_ago . ' hours ago' : '⚠️ Imported ' . $hours_ago . ' hours ago';
                } else {
                    $status = '❌ Imported ' . round($hours_ago / 24, 1) . ' days ago';
                }
                echo '<p>' . $status . '</p>';
            } else {
                echo '<p>❌ Never imported</p>';
            }
            ?>
            <button type="button" class="button" onclick="importAllArticles()"><?php _e('Import from WMS', 'wc-wms-integration'); ?></button>
        </div>
        
        <!-- Customer Import Status -->
        <div class="sync-card" style="border: 1px solid #ddd; padding: 15px; border-radius: 5px; background: #f9f9f9;">
            <h4 style="margin: 0 0 10px 0;"><?php _e('👥 Customer Import', 'wc-wms-integration'); ?></h4>
            <p style="margin: 5px 0; font-size: 12px; color: #666;"><em><?php _e('Note: WMS Customers API is read-only. Import only.', 'wc-wms-integration'); ?></em></p>
            <?php 
            $customer_import_time = get_option('wc_wms_customers_last_import', 0);
            
            if ($customer_import_time) {
                $hours_ago = round((time() - $customer_import_time) / 3600, 1);
                if ($hours_ago < 1) {
                    $status = '✅ Imported ' . round($hours_ago * 60) . ' minutes ago';
                } elseif ($hours_ago < 24) {
                    $status = ($hours_ago < 2) ? '✅ Imported ' . $hours_ago . ' hours ago' : '⚠️ Imported ' . $hours_ago . ' hours ago';
                } else {
                    $status = '❌ Imported ' . round($hours_ago / 24, 1) . ' days ago';
                }
                echo '<p>' . $status . '</p>';
            } else {
                echo '<p>❌ Never imported</p>';
            }
            ?>
            <div style="margin-top: 10px;">
                <button type="button" class="button" onclick="importCustomers()" style="margin-right: 5px;"><?php _e('Import from WMS', 'wc-wms-integration'); ?></button>
                <button type="button" class="button button-secondary" onclick="getCustomerStats()"><?php _e('View Stats', 'wc-wms-integration'); ?></button>
            </div>
        </div>
        
        <!-- Order Sync Status -->
        <div class="sync-card" style="border: 1px solid #ddd; padding: 15px; border-radius: 5px; background: #f9f9f9;">
            <h4 style="margin: 0 0 10px 0;"><?php _e('📋 Order Sync', 'wc-wms-integration'); ?></h4>
            <p style="margin: 5px 0; font-size: 12px; color: #666;"><em><?php _e('Sync orders from WMS to WooCommerce for complete order history.', 'wc-wms-integration'); ?></em></p>
            <?php 
            $order_sync_time = get_option('wc_wms_orders_last_sync', 0);
            
            if ($order_sync_time) {
                $hours_ago = round((time() - $order_sync_time) / 3600, 1);
                if ($hours_ago < 1) {
                    $status = '✅ Synced ' . round($hours_ago * 60) . ' minutes ago';
                } elseif ($hours_ago < 24) {
                    $status = ($hours_ago < 2) ? '✅ Synced ' . $hours_ago . ' hours ago' : '⚠️ Synced ' . $hours_ago . ' hours ago';
                } else {
                    $status = '❌ Synced ' . round($hours_ago / 24, 1) . ' days ago';
                }
                echo '<p>' . $status . '</p>';
            } else {
                echo '<p>❌ Never synced</p>';
            }
            ?>
            <button type="button" class="button" onclick="syncOrders()"><?php _e('Sync Orders Now', 'wc-wms-integration'); ?></button>
        </div>
        
        <!-- Inbound Sync Status -->
        <div class="sync-card" style="border: 1px solid #ddd; padding: 15px; border-radius: 5px; background: #f9f9f9;">
            <h4 style="margin: 0 0 10px 0;"><?php _e('📦 Inbound Sync', 'wc-wms-integration'); ?></h4>
            <p style="margin: 5px 0; font-size: 12px; color: #666;"><em><?php _e('Sync inbounds from WMS to track inventory receipts and stock updates.', 'wc-wms-integration'); ?></em></p>
            <?php 
            $inbound_sync_time = get_option('wc_wms_inbounds_last_sync', 0);
            $inbound_count = get_option('wc_wms_inbounds_synced_count', 0);
            
            if ($inbound_sync_time) {
                $hours_ago = round((time() - $inbound_sync_time) / 3600, 1);
                if ($hours_ago < 1) {
                    $status = '✅ Synced ' . round($hours_ago * 60) . ' minutes ago';
                } elseif ($hours_ago < 24) {
                    $status = ($hours_ago < 2) ? '✅ Synced ' . $hours_ago . ' hours ago' : '⚠️ Synced ' . $hours_ago . ' hours ago';
                } else {
                    $status = '❌ Synced ' . round($hours_ago / 24, 1) . ' days ago';
                }
                echo '<p>' . $status . '<br><small>' . $inbound_count . ' inbounds synced</small></p>';
            } else {
                echo '<p>❌ Never synced</p>';
            }
            ?>
            <button type="button" class="button" onclick="syncInbounds()"><?php _e('Sync Inbounds Now', 'wc-wms-integration'); ?></button>
            <p class="description"><small>🕐 Auto-sync: Every 4 hours</small></p>
        </div>
        
        <!-- Order Sync Status (Cron) -->
        <div class="sync-card" style="border: 1px solid #ddd; padding: 15px; border-radius: 5px; background: #f9f9f9;">
            <h4 style="margin: 0 0 10px 0;"><?php _e('📋 Order Sync (Auto)', 'wc-wms-integration'); ?></h4>
            <p style="margin: 5px 0; font-size: 12px; color: #666;"><em><?php _e('Automated sync of orders from WMS to WooCommerce for complete order history.', 'wc-wms-integration'); ?></em></p>
            <?php 
            $order_sync_time = get_option('wc_wms_orders_last_sync', 0);
            
            if ($order_sync_time) {
                $hours_ago = round((time() - $order_sync_time) / 3600, 1);
                if ($hours_ago < 1) {
                    $status = '✅ Synced ' . round($hours_ago * 60) . ' minutes ago';
                } elseif ($hours_ago < 24) {
                    $status = ($hours_ago < 2) ? '✅ Synced ' . $hours_ago . ' hours ago' : '⚠️ Synced ' . $hours_ago . ' hours ago';
                } else {
                    $status = '❌ Synced ' . round($hours_ago / 24, 1) . ' days ago';
                }
                echo '<p>' . $status . '</p>';
            } else {
                echo '<p>❌ Never synced</p>';
            }
            ?>
            <button type="button" class="button" onclick="syncOrders()"><?php _e('Sync Orders Now', 'wc-wms-integration'); ?></button>
            <p class="description"><small>🕐 Auto-sync: Every 2 hours</small></p>
        </div>
        
        <!-- Shipment Sync Status -->
        <div class="sync-card" style="border: 1px solid #ddd; padding: 15px; border-radius: 5px; background: #f9f9f9;">
            <h4 style="margin: 0 0 10px 0;"><?php _e('🚚 Shipment Sync', 'wc-wms-integration'); ?></h4>
            <p style="margin: 5px 0; font-size: 12px; color: #666;"><em><?php _e('Sync shipments from WMS to update order tracking information.', 'wc-wms-integration'); ?></em></p>
            <?php 
            $shipment_sync_time = get_option('wc_wms_shipments_last_sync', 0);
            $shipment_count = get_option('wc_wms_shipments_synced_count', 0);
            
            if ($shipment_sync_time) {
                $hours_ago = round((time() - $shipment_sync_time) / 3600, 1);
                if ($hours_ago < 1) {
                    $status = '✅ Synced ' . round($hours_ago * 60) . ' minutes ago';
                } elseif ($hours_ago < 24) {
                    $status = ($hours_ago < 2) ? '✅ Synced ' . $hours_ago . ' hours ago' : '⚠️ Synced ' . $hours_ago . ' hours ago';
                } else {
                    $status = '❌ Synced ' . round($hours_ago / 24, 1) . ' days ago';
                }
                echo '<p>' . $status . '<br><small>' . $shipment_count . ' shipments synced</small></p>';
            } else {
                echo '<p>❌ Never synced</p>';
            }
            ?>
            <button type="button" class="button" onclick="syncShipments()"><?php _e('Sync Shipments Now', 'wc-wms-integration'); ?></button>
            <p class="description"><small>🕐 Auto-sync: Every 3 hours</small></p>
        </div>
        
    </div>
    
    <!-- Master Import Button -->
    <div style="text-align: center; margin: 20px 0;">
        <?php 
        $initial_sync_completed = WC_WMS_Admin_Manager::isInitialSyncCompleted();
        
        if ($initial_sync_completed) {
            // Show regular import button after initial sync
            ?>
            <button type="button" class="button button-primary sync-everything-btn" onclick="importEverything()" style="font-size: 16px; padding: 10px 20px;">
                <?php _e('🔄 Import Everything from WMS', 'wc-wms-integration'); ?>
            </button>
            <p class="description"><?php _e('Imports shipping methods, articles, customers, orders, inbounds, shipments, and stock levels from WMS to WooCommerce, then tests connection', 'wc-wms-integration'); ?></p>
            <?php
        } else {
            // Show prominent initial sync button
            ?>
            <button type="button" class="button button-primary sync-everything-btn" onclick="importEverything()" style="font-size: 18px; padding: 15px 30px; background: #ff6b00; border-color: #ff6b00; box-shadow: 0 0 10px rgba(255, 107, 0, 0.3);">
                <?php _e('🚀 Complete Initial Sync - Enable Automation', 'wc-wms-integration'); ?>
            </button>
            <p class="description" style="font-weight: bold; color: #ff6b00;"><?php _e('⚠️ This will enable automatic synchronization processes (webhooks, orders, stock sync). Required before normal operations.', 'wc-wms-integration'); ?></p>
            <?php
        }
        ?>
    </div>
</div>
