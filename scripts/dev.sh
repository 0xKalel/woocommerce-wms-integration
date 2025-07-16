#!/bin/bash

# Quick development helper script for common tasks

case "$1" in
    "reset")
        echo "🔄 Resetting WordPress environment..."
        cd ..
        chmod +x setup.sh 2>/dev/null || true
        docker-compose --profile webhook-testing --profile dev-tools down -v --remove-orphans
        docker-compose up -d
        sleep 30
        echo "💡 Note: Reset will include test data by default. To skip test data, use: SKIP_TEST_DATA=true ./setup.sh"
        ./setup.sh
        ;;
    "init-env")
        echo "🔧 Environment is now automatically configured..."
        echo "✅ Containers run as www-data (33:33) for WordPress compatibility"
        echo "✅ Host files are editable by your user: $(whoami)"
        echo "✅ Use './dev.sh fix-permissions' to fix any permission issues"
        ;;
    "permissions")
        echo "🔧 Making all scripts executable..."
        cd ..
        chmod +x setup.sh dev scripts/*.sh 2>/dev/null || true
        echo "✅ All scripts are now executable!"
        ;;
    "fix-git-permissions")
        echo "🔧 Fixing Git permission issues..."
        chmod +x scripts/fix-git-permissions.sh 2>/dev/null || true
        ./scripts/fix-git-permissions.sh
        ;;
    "fix-permissions")
        echo "🔧 Fixing WordPress file permissions..."
        
        # Go to parent directory
        cd ..
        
        # Create directories if they don't exist
        mkdir -p wms uploads
        
        # Get current user info
        CURRENT_USER=$(whoami)
        USER_ID=$(id -u)
        GROUP_ID=$(id -g)
        
        echo "👤 Setting host ownership to $CURRENT_USER ($USER_ID:$GROUP_ID)"
        
        # CRITICAL FIX: uploads directory needs special handling
        echo "📁 Setting uploads directory to be writable by both user and www-data..."
        
        # Try to set group ownership to www-data (33) so both user and container can write
        if sudo chown -R $USER_ID:33 wms/ uploads/ 2>/dev/null; then
            echo "✅ Set group ownership to www-data (33)"
            chmod -R 755 wms/ 2>/dev/null || true
            chmod -R 775 uploads/ 2>/dev/null || true  # 775 = user+group can write
        else
            echo "⚠️ No sudo access, using fallback permissions..."
            # Fallback: make uploads world-writable
            chown -R $USER_ID:$GROUP_ID wms/ uploads/ 2>/dev/null || true
            chmod -R 755 wms/ 2>/dev/null || true
            chmod -R 777 uploads/ 2>/dev/null || true  # World-writable as fallback
        fi
        
        # Fix container permissions if containers are running (containers run as www-data)
        if docker-compose ps wordpress | grep -q "Up"; then
            echo "🔧 Fixing container permissions (www-data ownership)..."
            docker-compose exec -T --user root wordpress chown -R www-data:www-data /var/www/html/wp-content/plugins/wc-wms-integration 2>/dev/null || true
            docker-compose exec -T --user root wordpress chown -R www-data:www-data /var/www/html/wp-content/uploads 2>/dev/null || true
            docker-compose exec -T --user root wordpress chmod -R 755 /var/www/html/wp-content/plugins/wc-wms-integration 2>/dev/null || true
            docker-compose exec -T --user root wordpress chmod -R 775 /var/www/html/wp-content/uploads 2>/dev/null || true
        fi
        
        # Verify permissions
        echo "📋 File ownership after fix:"
        ls -la wms/wc-wms-integration.php 2>/dev/null || echo "   Plugin file not found yet"
        ls -la uploads/ 2>/dev/null | head -3 || echo "   Uploads directory empty"
        
        echo "✅ Permissions fixed for wms/ and uploads/ directories!"
        echo "📋 uploads/ directory is now writable by www-data (container)"
        ;;
    "data")
        echo "📊 Populating test data..."
        chmod +x scripts/populate-test-data.sh 2>/dev/null || true
        ./scripts/populate-test-data.sh
        ;;
    "setup")
        echo "🚀 Setting up WordPress..."
        cd ..
        ./setup.sh
        ;;
    "activate-wms")
        echo "🔧 Activating WMS Integration plugin..."
        chmod +x activate-wms.sh 2>/dev/null || true
        ./activate-wms.sh
        ;;
    "test-wpcli")
        echo "🧪 Testing WP-CLI cache fix..."
        chmod +x test-wpcli.sh 2>/dev/null || true
        ./test-wpcli.sh
        ;;
    "test-clean")
        echo "🧠 Testing clean setup (no warnings)..."
        chmod +x test-clean-setup.sh 2>/dev/null || true
        ./test-clean-setup.sh
        ;;
    "disable-emails")
        echo "🚑 Disabling all email notifications..."
        chmod +x disable-emails.sh 2>/dev/null || true
        ./disable-emails.sh
        ;;
    "enable-emails")
        echo "📧 Enabling all email notifications..."
        chmod +x enable-emails.sh 2>/dev/null || true
        ./enable-emails.sh
        ;;
    "logs")
        echo "📋 Showing WordPress logs..."
        cd ..
        docker-compose logs -f wordpress
        ;;
    "cli")
        echo "🔧 Opening WP-CLI..."
        cd ..
        docker-compose run --rm wpcli wp shell
        ;;
    "db")
        echo "🗜️  Opening database..."
        cd ..
        docker-compose exec db mysql -u wordpress -p wordpress
        ;;
    "status")
        echo "📊 Checking environment status..."
        echo "Containers:"
        cd ..
        docker-compose ps
        echo ""
        echo "WordPress URL: http://localhost:8000"
        echo "Admin: http://localhost:8000/wp-admin"
        echo "WooCommerce: http://localhost:8000/wp-admin/admin.php?page=wc-admin"
        ;;
    "debug")
        echo "🔍 Running WordPress debugging..."
        chmod +x debug.sh 2>/dev/null || true
        ./debug.sh
        ;;
    "webhook-start")
        echo "🌐 Starting webhook testing environment..."
        cd ..
        chmod +x scripts/webhook/webhook-dev.sh 2>/dev/null || true
        ./scripts/webhook/webhook-dev.sh start
        ;;
    "webhook-stop")
        echo "🛑 Stopping webhook testing..."
        cd ..
        chmod +x scripts/webhook/webhook-dev.sh 2>/dev/null || true
        ./scripts/webhook/webhook-dev.sh stop
        ;;
    "webhook-status")
        echo "📊 Checking webhook status..."
        cd ..
        chmod +x scripts/webhook/webhook-dev.sh 2>/dev/null || true
        ./scripts/webhook/webhook-dev.sh status
        ;;
    "webhook-test")
        echo "🧪 Testing webhook endpoints..."
        cd ..
        chmod +x scripts/webhook/webhook-dev.sh 2>/dev/null || true
        ./scripts/webhook/webhook-dev.sh test
        ;;
    "webhook-logs")
        echo "📋 Showing webhook logs..."
        cd ..
        chmod +x scripts/webhook/webhook-dev.sh 2>/dev/null || true
        ./scripts/webhook/webhook-dev.sh logs
        ;;
    "webhook-register")
        echo "📡 Registering webhooks with WMS..."
        cd ..
        chmod +x scripts/webhook/webhook-dev.sh 2>/dev/null || true
        ./scripts/webhook/webhook-dev.sh register
        ;;
    *)
        echo "🛠️  WordPress Development Helper"
        echo ""
        echo "Usage: ./scripts/dev.sh [command]"
        echo ""
        echo "Commands:"
        echo "  reset         - Complete reset (clean database & start fresh with test data)"
        echo "  init-env      - Initialize environment for current user"
        echo "  permissions   - Make all scripts executable"
        echo "  fix-git-permissions - Fix Git permission issues after setup"
        echo "  fix-permissions - Fix WordPress file permissions"
        echo "  data          - Populate test data (products, customers, orders)"
        echo "  setup         - Run WordPress setup (includes test data by default)"
        echo "  activate-wms  - Activate WMS Integration plugin"
        echo "  test-wpcli    - Test WP-CLI cache fix (eliminates warnings)"
        echo "  test-clean    - Test complete clean setup (verify all fixes)"
        echo "  disable-emails - Disable all email notifications (stop sendmail errors)"
        echo "  enable-emails - Re-enable all email notifications"
        echo "  logs      - Show WordPress logs"
        echo "  cli       - Open WP-CLI terminal"
        echo "  db        - Open database terminal"
        echo "  status    - Check environment status"
        echo "  debug     - Run WordPress debugging"
        echo ""
        echo "Webhook Testing:"
        echo "  webhook-start    - Start ngrok webhook testing environment"
        echo "  webhook-stop     - Stop webhook testing and reset URLs"
        echo "  webhook-status   - Check webhook testing status"
        echo "  webhook-test     - Test webhook endpoints"
        echo "  webhook-logs     - Show webhook-related logs"
        echo "  webhook-register - Register webhooks with WMS"
        echo ""
        echo "Examples:"
        echo "  ./scripts/dev.sh reset           # Clean start with test data"
        echo "  SKIP_TEST_DATA=true ./setup.sh   # Setup without test data"
        echo "  ./setup.sh --skip-test-data      # Alternative way to skip test data"
        echo "  ./scripts/dev.sh init-env        # Set up environment for your user"
        echo "  ./scripts/dev.sh permissions     # Fix script permissions"
        echo "  ./scripts/dev.sh fix-git-permissions # Fix Git showing all files as changed"
        echo "  ./scripts/dev.sh fix-permissions # Fix WordPress file permissions"
        echo "  ./scripts/dev.sh data            # Add test data manually"
        echo "  ./scripts/dev.sh debug           # Debug WordPress issues"
        echo "  ./scripts/dev.sh status          # Check what's running"
        echo "  ./scripts/dev.sh webhook-start   # Start webhook testing"
        echo "  ./scripts/dev.sh webhook-test    # Test webhook endpoints"
        ;;
esac
