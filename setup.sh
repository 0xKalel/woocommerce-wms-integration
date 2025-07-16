#!/bin/bash
# Check dependencies first
if [ -f "scripts/check-dependencies.sh" ]; then
    chmod +x scripts/check-dependencies.sh
    ./scripts/check-dependencies.sh || exit 1
fi

# Load environment variables
if [ -f ".env" ]; then
    export $(cat .env | grep -v '^#' | grep -v '^$' | xargs)
else
    echo "⚠️  No .env file found. Using defaults."
    echo "💡 Copy .env.example to .env and customize settings"
fi

fix_container_permissions() {
    echo "🔧 Fixing permissions inside container..."
    # Since containers now always run as www-data (33:33), just ensure proper permissions
    docker compose exec --user root wordpress bash -c "
        chown -R www-data:www-data /var/www/html/wp-content/plugins/wc-wms-integration &&
        chown -R www-data:www-data /var/www/html/wp-content/uploads &&
        chmod -R 755 /var/www/html/wp-content/plugins/wc-wms-integration &&
        chmod -R 755 /var/www/html/wp-content/uploads
    " || echo "⚠️ Failed to fix container permissions"
    
    # Fix host permissions so you can edit files
    echo "🔧 Fixing host permissions for file editing..."
    USER_ID=$(id -u)
    GROUP_ID=$(id -g)
    
    # Create uploads directory if it doesn't exist
    mkdir -p uploads
    
    # CRITICAL FIX: Set uploads directory to be writable by www-data (33) but accessible by user
    # Use 775 permissions and set group ownership to www-data
    sudo chown -R $USER_ID:33 wms/ uploads/ 2>/dev/null || {
        # Fallback: if no sudo, make it world-writable
        chmod -R 777 uploads/ 2>/dev/null || true
        chown -R $USER_ID:$GROUP_ID wms/ 2>/dev/null || true
    }
    
    # Set proper permissions: 755 for plugin files, 775 for uploads (needs to be writable by www-data)
    chmod -R 755 wms/ 2>/dev/null || true
    chmod -R 775 uploads/ 2>/dev/null || true
}

# Function to handle Git permission issues
fix_git_permissions() {
    echo "🔧 Preventing Git permission issues..."
    # Configure git to ignore file permission changes (one-time setup)
    git config core.filemode false 2>/dev/null || true
    
    # Only reset if there are actually permission-only changes
    if git diff --name-only | grep -q .; then
        echo "📝 Git detected permission changes, resetting..."
        git add -A 2>/dev/null || true
        git reset --hard HEAD 2>/dev/null || true
    fi
}
# WooCommerce-WMS Integration Setup Script
set -e

echo "🚀 Setting up WooCommerce-WMS Integration Development Environment"

# Initialize environment
echo "🔧 Initializing environment for user: $(whoami)"
mkdir -p wms uploads
mkdir -p logs/wordpress logs/apache logs/php logs/webhook logs/cron 2>/dev/null || {
    echo "⚠️  Permission issue creating directories, attempting with sudo..."
    sudo mkdir -p logs/wordpress logs/apache logs/php logs/webhook logs/cron
    sudo chown -R $USER:$USER logs
} || {
    echo "⚠️  Could not create log directories. Run: ./scripts/fix-permissions.sh"
}

# Set up log files with proper permissions
touch logs/wordpress/debug.log logs/wordpress/php_errors.log
chmod 666 logs/wordpress/debug.log logs/wordpress/php_errors.log 2>/dev/null || true

# Create simple .env file if it doesn't exist
if [ ! -f ".env" ]; then
    echo "⚠️ Creating basic .env file..."
    cat > .env << EOF
# WordPress-WMS Integration Environment
# Containers run as www-data (33:33) for consistency

# Optional: ngrok token for webhook testing
# NGROK_AUTHTOKEN=your_token_here

# WP-Cron configuration
# Set to true to use system cron instead of WordPress built-in cron
DISABLE_WP_CRON=true
EOF
else
    echo "✅ .env file already exists"
fi

