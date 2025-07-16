#!/bin/bash

# Make all necessary scripts executable
echo "🔧 Making all scripts executable..."

# Change to parent directory to access setup.sh
cd ..

# Make setup.sh executable
chmod +x setup.sh

# Make dev wrapper executable  
chmod +x dev

# Make all scripts in scripts directory executable
chmod +x scripts/*.sh

# Make webhook scripts executable
if [ -d "scripts/webhook" ]; then
    chmod +x scripts/webhook/*.sh
fi

# Also make setup-old.sh executable if it exists
if [ -f "setup-old.sh" ]; then
    chmod +x setup-old.sh
fi

echo "✅ All scripts are now executable!"
echo ""
echo "📋 Executable files:"
echo "   • setup.sh (main setup script)"
echo "   • dev (development wrapper)"
echo "   • scripts/dev.sh (development helper)"
echo "   • scripts/debug.sh (debugging tools)"
echo "   • scripts/init-env.sh (environment setup)"
echo "   • scripts/activate-wms.sh (WMS plugin activation)"
echo "   • scripts/test-wpcli.sh (WP-CLI testing)"
echo "   • scripts/test-clean-setup.sh (clean setup testing)"
echo "   • scripts/disable-emails.sh (email management)"
echo "   • scripts/enable-emails.sh (email management)"
echo "   • scripts/populate-test-data.sh (test data)"
echo "   • scripts/woocommerce-complete-setup.php (WooCommerce configuration)"
echo "   • scripts/wp-cron.sh (WP-Cron management)"
echo "   • scripts/cron-runner.sh (cron execution)"
echo "   • scripts/fix-permissions.sh (fix directory permissions)"
if [ -f "setup-old.sh" ]; then
    echo "   • setup-old.sh (backup)"
fi
echo ""
echo "🚀 Ready to use! Try:"
echo "   ./dev status"
echo "   ./scripts/dev.sh status"
