.PHONY: help setup setup-with-test-data reset reset-with-test-data start stop status logs shell clean clean-logs health wp-logs configure-wms webhook-start webhook-stop webhook-status webhook-test webhook-logs webhook-register cron-status cron-run cron-list cron-logs

# Default target
help:
	@echo "WordPress Docker Development Commands"
	@echo "===================================="
	@echo "make setup           - Initial WordPress setup (without test data)"
	@echo "make setup-with-test-data - Initial WordPress setup with test data"
	@echo "make start           - Start all containers"
	@echo "make stop            - Stop all containers"
	@echo "make reset           - Complete reset (removes data, no test data)"
	@echo "make reset-with-test-data - Complete reset with test data"
	@echo "make status          - Check environment status"
	@echo "make logs            - Follow container logs"
	@echo "make shell           - Open WordPress shell"
	@echo "make db-shell        - Open database shell"
	@echo "make health          - Run health checks"
	@echo "make clean           - Clean up everything (containers, volumes, images, logs)"
	@echo "make clean-logs      - Clean only log files (keep containers running)"
	@echo "make wp-logs         - View WordPress logs (local files)"
	@echo "make configure-wms   - Configure WMS integration credentials"
	@echo ""
	@echo "Manual Test Data:"
	@echo "./scripts/populate-test-data.sh - Add test data manually"
	@echo ""
	@echo "Cron Commands:"
	@echo "make cron-status     - Check WP-Cron status"
	@echo "make cron-run        - Manually run due cron events (may show segfault)"
	@echo "make cron-run-safe   - Run cron via existing container (recommended)"
	@echo "make cron-run-clean  - Run cron with explicit cleanup"
	@echo "make cron-list       - List scheduled cron events"
	@echo "make cron-logs       - View cron execution logs"
	@echo ""
	@echo "Webhook Testing Commands:"
	@echo "make webhook-start   - Start ngrok webhook testing environment"
	@echo "make webhook-stop    - Stop webhook testing and reset URLs"
	@echo "make webhook-status  - Check webhook testing status"
	@echo "make webhook-test    - Test webhook endpoints"
	@echo "make webhook-logs    - Show webhook-related logs"
	@echo "make webhook-register - Register webhooks with WMS"

setup:
	@chmod +x setup.sh scripts/*.sh scripts/webhook/*.sh
	@./setup.sh --skip-test-data

setup-with-test-data:
	@chmod +x setup.sh scripts/*.sh scripts/webhook/*.sh
	@./setup.sh

start:
	@docker-compose up -d
	@echo "✅ Containers started"
	@echo "🌐 WordPress: http://localhost:8000"

stop:
	@docker-compose stop
	@echo "✅ Containers stopped"

reset:
	@echo "🔄 Resetting environment..."
	@docker-compose --profile webhook-testing --profile dev-tools down -v --remove-orphans
	@rm -rf uploads/* wms/test-* logs/webhook/* 2>/dev/null || true
	@make setup

reset-with-test-data:
	@echo "🔄 Resetting environment..."
	@docker-compose --profile webhook-testing --profile dev-tools down -v --remove-orphans
	@rm -rf uploads/* wms/test-* logs/webhook/* 2>/dev/null || true
	@make setup-with-test-data

status:
	@./scripts/dev.sh status

logs:
	@docker-compose logs -f

shell:
	@docker-compose exec wordpress bash

db-shell:
	@docker-compose exec db mysql -u wordpress -pwordpress wordpress

health:
	@./scripts/health-check.sh

clean-logs:
	@echo "📋 Cleaning log files only..."
	@rm -f logs/wordpress/*.log logs/apache/*.log logs/php/*.log logs/webhook/*.log logs/cron/*.log 2>/dev/null || true
	@rm -f uploads/wc-logs/*.log 2>/dev/null || true
	@echo "🔄 Recreating clean log files..."
	@mkdir -p logs/wordpress logs/apache logs/php logs/webhook 2>/dev/null || true
	@mkdir -p logs/cron 2>/dev/null || sudo mkdir -p logs/cron 2>/dev/null || true
	@touch logs/wordpress/debug.log logs/wordpress/php_errors.log 2>/dev/null || true
	@chmod 666 logs/wordpress/*.log 2>/dev/null || true
	@sudo chown -R $(shell id -u):$(shell id -g) logs 2>/dev/null || true
	@echo "✅ Log cleanup complete!"

clean:
	@echo "🧹 Cleaning up everything..."
	@echo "📦 Stopping and removing containers..."
	@docker-compose --profile webhook-testing --profile dev-tools down -v --remove-orphans
	@echo "🗑️  Cleaning Docker system..."
	@docker system prune -f
	@echo "📋 Cleaning log files..."
	@sudo rm -rf logs 2>/dev/null || rm -rf logs 2>/dev/null || true
	@rm -f uploads/wc-logs/*.log 2>/dev/null || true
	@echo "🔄 Recreating clean log directories..."
	@mkdir -p logs/wordpress logs/apache logs/php logs/webhook logs/cron 2>/dev/null || true
	@touch logs/wordpress/debug.log logs/wordpress/php_errors.log 2>/dev/null || true
	@chmod -R 755 logs 2>/dev/null || true
	@chmod 666 logs/wordpress/*.log 2>/dev/null || true
	@echo "✅ Complete cleanup finished!"

wp-logs:
	@chmod +x scripts/logs.sh
	@./scripts/logs.sh view

configure-wms:
	@chmod +x scripts/configure-wms.sh
	@./scripts/configure-wms.sh

# Webhook testing commands
webhook-start:
	@chmod +x scripts/webhook/webhook-dev.sh
	@./scripts/webhook/webhook-dev.sh start

webhook-stop:
	@chmod +x scripts/webhook/webhook-dev.sh
	@./scripts/webhook/webhook-dev.sh stop

webhook-status:
	@chmod +x scripts/webhook/webhook-dev.sh
	@./scripts/webhook/webhook-dev.sh status

webhook-test:
	@chmod +x scripts/webhook/webhook-dev.sh
	@./scripts/webhook/webhook-dev.sh test

webhook-logs:
	@chmod +x scripts/webhook/webhook-dev.sh
	@./scripts/webhook/webhook-dev.sh logs

webhook-register:
	@chmod +x scripts/webhook/webhook-dev.sh
	@./scripts/webhook/webhook-dev.sh register

# Cron commands
cron-status:
	@chmod +x scripts/wp-cron.sh
	@./scripts/wp-cron.sh status

cron-run:
	@chmod +x scripts/wp-cron.sh
	@./scripts/wp-cron.sh run

# Alternative cron run using existing cron container (avoids segfault)
cron-run-safe:
	@echo "🔄 Running cron via existing cron container..."
	@docker-compose exec cron /usr/local/bin/wp cron event run --due-now --path=/var/www/html --allow-root

# Run cron with explicit cleanup to prevent segfault
cron-run-clean:
	@echo "🔄 Running WordPress cron with explicit cleanup..."
	@docker-compose run --rm wpcli bash -c "wp cron event run --due-now && exit 0"

cron-list:
	@chmod +x scripts/wp-cron.sh
	@./scripts/wp-cron.sh list

cron-logs:
	@echo "📋 Viewing cron logs..."
	@docker-compose logs -f cron
