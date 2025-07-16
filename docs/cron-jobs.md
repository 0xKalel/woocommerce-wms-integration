# WMS Integration Cron Jobs

## Overview

The WMS integration uses automated cron jobs for reliable background synchronization between WooCommerce and the WMS system, including webhook queue processing for ordered event handling.

## Scheduled Jobs

### Webhook Queue Processing
```php
'wc_wms_process_webhook_queue' → Every 1 minute
```
- **Purpose**: Process incoming webhooks in correct order with prerequisite checking
- **Direction**: WMS → WooCommerce  
- **Batch size**: 20 webhooks per execution
- **Features**: Prerequisite validation, priority processing, duplicate prevention

### Order Processing
```php
'wc_wms_process_order_queue' → Every 2 minutes
```
- **Purpose**: Export new WooCommerce orders to WMS
- **Direction**: WooCommerce → WMS
- **Batch size**: 10 orders per execution
- **Retry logic**: Failed orders automatically retried

### Stock Synchronization
```php
'wc_wms_sync_stock' → Every hour
```
- **Purpose**: Import current stock levels from WMS
- **Direction**: WMS → WooCommerce
- **Updates**: Product availability and quantities

### Order Synchronization
```php
'wc_wms_sync_orders' → Every 2 hours
```
- **Purpose**: Import order updates from WMS (backup for webhook failures)
- **Direction**: WMS → WooCommerce
- **Scope**: Recent orders (last 50)

### Inbound Synchronization
```php
'wc_wms_sync_inbounds' → Every 4 hours
```
- **Purpose**: Track inventory receipts and inbound status
- **Direction**: WMS → WooCommerce
- **Scope**: Last 7 days of inbounds

### Shipment Synchronization
```php
'wc_wms_sync_shipments' → Every 3 hours
```
- **Purpose**: Update order tracking information
- **Direction**: WMS → WooCommerce
- **Scope**: Last 3 days of shipments

### Maintenance Jobs
```php
'wc_wms_cleanup_queue' → Daily
'wc_wms_cleanup_webhooks' → Weekly  
'wc_wms_cleanup_sensitive_logs' → Daily
'wc_wms_reset_rate_limits' → Hourly
```

## Webhook Queue Processing

### Priority System
Events are processed by priority:
- **Priority 1-5**: Order lifecycle (`order.created`, `order.updated`, etc.)
- **Priority 10-11**: Stock updates (`stock.updated`)
- **Priority 15-18**: Shipment events (`shipment.created`, `shipment.updated`)
- **Priority 20+**: Inbound and article events

### Prerequisite Dependencies
Critical webhooks require prerequisites:
- `order.updated` requires `order.created`
- `order.planned` requires `order.created`
- `order.shipped` requires `order.created`
- `shipment.updated` requires `shipment.created`

### Processing Strategy
1. Webhooks are queued immediately upon arrival
2. System attempts immediate processing if prerequisites are met
3. Cron job processes remaining queue every minute
4. Failed webhooks are retried with exponential backoff

## Cron Management

### Check Status
```bash
# Via Make commands
make cron-status    # Check if cron is running
make cron-list      # List all scheduled jobs
make cron-logs      # View execution logs

# Via WP-CLI
wp cron event list
wp cron test
```

### Manual Execution
```bash
# Run all due cron jobs
make cron-run

# Run specific jobs
wp cron event run wc_wms_process_webhook_queue
wp cron event run wc_wms_sync_stock
wp cron event run wc_wms_process_order_queue
```

### WordPress Admin
Navigate to **WooCommerce → WMS Integration → Synchronization**:
- View last sync times for each job
- Webhook queue statistics (pending, processed, failed)
- Manual sync buttons for immediate execution
- Sync status indicators (✅ recent, ⚠️ overdue, ❌ failed)

## Configuration

### WordPress Cron (Default)
```php
// wp-config.php
define('DISABLE_WP_CRON', false);
```

### System Cron (Production Recommended)
```php
// wp-config.php
define('DISABLE_WP_CRON', true);
```

```bash
# crontab -e
* * * * * curl -s https://yourdomain.com/wp-cron.php >/dev/null 2>&1
```

### Custom Intervals
```php
'wc_wms_every_1min'   → 60 seconds   (Webhook queue)
'wc_wms_every_2min'   → 120 seconds  (Order queue)
'wc_wms_every_2hours' → 7200 seconds  
'wc_wms_every_3hours' → 10800 seconds
'wc_wms_every_4hours' → 14400 seconds
```

## Monitoring

### Webhook Queue Status
```bash
# Check queue statistics
wp eval "echo json_encode((new WC_WMS_Webhook_Queue_Manager())->getQueueStats());"

# View recent activity  
wp eval "print_r((new WC_WMS_Webhook_Queue_Manager())->getRecentActivity(10));"

# Retry failed webhooks
wp eval "(new WC_WMS_Webhook_Queue_Manager())->retryFailedWebhooks();"
```

### Log Files
```bash
# Cron execution logs
logs/cron/cron.log

# WordPress debug logs
logs/wordpress/debug.log

# Webhook processing logs
logs/wordpress/wc-wms-webhook-*.log
```

## Troubleshooting

### Webhook Queue Issues
```bash
# Check for stuck webhooks
wp eval "print_r((new WC_WMS_Webhook_Queue_Manager())->getQueueStats());"

# Force process webhook queue
wp cron event run wc_wms_process_webhook_queue

# Retry failed webhooks
wp eval "(new WC_WMS_Webhook_Queue_Manager())->retryFailedWebhooks();"
```

### General Cron Issues
```bash
# Check if WordPress cron is working
wp cron test

# Verify cron events are scheduled
wp cron event list --format=table

# Check for PHP errors
tail -f logs/wordpress/debug.log
```

### Emergency Recovery
```bash
# Clear all WMS cron jobs
wp cron event delete wc_wms_process_order_queue --all
wp cron event delete wc_wms_process_webhook_queue --all

# Restart cron system
wp plugin deactivate wc-wms-integration
wp plugin activate wc-wms-integration
```