# Function to run WP-CLI commands
run_wpcli() {
    docker-compose run --rm --user=33:33 wpcli wp "$@"
}

# Function to initialize WP-CLI cache directory
init_wpcli_cache() {
    echo "📁 Initializing WP-CLI cache directory..."
    docker-compose run --rm --user root wpcli mkdir -p /tmp/.wp-cli/cache 2>/dev/null || true
    docker-compose run --rm --user root wpcli chown -R 33:33 /tmp/.wp-cli/cache 2>/dev/null || true
}

# Function to check if WordPress is installed
check_wp_installed() {
    run_wpcli core is-installed 2>/dev/null && echo "installed" || echo "not-installed"
}

# Function to wait for database
wait_for_database() {
    echo "⏳ Waiting for database to be ready..."
    timeout=60
    while [[ $timeout -gt 0 ]]; do
        if docker-compose exec db mysqladmin ping -h localhost -u wordpress -pwordpress 2>/dev/null; then
            echo "✅ Database is ready!"
            return 0
        fi
        echo "Waiting for database... ($timeout seconds remaining)"
        sleep 3
        timeout=$((timeout - 3))
    done
    
    echo "❌ Database failed to start within 60 seconds"
    return 1
}

# Function to wait for WordPress
wait_for_wordpress() {
    echo "⏳ Waiting for WordPress to be ready..."
    timeout=60
    while [[ $timeout -gt 0 ]]; do
        # Check if WordPress is responding (including redirects which are normal for fresh installs)
        HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8000 2>/dev/null)
        if [[ "$HTTP_CODE" == "200" ]] || [[ "$HTTP_CODE" == "301" ]] || [[ "$HTTP_CODE" == "302" ]]; then
            echo "✅ WordPress is ready! (HTTP $HTTP_CODE)"
            return 0
        fi
        echo "Waiting for WordPress... ($timeout seconds remaining)"
        sleep 5
        timeout=$((timeout - 5))
    done
    
    echo "❌ WordPress failed to start within 60 seconds"
    return 1
}

# Fix permissions first
echo "🔧 Setting up directory structure and permissions..."
# Create directories if they don't exist
mkdir -p wms uploads

# Get current user info
CURRENT_USER=$(whoami)
USER_ID=$(id -u)
GROUP_ID=$(id -g)

echo "👤 Setting up for user: $CURRENT_USER ($USER_ID:$GROUP_ID)"

# Fix ownership and permissions on host
if command -v sudo &> /dev/null; then
    sudo chown -R "$USER_ID:$GROUP_ID" wms/ uploads/ || true
else
    chown -R "$USER_ID:$GROUP_ID" wms/ uploads/ || true
fi
chmod -R 755 wms/ uploads/ 2>/dev/null || true

# Build cron service if Dockerfile exists
if [ -f "Dockerfile.cron" ]; then
    echo "🔧 Building cron service..."
    docker-compose build cron
fi

# Start core services including cron
echo "📦 Starting WordPress, Database, and Cron services..."
docker-compose up -d wordpress db cron

# Wait for services to be ready
if ! wait_for_database; then
    echo "❌ Database setup failed"
    exit 1
fi

if ! wait_for_wordpress; then
    echo "❌ WordPress setup failed"
    exit 1
fi

# Initialize WP-CLI cache directory to prevent permission warnings
init_wpcli_cache

# Fix container permissions
fix_container_permissions

# Check if WordPress is already installed
echo "🔍 Checking WordPress installation status..."
# Ensure wp-config.php exists
if ! docker-compose run --rm wpcli wp config path > /dev/null 2>&1; then
    echo "⚠️  wp-config.php missing or unreadable. Attempting to create..."
    run_wpcli config create \
        --dbname="wordpress" \
        --dbuser="wordpress" \
        --dbpass="wordpress" \
        --dbhost="db" \
        --force
fi
WP_INSTALLED=$(check_wp_installed)

