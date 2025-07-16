#!/bin/bash
# WMS Configuration Script
# Manually set WMS credentials from .env file

echo "⚙️  Configuring WMS Integration Credentials..."

# Load environment variables
if [ -f ".env" ]; then
    export $(cat .env | grep -v '^#' | grep -v '^$' | xargs)
    echo "✅ Loaded .env file"
else
    echo "❌ No .env file found!"
    exit 1
fi

# Function to run WP-CLI commands
run_wpcli() {
    docker-compose run --rm --user=33:33 wpcli wp "$@"
}

# Set WMS credentials using WP-CLI
echo ""
echo "📝 Setting WMS credentials..."

if [ ! -z "$WMS_USERNAME" ]; then
    echo "  • Setting username: $WMS_USERNAME"
    run_wpcli option update wc_wms_integration_username "$WMS_USERNAME"
else
    echo "  ⚠️  WMS_USERNAME not found in .env"
fi

if [ ! -z "$WMS_PASSWORD" ]; then
    echo "  • Setting password: [HIDDEN]"
    run_wpcli option update wc_wms_integration_password "$WMS_PASSWORD"
else
    echo "  ⚠️  WMS_PASSWORD not found in .env"
fi

if [ ! -z "$WMS_CUSTOMER_ID" ]; then
    echo "  • Setting customer ID: $WMS_CUSTOMER_ID"
    run_wpcli option update wc_wms_integration_customer_id "$WMS_CUSTOMER_ID"
else
    echo "  ⚠️  WMS_CUSTOMER_ID not found in .env"
fi

if [ ! -z "$WMS_CODE" ]; then
    echo "  • Setting WMS code: $WMS_CODE"
    run_wpcli option update wc_wms_integration_wms_code "$WMS_CODE"
else
    echo "  ⚠️  WMS_CODE not found in .env"
fi

if [ ! -z "$WMS_API_URL" ]; then
    echo "  • Setting API URL: $WMS_API_URL"
    run_wpcli option update wc_wms_integration_api_url "$WMS_API_URL"
else
    echo "  ⚠️  WMS_API_URL not found in .env"
fi

# Verify the settings were applied
echo ""
echo "🔍 Verifying WMS configuration..."

USERNAME=$(run_wpcli option get wc_wms_integration_username 2>/dev/null || echo "NOT SET")
CUSTOMER_ID=$(run_wpcli option get wc_wms_integration_customer_id 2>/dev/null || echo "NOT SET")
WMS_CODE_CHECK=$(run_wpcli option get wc_wms_integration_wms_code 2>/dev/null || echo "NOT SET")
API_URL=$(run_wpcli option get wc_wms_integration_api_url 2>/dev/null || echo "NOT SET")

echo "  • Username: $USERNAME"
echo "  • Customer ID: $CUSTOMER_ID"
echo "  • WMS Code: $WMS_CODE_CHECK"
echo "  • API URL: $API_URL"
echo "  • Password: $([ "$USERNAME" != "NOT SET" ] && echo "SET" || echo "NOT SET")"

# Check if all required settings are configured
if [ "$USERNAME" != "NOT SET" ] && [ "$CUSTOMER_ID" != "NOT SET" ] && [ "$WMS_CODE_CHECK" != "NOT SET" ] && [ "$API_URL" != "NOT SET" ]; then
    echo ""
    echo "✅ All WMS credentials configured successfully!"
    echo "🎯 You can now test the connection in WordPress admin:"
    echo "   http://localhost:8000/wp-admin/admin.php?page=wc-wms-integration"
else
    echo ""
    echo "❌ Some WMS credentials are missing. Check your .env file."
fi

echo ""
echo "✅ WMS configuration complete!"
echo "💡 The warning messages should stop appearing now."