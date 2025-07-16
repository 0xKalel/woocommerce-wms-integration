<?php
/**
 * Simplified Inbound Management Tab
 * Follows proper architecture with services and AJAX handlers
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

?>
<div id="inbound-tab" class="tab-content" style="display: none;">
    <h2><?php _e('📦 Inbound Management', 'wc-wms-integration'); ?></h2>
    <p class="description"><?php _e('Import inbounds from WMS to track inventory receipts and update stock levels', 'wc-wms-integration'); ?></p>

    <!-- Quick Stats Card -->
    <div class="wms-card">
        <h3>Quick Statistics (Last 30 Days)</h3>
        <div class="stats-grid">
            <div class="stat-item">
                <span class="stat-value" id="total-inbounds">-</span>
                <span class="stat-label">Total Inbounds</span>
            </div>
            <div class="stat-item">
                <span class="stat-value" id="completed-inbounds">-</span>
                <span class="stat-label">Completed</span>
            </div>
            <div class="stat-item">
                <span class="stat-value" id="announced-inbounds">-</span>
                <span class="stat-label">Announced</span>
            </div>
            <div class="stat-item">
                <span class="stat-value" id="pending-inbounds">-</span>
                <span class="stat-label">Pending</span>
            </div>
        </div>
        <button type="button" class="button button-primary" id="refresh-stats">
            🔄 Refresh Stats from WMS
        </button>
        <p class="description"><small>💡 Stats auto-refresh via cron every 4 hours</small></p>
    </div>

    <!-- Create Inbound Card -->
    <div class="wms-card">
        <h3>Create New Inbound</h3>
        <form id="create-inbound-form" class="wms-form">
            <div class="form-row">
                <label for="external-reference">External Reference *</label>
                <input type="text" id="external-reference" name="external_reference" 
                       placeholder="PO-2024-001" required>
            </div>
            
            <div class="form-row">
                <label for="inbound-date">Inbound Date *</label>
                <input type="date" id="inbound-date" name="inbound_date" 
                       value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            
            <div class="form-row">
                <label for="is-return">Is Return?</label>
                <input type="checkbox" id="is-return" name="is_return" value="1">
                <span class="description">Check if this is a return inbound</span>
            </div>
            
            <div class="form-row">
                <label for="note">Note</label>
                <textarea id="note" name="note" rows="2" 
                          placeholder="Optional note about this inbound"></textarea>
            </div>

            <h4>Inbound Lines</h4>
            <div id="inbound-lines">
                <div class="inbound-line" data-index="0">
                    <div class="line-fields">
                        <input type="text" name="article_code" placeholder="Article Code/SKU *" required>
                        <input type="number" name="quantity" placeholder="Quantity *" min="1" required>
                        <input type="number" name="packing_slip" placeholder="Packing Slip">
                        <button type="button" class="button remove-line" title="Remove">
                            ❌
                        </button>
                    </div>
                </div>
            </div>
            
            <div class="form-actions">
                <button type="button" id="add-line" class="button">
                    ➕ Add Line
                </button>
                <button type="submit" class="button-primary">
                    ✅ Create Inbound
                </button>
            </div>
        </form>
    </div>

    <!-- Recent Inbounds Card -->
    <div class="wms-card">
        <h3>Recent Inbounds</h3>
        <p class="description"><small>💡 Inbounds auto-sync via cron every 4 hours. Use refresh button for manual updates.</small></p>
        
        <!-- Filters -->
        <div class="filters">
            <select id="status-filter">
                <option value="">All Statuses</option>
                <option value="announced">Announced</option>
                <option value="completed">Completed</option>
                <option value="cancelled">Cancelled</option>
            </select>
            <input type="date" id="from-date" placeholder="From Date">
            <input type="date" id="to-date" placeholder="To Date">
            <button type="button" id="apply-filters" class="button">Filter</button>
            <button type="button" id="refresh-inbounds" class="button button-primary">🔄 Refresh from WMS</button>
        </div>

        <!-- Inbounds Table -->
        <div class="table-container">
            <table class="wp-list-table widefat striped" id="inbounds-table">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>External Reference</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Lines</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td colspan="6" class="loading">Loading inbounds...</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="pagination">
            <button type="button" id="prev-page" class="button" disabled>
                ← Previous
            </button>
            <span id="page-info">Page 1</span>
            <button type="button" id="next-page" class="button" disabled>
                Next →
            </button>
        </div>
    </div>

    <!-- Inbound Details Modal -->
    <div id="inbound-modal" class="wms-modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Inbound Details</h3>
                <button type="button" class="modal-close">&times;</button>
            </div>
            <div class="modal-body" id="inbound-details">
                <!-- Details loaded dynamically -->
            </div>
            <div class="modal-footer">
                <button type="button" class="button modal-close">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Styles -->
<style>
.wms-card {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
}

.wms-card h3 {
    margin-top: 0;
    margin-bottom: 15px;
    font-size: 1.3em;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 15px;
    margin-bottom: 15px;
}

.stat-item {
    text-align: center;
    padding: 15px;
    background: #f8f9fa;
    border-radius: 4px;
}

.stat-value {
    display: block;
    font-size: 2em;
    font-weight: bold;
    color: #135e96;
    line-height: 1;
}

.stat-label {
    display: block;
    font-size: 0.9em;
    color: #666;
    margin-top: 5px;
}

.wms-form .form-row {
    margin-bottom: 15px;
}

.wms-form label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
}

.wms-form input, .wms-form textarea, .wms-form select {
    width: 100%;
    max-width: 400px;
}

.inbound-line {
    margin-bottom: 10px;
    padding: 15px;
    background: #f8f9fa;
    border: 1px solid #ddd;
    border-radius: 4px;
}

.line-fields {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr auto;
    gap: 10px;
    align-items: center;
}

.form-actions {
    margin-top: 20px;
    padding-top: 15px;
    border-top: 1px solid #ddd;
}

.filters {
    display: flex;
    gap: 10px;
    margin-bottom: 15px;
    align-items: center;
    flex-wrap: wrap;
}

.filters input, .filters select {
    max-width: 150px;
}

.table-container {
    overflow-x: auto;
}

.pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px solid #ddd;
}

.wms-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 100000;
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-content {
    background: white;
    border-radius: 4px;
    width: 90%;
    max-width: 700px;
    max-height: 80vh;
    display: flex;
    flex-direction: column;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px;
    border-bottom: 1px solid #ddd;
}

.modal-body {
    padding: 20px;
    overflow-y: auto;
}

.modal-footer {
    padding: 20px;
    border-top: 1px solid #ddd;
    text-align: right;
}

.modal-close {
    background: none;
    border: none;
    font-size: 1.5em;
    cursor: pointer;
    color: #666;
}

.status-announced { color: #ffb900; }
.status-completed { color: #46b450; }
.status-cancelled { color: #dc3232; }
.status-pending { color: #0073aa; }

.loading {
    text-align: center;
    color: #666;
    font-style: italic;
}

/* Responsive */
@media (max-width: 768px) {
    .line-fields {
        grid-template-columns: 1fr;
        gap: 5px;
    }
    
    .filters {
        flex-direction: column;
        align-items: stretch;
    }
    
    .filters input, .filters select {
        max-width: none;
    }
}
</style>

