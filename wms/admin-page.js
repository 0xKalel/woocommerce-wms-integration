

// Diagnose stock sync issues
function diagnoseStockMismatch() {
    var button = event.target;
    var originalText = button.textContent;
    setButtonLoading(button, true, originalText);
    
    // Add progress indicator
    var progressDiv = createProgressIndicator(button, 'Analyzing stock sync issues...');
    
    jQuery.post(ajaxurl, {
        action: 'wc_wms_diagnose_stock_mismatch',
        nonce: (typeof WC_WMS_ADMIN_NONCE !== 'undefined' ? WC_WMS_ADMIN_NONCE : '')
    }, function(response) {
        if (response.success) {
            var diagnosis = response.data.diagnosis;
            var analysis = diagnosis.analysis;
            
            var message = 'Stock Sync Diagnosis Results:\n\n';
            message += 'Summary: ' + response.data.summary + '\n\n';
            
            message += 'WooCommerce Products: ' + analysis.total_wc_products + '\n';
            message += 'WMS Stock Items: ' + analysis.total_stock_items + '\n';
            message += 'Successful Matches: ' + analysis.matches_found + '\n\n';
            
            if (analysis.sku_formats) {
                message += 'SKU Format Examples:\n';
                message += 'WooCommerce: ' + (analysis.sku_formats.wc_sku_examples || []).join(', ') + '\n';
                message += 'WMS Stock: ' + (analysis.sku_formats.stock_sku_examples || []).join(', ') + '\n\n';
            }
            
            if (diagnosis.recommendations && diagnosis.recommendations.length > 0) {
                message += 'Recommendations:\n';
                diagnosis.recommendations.forEach(function(rec, index) {
                    message += (index + 1) + '. ' + rec + '\n';
                });
            }
            
            alert(message);
            updateProgressIndicator(progressDiv, 'success', 'Diagnosis completed');
            
        } else {
            var errorMsg = 'Stock diagnosis failed: ' + (response.data || 'Unknown error');
            alert(errorMsg);
            updateProgressIndicator(progressDiv, 'error', 'Diagnosis failed');
        }
    }).fail(function(xhr, status, error) {
        var errorMsg = 'Diagnosis request failed: ' + error;
        alert(errorMsg);
        updateProgressIndicator(progressDiv, 'error', 'Request failed');
        console.error('Stock diagnosis failed:', xhr.responseText);
    }).always(function() {
        setButtonLoading(button, false, originalText);
        setTimeout(function() {
            if (progressDiv) progressDiv.fadeOut();
        }, 5000);
    });
}

/**
 * WMS Admin Page JavaScript
 */

// Tab navigation
function showTab(tabName) {
    // Hide all tabs
    var tabs = document.querySelectorAll('.tab-content');
    tabs.forEach(function(tab) {
        tab.style.display = 'none';
    });
    
    // Remove active class from all nav tabs
    var navTabs = document.querySelectorAll('.nav-tab');
    navTabs.forEach(function(tab) {
        tab.classList.remove('nav-tab-active');
    });
    
    // Show selected tab
    var targetTab = document.getElementById(tabName + '-tab');
    if (targetTab) {
        targetTab.style.display = 'block';
    }
    
    // Add active class to clicked nav tab
    var navTab = document.querySelector('a[href="#' + tabName + '"]');
    if (navTab) {
        navTab.classList.add('nav-tab-active');
    }
}

// Handle URL hash navigation
window.addEventListener('load', function() {
    var hash = window.location.hash;
    if (hash) {
        var tabName = hash.substring(1);
        var targetTab = document.getElementById(tabName + '-tab');
        if (targetTab) {
            showTab(tabName);
        } else {
            // Default to connection tab if hash tab doesn't exist
            showTab('connection');
        }
    } else {
        // Default to connection tab
        showTab('connection');
    }
});

// Also handle initial page load without waiting for full load event
document.addEventListener('DOMContentLoaded', function() {
    // Only show default tab if no tab is currently visible
    var visibleTabs = document.querySelectorAll('.tab-content[style*="display: block"], .tab-content[style*="display:block"]');
    if (visibleTabs.length === 0) {
        showTab('connection');
    }
});

// Stock sync functionality
function syncAllStock() {
    if (!confirm('This will import stock levels for all products from WMS. Continue?')) {
        return;
    }
    
    var button = event.target;
    var originalText = button.textContent;
    setButtonLoading(button, true, originalText);
    
    // Add progress indicator
    var progressDiv = createProgressIndicator(button, 'Importing stock levels...');
    
    jQuery.post(ajaxurl, {
        action: 'wc_wms_sync_all_stock',
        nonce: (typeof WC_WMS_ADMIN_NONCE !== 'undefined' ? WC_WMS_ADMIN_NONCE : '')
    }, function(response) {
        if (response.success) {
            var result = response.data;
            var message = 'Stock sync completed: ' + result.message;
            
            // Feedback with health score
            if (result.health_score) {
                message += '\n\nHealth Score: ' + result.health_score + '/100';
                message += '\nStatus: ' + getHealthStatus(result.health_score);
            }
            
            if (result.summary) {
                message += '\n\nSummary: ' + result.summary;
            }
            
            // Show success with details
            showNotice(message, 'success', result.health_score);
            updateProgressIndicator(progressDiv, 'success', 'Stock sync completed successfully!');
            
            // Update any health score displays on the page
            updateHealthScoreDisplays('stock', result.health_score);
            
        } else {
            var errorMsg = 'Stock import failed: ' + (response.data || 'Unknown error');
            showNotice(errorMsg, 'error');
            updateProgressIndicator(progressDiv, 'error', 'Stock sync failed');
        }
    }).fail(function(xhr, status, error) {
        var errorMsg = 'Stock sync request failed: ' + error;
        showNotice(errorMsg, 'error');
        updateProgressIndicator(progressDiv, 'error', 'Request failed');
        console.error('Stock sync failed:', xhr.responseText);
    }).always(function() {
        setButtonLoading(button, false, originalText);
        setTimeout(function() {
            if (progressDiv) progressDiv.fadeOut();
        }, 5000);
    });
}

