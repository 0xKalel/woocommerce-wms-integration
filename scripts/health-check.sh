#!/bin/bash

echo "🏥 WordPress Environment Health Check"
echo "====================================="

HEALTH_SCORE=0
TOTAL_CHECKS=0

# Function to check and score
check_health() {
    local CHECK_NAME=$1
    local CHECK_CMD=$2
    local SUCCESS_MSG=$3
    local FAIL_MSG=$4
    
    TOTAL_CHECKS=$((TOTAL_CHECKS + 1))
    
    echo -n "Checking $CHECK_NAME... "
    if eval "$CHECK_CMD" >/dev/null 2>&1; then
        echo "✅ $SUCCESS_MSG"
        HEALTH_SCORE=$((HEALTH_SCORE + 1))
        return 0
    else
        echo "❌ $FAIL_MSG"
        return 1
    fi
}

# Run health checks
check_health "Docker Service" "docker info" "Docker is running" "Docker is not running"
check_health "Database Container" "docker-compose ps db | grep -q Up" "Database is up" "Database is down"
check_health "WordPress Container" "docker-compose ps wordpress | grep -q Up" "WordPress is up" "WordPress is down"
check_health "WordPress URL" "curl -s -f http://localhost:8000" "WordPress is responding" "WordPress not responding"
check_health "Database Connection" "docker-compose exec db mysqladmin ping -h localhost -u wordpress -pwordpress" "Database accepting connections" "Database not accepting connections"
check_health "WordPress Installation" "docker-compose run --rm wpcli wp core is-installed" "WordPress is installed" "WordPress not installed"
check_health "Admin User" "docker-compose run --rm wpcli wp user get admin --field=ID" "Admin user exists" "Admin user missing"
check_health "WooCommerce Plugin" "docker-compose run --rm wpcli wp plugin is-active woocommerce" "WooCommerce is active" "WooCommerce not active"
check_health "WMS Plugin" "docker-compose run --rm wpcli wp plugin is-active wc-wms-integration" "WMS Integration is active" "WMS Integration not active"
check_health "File Permissions" "[ -w uploads ] && [ -w wms ]" "Directories are writable" "Permission issues detected"

# Calculate health percentage
HEALTH_PERCENTAGE=$((HEALTH_SCORE * 100 / TOTAL_CHECKS))

echo ""
echo "====================================="
echo "Health Score: $HEALTH_SCORE/$TOTAL_CHECKS ($HEALTH_PERCENTAGE%)"

if [ $HEALTH_PERCENTAGE -eq 100 ]; then
    echo "🎉 Environment is fully healthy!"
elif [ $HEALTH_PERCENTAGE -ge 80 ]; then
    echo "✅ Environment is mostly healthy"
elif [ $HEALTH_PERCENTAGE -ge 50 ]; then
    echo "⚠️  Environment has some issues"
else
    echo "❌ Environment needs attention"
fi

# Provide recommendations
if [ $HEALTH_PERCENTAGE -lt 100 ]; then
    echo ""
    echo "🔧 Recommendations:"
    
    if ! docker info >/dev/null 2>&1; then
        echo "   • Start Docker service"
    fi
    
    if ! docker-compose ps | grep -q "Up"; then
        echo "   • Run: docker-compose up -d"
    fi
    
    if ! docker-compose run --rm wpcli wp core is-installed >/dev/null 2>&1; then
        echo "   • Run: ./setup.sh"
    fi
    
    if [ ! -w uploads ] || [ ! -w wms ]; then
        echo "   • Run: ./scripts/permissions.sh fix"
    fi
fi

exit $((100 - HEALTH_PERCENTAGE))