<?php
/**
 * WMS Inbound Service
 * 
 * Handles all inbound operations with WMS
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Inbound_Service implements WC_WMS_Inbound_Service_Interface {
    
    /**
     * WMS client instance
     */
    private $client;
    
    /**
     * Get service name
     */
    public function getServiceName(): string {
        return 'inbound';
    }
    
    /**
     * Check if service is available
     */
    public function isAvailable(): bool {
        return $this->client->config()->hasValidCredentials();
    }
    
    /**
     * Get service configuration
     */
    public function getConfig(): array {
        return [
            'service_name' => $this->getServiceName(),
            'is_available' => $this->isAvailable(),
            'endpoints' => [
                'inbounds' => WC_WMS_Constants::ENDPOINT_INBOUNDS,
                'quality_controls' => WC_WMS_Constants::ENDPOINT_QUALITY_CONTROLS
            ]
        ];
    }
    
    /**
     * Constructor
     */
    public function __construct($client) {
        $this->client = $client;
    }
    
    /**
     * Get inbounds with optional filtering
     */
    public function getInbounds(array $params = []): mixed {
        try {
            $defaults = [
                'limit' => 10,
                'page' => 1,
                'direction' => 'desc',
                'sort' => 'inboundDate'
            ];
            
            $params = array_merge($defaults, $params);
            
            // Note: WMS API uses 'page' parameter, not offset conversion
            // Let the API handle pagination directly
            
            // Build query string for GET request
            $queryString = !empty($params) ? '?' . http_build_query($params) : '';
            $response = $this->client->makeAuthenticatedRequest('GET', WC_WMS_Constants::ENDPOINT_INBOUNDS . $queryString);
            
            if (is_wp_error($response)) {
                $this->client->logger()->error('Failed to fetch inbounds', [
                    'error' => $response->get_error_message(),
                    'params' => $params
                ]);
                return $response;
            }
            
            $this->client->logger()->debug('Fetched inbounds', [
                'count' => count($response),
                'params' => $params
            ]);
            
            return $response;
            
        } catch (Exception $e) {
            $this->client->logger()->error('Exception while fetching inbounds', [
                'error' => $e->getMessage(),
                'params' => $params
            ]);
            return new WP_Error('inbound_fetch_failed', $e->getMessage());
        }
    }
    
    /**
     * Get a specific inbound by ID
     */
    public function getInbound(string $inboundId): mixed {
        try {
            $params = ['expand' => 'inbound_lines,meta_data'];
            $queryString = !empty($params) ? '?' . http_build_query($params) : '';
            $response = $this->client->makeAuthenticatedRequest('GET', WC_WMS_Constants::ENDPOINT_INBOUNDS . "/{$inboundId}/" . $queryString);
            
            if (is_wp_error($response)) {
                $this->client->logger()->error('Failed to fetch inbound', [
                    'inbound_id' => $inboundId,
                    'error' => $response->get_error_message()
                ]);
                return $response;
            }
            
            $this->client->logger()->debug('Fetched inbound details', [
                'inbound_id' => $inboundId,
                'reference' => $response['reference'] ?? 'N/A'
            ]);
            
            return $response;
            
        } catch (Exception $e) {
            $this->client->logger()->error('Exception while fetching inbound', [
                'inbound_id' => $inboundId,
                'error' => $e->getMessage()
            ]);
            return new WP_Error('inbound_fetch_failed', $e->getMessage());
        }
    }
    
    /**
     * Create a new inbound
     */
    public function createInbound(array $inboundData): mixed {
        try {
            // Validate required fields according to WMS API docs
            if (empty($inboundData['external_reference'])) {
                return new WP_Error('missing_external_reference', 'External reference is required');
            }
            
            if (empty($inboundData['inbound_lines']) || !is_array($inboundData['inbound_lines'])) {
                return new WP_Error('missing_inbound_lines', 'At least one inbound line is required');
            }
            
            // Set default inbound date if not provided (good UX while still meeting API requirements)
            if (empty($inboundData['inbound_date'])) {
                $inboundData['inbound_date'] = date('Y-m-d');
            }
            
            // Validate and format inbound lines
            foreach ($inboundData['inbound_lines'] as $index => $line) {
                if (empty($line['article_code'])) {
                    return new WP_Error('missing_article_code', "Article code is required for line " . ($index + 1));
                }
                
                if (empty($line['quantity']) || !is_numeric($line['quantity'])) {
                    return new WP_Error('invalid_quantity', "Valid quantity is required for line " . ($index + 1));
                }
                
                // Set default packing slip quantity if not provided
                if (empty($line['packing_slip'])) {
                    $inboundData['inbound_lines'][$index]['packing_slip'] = $line['quantity'];
                }
            }
            
            $response = $this->client->makeAuthenticatedRequest('POST', WC_WMS_Constants::ENDPOINT_INBOUNDS . '/', $inboundData);
            
            if (is_wp_error($response)) {
                $this->client->logger()->error('Failed to create inbound', [
                    'data' => $inboundData,
                    'error' => $response->get_error_message()
                ]);
                return $response;
            }
            
            $this->client->logger()->info('Created inbound successfully', [
                'external_reference' => $inboundData['external_reference'],
                'inbound_id' => $response['id'] ?? 'N/A',
                'reference' => $response['reference'] ?? 'N/A',
                'lines_count' => count($inboundData['inbound_lines'])
            ]);
            
            return $response;
            
        } catch (Exception $e) {
            $this->client->logger()->error('Exception while creating inbound', [
                'data' => $inboundData,
                'error' => $e->getMessage()
            ]);
            return new WP_Error('inbound_create_failed', $e->getMessage());
        }
    }
    
    /**
     * Update an existing inbound
     */
    public function updateInbound(string $inboundId, array $inboundData): mixed {
        try {
            $response = $this->client->makeAuthenticatedRequest('PATCH', WC_WMS_Constants::ENDPOINT_INBOUNDS . "/{$inboundId}/", $inboundData);
            
            if (is_wp_error($response)) {
                $this->client->logger()->error('Failed to update inbound', [
                    'inbound_id' => $inboundId,
                    'data' => $inboundData,
                    'error' => $response->get_error_message()
                ]);
                return $response;
            }
            
            $this->client->logger()->info('Updated inbound successfully', [
                'inbound_id' => $inboundId,
                'reference' => $response['reference'] ?? 'N/A'
            ]);
            
            return $response;
            
        } catch (Exception $e) {
            $this->client->logger()->error('Exception while updating inbound', [
                'inbound_id' => $inboundId,
                'data' => $inboundData,
                'error' => $e->getMessage()
            ]);
            return new WP_Error('inbound_update_failed', $e->getMessage());
        }
    }
    
    /**
     * Cancel an inbound
     */
    public function cancelInbound(string $inboundId): mixed {
        try {
            $response = $this->client->makeAuthenticatedRequest('PATCH', WC_WMS_Constants::ENDPOINT_INBOUNDS . "/{$inboundId}/cancel/", []);
            
            if (is_wp_error($response)) {
                $this->client->logger()->error('Failed to cancel inbound', [
                    'inbound_id' => $inboundId,
                    'error' => $response->get_error_message()
                ]);
                return $response;
            }
            
            $this->client->logger()->info('Cancelled inbound successfully', [
                'inbound_id' => $inboundId
            ]);
            
            return $response;
            
        } catch (Exception $e) {
            $this->client->logger()->error('Exception while cancelling inbound', [
                'inbound_id' => $inboundId,
                'error' => $e->getMessage()
            ]);
            return new WP_Error('inbound_cancel_failed', $e->getMessage());
        }
    }
    
    /**
     * Get inbound statistics for the specified number of days
     */
    public function getInboundStats(int $days = 30): array {
        try {
            $fromDate = date('Y-m-d', strtotime("-{$days} days"));
            $toDate = date('Y-m-d');
            
            $params = [
                'from' => $fromDate,
                'to' => $toDate,
                'limit' => 1000 // Large limit to get all inbounds in range
            ];
            
            $inbounds = $this->getInbounds($params);
            
            if (is_wp_error($inbounds)) {
                return [
                    'total_inbounds' => 0,
                    'completed' => 0,
                    'announced' => 0,
                    'pending' => 0,
                    'cancelled' => 0,
                    'error' => $inbounds->get_error_message()
                ];
            }
            
            $stats = [
                'total_inbounds' => count($inbounds),
                'completed' => 0,
                'announced' => 0,
                'pending' => 0,
                'cancelled' => 0
            ];
            
            foreach ($inbounds as $inbound) {
                $status = $inbound['status'] ?? 'unknown';
                switch ($status) {
                    case 'completed':
                        $stats['completed']++;
                        break;
                    case 'announced':
                        $stats['announced']++;
                        break;
                    case 'cancelled':
                        $stats['cancelled']++;
                        break;
                    default:
                        $stats['pending']++;
                        break;
                }
            }
            
            return $stats;
            
        } catch (Exception $e) {
            $this->client->logger()->error('Exception while getting inbound stats', [
                'days' => $days,
                'error' => $e->getMessage()
            ]);
            
            return [
                'total_inbounds' => 0,
                'completed' => 0,
                'announced' => 0,
                'pending' => 0,
                'cancelled' => 0,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get quality controls for an inbound
     */
    public function getQualityControls(string $inboundId): mixed {
        try {
            $response = $this->client->makeAuthenticatedRequest('GET', WC_WMS_Constants::ENDPOINT_INBOUNDS . "/{$inboundId}/quality-controls/");
            
            if (is_wp_error($response)) {
                $this->client->logger()->error('Failed to fetch quality controls', [
                    'inbound_id' => $inboundId,
                    'error' => $response->get_error_message()
                ]);
                return $response;
            }
            
            $this->client->logger()->debug('Fetched quality controls', [
                'inbound_id' => $inboundId,
                'count' => count($response)
            ]);
            
            return $response;
            
        } catch (Exception $e) {
            $this->client->logger()->error('Exception while fetching quality controls', [
                'inbound_id' => $inboundId,
                'error' => $e->getMessage()
            ]);
            return new WP_Error('quality_controls_fetch_failed', $e->getMessage());
        }
    }
}