// Retry failed orders
function retryFailedOrders() {
    if (!confirm('This will retry all failed order exports. Continue?')) {
        return;
    }
    
    var button = event.target;
    button.disabled = true;
    button.textContent = 'Retrying...';
    
    jQuery.post(ajaxurl, {
        action: 'wc_wms_retry_failed_orders',
        nonce: (typeof WC_WMS_ADMIN_NONCE !== 'undefined' ? WC_WMS_ADMIN_NONCE : '')
    }, function(response) {
        if (response.success) {
            alert('Failed orders reset for retry: ' + response.data);
        } else {
            alert('Retry failed: ' + response.data);
        }
    }).always(function() {
        button.disabled = false;
        button.textContent = 'Retry Failed';
        location.reload();
    });
}

// Import all articles function
function importAllArticles() {
    if (!confirm('This will import all articles from WMS and create/update WooCommerce products. This may take several minutes. Continue?')) {
        return;
    }
    
    var button = event.target;
    var originalText = button.textContent;
    setButtonLoading(button, true, originalText);
    
    // Add progress indicator
    var progressDiv = createProgressIndicator(button, 'Importing articles from WMS...');
    
    jQuery.post(ajaxurl, {
        action: 'wc_wms_import_articles',
        nonce: (typeof WC_WMS_ADMIN_NONCE !== 'undefined' ? WC_WMS_ADMIN_NONCE : '')
    }, function(response) {
        if (response.success) {
            var result = response.data;
            var message = 'Article import completed: ' + result.message;
            
            // Feedback with health score and business insights
            if (result.health_score) {
                message += '\n\nHealth Score: ' + result.health_score + '/100';
                message += '\nStatus: ' + getHealthStatus(result.health_score);
            }
            
            if (result.business_insights) {
                message += '\n\nBusiness Insights:';
                if (result.business_insights.sync_coverage) {
                    message += '\n• Sync Coverage: ' + result.business_insights.sync_coverage + '%';
                }
                if (result.business_insights.category_distribution) {
                    message += '\n• Categories Updated: ' + Object.keys(result.business_insights.category_distribution).length;
                }
                if (result.business_insights.processing_time) {
                    message += '\n• Processing Time: ' + result.business_insights.processing_time + 's';
                }
            }
            
            if (result.summary) {
                message += '\n\nSummary: ' + result.summary;
            }
            
            if (result.error_details && result.error_details.length > 0) {
                message += '\n\nFirst few errors:';
                message += '\n' + result.error_details.slice(0, 3).join('\n');
            }
            
            // Show success notification
            showEnhancedNotice(message, 'success', result.health_score);
            updateProgressIndicator(progressDiv, 'success', 'Article import completed successfully!');
            
            // Update health score displays
            updateHealthScoreDisplays('product', result.health_score);
            
            // Trigger setup progress refresh if this was a setup-related import
            if (result.imported > 0 || result.updated > 0) {
                // Setup progress will be refreshed on page reload
            }
            
        } else {
            var errorMsg = 'Article import failed: ' + (response.data || 'Unknown error');
            showEnhancedNotice(errorMsg, 'error');
            updateProgressIndicator(progressDiv, 'error', 'Article import failed');
            console.error('Article import failed:', response);
        }
    }).fail(function(xhr, status, error) {
        var errorMsg = 'Article import request failed: ' + error;
        showEnhancedNotice(errorMsg, 'error');
        updateProgressIndicator(progressDiv, 'error', 'Request failed');
        console.error('Article import request failed:', xhr.responseText);
    }).always(function() {
        setButtonLoading(button, false, originalText);
        setTimeout(function() {
            if (progressDiv) progressDiv.fadeOut();
        }, 8000); // Longer timeout for import operations
    });
}

// Sync shipping methods function
function syncShippingMethods() {
    if (!confirm('This will sync shipping methods from WMS. Continue?')) {
        return;
    }
    
    var button = event.target;
    var originalText = button.textContent;
    setButtonLoading(button, true, originalText);
    
    // Add progress indicator
    var progressDiv = createProgressIndicator(button, 'Syncing shipping methods...');
    
    jQuery.post(ajaxurl, {
        action: 'wc_wms_sync_shipping_methods',
        nonce: (typeof WC_WMS_ADMIN_NONCE !== 'undefined' ? WC_WMS_ADMIN_NONCE : '')
    }, function(response) {
        if (response.success) {
            var result = response.data;
            var message = 'Shipping methods sync completed: ' + result.message;
            
            if (result.synced) {
                message += '\n\nDetails:';
                message += '\n• Methods Synced: ' + result.synced;
                message += '\n• Synced At: ' + result.synced_at;
            }
            
            showEnhancedNotice(message, 'success');
            updateProgressIndicator(progressDiv, 'success', 'Shipping methods synced successfully!');
            
        } else {
            var errorMsg = 'Shipping methods sync failed: ' + (response.data.message || response.data || 'Unknown error');
            showEnhancedNotice(errorMsg, 'error');
            updateProgressIndicator(progressDiv, 'error', 'Shipping methods sync failed');
        }
    }).fail(function(xhr, status, error) {
        var errorMsg = 'Shipping methods sync request failed: ' + error;
        showEnhancedNotice(errorMsg, 'error');
        updateProgressIndicator(progressDiv, 'error', 'Request failed');
        console.error('Shipping methods sync failed:', xhr.responseText);
    }).always(function() {
        setButtonLoading(button, false, originalText);
        setTimeout(function() {
            if (progressDiv) progressDiv.fadeOut();
        }, 5000);
    });
}

// Sync location types function
function syncLocationTypes() {
    if (!confirm('This will sync location types from WMS. Continue?')) {
        return;
    }
    
    var button = event.target;
    var originalText = button.textContent;
    setButtonLoading(button, true, originalText);
    
    // Add progress indicator
    var progressDiv = createProgressIndicator(button, 'Syncing location types...');
    
    jQuery.post(ajaxurl, {
        action: 'wc_wms_sync_location_types',
        nonce: (typeof WC_WMS_ADMIN_NONCE !== 'undefined' ? WC_WMS_ADMIN_NONCE : '')
    }, function(response) {
        if (response.success) {
            var result = response.data.result;
            var message = 'Location types sync completed: ' + response.data.message;
            
            if (result.total_count) {
                message += '\n\nDetails:';
                message += '\n• Total Types: ' + result.total_count;
                message += '\n• Pickable Types: ' + result.pickable_count;
                message += '\n• Transport Types: ' + result.transport_count;
            }
            
            showEnhancedNotice(message, 'success');
            updateProgressIndicator(progressDiv, 'success', 'Location types synced successfully!');
            
        } else {
            var errorMsg = 'Location types sync failed: ' + (response.data.message || response.data || 'Unknown error');
            showEnhancedNotice(errorMsg, 'error');
            updateProgressIndicator(progressDiv, 'error', 'Location types sync failed');
        }
    }).fail(function(xhr, status, error) {
        var errorMsg = 'Location types sync request failed: ' + error;
        showEnhancedNotice(errorMsg, 'error');
        updateProgressIndicator(progressDiv, 'error', 'Request failed');
        console.error('Location types sync failed:', xhr.responseText);
    }).always(function() {
        setButtonLoading(button, false, originalText);
        setTimeout(function() {
            if (progressDiv) progressDiv.fadeOut();
        }, 5000);
    });
}

