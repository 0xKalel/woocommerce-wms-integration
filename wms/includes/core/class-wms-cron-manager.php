<?php
/**
 * WMS Cron Manager
 * 
 * Handles all cron job operations for the WMS integration plugin
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Cron_Manager {
    
    /**
     * Schedule all cron jobs
     */
    public static function scheduleJobs(): void {
        // Clear any existing schedules first
        self::clearJobs();
        
        // Schedule order queue processing (every 2 minutes)
        if (!wp_next_scheduled('wc_wms_process_order_queue')) {
            wp_schedule_event(time(), 'wc_wms_every_2min', 'wc_wms_process_order_queue');
        }
        
        // Schedule webhook queue processing (every 1 minute)
        if (!wp_next_scheduled('wc_wms_process_webhook_queue')) {
            wp_schedule_event(time() + 30, 'wc_wms_every_1min', 'wc_wms_process_webhook_queue');
        }
        
        // Schedule stock synchronization (every hour)
        if (!wp_next_scheduled('wc_wms_sync_stock')) {
            wp_schedule_event(time(), 'hourly', 'wc_wms_sync_stock');
        }
        
        // Schedule order synchronization (every 2 hours)
        if (!wp_next_scheduled('wc_wms_sync_orders')) {
            wp_schedule_event(time() + 60, 'wc_wms_every_2hours', 'wc_wms_sync_orders');
        }
        
        // Schedule inbound synchronization (every 4 hours) 
        if (!wp_next_scheduled('wc_wms_sync_inbounds')) {
            wp_schedule_event(time() + 120, 'wc_wms_every_4hours', 'wc_wms_sync_inbounds');
        }
        
        // Schedule shipment synchronization (every 3 hours)
        if (!wp_next_scheduled('wc_wms_sync_shipments')) {
            wp_schedule_event(time() + 180, 'wc_wms_every_3hours', 'wc_wms_sync_shipments');
        }
        
        // Schedule product synchronization (every 6 hours)
        if (!wp_next_scheduled('wc_wms_sync_products')) {
            wp_schedule_event(time() + 300, 'twicedaily', 'wc_wms_sync_products');
        }
        
        // Schedule queue cleanup (daily)
        if (!wp_next_scheduled('wc_wms_cleanup_queue')) {
            wp_schedule_event(time(), 'daily', 'wc_wms_cleanup_queue');
        }
        
        // Schedule webhook cleanup (weekly)
        if (!wp_next_scheduled('wc_wms_cleanup_webhooks')) {
            wp_schedule_event(time(), 'weekly', 'wc_wms_cleanup_webhooks');
        }
        
        // Schedule sensitive log cleanup (daily) - GDPR compliance
        if (!wp_next_scheduled('wc_wms_cleanup_sensitive_logs')) {
            wp_schedule_event(time(), 'daily', 'wc_wms_cleanup_sensitive_logs');
        }
        
        // Schedule rate limit reset check (hourly)
        if (!wp_next_scheduled('wc_wms_reset_rate_limits')) {
            wp_schedule_event(time(), 'hourly', 'wc_wms_reset_rate_limits');
        }
    }
    
    /**
     * Clear all scheduled cron jobs
     */
    public static function clearJobs(): void {
        $cron_hooks = [
            'wc_wms_process_order_queue',
            'wc_wms_process_webhook_queue',
            'wc_wms_sync_stock',
            'wc_wms_sync_orders',
            'wc_wms_sync_inbounds', 
            'wc_wms_sync_shipments',
            'wc_wms_sync_products',
            'wc_wms_cleanup_queue',
            'wc_wms_cleanup_webhooks',
            'wc_wms_cleanup_sensitive_logs',
            'wc_wms_reset_rate_limits'
        ];
        
        foreach ($cron_hooks as $hook) {
            $timestamp = wp_next_scheduled($hook);
            if ($timestamp) {
                wp_unschedule_event($timestamp, $hook);
            }
            // Also clear all scheduled instances
            wp_clear_scheduled_hook($hook);
        }
    }
    
    /**
     * Add custom cron intervals
     */
    public static function addCustomIntervals($schedules): array {
        // Every 1 minute for webhook queue processing
        $schedules['wc_wms_every_1min'] = array(
            'interval' => WC_WMS_Constants::CRON_WEBHOOK_QUEUE_INTERVAL,
            'display'  => __('Every 1 Minute (WMS Webhook Queue)', 'wc-wms-integration')
        );
        
        // Every 2 minutes for queue processing
        $schedules['wc_wms_every_2min'] = array(
            'interval' => WC_WMS_Constants::CRON_QUEUE_INTERVAL,
            'display'  => __('Every 2 Minutes (WMS Queue)', 'wc-wms-integration')
        );
        
        // Every 5 minutes for monitoring and stuck webhook checks
        $schedules['wc_wms_every_5min'] = array(
            'interval' => WC_WMS_Constants::CRON_URGENT_INTERVAL,
            'display'  => __('Every 5 Minutes (WMS Monitoring)', 'wc-wms-integration')
        );
        
        // Every 15 minutes for health checks
        $schedules['wc_wms_every_15min'] = array(
            'interval' => WC_WMS_Constants::CRON_REGULAR_INTERVAL,
            'display'  => __('Every 15 Minutes (WMS Health Check)', 'wc-wms-integration')
        );
        
        // Every 2 hours for order sync
        $schedules['wc_wms_every_2hours'] = array(
            'interval' => 7200, // 2 hours
            'display'  => __('Every 2 Hours (WMS Orders)', 'wc-wms-integration')
        );
        
        // Every 3 hours for shipment sync
        $schedules['wc_wms_every_3hours'] = array(
            'interval' => 10800, // 3 hours
            'display'  => __('Every 3 Hours (WMS Shipments)', 'wc-wms-integration')
        );
        
        // Every 4 hours for inbound sync
        $schedules['wc_wms_every_4hours'] = array(
            'interval' => 14400, // 4 hours
            'display'  => __('Every 4 Hours (WMS Inbounds)', 'wc-wms-integration')
        );
        
        return $schedules;
    }
    
    /**
     * Ensure cron jobs are scheduled
     */
    public static function ensureJobsScheduled(): void {
        // Only run this check occasionally to avoid performance impact
        $last_check = get_option('wc_wms_cron_last_check', 0);
        if (time() - $last_check < WC_WMS_Constants::CRON_HOURLY_CHECK) {
            return;
        }
        
        $missing_jobs = [];
        
        // Check if essential cron jobs are scheduled
        if (!wp_next_scheduled('wc_wms_process_order_queue')) {
            wp_schedule_event(time(), 'wc_wms_every_2min', 'wc_wms_process_order_queue');
            $missing_jobs[] = 'order_queue';
        }
        
        if (!wp_next_scheduled('wc_wms_process_webhook_queue')) {
            wp_schedule_event(time() + 30, 'wc_wms_every_1min', 'wc_wms_process_webhook_queue');
            $missing_jobs[] = 'webhook_queue';
        }
        
        if (!wp_next_scheduled('wc_wms_sync_stock')) {
            wp_schedule_event(time() + 60, 'hourly', 'wc_wms_sync_stock');
            $missing_jobs[] = 'stock_sync';
        }
        
        if (!wp_next_scheduled('wc_wms_sync_orders')) {
            wp_schedule_event(time() + 120, 'wc_wms_every_2hours', 'wc_wms_sync_orders');
            $missing_jobs[] = 'order_sync';
        }
        
        if (!wp_next_scheduled('wc_wms_sync_inbounds')) {
            wp_schedule_event(time() + 180, 'wc_wms_every_4hours', 'wc_wms_sync_inbounds');
            $missing_jobs[] = 'inbound_sync';
        }
        
        if (!wp_next_scheduled('wc_wms_sync_shipments')) {
            wp_schedule_event(time() + 240, 'wc_wms_every_3hours', 'wc_wms_sync_shipments');
            $missing_jobs[] = 'shipment_sync';
        }
        
        if (!wp_next_scheduled('wc_wms_sync_products')) {
            wp_schedule_event(time() + 120, 'twicedaily', 'wc_wms_sync_products');
            $missing_jobs[] = 'product_sync';
        }
        
        // NEW: Enhanced webhook management jobs
        if (!wp_next_scheduled('wc_wms_check_stuck_webhooks')) {
            wp_schedule_event(time() + 300, 'wc_wms_every_5min', 'wc_wms_check_stuck_webhooks');
            $missing_jobs[] = 'stuck_webhook_check';
        }
        
        if (!wp_next_scheduled('wc_wms_webhook_health_check')) {
            wp_schedule_event(time() + 360, 'wc_wms_every_15min', 'wc_wms_webhook_health_check');
            $missing_jobs[] = 'webhook_health_check';
        }
        
        if (!empty($missing_jobs)) {
            error_log('WMS Integration: Rescheduled missing cron jobs: ' . implode(', ', $missing_jobs));
        }
        
        update_option('wc_wms_cron_last_check', time());
    }
}
