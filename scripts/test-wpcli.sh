#!/bin/bash

# Test WP-CLI without cache warnings
echo "🧪 Testing WP-CLI cache fix..."

# Initialize cache directory
echo "📁 Setting up WP-CLI cache directory..."
(cd .. && docker-compose run --rm --user root wpcli mkdir -p /tmp/.wp-cli/cache 2>/dev/null || true)
(cd .. && docker-compose run --rm --user root wpcli chown -R 33:33 /tmp/.wp-cli/cache 2>/dev/null || true)

echo "✅ Cache directory initialized!"

# Test WP-CLI command
echo "🧪 Testing WP-CLI command (should have no cache warnings)..."
cd .. && docker-compose run --rm wpcli wp --info

echo ""
echo "✅ Test complete! If you see no 'Failed to create directory' warnings above, the fix worked!"
echo ""
echo "💡 This fix:"
echo "   • Creates WP-CLI cache directory in /tmp/.wp-cli/cache"
echo "   • Sets proper permissions for www-data user"
echo "   • Eliminates the permission warning during plugin installs"
echo "   • Speeds up WP-CLI operations with caching"