// Import everything function
function importEverything() {
    // Check if this is the initial sync
    var isInitialSync = !jQuery('.initial-sync-status .notice-success').length;
    
    var confirmMessage = isInitialSync ? 
        'This will complete the INITIAL SYNC and enable automatic synchronization processes (webhooks, orders, stock sync).\n\nThis will import shipping methods, articles, customers, orders, inbounds, shipments, and stock from WMS.\n\nThis may take a few minutes. Continue?' :
        'This will import shipping methods, articles, customers, orders, inbounds, shipments, and stock from WMS, then test connection. This may take a few minutes. Continue?';
    
    if (!confirm(confirmMessage)) {
        return;
    }
    
    var button = event.target;
    button.disabled = true;
    button.textContent = isInitialSync ? '🚀 Completing Initial Sync...' : '🔄 Importing...';
    
    jQuery.post(ajaxurl, {
        action: 'wc_wms_sync_everything',
        nonce: (typeof WC_WMS_ADMIN_NONCE !== 'undefined' ? WC_WMS_ADMIN_NONCE : '')
    }, function(response) {
        if (response.success) {
            var result = response.data;
            var message = isInitialSync ? 
                '🚀 Initial sync completed successfully!\n\nAutomatic synchronization processes are now enabled.\n\n' :
                'Import from WMS completed:\n';
            message += '• Connection: ' + (result.connection ? 'OK' : 'Failed') + '\n';
            message += '• Shipping Methods: ' + (result.shipping_methods || 'Done') + '\n';
            message += '• Articles: ' + (result.articles || 'Done') + '\n';
            message += '• Customers: ' + (result.customers || 'Done') + '\n';
            message += '• Orders: ' + (result.orders || 'Done') + '\n';
            message += '• Inbounds: ' + (result.inbounds || 'Done') + '\n';
            message += '• Shipments: ' + (result.shipments || 'Done') + '\n';
            message += '• Stock: ' + (result.stock || 'Done') + '\n';
            alert(message);
            
            // Reload page after initial sync to update UI
            if (isInitialSync) {
                window.location.reload();
            }
        } else {
            alert('Import failed: ' + (response.data || 'Unknown error'));
        }
    }).always(function() {
        button.disabled = false;
        button.textContent = '🔄 Import Everything from WMS';
        location.reload();
    });
}

// Customer import functions
function importCustomers() {
    if (!confirm('This will import customers from WMS to WooCommerce. Note: WMS Customers API is read-only. Continue?')) {
        return;
    }
    
    var button = event.target;
    button.disabled = true;
    button.textContent = 'Importing...';
    
    jQuery.post(ajaxurl, {
        action: 'wc_wms_import_customers',
        nonce: (typeof WC_WMS_ADMIN_NONCE !== 'undefined' ? WC_WMS_ADMIN_NONCE : '')
    }, function(response) {
        if (response.success) {
            var result = response.data;
            var message = 'Customer import completed:\n';
            message += '• Imported: ' + result.imported + '\n';
            message += '• Updated: ' + result.updated + '\n';
            message += '• Errors: ' + result.errors + '\n';
            
            if (result.error_details && result.error_details.length > 0) {
                message += '\nFirst few errors:\n';
                message += result.error_details.slice(0, 3).map(function(error) {
                    return '• ' + error.customer_name + ': ' + error.error;
                }).join('\n');
            }
            
            alert(message);
        } else {
            alert('Customer import failed: ' + (response.data.message || 'Unknown error'));
        }
    }).always(function() {
        button.disabled = false;
        button.textContent = 'Import from WMS';
        location.reload();
    });
}

// Order sync function
function syncOrders() {
    if (!confirm('This will sync orders from WMS to WooCommerce. This may take a few minutes. Continue?')) {
        return;
    }
    
    var button = event.target;
    button.disabled = true;
    button.textContent = 'Syncing...';
    
    jQuery.post(ajaxurl, {
        action: 'wc_wms_sync_orders',
        nonce: (typeof WC_WMS_ADMIN_NONCE !== 'undefined' ? WC_WMS_ADMIN_NONCE : '')
    }, function(response) {
        if (response.success) {
            var result = response.data;
            var message = 'Order sync completed:\n';
            message += '• Total fetched: ' + result.total_fetched + '\n';
            message += '• Created: ' + result.created + '\n';
            message += '• Updated: ' + result.updated + '\n';
            message += '• Skipped: ' + result.skipped + '\n';
            
            alert(message);
        } else {
            var errorMessage = 'Order sync failed: ' + (response.data.message || 'Unknown error');
            
            if (response.data.error_count && response.data.error_count > 0) {
                errorMessage += '\n\nPartial results:\n';
                errorMessage += '• Created: ' + (response.data.created || 0) + '\n';
                errorMessage += '• Updated: ' + (response.data.updated || 0) + '\n';
                errorMessage += '• Errors: ' + response.data.error_count + '\n';
                
                if (response.data.errors && response.data.errors.length > 0) {
                    errorMessage += '\nFirst few errors:\n';
                    errorMessage += response.data.errors.slice(0, 3).map(function(error) {
                        return '• ' + (error.external_reference || 'Unknown') + ': ' + error.error;
                    }).join('\n');
                }
            }
            
            alert(errorMessage);
        }
    }).always(function() {
        button.disabled = false;
        button.textContent = 'Sync Orders Now';
        location.reload();
    });
}

