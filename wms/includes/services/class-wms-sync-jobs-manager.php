<?php
/**
 * WMS Sync Jobs Manager
 * 
 * Manages queue-based "Sync Everything" operations with individual jobs
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Sync_Jobs_Manager {
    
    private $jobs_table;
    private $wmsClient;
    
    /**
     * Sync job types in execution order
     */
    const SYNC_JOBS = [
        'connection_test' => [
            'title' => 'Testing WMS Connection',
            'priority' => 1,
            'timeout' => 30
        ],
        'webhook_registration' => [
            'title' => 'Registering Webhooks',
            'priority' => 2,
            'timeout' => 60
        ],
        'shipping_methods' => [
            'title' => 'Syncing Shipping Methods',
            'priority' => 3,
            'timeout' => 45
        ],
        'location_types' => [
            'title' => 'Syncing Location Types',
            'priority' => 4,
            'timeout' => 45
        ],
        'articles_import' => [
            'title' => 'Importing Articles/Products',
            'priority' => 5,
            'timeout' => 120
        ],
        'stock_sync' => [
            'title' => 'Syncing Stock Levels',
            'priority' => 6,
            'timeout' => 120
        ],
        'customers_import' => [
            'title' => 'Importing Customers',
            'priority' => 7,
            'timeout' => 90
        ],
        'orders_sync' => [
            'title' => 'Syncing Orders',
            'priority' => 8,
            'timeout' => 120
        ],
        'inbounds_sync' => [
            'title' => 'Syncing Inbounds',
            'priority' => 9,
            'timeout' => 90
        ],
        'shipments_sync' => [
            'title' => 'Syncing Shipments',
            'priority' => 10,
            'timeout' => 90
        ]
    ];
    
    public function __construct() {
        global $wpdb;
        $this->jobs_table = $wpdb->prefix . 'wc_wms_sync_jobs';
        $this->wmsClient = WC_WMS_Service_Container::getWmsClient();
        
        // Create table on first instantiation
        $this->createJobsTable();
    }
    
    /**
     * Start a full "Sync Everything" operation
     */
    public function startSyncEverything(): string {
        $batch_id = $this->generateBatchId();
        
        $this->wmsClient->logger()->info('Starting Sync Everything batch', [
            'batch_id' => $batch_id,
            'total_jobs' => count(self::SYNC_JOBS)
        ]);
        
        // Queue all sync jobs
        foreach (self::SYNC_JOBS as $job_type => $config) {
            $this->queueSyncJob($batch_id, $job_type, $config);
        }
        
        // Trigger immediate job processing
        $this->triggerJobProcessing();
        
        return $batch_id;
    }
    
    /**
     * Get sync progress for a batch
     */
    public function getSyncProgress(string $batch_id): array {
        global $wpdb;
        
        $jobs = $wpdb->get_results($wpdb->prepare(
            "SELECT job_type, status, result_data, error_message, started_at, completed_at
             FROM {$this->jobs_table} 
             WHERE batch_id = %s 
             ORDER BY priority ASC",
            $batch_id
        ), ARRAY_A);
        
        if (empty($jobs)) {
            return ['error' => 'Batch not found', 'batch_id' => $batch_id];
        }
        
        $progress = [
            'batch_id' => $batch_id,
            'total_jobs' => count($jobs),
            'completed_jobs' => 0,
            'failed_jobs' => 0,
            'current_job' => null,
            'overall_status' => 'running',
            'jobs' => [],
            'started_at' => null,
            'completed_at' => null
        ];
        
        $first_started = null;
        $last_completed = null;
        
        foreach ($jobs as $job) {
            $job_config = self::SYNC_JOBS[$job['job_type']] ?? ['title' => $job['job_type']];
            
            $job_progress = [
                'type' => $job['job_type'],
                'title' => $job_config['title'],
                'status' => $job['status'],
                'result' => $job['result_data'] ? json_decode($job['result_data'], true) : null,
                'error' => $job['error_message'],
                'started_at' => $job['started_at'],
                'completed_at' => $job['completed_at']
            ];
            
            $progress['jobs'][] = $job_progress;
            
            // Track timing
            if ($job['started_at'] && (!$first_started || $job['started_at'] < $first_started)) {
                $first_started = $job['started_at'];
            }
            
            if ($job['completed_at'] && (!$last_completed || $job['completed_at'] > $last_completed)) {
                $last_completed = $job['completed_at'];
            }
            
            // Count status
            switch ($job['status']) {
                case 'completed':
                    $progress['completed_jobs']++;
                    break;
                case 'failed':
                    $progress['failed_jobs']++;
                    break;
                case 'processing':
                    $progress['current_job'] = $job_progress;
                    break;
            }
        }
        
        $progress['started_at'] = $first_started;
        $progress['completed_at'] = $last_completed;
        
        // Determine overall status
        if ($progress['completed_jobs'] + $progress['failed_jobs'] >= $progress['total_jobs']) {
            $progress['overall_status'] = $progress['failed_jobs'] > 0 ? 'completed_with_errors' : 'completed';
        } elseif ($progress['current_job']) {
            $progress['overall_status'] = 'running';
        } else {
            $progress['overall_status'] = 'pending';
        }
        
        // Calculate percentage
        $progress['percentage'] = round(
            (($progress['completed_jobs'] + $progress['failed_jobs']) / $progress['total_jobs']) * 100
        );
        
        return $progress;
    }
    
    /**
     * Process next pending job
     */
    public function processNextJob(): ?array {
        global $wpdb;
        
        // Get next pending job
        $job = $wpdb->get_row(
            "SELECT * FROM {$this->jobs_table} 
             WHERE status = 'pending' 
             ORDER BY priority ASC, created_at ASC 
             LIMIT 1",
            ARRAY_A
        );
        
        if (!$job) {
            return null; // No pending jobs
        }
        
        return $this->processJob($job);
    }
    
    /**
     * Process a specific job
     */
    private function processJob(array $job): array {
        global $wpdb;
        
        $job_id = $job['id'];
        $job_type = $job['job_type'];
        
        // Mark as processing
        $wpdb->update(
            $this->jobs_table,
            [
                'status' => 'processing',
                'started_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ],
            ['id' => $job_id],
            ['%s', '%s', '%s'],
            ['%d']
        );
        
        $this->wmsClient->logger()->info("Processing sync job: {$job_type}", [
            'job_id' => $job_id,
            'batch_id' => $job['batch_id']
        ]);
        
        try {
            $result = $this->executeSyncJob($job_type);
            
            // Mark as completed
            $wpdb->update(
                $this->jobs_table,
                [
                    'status' => 'completed',
                    'result_data' => wp_json_encode($result),
                    'completed_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ],
                ['id' => $job_id],
                ['%s', '%s', '%s', '%s'],
                ['%d']
            );
            
            $this->wmsClient->logger()->info("Sync job completed: {$job_type}", [
                'job_id' => $job_id,
                'result' => $result
            ]);
            
            return ['success' => true, 'result' => $result];
            
        } catch (Exception $e) {
            // Mark as failed
            $wpdb->update(
                $this->jobs_table,
                [
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'completed_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ],
                ['id' => $job_id],
                ['%s', '%s', '%s', '%s'],
                ['%d']
            );
            
            $this->wmsClient->logger()->error("Sync job failed: {$job_type}", [
                'job_id' => $job_id,
                'error' => $e->getMessage()
            ]);
            
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Execute specific sync job
     */
    private function executeSyncJob(string $job_type): array {
        switch ($job_type) {
            case 'connection_test':
                return $this->wmsClient->testConnection();
                
            case 'webhook_registration':
                $result = $this->wmsClient->webhookIntegrator()->registerAllWebhooks();
                if (is_wp_error($result)) {
                    throw new Exception($result->get_error_message());
                }
                return $result;
                
            case 'shipping_methods':
                $result = $this->wmsClient->shipmentIntegrator()->syncShippingMethods();
                if (is_wp_error($result)) {
                    throw new Exception($result->get_error_message());
                }
                return $result;
                
            case 'location_types':
                return $this->wmsClient->locationTypes()->syncLocationTypes();
                
            case 'articles_import':
                $productSync = WC_WMS_Service_Container::getProductSync();
                $result = $productSync->import_articles_from_wms(['limit' => 25]);
                if (is_wp_error($result)) {
                    throw new Exception($result->get_error_message());
                }
                return $result;
                
            case 'stock_sync':
                return $this->wmsClient->stockIntegrator()->syncAllStock(25);
                
            case 'customers_import':
                $customerSync = WC_WMS_Service_Container::getCustomerSync();
                $result = $customerSync->import_customers_from_wms(['limit' => 25]);
                if (is_wp_error($result)) {
                    throw new Exception($result->get_error_message());
                }
                return $result;
                
            case 'orders_sync':
                $orderSyncManager = new WC_WMS_Order_Sync_Manager($this->wmsClient);
                return $orderSyncManager->processManualOrderSync();
                
            case 'inbounds_sync':
                $inboundService = WC_WMS_Service_Container::getInboundService();
                $params = [
                    'limit' => 25,
                    'from' => date('Y-m-d', strtotime('-30 days')),
                    'sort' => 'inboundDate',
                    'direction' => 'desc'
                ];
                
                $inbounds = $inboundService->getInbounds($params);
                if (is_wp_error($inbounds)) {
                    throw new Exception($inbounds->get_error_message());
                }
                
                return ['total_synced' => count($inbounds), 'inbounds' => $inbounds];
                
            case 'shipments_sync':
                $shipmentIntegrator = $this->wmsClient->shipmentIntegrator();
                $shipments = $shipmentIntegrator->getRecentShipments(3, 25);
                
                $orders_updated = 0;
                foreach ($shipments as $shipment) {
                    try {
                        if (is_array($shipment)) {
                            $result = $shipmentIntegrator->processShipmentWebhook($shipment);
                            if ($result['success']) {
                                $orders_updated++;
                            }
                        }
                    } catch (Exception $e) {
                        // Log but continue
                        $this->wmsClient->logger()->warning('Shipment processing error', [
                            'shipment' => $shipment,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
                
                return [
                    'total_synced' => count($shipments),
                    'orders_updated' => $orders_updated,
                    'shipments' => $shipments
                ];
                
            default:
                throw new Exception("Unknown job type: {$job_type}");
        }
    }
    
    /**
     * Queue a sync job
     */
    private function queueSyncJob(string $batch_id, string $job_type, array $config): bool {
        global $wpdb;
        
        return $wpdb->insert(
            $this->jobs_table,
            [
                'batch_id' => $batch_id,
                'job_type' => $job_type,
                'priority' => $config['priority'],
                'status' => 'pending',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ],
            ['%s', '%s', '%d', '%s', '%s', '%s']
        ) !== false;
    }
    
    /**
     * Generate unique batch ID
     */
    private function generateBatchId(): string {
        return 'sync_' . date('Ymd_His') . '_' . wp_generate_password(8, false);
    }
    
    /**
     * Create jobs table
     */
    private function createJobsTable(): void {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->jobs_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            batch_id varchar(50) NOT NULL,
            job_type varchar(50) NOT NULL,
            priority int(11) NOT NULL DEFAULT 0,
            status enum('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
            result_data longtext,
            error_message text,
            created_at datetime NOT NULL,
            started_at datetime NULL,
            completed_at datetime NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY batch_id (batch_id),
            KEY status (status),
            KEY priority (priority)
        ) {$charset_collate};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Trigger job processing via WP-Cron
     */
    private function triggerJobProcessing(): void {
        // Schedule immediate processing
        if (!wp_next_scheduled('wc_wms_process_sync_jobs')) {
            wp_schedule_single_event(time(), 'wc_wms_process_sync_jobs');
        }
    }
    
    /**
     * Clean up old completed/failed jobs
     */
    public function cleanupOldJobs(int $days_old = 7): int {
        global $wpdb;
        
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days_old} days"));
        
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->jobs_table} 
             WHERE status IN ('completed', 'failed') 
             AND completed_at < %s",
            $cutoff_date
        ));
        
        return $deleted ?: 0;
    }
}
