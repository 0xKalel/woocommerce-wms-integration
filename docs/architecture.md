# WMS Integration Architecture

## Overview

The WMS integration follows a **clean architecture pattern** with separated concerns and dependency injection.

## System Architecture

```
WordPress/WooCommerce
    ↓
WMS Integration Plugin
    ↓
Service Layer → WMS API
    ↓
External WMS System
```

## Core Components

### Service Container
- **Dependency injection**: Clean service management
- **Single responsibility**: Each service handles one domain
- **Testable**: Easy to mock and unit test

### Service Layer
- **WMS Client**: API communication and authentication
- **Order Integrator**: Order lifecycle management
- **Stock Integrator**: Inventory synchronization
- **Webhook Integrator**: Real-time event processing
- **Inbound Service**: Warehouse receipt management
- **Shipment Service**: Tracking and delivery updates

### Processing Layer
- **Queue Manager**: Reliable background job processing
- **Cron System**: Scheduled synchronization tasks
- **Rate Limiter**: API protection and throttling
- **Event Dispatcher**: WordPress action/filter integration

## Plugin Structure

```
wms/
├── includes/
│   ├── core/           # Core system classes (includes queue & cron handlers)
│   ├── services/       # Business logic services  
│   ├── integrators/    # WMS API integrations
│   ├── webhooks/       # Webhook processors
│   ├── interfaces/     # Service contracts
│   ├── cache/          # Caching mechanisms
│   └── [support files] # Logger, constants, sync classes
├── admin-tabs/         # WordPress admin interface
└── admin-page.js       # Frontend interactions
```

## Key Patterns

### Event-Driven Architecture
- **WordPress hooks**: Native integration points
- **Custom events**: Business logic triggers
- **Webhook processing**: Real-time external events

### Queue-Based Processing
- **Reliability**: Operations never lost
- **Retry logic**: Automatic failure recovery
- **Background execution**: Non-blocking operations

### Configuration Management
- **Environment-based**: Development vs production settings
- **Secure storage**: Encrypted API credentials
- **Validation**: Configuration health checks

## Integration Points

### WordPress/WooCommerce
- **Order hooks**: Automatic order export triggers
- **Admin interface**: Management and monitoring UI
- **REST API**: Webhook endpoint registration
- **Cron system**: Scheduled task integration

### External WMS
- **REST API**: JSON-based communication
- **Authentication**: Token-based security
- **Webhooks**: Real-time event notifications
- **Rate limiting**: Respectful API usage

## Design Principles

- **Separation of concerns**: Clear boundaries between layers
- **Dependency injection**: Loose coupling between components  
- **Error resilience**: Graceful failure handling
- **Extensibility**: Easy to add new features
- **Security**: Proper authentication and validation
- **Performance**: Efficient processing and caching
