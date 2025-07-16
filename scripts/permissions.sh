#!/bin/bash

# Comprehensive permission management for WordPress Docker + WSL

set -e

echo "🔧 WordPress Permission Management"
echo "=================================="

# Detect environment
detect_environment() {
    if grep -qi microsoft /proc/version 2>/dev/null; then
        echo "wsl"
    elif [[ "$OSTYPE" == "darwin"* ]]; then
        echo "macos"
    else
        echo "linux"
    fi
}

# Get WordPress container ID
get_wp_container() {
    docker-compose ps -q wordpress 2>/dev/null || echo ""
}

# Main permission fix function
fix_permissions() {
    local ENV=$(detect_environment)
    local WP_CONTAINER=$(get_wp_container)
    
    echo "🔍 Environment: $ENV"
    
    # Create required directories
    mkdir -p wms uploads
    
    # Get user info
    local USER_ID=$(id -u)
    local GROUP_ID=$(id -g)
    
    echo "👤 Current user: $(whoami) ($USER_ID:$GROUP_ID)"
    
    # Fix host permissions based on environment
    case $ENV in
        "wsl")
            # WSL specific: Try to use www-data group (33)
            if getent group www-data >/dev/null 2>&1; then
                echo "📁 Setting WSL permissions with www-data group..."
                sudo chown -R $USER_ID:www-data wms/ uploads/ 2>/dev/null || {
                    echo "⚠️  Sudo failed, using fallback permissions"
                    chown -R $USER_ID:$GROUP_ID wms/ uploads/
                }
                chmod -R 775 wms/ uploads/
            else
                # Create www-data group if doesn't exist
                echo "📁 Creating www-data group for WSL..."
                sudo groupadd -g 33 www-data 2>/dev/null || true
                sudo usermod -a -G www-data $USER 2>/dev/null || true
                
                # Apply permissions
                sudo chown -R $USER_ID:33 wms/ uploads/
                chmod -R 775 wms/ uploads/
            fi
            ;;
            
        "macos")
            # macOS: Use staff group typically
            echo "📁 Setting macOS permissions..."
            chown -R $USER_ID:staff wms/ uploads/
            chmod -R 775 wms/ uploads/
            ;;
            
        "linux")
            # Linux: Standard permissions
            echo "📁 Setting Linux permissions..."
            if groups | grep -q www-data; then
                chown -R $USER_ID:www-data wms/ uploads/
            else
                chown -R $USER_ID:$GROUP_ID wms/ uploads/
            fi
            chmod -R 775 wms/ uploads/
            ;;
    esac
    
    # Fix container permissions if running
    if [ -n "$WP_CONTAINER" ]; then
        echo "🐳 Fixing container permissions..."
        
        # Ensure directories exist in container
        docker-compose exec -T --user root wordpress bash -c "
            mkdir -p /var/www/html/wp-content/plugins/wc-wms-integration
            mkdir -p /var/www/html/wp-content/uploads
            chown -R www-data:www-data /var/www/html/wp-content/plugins/wc-wms-integration
            chown -R www-data:www-data /var/www/html/wp-content/uploads
            chmod -R 755 /var/www/html/wp-content/plugins/wc-wms-integration
            chmod -R 775 /var/www/html/wp-content/uploads
        " || echo "⚠️  Container permission fix failed"
    else
        echo "⚠️  WordPress container not running"
    fi
    
    # Verify permissions
    echo ""
    echo "📊 Permission Check:"
    ls -la wms/ 2>/dev/null | head -5 || echo "   wms/ directory empty"
    ls -la uploads/ 2>/dev/null | head -5 || echo "   uploads/ directory empty"
}

# Run based on argument
case "$1" in
    "check")
        echo "📊 Current Permissions:"
        ls -la wms/ uploads/ 2>/dev/null || echo "Directories not found"
        ;;
    "fix")
        fix_permissions
        ;;
    *)
        echo "Usage: $0 [check|fix]"
        echo "  check - Show current permissions"
        echo "  fix   - Fix all permissions"
        ;;
esac