<!-- JavaScript -->
<script>
jQuery(document).ready(function($) {
    let currentPage = 1;
    let lineIndex = 1;

    // Initialize
    // Note: Auto-loading removed - use cron sync (every 4h) + manual refresh buttons
    // loadStats();
    // loadInbounds();
    
    // Show initial messages
    $('#total-inbounds').text('--');
    $('#completed-inbounds').text('--');
    $('#announced-inbounds').text('--');
    $('#pending-inbounds').text('--');
    
    // Show initial message in table
    $('#inbounds-table tbody').html('<tr><td colspan="6" class="loading">📊 Inbounds auto-sync every 4 hours via cron. Click "🔄 Refresh from WMS" to load latest data manually.</td></tr>');

    // Event Handlers
    $('#refresh-stats').click(loadStats);
    $('#refresh-inbounds').click(() => { currentPage = 1; loadInbounds(); });
    $('#add-line').click(addInboundLine);
    $('#create-inbound-form').submit(createInbound);
    $('#apply-filters').click(() => { currentPage = 1; loadInbounds(); });
    $('#prev-page').click(() => { if (currentPage > 1) { currentPage--; loadInbounds(); } });
    $('#next-page').click(() => { currentPage++; loadInbounds(); });
    
    // Event delegation
    $(document).on('click', '.remove-line', removeInboundLine);
    $(document).on('click', '.view-inbound', viewInbound);
    $(document).on('click', '.cancel-inbound', cancelInbound);
    $(document).on('click', '.modal-close', () => $('#inbound-modal').hide());

    // Functions
    function loadStats() {
        $.post(ajaxurl, {
            action: 'wc_wms_get_inbound_stats',
            nonce: (typeof WC_WMS_ADMIN_NONCE !== 'undefined' ? WC_WMS_ADMIN_NONCE : ''),
            days: 30
        }, function(response) {
            if (response.success) {
                const stats = response.data;
                $('#total-inbounds').text(stats.total_inbounds || 0);
                $('#completed-inbounds').text(stats.completed || 0);
                $('#announced-inbounds').text(stats.announced || 0);
                $('#pending-inbounds').text(stats.pending || 0);
            }
        });
    }

    function loadInbounds() {
        const params = {
            action: 'wc_wms_get_inbounds',
            nonce: (typeof WC_WMS_ADMIN_NONCE !== 'undefined' ? WC_WMS_ADMIN_NONCE : ''),
            page: currentPage,
            limit: 10
        };

        // Add filters
        const status = $('#status-filter').val();
        const fromDate = $('#from-date').val();
        const toDate = $('#to-date').val();

        if (status) params.status = status;
        if (fromDate) params.from = fromDate;
        if (toDate) params.to = toDate;

        $.post(ajaxurl, params, function(response) {
            const tbody = $('#inbounds-table tbody');
            tbody.empty();

            if (response.success && response.data.length > 0) {
                response.data.forEach(function(inbound) {
                    const row = `
                        <tr>
                            <td><strong>${inbound.reference}</strong></td>
                            <td>${inbound.external_reference}</td>
                            <td><span class="status-${inbound.status}">${getStatusIcon(inbound.status)} ${inbound.status}</span></td>
                            <td>${formatDate(inbound.inbound_date)}</td>
                            <td>${inbound.inbound_lines ? inbound.inbound_lines.length : 0} lines</td>
                            <td>
                                <button class="button-small view-inbound" data-id="${inbound.id}">View</button>
                                ${inbound.status === 'announced' ? 
                                    `<button class="button-small cancel-inbound" data-id="${inbound.id}">Cancel</button>` : 
                                    ''}
                            </td>
                        </tr>
                    `;
                    tbody.append(row);
                });
                
                updatePagination(response.data.length);
            } else {
                tbody.append('<tr><td colspan="6" class="loading">No inbounds found</td></tr>');
                updatePagination(0);
            }
        });
    }

    function addInboundLine() {
        const container = $('#inbound-lines');
        const newLine = `
            <div class="inbound-line" data-index="${lineIndex}">
                <div class="line-fields">
                    <input type="text" name="article_code" placeholder="Article Code/SKU *" required>
                    <input type="number" name="quantity" placeholder="Quantity *" min="1" required>
                    <input type="number" name="packing_slip" placeholder="Packing Slip">
                    <button type="button" class="button remove-line" title="Remove">
                        ❌
                    </button>
                </div>
            </div>
        `;
        container.append(newLine);
        lineIndex++;
    }

    function removeInboundLine() {
        if ($('.inbound-line').length > 1) {
            $(this).closest('.inbound-line').remove();
        } else {
            showNotice('At least one line is required', 'error');
        }
    }

    function addInboundLine() {
        const container = $('#inbound-lines');
        const newLine = `
            <div class="inbound-line" data-index="${lineIndex}">
                <div class="line-fields">
                    <input type="text" name="article_code" placeholder="Article Code/SKU *" required>
                    <input type="number" name="quantity" placeholder="Quantity *" min="1" required>
                    <input type="number" name="packing_slip" placeholder="Packing Slip">
                    <button type="button" class="button remove-line" title="Remove">
                        ❌
                    </button>
                </div>
            </div>
        `;
        container.append(newLine);
        lineIndex++;
    }

    function removeInboundLine() {
        if ($('.inbound-line').length > 1) {
            $(this).closest('.inbound-line').remove();
        } else {
            showNotice('At least one line is required', 'error');
        }
    }

    function createInbound(e) {
        e.preventDefault();

        const lines = [];
        $('.inbound-line').each(function() {
            const articleCode = $(this).find('[name="article_code"]').val();
            const quantity = $(this).find('[name="quantity"]').val();
            const packingSlip = $(this).find('[name="packing_slip"]').val();

            if (articleCode && quantity) {
                lines.push({
                    article_code: articleCode,
                    quantity: parseInt(quantity),
                    packing_slip: parseInt(packingSlip || quantity)
                });
            }
        });

        if (lines.length === 0) {
            showNotice('Please add at least one inbound line', 'error');
            return;
        }

        $.post(ajaxurl, {
            action: 'wc_wms_create_inbound',
            nonce: (typeof WC_WMS_ADMIN_NONCE !== 'undefined' ? WC_WMS_ADMIN_NONCE : ''),
            external_reference: $('#external-reference').val(),
            inbound_date: $('#inbound-date').val(),
            note: $('#note').val(),
            is_return: $('#is-return').is(':checked') ? 1 : 0,
            inbound_lines: JSON.stringify(lines)
        }, function(response) {
            if (response.success) {
                showNotice('Inbound created successfully: ' + response.data.reference, 'success');
                $('#create-inbound-form')[0].reset();
                loadInbounds();
                loadStats();
            } else {
                showNotice('Error: ' + response.data.message, 'error');
            }
        });
    }

    function viewInbound() {
        const inboundId = $(this).data('id');
        
        $.post(ajaxurl, {
            action: 'wc_wms_get_inbound_details',
            nonce: (typeof WC_WMS_ADMIN_NONCE !== 'undefined' ? WC_WMS_ADMIN_NONCE : ''),
            inbound_id: inboundId
        }, function(response) {
            if (response.success) {
                const inbound = response.data;
                let content = `
                    <h4>Inbound: ${inbound.reference}</h4>
                    <p><strong>External Reference:</strong> ${inbound.external_reference}</p>
                    <p><strong>Status:</strong> <span class="status-${inbound.status}">${getStatusIcon(inbound.status)} ${inbound.status}</span></p>
                    <p><strong>Date:</strong> ${formatDate(inbound.inbound_date)}</p>
                    <p><strong>Type:</strong> ${inbound.is_return ? 'Return Inbound' : 'Regular Inbound'}</p>
                    <p><strong>Note:</strong> ${inbound.note || 'No note'}</p>
                    
                    <h5>Inbound Lines</h5>
                    <table class="wp-list-table widefat">
                        <thead>
                            <tr>
                                <th>Article Code</th>
                                <th>Quantity</th>
                                <th>Processed</th>
                                <th>Packing Slip</th>
                            </tr>
                        </thead>
                        <tbody>
                `;

                if (inbound.inbound_lines) {
                    inbound.inbound_lines.forEach(function(line) {
                        content += `
                            <tr>
                                <td>${line.variant?.article_code || 'N/A'}</td>
                                <td>${line.quantity}</td>
                                <td>${line.processed || 0}</td>
                                <td>${line.packing_slip}</td>
                            </tr>
                        `;
                    });
                }

                content += `</tbody></table>`;
                $('#inbound-details').html(content);
                $('#inbound-modal').show();
            } else {
                showNotice('Error loading inbound details: ' + response.data.message, 'error');
            }
        });
    }

    function cancelInbound() {
        if (!confirm('Are you sure you want to cancel this inbound?')) return;

        const inboundId = $(this).data('id');
        
        $.post(ajaxurl, {
            action: 'wc_wms_cancel_inbound',
            nonce: (typeof WC_WMS_ADMIN_NONCE !== 'undefined' ? WC_WMS_ADMIN_NONCE : ''),
            inbound_id: inboundId
        }, function(response) {
            if (response.success) {
                showNotice('Inbound cancelled successfully', 'success');
                loadInbounds();
                loadStats();
            } else {
                showNotice('Error: ' + response.data.message, 'error');
            }
        });
    }

    function updatePagination(itemCount) {
        $('#prev-page').prop('disabled', currentPage <= 1);
        $('#next-page').prop('disabled', itemCount < 10);
        $('#page-info').text(`Page ${currentPage}`);
    }

    function formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleDateString();
    }

    function getStatusIcon(status) {
        switch(status) {
            case 'completed': return '✅';
            case 'announced': return '⚠️';
            case 'cancelled': return '❌';
            case 'pending': return '🔄';
            default: return '🔄';
        }
    }

    function showNotice(message, type) {
        const notice = $(`<div class="notice notice-${type} is-dismissible"><p>${message}</p></div>`);
        $('#inbound-tab h2').after(notice);
        setTimeout(() => notice.remove(), 5000);
    }

    // Close modal when clicking outside
    $('#inbound-modal').click(function(e) {
        if (e.target === this) {
            $(this).hide();
        }
    });
});
</script>