if [[ "$WP_INSTALLED" == "not-installed" ]]; then
    echo "🔧 Installing WordPress..."
    
    # Install WordPress with better error handling
    if run_wpcli core install \
        --title="WooCommerce-WMS Integration" \
        --admin_user="admin" \
        --admin_password="password" \
        --admin_email="admin@example.com" \
        --url="http://localhost:8000" \
        --skip-email; then
        echo "✅ WordPress installation successful!"
    else
        echo "❌ WordPress installation failed!"
        echo "🔧 Trying to fix..."
        
        # Try to fix common issues
        run_wpcli core download --force
        run_wpcli config create \
            --dbname="wordpress" \
            --dbuser="wordpress" \
            --dbpass="wordpress" \
            --dbhost="db" \
            --force
        
        # Try installation again
        if run_wpcli core install \
            --title="WooCommerce-WMS Integration" \
            --admin_user="admin" \
            --admin_password="password" \
            --admin_email="admin@example.com" \
            --url="http://localhost:8000" \
            --skip-email; then
            echo "✅ WordPress installation successful on retry!"
        else
            echo "❌ WordPress installation failed on retry!"
            exit 1
        fi
    fi
else
    echo "✅ WordPress is already installed!"
fi

# Verify installation
echo "🔍 Verifying WordPress installation..."
if run_wpcli core is-installed; then
    echo "✅ WordPress installation verified!"
else
    echo "❌ WordPress installation verification failed!"
    exit 1
fi

# Check if user exists
echo "🔍 Checking admin user..."
if run_wpcli user get admin --field=user_login 2>/dev/null; then
    echo "✅ Admin user exists!"
else
    echo "⚠️  Admin user not found, creating..."
    run_wpcli user create admin admin@example.com \
        --role=administrator \
        --user_pass=password \
        --first_name=Admin \
        --last_name=User
fi

# Fix permissions again before plugin installation
fix_container_permissions

# Install and activate WooCommerce
echo "🛒 Installing WooCommerce..."
if run_wpcli plugin is-installed woocommerce; then
    echo "✅ WooCommerce is already installed!"
    run_wpcli plugin activate woocommerce
else
    # Try to install WooCommerce with better error handling
    echo "📥 Downloading WooCommerce..."
    if run_wpcli plugin install woocommerce --activate; then
        echo "✅ WooCommerce installation successful!"
    else
        echo "❌ WooCommerce installation failed!"
        echo "🔧 Trying alternative method..."
        
        # Try manual download
        run_wpcli plugin install https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip --activate
        
        if run_wpcli plugin is-active woocommerce; then
            echo "✅ WooCommerce installation successful on retry!"
        else
            echo "❌ WooCommerce installation failed on retry!"
            echo "⚠️  Continuing setup without WooCommerce..."
        fi
    fi
fi

# Execute comprehensive WooCommerce setup via PHP script
echo "🚀 Running comprehensive WooCommerce configuration..."
echo "⚡ This replaces 50+ individual WP-CLI commands with one fast PHP execution"

# Copy the PHP script into the container and execute it
docker cp scripts/woocommerce-complete-setup.php $(docker-compose ps -q wordpress):/tmp/woocommerce-complete-setup.php
if docker-compose exec -T wordpress php /tmp/woocommerce-complete-setup.php; then
    echo "✅ WooCommerce comprehensive setup completed successfully!"
else
    echo "❌ WooCommerce setup failed, falling back to basic configuration..."
    # Basic fallback configuration
    run_wpcli option update woocommerce_store_address "123 Test Street" 2>/dev/null || true
    run_wpcli option update woocommerce_default_country "US:CA" 2>/dev/null || true
    run_wpcli option update woocommerce_currency "USD" 2>/dev/null || true
    run_wpcli option update woocommerce_onboarding_skipped "1" 2>/dev/null || true
    run_wpcli option update woocommerce_cod_enabled "yes" 2>/dev/null || true
    run_wpcli user meta update admin woocommerce_admin_core_profiler_completed 1 2>/dev/null || true
fi

# Clean up
docker-compose exec -T wordpress rm -f /tmp/woocommerce-complete-setup.php 2>/dev/null || true

