<?php
/**
 * WMS Integration Constants
 * 
 * Centralized constants to eliminate magic numbers throughout the codebase
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Constants {
    
    // HTTP Status Codes
    const HTTP_OK = 200;
    const HTTP_CREATED = 201;
    const HTTP_NO_CONTENT = 204;
    const HTTP_BAD_REQUEST = 400;
    const HTTP_UNAUTHORIZED = 401;
    const HTTP_FORBIDDEN = 403;
    const HTTP_NOT_FOUND = 404;
    const HTTP_METHOD_NOT_ALLOWED = 405;
    const HTTP_TOO_MANY_REQUESTS = 429;
    const HTTP_SERVER_ERROR_START = 500;
    
    // Timeout Values (in seconds)
    const WEBHOOK_STALE_TIMEOUT = 300;      // 5 minutes
    const TOKEN_REFRESH_BUFFER = 300;       // 5 minutes
    const RATE_LIMIT_WAIT_MAX = 300;        // 5 minutes
    const REQUEST_TIMEOUT = 30;             // 30 seconds
    
    // Retry Configuration
    const RETRY_INTERVALS = [30, 120, 300, 900, 3600]; // 30s, 2m, 5m, 15m, 1h
    const MAX_RETRIES = 3;
    
    // Retention periods (in days)
    const CLEANUP_COMPLETED_DAYS = 7;
    const CLEANUP_FAILED_DAYS = 30;
    const CLEANUP_LOGS_DAYS = 30;
    
    // Rate Limiting
    const RATE_LIMIT_DEFAULT = 3600;        // Requests per hour
    const RATE_LIMIT_THRESHOLD = 10;        // Remaining requests threshold
    
    // Cron Intervals (in seconds)
    const CRON_QUEUE_INTERVAL = 120;        // 2 minutes
    const CRON_WEBHOOK_QUEUE_INTERVAL = 60; // 1 minute for webhook queue
    const CRON_URGENT_INTERVAL = 300;       // 5 minutes
    const CRON_REGULAR_INTERVAL = 900;      // 15 minutes
    const CRON_HOURLY_CHECK = 3600;         // 1 hour
    
    // Queue Status
    const QUEUE_STATUS_PENDING = 'pending';
    const QUEUE_STATUS_PROCESSING = 'processing';
    const QUEUE_STATUS_COMPLETED = 'completed';
    const QUEUE_STATUS_FAILED = 'failed';
    
    // Webhook Events
    const WEBHOOK_ORDER_CREATED = 'order.created';
    const WEBHOOK_ORDER_UPDATED = 'order.updated';
    const WEBHOOK_ORDER_SHIPPED = 'order.shipped';
    const WEBHOOK_STOCK_UPDATED = 'stock.updated';
    const WEBHOOK_SHIPMENT_CREATED = 'shipment.created';
    const WEBHOOK_SHIPMENT_UPDATED = 'shipment.updated';
    const WEBHOOK_INBOUND_CREATED = 'inbound.created';
    const WEBHOOK_INBOUND_UPDATED = 'inbound.updated';
    const WEBHOOK_INBOUND_COMPLETED = 'inbound.completed';
    
    // WMS Endpoints
    const ENDPOINT_AUTH = '/wms/auth/login/';
    const ENDPOINT_REFRESH = '/wms/auth/refresh/';
    const ENDPOINT_ORDERS = '/wms/orders/';
    const ENDPOINT_ARTICLES = '/wms/articles/';
    const ENDPOINT_VARIANTS = '/wms/variants/';
    const ENDPOINT_SHIPMENTS = '/wms/shipments/';
    const ENDPOINT_LOCATION_TYPES = '/wms/locationtypes/';
    const ENDPOINT_LOCATION_TYPE = '/wms/locationtype/';
    const ENDPOINT_MODIFICATIONS = '/wms/modifications/';
    const ENDPOINT_WEBHOOKS = '/wms/webhooks/';
    const ENDPOINT_GDPR_EXPORT = '/wms/gdpr/request-person-data/';
    const ENDPOINT_GDPR_REDACT = '/wms/gdpr/redact-person-data/';
    const ENDPOINT_INBOUNDS = '/wms/inbounds/';
    const ENDPOINT_QUALITY_CONTROLS = '/wms/quality-controls/';
    
    // Modification Reasons
    const MODIFICATION_REASON_LOST = 'LOST';
    const MODIFICATION_REASON_DEFECTIVE = 'DEFECTIVE';
    const MODIFICATION_REASON_CORRECTION = 'CORRECTION';
    
    // Modification Status
    const MODIFICATION_STATUS_CREATED = 'created';
    const MODIFICATION_STATUS_APPROVED = 'approved';
    const MODIFICATION_STATUS_DISAPPROVED = 'disapproved';
    
    // Database Table Names
    const TABLE_QUEUE = 'wc_wms_integration_queue';
    const TABLE_PRODUCT_QUEUE = 'wc_wms_product_sync_queue';
    const TABLE_WEBHOOK_PROCESSING_QUEUE = 'wc_wms_webhook_processing_queue';
    const TABLE_API_LOGS = 'wc_wms_api_logs';
    const TABLE_WEBHOOK_LOGS = 'wc_wms_webhook_logs';
    const TABLE_WEBHOOK_IDS = 'wc_wms_webhook_ids';
}
