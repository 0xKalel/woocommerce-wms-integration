#!/bin/bash

# WordPress Debug and Troubleshooting Script
echo "🔍 WordPress Debug Information"
echo "================================"

# Function to run WP-CLI commands
run_wpcli() {
    (cd .. && docker-compose run --rm wpcli wp "$@")
}

echo ""
echo "📊 Container Status:"
cd .. && docker-compose ps

echo ""
echo "🌐 WordPress URL Test:"
if curl -s -f http://localhost:8000 > /dev/null 2>&1; then
    echo "✅ WordPress URL is responding"
else
    echo "❌ WordPress URL is not responding"
fi

echo ""
echo "🗄️  Database Connection Test:"
if (cd .. && docker-compose exec db mysqladmin ping -h localhost -u wordpress -pwordpress 2>/dev/null); then
    echo "✅ Database is responding"
else
    echo "❌ Database is not responding"
fi

echo ""
echo "🔧 WordPress Installation Status:"
WP_INSTALLED=$(run_wpcli core is-installed 2>/dev/null && echo "installed" || echo "not-installed")
echo "Installation status: $WP_INSTALLED"

if [[ "$WP_INSTALLED" == "installed" ]]; then
    echo ""
    echo "👥 Users:"
    run_wpcli user list --format=table
    
    echo ""
    echo "🔐 Admin User Test:"
    if run_wpcli user check-password admin password 2>/dev/null; then
        echo "✅ Admin user password is correct"
    else
        echo "❌ Admin user password is incorrect"
    fi
    
    echo ""
    echo "🔌 Active Plugins:"
    run_wpcli plugin list --status=active --format=table
    
    echo ""
    echo "⚙️  WordPress Options:"
    echo "Home URL: $(run_wpcli option get home)"
    echo "Site URL: $(run_wpcli option get siteurl)"
    echo "Admin Email: $(run_wpcli option get admin_email)"
else
    echo ""
    echo "🔧 WordPress Core Files:"
    if run_wpcli core is-installed --network 2>/dev/null; then
        echo "✅ WordPress core files are present"
    else
        echo "❌ WordPress core files are missing"
    fi
    
    echo ""
    echo "🗄️  Database Tables:"
    DB_TABLES=$((cd .. && docker-compose exec db mysql -u wordpress -pwordpress -D wordpress -e "SHOW TABLES;" 2>/dev/null | wc -l))
    echo "Database tables count: $DB_TABLES"
fi

echo ""
echo "📋 WordPress Logs (last 20 lines):"
cd .. && docker-compose logs --tail=20 wordpress

echo ""
echo "🔧 Troubleshooting Suggestions:"
echo "================================"

if [[ "$WP_INSTALLED" == "not-installed" ]]; then
    echo "❌ WordPress is not installed. Try:"
    echo "   ./scripts/dev.sh reset    # Complete reset"
    echo "   ./setup.sh        # Run setup again"
fi

if ! curl -s -f http://localhost:8000 > /dev/null 2>&1; then
    echo "❌ WordPress URL not responding. Check:"
    echo "   docker-compose up -d    # Start services"
    echo "   docker-compose logs wordpress    # Check logs"
fi

if ! (cd .. && docker-compose exec db mysqladmin ping -h localhost -u wordpress -pwordpress 2>/dev/null); then
    echo "❌ Database not responding. Try:"
    echo "   docker-compose restart db    # Restart database"
    echo "   docker-compose logs db       # Check database logs"
fi

echo ""
echo "🚀 Quick Fix Commands:"
echo "   ./scripts/dev.sh reset       # Complete reset"
echo "   ./scripts/dev.sh permissions # Fix script permissions"
echo "   ./scripts/dev.sh status      # Check status"
