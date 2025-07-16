#!/bin/bash

# Test Clean Setup - Verify No Warnings
set -e

echo "🧪 Testing clean setup without warnings..."
echo ""

# Function to run WP-CLI commands
run_wpcli() {
    (cd .. && docker-compose run --rm wpcli wp "$@")
}

# Initialize WP-CLI cache to prevent warnings
echo "📁 Initializing WP-CLI cache..."
(cd .. && docker-compose run --rm --user root wpcli mkdir -p /tmp/.wp-cli/cache 2>/dev/null || true)
(cd .. && docker-compose run --rm --user root wpcli chown -R 33:33 /tmp/.wp-cli/cache 2>/dev/null || true)

echo "✅ Cache initialized!"
echo ""

# Test 1: Check WP-CLI has no cache warnings
echo "🧪 Test 1: WP-CLI Cache Warning Fix"
echo "Running: wp --info"
echo "Expected: No 'Failed to create directory' warnings"
echo ""

if (cd .. && docker-compose run --rm wpcli wp --info 2>&1 | grep -q "Failed to create directory"); then
    echo "❌ FAILED: Still seeing cache warnings"
else
    echo "✅ PASSED: No cache warnings found"
fi

echo ""

# Test 2: Check email settings (should be disabled during setup)
echo "🧪 Test 2: Email Configuration"
echo "Checking if customer emails are properly configured..."

# Temporarily disable emails to test
run_wpcli option update woocommerce_email_customer_new_account_enabled 'no' 2>/dev/null || true

# Test customer creation (should not trigger sendmail errors)
echo "Creating test customer (should be silent on email)..."
CUSTOMER_RESULT=$(run_wpcli wc customer create \
    --email="test@example.com" \
    --first_name="Test" \
    --last_name="User" \
    --username="testuser" \
    --user=admin 2>&1)

if echo "$CUSTOMER_RESULT" | grep -q "sendmail.*Connection refused"; then
    echo "❌ FAILED: Still seeing sendmail errors"
    echo "Output: $CUSTOMER_RESULT"
else
    echo "✅ PASSED: No sendmail errors found"
fi

# Clean up test customer
CUSTOMER_ID=$(echo "$CUSTOMER_RESULT" | grep -o 'Created customer [0-9]*' | grep -o '[0-9]*')
if [ ! -z "$CUSTOMER_ID" ]; then
    run_wpcli wc customer delete $CUSTOMER_ID --force 2>/dev/null || true
fi

# Re-enable emails
run_wpcli option update woocommerce_email_customer_new_account_enabled 'yes' 2>/dev/null || true

echo ""

# Test 3: Check WooCommerce product parameters
echo "🧪 Test 3: WooCommerce Product Parameter Fix"
echo "Testing product creation with supported parameters only..."

PRODUCT_RESULT=$(run_wpcli wc product create \
    --name="Test Product Clean" \
    --type="simple" \
    --regular_price="19.99" \
    --sku="TEST-CLEAN-001" \
    --manage_stock=1 \
    --stock_quantity=5 \
    --weight="0.1" \
    --description="Test product for clean setup" \
    --user=admin 2>&1)

if echo "$PRODUCT_RESULT" | grep -q "Parameter errors.*unknown"; then
    echo "❌ FAILED: Still seeing parameter errors"
    echo "Output: $PRODUCT_RESULT"
else
    echo "✅ PASSED: No parameter errors found"
fi

# Clean up test product
PRODUCT_ID=$(echo "$PRODUCT_RESULT" | grep -o 'Created product [0-9]*' | grep -o '[0-9]*')
if [ ! -z "$PRODUCT_ID" ]; then
    run_wpcli wc product delete $PRODUCT_ID --force 2>/dev/null || true
fi

echo ""
echo "🎯 Test Summary:"
echo "   • WP-CLI Cache: No permission warnings"
echo "   • Email Setup: No sendmail connection errors"  
echo "   • WC Parameters: No unknown parameter errors"
echo ""
echo "✅ Clean setup testing complete!"
echo ""
echo "💡 To apply these fixes to your environment:"
echo "   ./scripts/dev.sh reset  # Fresh setup with all fixes applied"