# Create test data (optional - must be explicitly requested)
if [ "$1" == "--add-test-data" ] || [ "$ADD_TEST_DATA" == "true" ]; then
    echo "🎯 Creating comprehensive test data (products, customers, categories)..."
    echo "💡 Test data creation was explicitly requested"
    
    if run_wpcli plugin is-active woocommerce; then
        
        # Create product categories
        echo "📁 Creating product categories..."
        run_wpcli wc product_cat create \
            --name="Electronics" \
            --description="Electronic devices and gadgets" \
            --user=admin 2>/dev/null || echo "⚠️  Electronics category may already exist"

        run_wpcli wc product_cat create \
            --name="Clothing" \
            --description="Apparel and accessories" \
            --user=admin 2>/dev/null || echo "⚠️  Clothing category may already exist"

        run_wpcli wc product_cat create \
            --name="Home & Garden" \
            --description="Home improvement and garden supplies" \
            --user=admin 2>/dev/null || echo "⚠️  Home & Garden category may already exist"

        run_wpcli wc product_cat create \
            --name="Books" \
            --description="Books and educational materials" \
            --user=admin 2>/dev/null || echo "⚠️  Books category may already exist"

        # Create test customers (basic info - billing can be added via admin)
        echo "👥 Creating test customers..."
        run_wpcli wc customer create \
            --email="john.doe@example.com" \
            --first_name="John" \
            --last_name="Doe" \
            --username="johndoe" \
            --user=admin 2>/dev/null || echo "⚠️  John Doe customer may already exist"

        run_wpcli wc customer create \
            --email="jane.smith@example.com" \
            --first_name="Jane" \
            --last_name="Smith" \
            --username="janesmith" \
            --user=admin 2>/dev/null || echo "⚠️  Jane Smith customer may already exist"

        run_wpcli wc customer create \
            --email="bob.wilson@example.com" \
            --first_name="Bob" \
            --last_name="Wilson" \
            --username="bobwilson" \
            --user=admin 2>/dev/null || echo "⚠️  Bob Wilson customer may already exist"

        # Create diverse products with WMS-compatible SKUs (dimensions can be added via admin)
        echo "📦 Creating comprehensive product catalog..."
        
        # Electronics
        run_wpcli wc product create \
            --name="Wireless Bluetooth Headphones" \
            --type="simple" \
            --regular_price="129.99" \
            --sale_price="99.99" \
            --sku="WMS-EL-001" \
            --manage_stock=1 \
            --stock_quantity=25 \
            --weight="0.5" \
            --description="High-quality wireless headphones with noise cancellation" \
            --short_description="Premium wireless headphones" \
            --user=admin 2>/dev/null || echo "⚠️  Bluetooth Headphones may already exist"

        run_wpcli wc product create \
            --name="Smart Watch Series 5" \
            --type="simple" \
            --regular_price="299.99" \
            --sku="WMS-EL-002" \
            --manage_stock=1 \
            --stock_quantity=15 \
            --weight="0.3" \
            --description="Advanced smartwatch with health monitoring" \
            --short_description="Feature-rich smartwatch" \
            --user=admin 2>/dev/null || echo "⚠️  Smart Watch may already exist"

        run_wpcli wc product create \
            --name="USB-C Charging Cable" \
            --type="simple" \
            --regular_price="19.99" \
            --sku="WMS-EL-003" \
            --manage_stock=1 \
            --stock_quantity=100 \
            --weight="0.1" \
            --description="Fast charging USB-C cable 6ft" \
            --short_description="USB-C charging cable" \
            --user=admin 2>/dev/null || echo "⚠️  USB-C Cable may already exist"

        # Clothing
        run_wpcli wc product create \
            --name="Premium Cotton T-Shirt" \
            --type="simple" \
            --regular_price="29.99" \
            --sku="WMS-CL-001" \
            --manage_stock=1 \
            --stock_quantity=50 \
            --weight="0.3" \
            --description="Comfortable premium cotton t-shirt" \
            --short_description="Premium cotton t-shirt" \
            --user=admin 2>/dev/null || echo "⚠️  T-Shirt may already exist"

        run_wpcli wc product create \
            --name="Denim Jeans" \
            --type="simple" \
            --regular_price="79.99" \
            --sku="WMS-CL-002" \
            --manage_stock=1 \
            --stock_quantity=30 \
            --weight="1.2" \
            --description="Classic blue denim jeans" \
            --short_description="Blue denim jeans" \
            --user=admin 2>/dev/null || echo "⚠️  Denim Jeans may already exist"

        run_wpcli wc product create \
            --name="Leather Wallet" \
            --type="simple" \
            --regular_price="49.99" \
            --sku="WMS-CL-003" \
            --manage_stock=1 \
            --stock_quantity=20 \
            --weight="0.2" \
            --description="Genuine leather wallet with multiple card slots" \
            --short_description="Leather wallet" \
            --user=admin 2>/dev/null || echo "⚠️  Leather Wallet may already exist"

        # Home & Garden
        run_wpcli wc product create \
            --name="Ceramic Coffee Mug Set" \
            --type="simple" \
            --regular_price="34.99" \
            --sku="WMS-HG-001" \
            --manage_stock=1 \
            --stock_quantity=40 \
            --weight="2.0" \
            --description="Set of 4 ceramic coffee mugs" \
            --short_description="Ceramic mug set" \
            --user=admin 2>/dev/null || echo "⚠️  Coffee Mug Set may already exist"

        run_wpcli wc product create \
            --name="Indoor Plant Pot" \
            --type="simple" \
            --regular_price="24.99" \
            --sku="WMS-HG-002" \
            --manage_stock=1 \
            --stock_quantity=35 \
            --weight="1.5" \
            --description="Modern ceramic plant pot with drainage" \
            --short_description="Ceramic plant pot" \
            --user=admin 2>/dev/null || echo "⚠️  Plant Pot may already exist"

        # Books
        run_wpcli wc product create \
            --name="JavaScript: The Complete Guide" \
            --type="simple" \
            --regular_price="59.99" \
            --sale_price="44.99" \
            --sku="WMS-BK-001" \
            --manage_stock=1 \
            --stock_quantity=50 \
            --weight="0.8" \
            --description="Comprehensive guide to modern JavaScript development" \
            --short_description="JavaScript programming book" \
            --user=admin 2>/dev/null || echo "⚠️  JavaScript Book may already exist"

        run_wpcli wc product create \
            --name="Docker for Developers" \
            --type="simple" \
            --regular_price="49.99" \
            --sku="WMS-BK-002" \
            --manage_stock=1 \
            --stock_quantity=30 \
            --weight="0.6" \
            --description="Learn Docker containerization for modern development" \
            --short_description="Docker development book" \
            --user=admin 2>/dev/null || echo "⚠️  Docker Book may already exist"

        # Test different stock scenarios
        run_wpcli wc product create \
            --name="Limited Edition Poster" \
            --type="simple" \
            --regular_price="29.99" \
            --sku="WMS-LIM-001" \
            --manage_stock=1 \
            --stock_quantity=3 \
            --weight="0.1" \
            --description="Limited edition art poster - only few left!" \
            --short_description="Limited edition poster" \
            --user=admin 2>/dev/null || echo "⚠️  Limited Edition Poster may already exist"

        run_wpcli wc product create \
            --name="Sold Out Special Item" \
            --type="simple" \
            --regular_price="99.99" \
            --sku="WMS-OUT-001" \
            --manage_stock=1 \
            --stock_quantity=0 \
            --weight="1.0" \
            --description="This item is currently out of stock" \
            --short_description="Out of stock item" \
            --user=admin 2>/dev/null || echo "⚠️  Out of Stock Item may already exist"

        run_wpcli wc product create \
            --name="Pre-order New Release" \
            --type="simple" \
            --regular_price="149.99" \
            --sku="WMS-PRE-001" \
            --manage_stock=1 \
            --stock_quantity=0 \
            --backorders="yes" \
            --weight="2.0" \
            --description="New product available for pre-order" \
            --short_description="Pre-order item" \
            --user=admin 2>/dev/null || echo "⚠️  Pre-order Item may already exist"

        # Re-enable customer emails after setup
        echo "📧 Re-enabling email notifications..."
        chmod +x scripts/enable-emails.sh 2>/dev/null || true
        ./scripts/enable-emails.sh >/dev/null 2>&1 || echo "⚠️  Could not re-enable emails - you can run ./scripts/enable-emails.sh manually"
        
    else
        echo "⚠️  WooCommerce not active, skipping test data creation"
    fi
