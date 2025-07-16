# WordPress WMS Integration

A comprehensive WordPress plugin that integrates WooCommerce with eWarehousing Solutions WMS for complete order fulfillment and inventory management. Features real-time webhooks, automated sync jobs, queue-based processing, and a complete Docker development environment.

## Features

- **Order Management**: Automatic order export to WMS with queue-based reliability
- **Real-time Updates**: Webhooks for order status, stock levels, and shipment tracking
- **Inventory Sync**: Automated stock synchronization and inbound tracking
- **Admin Interface**: Complete WordPress admin interface for monitoring and control
- **Background Jobs**: Automated cron jobs for continuous data synchronization
- **Error Handling**: Comprehensive retry logic and failure management
- **Development Environment**: Complete Docker setup with webhook testing

## Prerequisites

- Docker
- Docker Compose  
- Make (optional, but recommended)

## Quick Start

```bash
# Basic setup
make setup
make webhook-start  # Creates public URL, saved to logs/webhook/webhook-urls.txt
make cron-run

# Access WordPress: http://localhost:8000
# Admin: http://localhost:8000/wp-admin (admin/password)
```

## With Test Data

```bash
# Clean setup with sample data
make clean
make setup-with-test-data
```

## Documentation

- [Architecture Overview](docs/architecture.md) - System design and component structure
- [Sync Logic](docs/sync-logic.md) - How data synchronization works
- [Configuration](docs/configuration.md) - Setup and configuration guide
- [Cron Jobs](docs/cron-jobs.md) - Automated background sync tasks
- [Autocomplete Setup](docs/autocomplete-setup.md) - VSCode autocomplete for WordPress/WooCommerce

## Main Commands

- `make setup` - Initial WordPress setup (clean install)
- `make setup-with-test-data` - Initial setup with sample products and orders
- `make start` - Start all containers
- `make stop` - Stop all containers
- `make webhook-start` - Start ngrok tunnel for webhook testing
- `make cron-run` - Manually run WordPress cron jobs
- `make clean` - Clean up everything (containers, volumes, images, logs)

## Important Scripts

- `scripts/populate-test-data.sh` - Add sample WooCommerce data
- `scripts/configure-wms.sh` - Configure WMS API credentials
- `scripts/fix-permissions.sh` - Fix file permissions
- `scripts/health-check.sh` - Run environment health checks
- `scripts/activate-wms.sh` - Activate and configure WMS plugin

## Project Structure

- `wms/` - WMS integration plugin source code
- `docs/` - Technical documentation and guides
- `scripts/` - Development and setup scripts
- `uploads/` - WordPress uploads directory
- `logs/` - Application and container logs
- `config/` - Docker and environment configuration
- `docker-compose.yml` - Docker services configuration
- `Makefile` - Development commands and shortcuts
