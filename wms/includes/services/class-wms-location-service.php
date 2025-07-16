<?php
/**
 * WMS Location Service
 * 
 * Handles all location type operations with WMS
 * 
 * @package WC_WMS_Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_WMS_Location_Service {
    
    /**
     * WMS client instance
     */
    private $client;
    
    /**
     * Constructor
     */
    public function __construct(WC_WMS_Client $client) {
        $this->client = $client;
    }
    
    /**
     * Get all location types from WMS
     */
    public function getLocationTypes(array $params = []): array {
        $endpoint = WC_WMS_Constants::ENDPOINT_LOCATION_TYPES;
        
        // Add query parameters if provided
        if (!empty($params)) {
            $endpoint .= '?' . http_build_query($params);
        }
        
        // Add expand header for administration_code
        $extraHeaders = [
            'Expand' => 'administration_code'
        ];
        
        $this->client->logger()->debug('Getting location types from WMS', [
            'endpoint' => $endpoint,
            'params' => $params
        ]);
        
        $response = $this->client->makeAuthenticatedRequest('GET', $endpoint, null, $extraHeaders);
        
        $locationCount = is_array($response) ? count($response) : 0;
        $this->client->logger()->info('Location types retrieved successfully', [
            'location_count' => $locationCount
        ]);
        
        return $response;
    }
    
    /**
     * Get single location type from WMS
     */
    public function getLocationType(string $locationTypeId): array {
        $endpoint = WC_WMS_Constants::ENDPOINT_LOCATION_TYPE . $locationTypeId . '/';
        
        // Add expand header for administration_code
        $extraHeaders = [
            'Expand' => 'administration_code'
        ];
        
        $this->client->logger()->debug('Getting location type from WMS', [
            'location_type_id' => $locationTypeId
        ]);
        
        $response = $this->client->makeAuthenticatedRequest('GET', $endpoint, null, $extraHeaders);
        
        if (isset($response['id'])) {
            $this->client->logger()->info('Location type retrieved successfully', [
                'location_type_id' => $locationTypeId,
                'code' => $response['code'] ?? 'unknown',
                'name' => $response['name'] ?? 'unknown'
            ]);
        }
        
        return $response;
    }
    
    /**
     * Search location types by criteria
     */
    public function searchLocationTypes(array $criteria): array {
        $allowedParams = [
            'code' => 'string',
            'pickable' => 'boolean'
        ];
        
        $params = [];
        foreach ($criteria as $key => $value) {
            if (isset($allowedParams[$key]) && $value !== null) {
                // Convert boolean to string for API
                if (is_bool($value)) {
                    $params[$key] = $value ? 'true' : 'false';
                } else {
                    $params[$key] = $value;
                }
            }
        }
        
        $this->client->logger()->info('Searching location types', [
            'criteria' => $params
        ]);
        
        return $this->getLocationTypes($params);
    }
    
    /**
     * Get pickable location types only
     */
    public function getPickableLocationTypes(): array {
        return $this->searchLocationTypes(['pickable' => true]);
    }
    
    /**
     * Get transport location types only
     */
    public function getTransportLocationTypes(): array {
        $allTypes = $this->getLocationTypes();
        
        // Filter for transport types
        $transportTypes = array_filter($allTypes, function($type) {
            return !empty($type['transport']);
        });
        
        $this->client->logger()->info('Filtered transport location types', [
            'total_types' => count($allTypes),
            'transport_types' => count($transportTypes)
        ]);
        
        return array_values($transportTypes);
    }
    
    /**
     * Get location type by code
     */
    public function getLocationTypeByCode(string $code): ?array {
        $this->client->logger()->debug('Getting location type by code', [
            'code' => $code
        ]);
        
        $locationTypes = $this->searchLocationTypes(['code' => $code]);
        
        if (is_array($locationTypes) && !empty($locationTypes)) {
            $locationType = reset($locationTypes);
            $this->client->logger()->debug('Location type found by code', [
                'code' => $code,
                'location_type_id' => $locationType['id'] ?? 'unknown'
            ]);
            return $locationType;
        }
        
        $this->client->logger()->debug('No location type found by code', [
            'code' => $code
        ]);
        
        return null;
    }
    
    /**
     * Sync location types from WMS to WordPress options
     */
    public function syncLocationTypes(): array {
        $this->client->logger()->info('Syncing location types from WMS');
        
        $locationTypes = $this->getLocationTypes();
        
        $typeMapping = [];
        $typeOptions = [];
        $pickableTypes = [];
        $transportTypes = [];
        
        foreach ($locationTypes as $type) {
            // Validate the location type data structure
            if (!$this->validateLocationTypeData($type)) {
                $this->client->logger()->warning('Invalid location type data structure', [
                    'location_type' => $type
                ]);
                continue;
            }
            
            $typeId = $type['id'];
            $typeCode = $type['code'];
            $typeName = $type['name'];
            
            $typeMapping[$typeCode] = $typeId;
            $typeOptions[$typeId] = [
                'id' => $typeId,
                'code' => $typeCode,
                'name' => $typeName,
                'transport' => !empty($type['transport']),
                'pickable' => !empty($type['pickable']),
                'administration_code' => $type['administration_code'] ?? null
            ];
            
            // Group by capabilities
            if (!empty($type['pickable'])) {
                $pickableTypes[$typeId] = $typeOptions[$typeId];
            }
            
            if (!empty($type['transport'])) {
                $transportTypes[$typeId] = $typeOptions[$typeId];
            }
        }
        
        // Update WordPress options
        update_option('wc_wms_location_types', $typeOptions);
        update_option('wc_wms_location_type_mapping', $typeMapping);
        update_option('wc_wms_pickable_location_types', $pickableTypes);
        update_option('wc_wms_transport_location_types', $transportTypes);
        update_option('wc_wms_location_types_synced_at', current_time('mysql'));
        
        $this->client->logger()->info('Location types synced successfully', [
            'total_count' => count($typeOptions),
            'pickable_count' => count($pickableTypes),
            'transport_count' => count($transportTypes),
            'codes' => array_keys($typeMapping)
        ]);
        
        return [
            'success' => true,
            'total_count' => count($typeOptions),
            'pickable_count' => count($pickableTypes),
            'transport_count' => count($transportTypes),
            'types' => $typeOptions
        ];
    }
    
    /**
     * Get cached location types from WordPress options
     */
    public function getCachedLocationTypes(): array {
        $cached = get_option('wc_wms_location_types', []);
        $lastSync = get_option('wc_wms_location_types_synced_at', 0);
        
        return [
            'types' => $cached,
            'last_sync' => $lastSync,
            'last_sync_formatted' => $lastSync ? date('Y-m-d H:i:s', strtotime($lastSync)) : 'Never'
        ];
    }
    
    /**
     * Get location type statistics
     */
    public function getLocationTypeStatistics(): array {
        $cached = $this->getCachedLocationTypes();
        $types = $cached['types'];
        
        $stats = [
            'total_types' => count($types),
            'pickable_types' => 0,
            'transport_types' => 0,
            'non_pickable_types' => 0,
            'codes' => [],
            'last_sync' => $cached['last_sync_formatted']
        ];
        
        foreach ($types as $type) {
            $stats['codes'][] = $type['code'];
            
            if (!empty($type['pickable'])) {
                $stats['pickable_types']++;
            } else {
                $stats['non_pickable_types']++;
            }
            
            if (!empty($type['transport'])) {
                $stats['transport_types']++;
            }
        }
        
        return $stats;
    }
    
    /**
     * Validate location type exists
     */
    public function validateLocationTypeExists(string $locationTypeId): bool {
        try {
            $locationType = $this->getLocationType($locationTypeId);
            return $this->validateLocationTypeData($locationType);
        } catch (Exception $e) {
            $this->client->logger()->error('Location type validation failed', [
                'location_type_id' => $locationTypeId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Validate location type data structure
     */
    private function validateLocationTypeData(array $data): bool {
        return !empty($data['id']) && 
               !empty($data['code']) && 
               !empty($data['name']) &&
               isset($data['transport']) && 
               isset($data['pickable']);
    }
    
    /**
     * Get location type options for select fields
     */
    public function getLocationTypeOptions(bool $pickableOnly = false): array {
        $cached = $this->getCachedLocationTypes();
        $types = $cached['types'];
        $options = [];
        
        foreach ($types as $type) {
            // Filter for pickable only if requested
            if ($pickableOnly && empty($type['pickable'])) {
                continue;
            }
            
            $options[$type['id']] = sprintf(
                '%s - %s%s%s',
                $type['code'],
                $type['name'],
                !empty($type['pickable']) ? ' (Pickable)' : '',
                !empty($type['transport']) ? ' (Transport)' : ''
            );
        }
        
        return $options;
    }
    
    /**
     * Get service configuration
     */
    public function getConfig(): array {
        return [
            'service_name' => 'location',
            'endpoints' => [
                'list' => WC_WMS_Constants::ENDPOINT_LOCATION_TYPES,
                'get' => WC_WMS_Constants::ENDPOINT_LOCATION_TYPE . '{id}/'
            ],
            'supported_filters' => [
                'code' => 'string',
                'pickable' => 'boolean'
            ],
            'expand_groups' => [
                'administration_code'
            ]
        ];
    }
}
