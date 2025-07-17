#!/bin/bash

# WooCommerce Test Data Population Script
# This script creates comprehensive test data for WooCommerce development and testing.
# Can be run manually when test data was skipped during setup or needs to be refreshed.
# Usage: ./scripts/populate-test-data.sh
set -e

# Function to run WP-CLI commands
run_wpcli() {
    docker-compose run --rm wpcli wp "$@"
}

echo "🎯 Populating WooCommerce with realistic test data..."

# Create product categories
echo "📁 Creating product categories..."
run_wpcli wc product_cat create \
    --name="Electronics" \
    --description="Electronic devices and gadgets" \
    --user=admin

run_wpcli wc product_cat create \
    --name="Clothing" \
    --description="Apparel and accessories" \
    --user=admin

run_wpcli wc product_cat create \
    --name="Home & Garden" \
    --description="Home improvement and garden supplies" \
    --user=admin

run_wpcli wc product_cat create \
    --name="Books" \
    --description="Books and educational materials" \
    --user=admin

# Create customers
echo "👥 Creating test customers..."
run_wpcli wc customer create \
    --email="john.doe@example.com" \
    --first_name="John" \
    --last_name="Doe" \
    --username="johndoe" \
    --billing_first_name="John" \
    --billing_last_name="Doe" \
    --billing_address_1="123 Main Street" \
    --billing_city="New York" \
    --billing_state="NY" \
    --billing_postcode="10001" \
    --billing_country="US" \
    --billing_email="john.doe@example.com" \
    --billing_phone="555-0123" \
    --user=admin

run_wpcli wc customer create \
    --email="jane.smith@example.com" \
    --first_name="Jane" \
    --last_name="Smith" \
    --username="janesmith" \
    --billing_first_name="Jane" \
    --billing_last_name="Smith" \
    --billing_address_1="456 Oak Avenue" \
    --billing_city="Los Angeles" \
    --billing_state="CA" \
    --billing_postcode="90210" \
    --billing_country="US" \
    --billing_email="jane.smith@example.com" \
    --billing_phone="555-0456" \
    --user=admin

run_wpcli wc customer create \
    --email="bob.wilson@example.com" \
    --first_name="Bob" \
    --last_name="Wilson" \
    --username="bobwilson" \
    --billing_first_name="Bob" \
    --billing_last_name="Wilson" \
    --billing_address_1="789 Pine Road" \
    --billing_city="Chicago" \
    --billing_state="IL" \
    --billing_postcode="60601" \
    --billing_country="US" \
    --billing_email="bob.wilson@example.com" \
    --billing_phone="555-0789" \
    --user=admin

# Create diverse products with WMS-compatible SKUs
echo "📦 Creating diverse product catalog..."

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
    --dimensions_length="20" \
    --dimensions_width="15" \
    --dimensions_height="8" \
    --categories="[{\"id\":1}]" \
    --description="High-quality wireless headphones with noise cancellation" \
    --short_description="Premium wireless headphones" \
    --user=admin

run_wpcli wc product create \
    --name="Smart Watch Series 5" \
    --type="simple" \
    --regular_price="299.99" \
    --sku="WMS-EL-002" \
    --manage_stock=1 \
    --stock_quantity=15 \
    --weight="0.3" \
    --dimensions_length="5" \
    --dimensions_width="4" \
    --dimensions_height="1" \
    --categories="[{\"id\":1}]" \
    --description="Advanced smartwatch with health monitoring" \
    --short_description="Feature-rich smartwatch" \
    --user=admin

run_wpcli wc product create \
    --name="USB-C Charging Cable" \
    --type="simple" \
    --regular_price="19.99" \
    --sku="WMS-EL-003" \
    --manage_stock=1 \
    --stock_quantity=100 \
    --weight="0.1" \
    --dimensions_length="15" \
    --dimensions_width="2" \
    --dimensions_height="1" \
    --categories="[{\"id\":1}]" \
    --description="Fast charging USB-C cable 6ft" \
    --short_description="USB-C charging cable" \
    --user=admin

# Clothing
run_wpcli wc product create \
    --name="Denim Jeans" \
    --type="simple" \
    --regular_price="79.99" \
    --sku="WMS-CL-002" \
    --manage_stock=1 \
    --stock_quantity=30 \
    --weight="1.2" \
    --categories="[{\"id\":2}]" \
    --description="Classic blue denim jeans" \
    --short_description="Blue denim jeans" \
    --user=admin

run_wpcli wc product create \
    --name="Leather Wallet" \
    --type="simple" \
    --regular_price="49.99" \
    --sku="WMS-CL-003" \
    --manage_stock=1 \
    --stock_quantity=20 \
    --weight="0.2" \
    --dimensions_length="10" \
    --dimensions_width="8" \
    --dimensions_height="2" \
    --categories="[{\"id\":2}]" \
    --description="Genuine leather wallet with multiple card slots" \
    --short_description="Leather wallet" \
    --user=admin