// Inbound sync function
function syncInbounds() {
    if (!confirm('This will sync inbounds from WMS to track inventory receipts and stock updates. Continue?')) {
        return;
    }
    
    var button = event.target;
    var originalText = button.textContent;
    setButtonLoading(button, true, originalText);
    
    // Add progress indicator
    var progressDiv = createProgressIndicator(button, 'Syncing inbounds from WMS...');
    
    jQuery.post(ajaxurl, {
        action: 'wc_wms_sync_inbounds',
        nonce: (typeof WC_WMS_ADMIN_NONCE !== 'undefined' ? WC_WMS_ADMIN_NONCE : '')
    }, function(response) {
        if (response.success) {
            var result = response.data;
            var message = 'Inbound sync completed: ' + result.message;
            
            if (result.total_synced !== undefined) {
                message += '\n\nDetails:';
                message += '\n• Total Synced: ' + result.total_synced;
                if (result.new_inbounds !== undefined) {
                    message += '\n• New Inbounds: ' + result.new_inbounds;
                }
                if (result.updated_inbounds !== undefined) {
                    message += '\n• Updated Inbounds: ' + result.updated_inbounds;
                }
                if (result.completed_inbounds !== undefined) {
                    message += '\n• Completed Inbounds: ' + result.completed_inbounds;
                }
            }
            
            showEnhancedNotice(message, 'success');
            updateProgressIndicator(progressDiv, 'success', 'Inbound sync completed successfully!');
            
        } else {
            var errorMsg = 'Inbound sync failed: ' + (response.data.message || response.data || 'Unknown error');
            showEnhancedNotice(errorMsg, 'error');
            updateProgressIndicator(progressDiv, 'error', 'Inbound sync failed');
        }
    }).fail(function(xhr, status, error) {
        var errorMsg = 'Inbound sync request failed: ' + error;
        showEnhancedNotice(errorMsg, 'error');
        updateProgressIndicator(progressDiv, 'error', 'Request failed');
        console.error('Inbound sync failed:', xhr.responseText);
    }).always(function() {
        setButtonLoading(button, false, originalText);
        setTimeout(function() {
            if (progressDiv) progressDiv.fadeOut();
        }, 5000);
        
        // Update the sync status display without reloading
        if (response.success) {
            updateInboundSyncStatus(response.data);
        }
    });
}

// Update inbound sync status display without page reload
function updateInboundSyncStatus(syncData) {
    // Find the inbound sync card
    var inboundCard = jQuery('h4').filter(function() {
        return jQuery(this).text().indexOf('Inbound Sync') !== -1;
    }).closest('.sync-card');
    
    if (inboundCard.length > 0) {
        // Update the status text to show "just synced"
        var statusP = inboundCard.find('p').first();
        var syncCount = syncData ? syncData.total_synced : 'Unknown';
        var newStatusHtml = '✅ Synced just now<br><small>' + syncCount + ' inbounds synced</small>';
        statusP.html(newStatusHtml);
        
        // Add a subtle animation to show the update
        inboundCard.css('background-color', '#f0fff0');
        setTimeout(function() {
            inboundCard.animate({'background-color': '#f9f9f9'}, 1000);
        }, 2000);
    }
}

// Shipment sync function
function syncShipments() {
    if (!confirm('This will sync shipments from WMS to update order tracking information. Continue?')) {
        return;
    }
    
    var button = event.target;
    var originalText = button.textContent;
    setButtonLoading(button, true, originalText);
    
    // Add progress indicator
    var progressDiv = createProgressIndicator(button, 'Syncing shipments from WMS...');
    
    jQuery.post(ajaxurl, {
        action: 'wc_wms_sync_shipments',
        nonce: (typeof WC_WMS_ADMIN_NONCE !== 'undefined' ? WC_WMS_ADMIN_NONCE : '')
    }, function(response) {
        if (response.success) {
            var result = response.data;
            var message = 'Shipment sync completed: ' + result.message;
            
            if (result.total_synced !== undefined) {
                message += '\n\nDetails:';
                message += '\n• Total Synced: ' + result.total_synced;
                if (result.orders_updated !== undefined) {
                    message += '\n• Orders Updated: ' + result.orders_updated;
                }
                if (result.tracking_numbers_added !== undefined) {
                    message += '\n• Tracking Numbers Added: ' + result.tracking_numbers_added;
                }
            }
            
            showEnhancedNotice(message, 'success');
            updateProgressIndicator(progressDiv, 'success', 'Shipment sync completed successfully!');
            
        } else {
            var errorMsg = 'Shipment sync failed: ' + (response.data.message || response.data || 'Unknown error');
            showEnhancedNotice(errorMsg, 'error');
            updateProgressIndicator(progressDiv, 'error', 'Shipment sync failed');
        }
    }).fail(function(xhr, status, error) {
        var errorMsg = 'Shipment sync request failed: ' + error;
        showEnhancedNotice(errorMsg, 'error');
        updateProgressIndicator(progressDiv, 'error', 'Request failed');
        console.error('Shipment sync failed:', xhr.responseText);
    }).always(function() {
        setButtonLoading(button, false, originalText);
        setTimeout(function() {
            if (progressDiv) progressDiv.fadeOut();
        }, 5000);
        
        // Update the sync status display without reloading
        if (response && response.success) {
            updateShipmentSyncStatus(response.data);
        }
    });
}

// Update shipment sync status display without page reload
function updateShipmentSyncStatus(syncData) {
    // Find the shipment sync card
    var shipmentCard = jQuery('h4').filter(function() {
        return jQuery(this).text().indexOf('Shipment Sync') !== -1;
    }).closest('.sync-card');
    
    if (shipmentCard.length > 0) {
        // Update the status text to show "just synced"
        var statusP = shipmentCard.find('p').first();
        var syncCount = syncData ? syncData.total_synced : 'Unknown';
        var newStatusHtml = '✅ Synced just now<br><small>' + syncCount + ' shipments synced</small>';
        statusP.html(newStatusHtml);
        
        // Add a subtle animation to show the update
        shipmentCard.css('background-color', '#f0fff0');
        setTimeout(function() {
            shipmentCard.animate({'background-color': '#f9f9f9'}, 1000);
        }, 2000);
    }
}