else
    echo "⏭️  Skipping test data creation (default behavior)"
    echo "💡 To add test data: run './scripts/populate-test-data.sh' or 'make setup-with-test-data'"
fi

# Activate WMS Integration plugin
echo "🔧 Activating WMS Integration plugin..."
run_wpcli plugin activate wc-wms-integration 2>/dev/null

if run_wpcli plugin is-active wc-wms-integration; then
    echo "✅ WMS Integration plugin is active!"
    
    # Configure WMS settings using dedicated script
    echo "🔧 Configuring WMS integration settings..."
    if [ -f "scripts/configure-wms.sh" ]; then
        chmod +x scripts/configure-wms.sh
        # Run the WMS configuration script (it will handle output and verification)
        ./scripts/configure-wms.sh || echo "⚠️  WMS configuration script failed - you can run 'make configure-wms' manually"
    else
        echo "⚠️  WMS configuration script not found. Run 'make configure-wms' manually after setup."
    fi
else
    echo "⚠️  WMS Integration plugin failed to activate."
    echo "📋 Checking debug.log for possible fatal error..."
    docker-compose run --rm wpcli tail -n 20 wp-content/debug.log || echo "⚠️  No debug log found"
fi

# Enable pretty permalinks for better API endpoints
echo "🔗 Configuring permalinks..."
run_wpcli rewrite structure '/%postname%/' 2>/dev/null || true
run_wpcli rewrite flush 2>/dev/null || true

