#!/bin/bash

# Quick WMS Plugin Activation Script
set -e

echo "🔧 Activating WMS Integration Plugin..."

# Function to run WP-CLI commands
run_wpcli() {
    (cd .. && docker-compose run --rm wpcli wp "$@")
}

# Initialize WP-CLI cache directory to prevent permission warnings
echo "📁 Initializing WP-CLI cache..."
(cd .. && docker-compose run --rm --user root wpcli mkdir -p /tmp/.wp-cli/cache 2>/dev/null || true)
(cd .. && docker-compose run --rm --user root wpcli chown -R 33:33 /tmp/.wp-cli/cache 2>/dev/null || true)

# Check if plugin exists
if run_wpcli plugin get wc-wms-integration --field=name 2>/dev/null; then
    echo "✅ WMS Integration plugin found!"
    
    # Activate the plugin
    if run_wpcli plugin activate wc-wms-integration; then
        echo "✅ WMS Integration plugin activated successfully!"
        echo ""
        echo "🎯 You can now access it at:"
        echo "   http://localhost:8000/wp-admin/admin.php?page=wc-wms-integration"
    else
        echo "❌ Failed to activate WMS Integration plugin"
        echo "🔧 Try manually activating it in WordPress admin:"
        echo "   http://localhost:8000/wp-admin/plugins.php"
    fi
else
    echo "❌ WMS Integration plugin not found!"
    echo "🔧 Check if the ./wms/ directory is properly mounted"
    echo "   The plugin should be located at ./wms/wc-wms-integration.php"
fi

# Verify activation
echo ""
echo "🔍 Plugin status:"
if run_wpcli plugin is-active wc-wms-integration 2>/dev/null; then
    echo "✅ WMS Integration plugin is ACTIVE"
else
    echo "⚠️  WMS Integration plugin is NOT ACTIVE"
fi