function getCustomerStats() {
    var button = event.target;
    button.disabled = true;
    button.textContent = 'Loading...';
    
    jQuery.post(ajaxurl, {
        action: 'wc_wms_get_customer_stats',
        nonce: (typeof WC_WMS_ADMIN_NONCE !== 'undefined' ? WC_WMS_ADMIN_NONCE : '')
    }, function(response) {
        if (response.success) {
            var stats = response.data;
            var message = 'Customer Import Statistics:\n';
            message += '• Total WooCommerce Customers: ' + stats.total_customers + '\n';
            message += '• Imported from WMS: ' + stats.imported_customers + '\n';
            message += '• Auto-import: ' + (stats.import_enabled ? 'Enabled' : 'Disabled') + '\n';
            
            if (stats.last_import) {
                message += '• Last Import: ' + new Date(stats.last_import * 1000).toLocaleString() + '\n';
            }
            
            message += '\nNote: ' + stats.api_note;
            
            alert(message);
        } else {
            alert('Failed to get customer stats: ' + (response.data || 'Unknown error'));
        }
    }).always(function() {
        button.disabled = false;
        button.textContent = 'View Stats';
    });
}


// Export all products function
function exportAllProducts() {
    // Validate required WordPress AJAX variables
    if (typeof ajaxurl === 'undefined') {
        alert('Error: ajaxurl is not defined. WordPress AJAX may not be properly loaded.');
        return;
    }
    
    if (typeof WC_WMS_ADMIN_NONCE === 'undefined') {
        alert('Error: WC_WMS_ADMIN_NONCE is not defined. Admin scripts may not be properly loaded.');
        return;
    }
    
    if (!confirm('This will export ALL WooCommerce products to WMS. This may take several minutes for large catalogs. Continue?')) {
        return;
    }
    
    var button = event.target;
    var originalText = button.textContent;
    button.disabled = true;
    button.textContent = 'Exporting...';
    
    // Create progress indicator
    var progressDiv = jQuery('<div id="export-progress" style="margin-top: 10px; padding: 10px; background: #f0f0f0; border-radius: 3px;"></div>');
    jQuery(button).after(progressDiv);
    progressDiv.html('<strong>🔄 Starting export...</strong>');
    
    // Make AJAX request to export products
    
    jQuery.post(ajaxurl, {
        action: 'wc_wms_export_all_products',
        nonce: WC_WMS_ADMIN_NONCE
    }, function(response) {
        
        if (response.success) {
            var result = response.data;
            var message = 'Export/Update completed:\n';
            message += '• New Products: ' + result.exported + ' exported\n';
            message += '• Existing Products: ' + result.updated + ' updated\n';
            message += '• Errors: ' + result.errors + ' products\n';
            
            if (result.error_details && result.error_details.length > 0) {
                message += '\nFirst few errors:\n';
                message += result.error_details.slice(0, 3).join('\n');
            }
            
            if (result.export_completed) {
                message += '\n\n✅ Setup Status: Products export marked as completed!';
                message += '\n\nNext Steps:';
                message += '\n1. Go to Setup tab to configure shipping methods';
                message += '\n2. Register webhooks in Webhooks tab';
                message += '\n3. Perform initial stock sync in Synchronization tab';
            }
            
            alert(message);
            
            // Update progress
            var progressMessage = '<strong>✅ Export/Update Complete!</strong> New: ' + result.exported + ', Updated: ' + result.updated + ', Errors: ' + result.errors;
            if (result.export_completed) {
                progressMessage += '<br><strong style="color: green;">🎉 Products export setup completed!</strong>';
                // Setup progress will be refreshed on page reload
            }
            progressDiv.html(progressMessage);
        } else {
            var errorMsg = 'Export failed: ' + (response.data || 'Unknown error');
            alert(errorMsg);
            progressDiv.html('<strong>❌ Export Failed!</strong> ' + (response.data || 'Unknown error'));
            console.error('Export failed:', response);
        }
    }).fail(function(xhr, status, error) {
        var errorMsg = 'Request failed: ' + error;
        if (xhr.responseText) {
            try {
                var errorResponse = JSON.parse(xhr.responseText);
                if (errorResponse.data) {
                    errorMsg += '\nDetails: ' + errorResponse.data;
                }
            } catch (e) {
                errorMsg += '\nResponse: ' + xhr.responseText.substring(0, 200);
            }
        }
        alert(errorMsg);
        progressDiv.html('<strong>❌ Request Failed!</strong> ' + error + ' (Status: ' + status + ')');
        console.error('Export request failed:', {
            status: status,
            error: error,
            responseText: xhr.responseText,
            statusCode: xhr.status
        });
    }).always(function() {
        button.disabled = false;
        button.textContent = originalText;
    });
}

// Webhook management functions
function registerWebhooks() {
    if (!confirm('This will DELETE all existing webhooks and register fresh ones with WMS. This ensures clean webhook registration. Continue?')) {
        return;
    }
    
    var button = event.target;
    button.disabled = true;
    button.textContent = 'Deleting existing & registering fresh...';
    
    jQuery.post(ajaxurl, {
        action: 'wc_wms_register_webhooks',
        nonce: (typeof WC_WMS_ADMIN_NONCE !== 'undefined' ? WC_WMS_ADMIN_NONCE : '')
    }, function(response) {
        if (response.success) {
            var result = response.data;
            var message = result.message || result.summary || 'Webhooks processed successfully';
            
            if (result.deletion_results) {
                message += '\n\nCleanup: ' + result.deletion_results.deleted.length + ' existing webhooks deleted';
                if (result.deletion_results.errors.length > 0) {
                    message += ' (' + result.deletion_results.errors.length + ' deletion errors)';
                }
            }
            
            if (result.partial_success) {
                message += '\n\nDetails:\n';
                message += 'Registered: ' + (result.registered ? result.registered.length : 0) + '\n';
                message += 'Skipped: ' + (result.skipped ? result.skipped.length : 0) + '\n';
                message += 'Errors: ' + (result.errors ? result.errors.length : 0);
            } else {
                message += '\n\nSummary: ' + (result.summary || 'Operation completed');
            }
            
            alert(message);
        } else {
            var errorMsg = 'Failed to register webhooks';
            if (response.data && response.data.message) {
                errorMsg += ': ' + response.data.message;
            } else if (response.data && response.data.errors) {
                errorMsg += ': ' + response.data.errors.join(', ');
            } else {
                errorMsg += ': Unknown error';
            }
            alert(errorMsg);
        }
    }).fail(function(xhr, status, error) {
        alert('Request failed: ' + error);
    }).always(function() {
        button.disabled = false;
        button.textContent = 'Register All Webhooks with WMS';
        location.reload();
    });
}

