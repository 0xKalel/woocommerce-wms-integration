# WMS Integration Sync Logic

## Overview

The WMS integration uses **bidirectional sync** combining real-time webhooks with scheduled background synchronization. The system includes webhook queue processing to ensure proper event ordering and reliability.

## Data Flow

### WooCommerce → WMS (Orders)
```
New Order → Queue → WMS API (Every 2 min)
```
- **Purpose**: Send new orders for fulfillment
- **Method**: Queue-based processing with retry logic

### WMS → WooCommerce (Updates)
```
WMS Changes → Webhook Queue → Ordered Processing → WooCommerce (Real-time)
WMS Data → Cron Sync → WooCommerce (Scheduled backup)
```

## Webhook Processing Architecture

### Webhook Queue System
All incoming webhooks are processed through a queue to ensure proper ordering:

1. **Webhook Arrival**: Incoming webhook is validated and queued
2. **Duplicate Check**: X-Webhook-Id header prevents duplicate processing
3. **Priority Assignment**: Events assigned priority based on type
4. **Prerequisite Check**: Dependencies verified before processing
5. **Ordered Execution**: Events processed in correct sequence

### Event Ordering
The system ensures proper event sequence:
- `order.created` must be processed before `order.updated`
- `order.created` must be processed before `order.shipped`
- `shipment.created` must be processed before `shipment.updated`

### Processing Priority
Events are processed by priority:
1. **Order lifecycle** (Priority 1-5): `order.created`, `order.updated`, `order.shipped`
2. **Stock updates** (Priority 10-11): `stock.updated`, `stock.adjustment`
3. **Shipments** (Priority 15-18): `shipment.created`, `shipment.updated`
4. **Inbounds** (Priority 20+): `inbound.created`, `inbound.completed`

## Sync Methods

### Real-time (Webhooks)
Processed through webhook queue with ordering:
- **Order updates**: `order.created`, `order.updated`, `order.shipped`
- **Stock changes**: `stock.updated`, `stock.adjustment`
- **Shipment tracking**: `shipment.created`, `shipment.updated`
- **Inbound completion**: `inbound.created`, `inbound.completed`

### Scheduled (Crons)
Backup synchronization for reliability:
- **Webhook queue**: Every 1 minute (queue processing)
- **Order queue**: Every 2 minutes (WC → WMS)
- **Stock sync**: Every hour (WMS → WC)
- **Order sync**: Every 2 hours (WMS → WC)
- **Inbound sync**: Every 4 hours (WMS → WC)
- **Shipment sync**: Every 3 hours (WMS → WC)

## Reliability Features

### Webhook Reliability
- **Duplicate Detection**: X-Webhook-Id header validation
- **HMAC Verification**: Cryptographic signature validation
- **Ordering Enforcement**: Prerequisite checking prevents out-of-order processing
- **Queue Persistence**: Webhooks stored in database until processed

### Error Handling
- **Retry Strategy**: Exponential backoff (30s, 2m, 5m, 15m, 1h)
- **Max Attempts**: 3 retries before marking as failed
- **Queue Recovery**: Failed items can be manually retried
- **Deadlock Prevention**: Timeout handling for stuck prerequisites

### Rate Limiting
- **API Protection**: Prevents exceeding WMS API limits
- **Backoff Strategy**: Automatic delays when limits approached
- **Monitoring**: Rate limit status tracking and alerts

## Processing Strategy

### Hybrid Approach
The system uses both immediate and queued processing:

1. **Immediate Attempt**: When webhook arrives, system immediately tries to process
2. **Queue Fallback**: If prerequisites not met, webhook waits in queue
3. **Cron Processing**: Every minute cron processes remaining queue items
4. **Order Preservation**: Critical events always processed in correct sequence

### Batch Processing
- **Webhook Queue**: 20 items per batch
- **Order Queue**: 10 orders per batch
- **Stock Sync**: All products with changes
- **Backlog Management**: Automatic scaling during high-volume periods

## Key Features

- **Queue-based reliability**: No events lost, proper ordering guaranteed
- **Webhook validation**: HMAC signature and duplicate prevention
- **Prerequisite enforcement**: Dependencies verified before processing
- **Comprehensive logging**: Full audit trail with performance metrics
- **Manual controls**: Admin interface for queue management
- **Auto-recovery**: Missing cron jobs automatically rescheduled
- **Real-time processing**: Immediate webhook processing when possible

## Data Integrity

### Order Lifecycle Protection
The webhook queue prevents common data integrity issues:
- Order updates arriving before order creation
- Shipment updates without shipment creation
- Status changes processed out of sequence

### Consistency Guarantees
- **Event Ordering**: Prerequisites ensure logical event sequence
- **Duplicate Prevention**: X-Webhook-Id prevents double processing
- **Atomic Operations**: Database transactions ensure data consistency
- **Recovery Mechanisms**: Failed operations can be safely retried