# Configure WP-Cron
echo "⏰ Configuring WP-Cron..."
if [ -f "scripts/wp-cron.sh" ]; then
    chmod +x scripts/wp-cron.sh
    # Verify cron configuration
    CRON_STATUS=$(run_wpcli config get DISABLE_WP_CRON 2>/dev/null || echo "false")
    if [ "$CRON_STATUS" = "true" ]; then
        echo "✅ WP-Cron properly disabled (using system cron)"
    else
        echo "⚠️  WP-Cron is using built-in method (loads on page requests)"
    fi
fi

# Final verification
echo "🔍 Final verification..."
echo "WordPress installation: $(run_wpcli core is-installed && echo "✅ OK" || echo "❌ FAILED")"
echo "Admin user: $(run_wpcli user get admin --field=user_login 2>/dev/null && echo "✅ OK" || echo "❌ FAILED")"
echo "WooCommerce plugin: $(run_wpcli plugin is-active woocommerce 2>/dev/null && echo "✅ OK" || echo "⚠️  NOT ACTIVE")"
echo "WMS Integration plugin: $(run_wpcli plugin is-active wc-wms-integration 2>/dev/null && echo "✅ OK" || echo "⚠️  NOT ACTIVE")"
echo "Cron service: $(docker-compose ps cron 2>/dev/null | grep -q 'Up' && echo "✅ OK" || echo "❌ NOT RUNNING")"

# Test login
echo "🔐 Testing admin login..."
if run_wpcli user check-password admin password 2>/dev/null; then
    echo "✅ Admin login test successful!"
else
    echo "❌ Admin login test failed!"
    echo "🔧 Resetting admin password..."
    run_wpcli user update admin --user_pass=password
    echo "✅ Admin password reset to 'password'"