function checkWebhookStatus() {
    var button = event.target;
    button.disabled = true;
    button.textContent = 'Checking...';
    
    jQuery.post(ajaxurl, {
        action: 'wc_wms_check_webhook_status',
        nonce: (typeof WC_WMS_ADMIN_NONCE !== 'undefined' ? WC_WMS_ADMIN_NONCE : '')
    }, function(response) {
        if (response.success) {
            var status = response.data;
            var message = 'Webhook Status (Local Tracking):\n';
            message += 'Registered locally: ' + status.registered_locally + '\n';
            message += 'Expected webhooks: ' + status.expected_webhooks + '\n';
            message += 'All registered: ' + (status.all_registered ? 'Yes' : 'No') + '\n';
            
            if (status.missing_events && status.missing_events.length > 0) {
                message += 'Missing events: ' + status.missing_events.join(', ') + '\n';
            }
            
            if (status.registered_events && status.registered_events.length > 0) {
                message += 'Registered events: ' + status.registered_events.join(', ') + '\n';
            }
            
            if (status.last_registration) {
                message += 'Last registration: ' + status.last_registration + '\n';
            }
            
            message += '\nNote: ' + status.note;
            
            alert(message);
        } else {
            alert('Failed to check webhook status: ' + (response.data || 'Unknown error'));
        }
    }).always(function() {
        button.disabled = false;
        button.textContent = 'Check Local Webhook Status';
    });
}

function deleteAllWebhooks() {
    if (!confirm('Are you sure you want to delete ALL webhooks from WMS? This cannot be undone.')) {
        return;
    }
    
    var button = event.target;
    button.disabled = true;
    button.textContent = 'Deleting...';
    
    jQuery.post(ajaxurl, {
        action: 'wc_wms_delete_all_webhooks',
        nonce: (typeof WC_WMS_ADMIN_NONCE !== 'undefined' ? WC_WMS_ADMIN_NONCE : '')
    }, function(response) {
        if (response.success) {
            alert('All webhooks deleted: ' + response.data.message);
        } else {
            alert('Failed to delete webhooks: ' + (response.data.message || 'Unknown error'));
        }
    }).always(function() {
        button.disabled = false;
        button.textContent = 'Delete All Webhooks';
        location.reload();
    });
}

function validateWebhookConfig() {
    var button = event.target;
    button.disabled = true;
    button.textContent = 'Validating...';
    
    jQuery.post(ajaxurl, {
        action: 'wc_wms_validate_webhook_config',
        nonce: (typeof WC_WMS_ADMIN_NONCE !== 'undefined' ? WC_WMS_ADMIN_NONCE : '')
    }, function(response) {
        if (response.success) {
            var validation = response.data;
            var message = 'Webhook Configuration:\n';
            message += 'Valid: ' + (validation.valid ? 'Yes' : 'No') + '\n';
            message += 'Webhook URL: ' + validation.webhook_url + '\n';
            message += 'Secret configured: ' + (validation.webhook_secret_configured ? 'Yes' : 'No') + '\n';
            if (validation.issues.length > 0) {
                message += '\nIssues:\n' + validation.issues.join('\n');
            }
            alert(message);
        } else {
            alert('Failed to validate webhook config: ' + (response.data || 'Unknown error'));
        }
    }).always(function() {
        button.disabled = false;
        button.textContent = 'Validate Webhook Config';
    });
}

function generateWebhookSecret() {
    if (!confirm('This will generate a new webhook secret. You will need to re-register webhooks. Continue?')) {
        return;
    }
    
    var button = event.target;
    button.disabled = true;
    button.textContent = 'Generating...';
    
    jQuery.post(ajaxurl, {
        action: 'wc_wms_generate_webhook_secret',
        nonce: (typeof WC_WMS_ADMIN_NONCE !== 'undefined' ? WC_WMS_ADMIN_NONCE : '')
    }, function(response) {
        if (response.success) {
            alert('New webhook secret generated: ' + response.data.message);
        } else {
            alert('Failed to generate webhook secret: ' + (response.data || 'Unknown error'));
        }
    }).always(function() {
        button.disabled = false;
        button.textContent = 'Generate New Secret';
        location.reload();
    });
}



// Utility Functions
function showNotice(message, type) {
    type = type || 'success';
    var noticeClass = 'notice-' + type;
    var notice = jQuery('<div class="notice ' + noticeClass + ' is-dismissible"><p>' + message + '</p></div>');
    jQuery('.wrap h1').after(notice);
    
    // Auto-dismiss after 5 seconds
    setTimeout(function() {
        notice.fadeOut();
    }, 5000);
}

// Notice with health score and visual indicators
function showEnhancedNotice(message, type, healthScore) {
    type = type || 'success';
    var noticeClass = 'notice-' + type;
    var healthIndicator = '';
    
    if (healthScore !== undefined && healthScore !== null) {
        var healthStatus = getHealthStatus(healthScore);
        var healthColor = getHealthColor(healthScore);
        healthIndicator = '<div style="float: right; background: ' + healthColor + '; color: white; padding: 3px 8px; border-radius: 3px; font-size: 12px; margin-left: 10px;">Health: ' + healthScore + '/100 (' + healthStatus + ')</div>';
    }
    
    var notice = jQuery('<div class="notice ' + noticeClass + ' is-dismissible" style="position: relative;">' + healthIndicator + '<p>' + message + '</p></div>');
    jQuery('.wrap h1').after(notice);
    
    // Auto-dismiss after 8 seconds
    setTimeout(function() {
        notice.fadeOut();
    }, 8000);
}

// Create progress indicator
function createProgressIndicator(button, message) {
    var progressDiv = jQuery('<div class="wms-progress-indicator" style="margin-top: 10px; padding: 10px; background: #f0f8ff; border: 1px solid #0073aa; border-radius: 3px; font-size: 14px;"><span class="spinner is-active" style="float: left; margin: 0 8px 0 0;"></span><span class="progress-message">' + message + '</span></div>');
    jQuery(button).after(progressDiv);
    return progressDiv;
}