# Home & Garden
run_wpcli wc product create \
    --name="Ceramic Coffee Mug Set" \
    --type="simple" \
    --regular_price="34.99" \
    --sku="WMS-HG-001" \
    --manage_stock=1 \
    --stock_quantity=40 \
    --weight="2.0" \
    --dimensions_length="15" \
    --dimensions_width="10" \
    --dimensions_height="12" \
    --categories="[{\"id\":3}]" \
    --description="Set of 4 ceramic coffee mugs" \
    --short_description="Ceramic mug set" \
    --user=admin

run_wpcli wc product create \
    --name="Indoor Plant Pot" \
    --type="simple" \
    --regular_price="24.99" \
    --sku="WMS-HG-002" \
    --manage_stock=1 \
    --stock_quantity=35 \
    --weight="1.5" \
    --dimensions_length="20" \
    --dimensions_width="20" \
    --dimensions_height="18" \
    --categories="[{\"id\":3}]" \
    --description="Modern ceramic plant pot with drainage" \
    --short_description="Ceramic plant pot" \
    --user=admin

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
    --dimensions_length="24" \
    --dimensions_width="18" \
    --dimensions_height="3" \
    --categories="[{\"id\":4}]" \
    --description="Comprehensive guide to modern JavaScript development" \
    --short_description="JavaScript programming book" \
    --user=admin

run_wpcli wc product create \
    --name="Docker for Developers" \
    --type="simple" \
    --regular_price="49.99" \
    --sku="WMS-BK-002" \
    --manage_stock=1 \
    --stock_quantity=30 \
    --weight="0.6" \
    --dimensions_length="24" \
    --dimensions_width="18" \
    --dimensions_height="2" \
    --categories="[{\"id\":4}]" \
    --description="Learn Docker containerization for modern development" \
    --short_description="Docker development book" \
    --user=admin

# Create products with different stock scenarios for testing
echo "📊 Creating products with various stock scenarios..."

# Low stock product
run_wpcli wc product create \
    --name="Limited Edition Poster" \
    --type="simple" \
    --regular_price="29.99" \
    --sku="WMS-LIM-001" \
    --manage_stock=1 \
    --stock_quantity=3 \
    --low_stock_amount=5 \
    --weight="0.1" \
    --description="Limited edition art poster - only few left!" \
    --short_description="Limited edition poster" \
    --user=admin

# Out of stock product
run_wpcli wc product create \
    --name="Sold Out Special Item" \
    --type="simple" \
    --regular_price="99.99" \
    --sku="WMS-OUT-001" \
    --manage_stock=1 \
    --stock_quantity=0 \
    --stock_status="outofstock" \
    --weight="1.0" \
    --description="This item is currently out of stock" \
    --short_description="Out of stock item" \
    --user=admin

# Backorder product
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
    --user=admin

# Configure shipping methods
echo "🚚 Setting up comprehensive shipping configuration..."
# Configure payment methods
echo "💳 Setting up payment methods..."
run_wpcli option update woocommerce_bacs_enabled 'yes'
run_wpcli option update woocommerce_cheque_enabled 'yes'
run_wpcli option update woocommerce_cod_enabled 'yes'

# Configure tax settings
echo "📊 Setting up tax configuration..."
run_wpcli option update woocommerce_calc_taxes 'yes'
run_wpcli option update woocommerce_tax_based_on 'shipping'
run_wpcli option update woocommerce_tax_round_at_subtotal 'no'
run_wpcli option update woocommerce_tax_display_shop 'excl'
run_wpcli option update woocommerce_tax_display_cart 'excl'

# Clear any caches
echo "🧹 Clearing caches..."
run_wpcli cache flush

echo "✅ Test data population complete!"
echo ""
echo "📊 **Created:**"
echo "   • 4 Product Categories (Electronics, Clothing, Home & Garden, Books)"
echo "   • 3 Test Customers (John Doe, Jane Smith, Bob Wilson)"
echo "   • 15+ Products with various:"
echo "     - Stock levels (normal, low, out of stock, backorder)"
echo "     - Product types (simple, variable)"
echo "     - WMS-compatible SKUs (WMS-XX-###)"
echo "     - Realistic dimensions and weights"
echo "   • Comprehensive shipping configuration"
echo "   • Payment methods and tax settings"
echo ""
echo "🎯 **Perfect for testing:**"
echo "   • Order export to WMS"
echo "   • Stock synchronization"
echo "   • Various order scenarios"
echo "   • Webhook processing"
echo ""
echo "🌐 **Next steps:**"
echo "   1. Visit: http://localhost:8000/wp-admin/admin.php?page=wc-admin"
echo "   2. Browse products and place test orders"
echo "   3. Test the WMS integration plugin"