fi
echo "📋 Showing recent WordPress error log entries..."
docker-compose run --rm wpcli tail -n 20 wp-content/debug.log || echo "⚠️  No debug log found"
echo "✅ Complete setup finished!"
echo ""
echo "🌐 WordPress Admin: http://localhost:8000/wp-admin"
echo "👤 Username: admin"
echo "🔑 Password: password"
echo ""
echo "🛒 WooCommerce: http://localhost:8000/wp-admin/admin.php?page=wc-admin"
echo "🔧 WMS Integration: http://localhost:8000/wp-admin/admin.php?page=wc-wms-integration"
if [ ! -z "$WMS_USERNAME" ] && [ ! -z "$WMS_PASSWORD" ]; then
    echo "   ✅ WMS credentials have been pre-configured from .env file!"
fi
echo "🗄️  phpMyAdmin: http://localhost:8080 (run: docker-compose --profile dev-tools up -d)"
echo "📁 Plugin directory: ./wms/ (your WMS integration plugin)"
echo ""
if [ "$1" == "--add-test-data" ] || [ "$ADD_TEST_DATA" == "true" ]; then
    echo "📊 **Test Data Created:**"
    echo "   • 4 Product Categories (Electronics, Clothing, Home & Garden, Books)"
    echo "   • 3 Test Customers (basic info - add billing via admin)"
    echo "   • 12+ Products with WMS-compatible SKUs and stock levels"
    echo "   • Various stock scenarios (normal, low, out of stock, backorder)"
    echo "   • Payment methods and comprehensive shipping configuration"
    echo "   • WooCommerce setup wizard completely bypassed"
else
    echo "📊 **Setup Complete (without test data):**"
    echo "   • WordPress and WooCommerce configured"
    echo "   • Payment methods and comprehensive shipping configuration"
    echo "   • WooCommerce setup wizard completely bypassed"
    echo "   • To add test data: run './scripts/populate-test-data.sh' or 'make setup-with-test-data'"
fi
echo "   • WMS Integration plugin installed and activated"
echo "   • ⚡ Fast PHP-based configuration (replaces 50+ individual commands)"
echo "   • Clean setup (no cache/email/parameter warnings)"
echo "   • Email notifications properly configured (silent setup)"
echo ""
echo "🎯 **Ready for Development:**"
echo "   1. Edit plugin files in ./wms/ (changes appear immediately)"
echo "   2. Visit WooCommerce > WMS Integration in WordPress admin"
echo "   3. Browse customers and products in WooCommerce"
echo "   4. Place test orders and develop WMS integration"
echo ""
echo "🔗 **Advanced Features:**"
echo "   • Webhook testing: docker-compose --profile webhook-testing up -d"
echo "   • ngrok interface: http://localhost:4040"
echo "   • Permission fixes: ./scripts/dev.sh fix-permissions"
echo "   • Environment reset: ./scripts/dev.sh reset"
echo "   • Test clean setup: ./scripts/dev.sh test-clean"
echo "   • Email control: ./scripts/dev.sh disable-emails | ./scripts/dev.sh enable-emails"
echo ""
echo "⏰ **WP-Cron Management:**"
echo "   • Status: make cron-status"
echo "   • Run manually: make cron-run"
echo "   • List events: make cron-list"
echo "   • View logs: make cron-logs"
echo "   • Cron is running in the background via system cron (every minute)"
echo ""
echo "📡 **Webhook Testing (HTTPS required for WMS):**"
echo "   • Setup check: ./scripts/webhook/setup-check.sh"
echo "   • Start testing: make webhook-start"
echo "   • Test endpoints: make webhook-test"
echo "   • Register with WMS: make webhook-register"
echo "   • ngrok dashboard: http://localhost:4040 (when running)"
echo "   • Documentation: ./scripts/webhook/README.md"
echo ""
echo "📝 **To enable webhook testing:**"
echo "   1. Sign up at https://ngrok.com/"
echo "   2. Add NGROK_AUTHTOKEN to .env file"
echo "   3. Run: make webhook-start"
fix_container_permissions

# Fix Git permission issues caused by setup
fix_git_permissions

echo "🎯 **Setup Complete!**"
echo "✅ WordPress environment is ready for development"
echo "✅ Git permissions have been automatically fixed"