// Update progress indicator
function updateProgressIndicator(progressDiv, status, message) {
    if (!progressDiv) return;
    
    var icon, bgColor, borderColor;
    switch (status) {
        case 'success':
            icon = '✅';
            bgColor = '#f0fff4';
            borderColor = '#28a745';
            break;
        case 'error':
            icon = '❌';
            bgColor = '#fff5f5';
            borderColor = '#dc3545';
            break;
        case 'warning':
            icon = '⚠️';
            bgColor = '#fffbf0';
            borderColor = '#ffc107';
            break;
        default:
            icon = '⏳';
            bgColor = '#f0f8ff';
            borderColor = '#0073aa';
    }
    
    progressDiv.html('<span style="margin-right: 8px;">' + icon + '</span><span>' + message + '</span>');
    progressDiv.css({
        'background-color': bgColor,
        'border-color': borderColor
    });
}

// Get health status text
function getHealthStatus(score) {
    if (score >= 80) return 'Excellent';
    if (score >= 60) return 'Good';
    if (score >= 40) return 'Fair';
    return 'Poor';
}

// Get health status color
function getHealthColor(score) {
    if (score >= 80) return '#28a745'; // Green
    if (score >= 60) return '#17a2b8'; // Blue
    if (score >= 40) return '#ffc107'; // Yellow
    return '#dc3545'; // Red
}

// Update health score displays on the page
function updateHealthScoreDisplays(integrator, healthScore) {
    if (healthScore === undefined || healthScore === null) return;
    
    // Update any health score elements for this integrator
    jQuery('.health-score-' + integrator).each(function() {
        var element = jQuery(this);
        element.text(healthScore + '/100');
        element.removeClass('health-excellent health-good health-fair health-poor');
        
        var healthClass = 'health-poor';
        if (healthScore >= 80) healthClass = 'health-excellent';
        else if (healthScore >= 60) healthClass = 'health-good';
        else if (healthScore >= 40) healthClass = 'health-fair';
        
        element.addClass(healthClass);
    });
    
    // Update overall health if all integrators have scores
    updateOverallHealthScore();
}

// Calculate and update overall health score
function updateOverallHealthScore() {
    var healthScores = [];
    jQuery('[class*="health-score-"]').each(function() {
        var scoreText = jQuery(this).text();
        var score = parseInt(scoreText.split('/')[0]);
        if (!isNaN(score)) {
            healthScores.push(score);
        }
    });
    
    if (healthScores.length > 0) {
        var overallScore = Math.round(healthScores.reduce((a, b) => a + b) / healthScores.length);
        jQuery('.overall-health-score').each(function() {
            jQuery(this).text(overallScore + '/100');
            jQuery(this).css('color', getHealthColor(overallScore));
        });
    }
}

function setButtonLoading(button, isLoading, originalText) {
    if (isLoading) {
        button.disabled = true;
        button.setAttribute('data-original-text', originalText || button.textContent);
        button.textContent = 'Loading...';
    } else {
        button.disabled = false;
        button.textContent = button.getAttribute('data-original-text') || originalText;
    }
}

// Initialize on document ready
jQuery(document).ready(function($) {
    // Add confirm dialogs to dangerous actions
    $('.button-secondary').on('click', function(e) {
        if ($(this).text().toLowerCase().includes('delete')) {
            if (!confirm('Are you sure you want to perform this action?')) {
                e.preventDefault();
                return false;
            }
        }
    });
    
    // Auto-refresh status indicators every 30 seconds
    setInterval(function() {
        // Only refresh if we're on the connection tab
        if ($('#connection-tab').is(':visible')) {
            location.reload();
        }
    }, 30000);
    
    // Refresh progress when switching to setup tab
    $('a[href="#setup"]').on('click', function() {
        setTimeout(function() {
            refreshSetupProgress();
        }, 500);
    });
});

// Refresh setup progress function
function refreshSetupProgress() {
    jQuery.post(ajaxurl, {
        action: 'wc_wms_refresh_setup_progress',
        nonce: (typeof WC_WMS_ADMIN_NONCE !== 'undefined' ? WC_WMS_ADMIN_NONCE : '')
    }, function(response) {
        if (response.success) {
            updateSetupProgressDisplay(response.data);
        }
    }).fail(function() {
        // Failed to refresh setup progress
    });
}

// Update setup progress display
function updateSetupProgressDisplay(data) {
    var progressContainer = jQuery('.setup-progress ul');
    if (progressContainer.length === 0) {
        return;
    }
    
    progressContainer.empty();
    
    jQuery.each(data.progress_items, function(key, item) {
        var status = item.completed ? 'completed' : 'pending';
        var listItem = '<li style="margin: 5px 0;">' + item.icon + ' ' + item.label + ' <small>(' + status + ')</small></li>';
        progressContainer.append(listItem);
    });
    
    // Add overall progress if we have export stats
    if (data.export_stats) {
        var statsHtml = '<li style="margin: 10px 0; padding-top: 10px; border-top: 1px solid #ddd;"><strong>Export Statistics:</strong><br>';
        
        if (data.export_stats.updated !== undefined) {
            // Format with separate export and update counts
            statsHtml += '<small>New Products: ' + data.export_stats.exported + ' | ';
            statsHtml += 'Updated Products: ' + data.export_stats.updated + ' | ';
        } else {
            // Format with combined export count
            statsHtml += '<small>Exported: ' + data.export_stats.exported + ' | ';
            if (data.export_stats.skipped !== undefined) {
                statsHtml += 'Skipped: ' + data.export_stats.skipped + ' | ';
            }
        }
        
        statsHtml += 'Errors: ' + data.export_stats.errors + '<br>';
        statsHtml += 'Completed: ' + data.export_stats.timestamp + '</small></li>';
        progressContainer.append(statsHtml);
    }
    
    // Add overall progress bar
    if (data.overall_progress) {
        var progressBarHtml = '<li style="margin: 10px 0;"><div style="background: #f0f0f0; border-radius: 10px; overflow: hidden;"><div style="background: #4CAF50; height: 20px; width: ' + data.overall_progress.percentage + '%; transition: width 0.5s;"></div></div><small>Overall Progress: ' + data.overall_progress.completed_steps + '/' + data.overall_progress.total_steps + ' (' + data.overall_progress.percentage + '%)</small></li>';
        progressContainer.append(progressBarHtml);
    }
    
}

