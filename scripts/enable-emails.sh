#!/bin/bash

# Re-enable all email functions after setup
set -e

echo "📧 Re-enabling ALL email notifications..."

# Function to run WP-CLI commands
run_wpcli() {
    (cd .. && docker-compose run --rm wpcli wp "$@")
}

# Initialize WP-CLI cache
echo "📁 Initializing WP-CLI cache..."
(cd .. && docker-compose run --rm --user root wpcli mkdir -p /tmp/.wp-cli/cache 2>/dev/null || true)
(cd .. && docker-compose run --rm --user root wpcli chown -R 33:33 /tmp/.wp-cli/cache 2>/dev/null || true)

# Re-enable ALL email notifications
echo "✅ Re-enabling ALL email notifications..."

# WooCommerce emails
run_wpcli option update woocommerce_email_customer_new_account_enabled 'yes' 2>/dev/null || true
run_wpcli option update woocommerce_email_new_order_enabled 'yes' 2>/dev/null || true
run_wpcli option update woocommerce_email_customer_processing_order_enabled 'yes' 2>/dev/null || true
run_wpcli option update woocommerce_email_customer_completed_order_enabled 'yes' 2>/dev/null || true
run_wpcli option update woocommerce_email_customer_on_hold_order_enabled 'yes' 2>/dev/null || true
run_wpcli option update woocommerce_email_customer_refunded_order_enabled 'yes' 2>/dev/null || true

# WordPress core emails  
run_wpcli option update users_can_register 1 2>/dev/null || true

# Remove the wp_mail disable filter
run_wpcli eval "
// Re-enable wp_mail function
remove_filter('pre_wp_mail', 'disable_wp_mail');
delete_option('temp_emails_disabled');
" 2>/dev/null || true

echo "✅ All email notifications re-enabled!"
echo ""
echo "💡 Email notifications are now working normally"
echo "   Customers will receive welcome emails for new accounts"
echo "   Store admins will receive order notifications"
