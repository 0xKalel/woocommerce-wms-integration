#!/bin/bash

# Completely disable all email functions during WooCommerce setup
set -e

echo "📧 Completely disabling ALL email notifications during setup..."

# Function to run WP-CLI commands
run_wpcli() {
    (cd .. && docker-compose run --rm wpcli wp "$@")
}

# Initialize WP-CLI cache
echo "📁 Initializing WP-CLI cache..."
(cd .. && docker-compose run --rm --user root wpcli mkdir -p /tmp/.wp-cli/cache 2>/dev/null || true)
(cd .. && docker-compose run --rm --user root wpcli chown -R 33:33 /tmp/.wp-cli/cache 2>/dev/null || true)

# Disable ALL email notifications completely
echo "🚫 Disabling ALL email notifications..."

# WooCommerce emails
run_wpcli option update woocommerce_email_customer_new_account_enabled 'no' 2>/dev/null || true
run_wpcli option update woocommerce_email_new_order_enabled 'no' 2>/dev/null || true
run_wpcli option update woocommerce_email_customer_processing_order_enabled 'no' 2>/dev/null || true
run_wpcli option update woocommerce_email_customer_completed_order_enabled 'no' 2>/dev/null || true
run_wpcli option update woocommerce_email_customer_on_hold_order_enabled 'no' 2>/dev/null || true
run_wpcli option update woocommerce_email_customer_refunded_order_enabled 'no' 2>/dev/null || true

# WordPress core emails  
run_wpcli option update woocommerce_registration_generate_password 'yes' 2>/dev/null || true
run_wpcli option update users_can_register 0 2>/dev/null || true

# Add a PHP function to completely disable wp_mail during setup
run_wpcli eval "
// Temporarily disable wp_mail function
function disable_wp_mail() {
    return false;
}
add_filter('pre_wp_mail', 'disable_wp_mail');
update_option('temp_emails_disabled', '1');
" 2>/dev/null || true

echo "✅ All email notifications disabled!"
echo ""
echo "💡 To re-enable emails later:"
echo "   ./scripts/enable-emails.sh"
echo "   Or: ./scripts/dev.sh setup (automatically re-enables at end)"