// Simple refresh display function
function refreshStoredData() {
    location.reload();
}

// Sync everything function (one-click setup)
function syncEverything() {
    var button = event.target;
    var originalText = button.textContent;
    
    // Disable button and show progress
    button.disabled = true;
    button.textContent = '🔄 Setting up integration...';
    
    // Show progress section
    jQuery('#sync-progress').show();
    jQuery('#sync-results').hide();
    
    // Initialize progress display
    var progressSteps = [
        'Testing connection...',
        'Registering webhooks...',
        'Syncing shipping methods...',
        'Importing articles/products...',
        'Syncing stock levels...',
        'Syncing orders from WMS...',
        'Auto-mapping shipping methods...'
    ];
    
    var progressHtml = '<ul style="list-style: none; padding: 0; margin: 0;">';
    progressSteps.forEach(function(step, index) {
        progressHtml += '<li id="step-' + index + '" style="margin: 5px 0; padding: 5px; background: #f0f0f0; border-radius: 3px;">';
        progressHtml += '⏳ ' + step + '</li>';
    });
    progressHtml += '</ul>';
    
    jQuery('#progress-steps').html(progressHtml);
    
    // Simulate progress updates (since we can't get real-time updates from PHP)
    var currentStep = 0;
    var progressInterval = setInterval(function() {
        if (currentStep < progressSteps.length) {
            jQuery('#step-' + currentStep).html('⚙️ ' + progressSteps[currentStep] + ' <span style="color: #0073aa;">in progress...</span>');
            currentStep++;
        }
    }, 3000); // Update every 3 seconds
    
    // Make AJAX call
    jQuery.post(ajaxurl, {
        action: 'wc_wms_sync_everything',
        nonce: (typeof WC_WMS_ADMIN_NONCE !== 'undefined' ? WC_WMS_ADMIN_NONCE : '')
    }, function(response) {
        clearInterval(progressInterval);
        
        if (response.success) {
            // Update all progress steps as completed
            progressSteps.forEach(function(step, index) {
                var stepElement = jQuery('#step-' + index);
                if (index < progressSteps.length - 1) {
                    stepElement.html('✅ ' + step.replace('...', ' - completed'));
                } else {
                    stepElement.html('✅ ' + step.replace('...', ' - completed'));
                }
                stepElement.css('background', '#d1ecf1');
            });
            
            // Show detailed results
            var resultsHtml = '<div style="background: #d1ecf1; padding: 15px; border-radius: 5px; margin: 10px 0;">';
            resultsHtml += '<h4 style="margin: 0 0 10px 0; color: #0f5132;">✅ Setup Completed Successfully!</h4>';
            
            if (response.data.results) {
                resultsHtml += '<ul style="margin: 0; padding-left: 20px;">';
                Object.keys(response.data.results).forEach(function(key) {
                    var result = response.data.results[key];
                    var icon = result.startsWith('success') ? '✅' : '❌';
                    var capitalizedKey = key.charAt(0).toUpperCase() + key.slice(1);
                    resultsHtml += '<li><strong>' + capitalizedKey + ':</strong> ' + icon + ' ' + result + '</li>';
                });
                resultsHtml += '</ul>';
            }
            
            if (response.data.next_steps) {
                resultsHtml += '<div style="margin-top: 15px; padding: 10px; background: white; border-radius: 3px;">';
                resultsHtml += '<h5 style="margin: 0 0 5px 0;">🎯 Next Steps:</h5>';
                resultsHtml += '<ul style="margin: 0; padding-left: 20px;">';
                response.data.next_steps.forEach(function(step) {
                    resultsHtml += '<li>' + step + '</li>';
                });
                resultsHtml += '</ul></div>';
            }
            
            resultsHtml += '</div>';
            
            // Show success message with page reload option
            resultsHtml += '<div style="text-align: center; margin-top: 15px;">';
            resultsHtml += '<button type="button" class="button button-primary" onclick="location.reload()" style="padding: 10px 20px;">';
            resultsHtml += '🔄 Refresh Page to See Updates</button>';
            resultsHtml += '</div>';
            
            jQuery('#results-content').html(resultsHtml);
            jQuery('#sync-results').show();
            
        } else {
            // Show error
            var errorHtml = '<div style="background: #f8d7da; padding: 15px; border-radius: 5px; color: #721c24;">';
            errorHtml += '<h4 style="margin: 0 0 10px 0;">❌ Setup Failed</h4>';
            errorHtml += '<p>' + (response.data || 'Unknown error occurred') + '</p>';
            
            if (response.data && response.data.results) {
                errorHtml += '<h5>Detailed Results:</h5><ul style="margin: 0; padding-left: 20px;">';
                Object.keys(response.data.results).forEach(function(key) {
                    var result = response.data.results[key];
                    var icon = result.startsWith('success') ? '✅' : '❌';
                    errorHtml += '<li><strong>' + key + ':</strong> ' + icon + ' ' + result + '</li>';
                });
                errorHtml += '</ul>';
            }
            
            errorHtml += '<p style="margin-top: 10px;"><em>Check the logs for more details or try individual sync operations in the Advanced section below.</em></p>';
            errorHtml += '</div>';
            
            jQuery('#results-content').html(errorHtml);
            jQuery('#sync-results').show();
            
            // Update progress steps to show failure
            progressSteps.forEach(function(step, index) {
                jQuery('#step-' + index).css('background', '#f8d7da');
            });
        }
    }).fail(function(xhr, status, error) {
        clearInterval(progressInterval);
        
        var errorHtml = '<div style="background: #f8d7da; padding: 15px; border-radius: 5px; color: #721c24;">';
        errorHtml += '<h4 style="margin: 0 0 10px 0;">❌ Setup Failed</h4>';
        errorHtml += '<p>AJAX request failed: ' + error + '</p>';
        errorHtml += '<p><em>Please check your connection and try again.</em></p>';
        errorHtml += '</div>';
        
        jQuery('#results-content').html(errorHtml);
        jQuery('#sync-results').show();
        
    }).always(function() {
        // Re-enable button
        button.disabled = false;
        button.textContent = originalText;
    });
}
