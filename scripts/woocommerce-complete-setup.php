<?php
/**
 * WooCommerce Complete Setup Script
 * 
 * This script configures WooCommerce with:
 * - All basic settings and store information
 * - User meta to bypass setup wizard
 * - Shipping zones and methods with free shipping as default
 * - Payment methods with COD as default
 * - Shipping classes and product assignments
 * - Complete onboarding bypass
 */

// Load WordPress
require_once('/var/www/html/wp-load.php');

echo "🚀 Starting comprehensive WooCommerce configuration...\n";

// Check if WooCommerce is active and classes are available
if (!class_exists('WooCommerce') || !function_exists('WC')) {
    echo "❌ WooCommerce is not active or loaded properly\n";
    exit(1);
}

// Initialize WooCommerce if not already done
if (!did_action('woocommerce_init')) {
    WC()->init();
}

// Ensure WooCommerce is fully loaded
if (!class_exists('WC_Shipping_Zones')) {
    echo "⚠️  WooCommerce shipping classes not loaded, attempting to load...\n";
    
    // Try to load WooCommerce shipping classes
    if (file_exists(WP_PLUGIN_DIR . '/woocommerce/includes/class-wc-shipping-zones.php')) {
        include_once(WP_PLUGIN_DIR . '/woocommerce/includes/class-wc-shipping-zones.php');
    }
    
    if (!class_exists('WC_Shipping_Zones')) {
        echo "❌ Cannot load WooCommerce shipping classes\n";
        exit(1);
    }
}

// Additional WooCommerce initialization
if (function_exists('wc_load_cart')) {
    wc_load_cart();
}

echo "✅ WooCommerce successfully loaded and initialized\n";

// ============================================================================
// HELPER FUNCTIONS FOR MODERN WOOCOMMERCE API
// ============================================================================

/**
 * Configure shipping method instance with direct option updates
 * Compatible with all WooCommerce versions
 */
function configure_shipping_method_instance($instance_id, $settings_array) {
    if (!$instance_id) {
        echo "⚠️  Invalid instance ID\n";
        return false;
    }
    
    // Directly update shipping method instance settings using option key pattern
    $option_key = "woocommerce_shipping_instance_" . $instance_id;
    
    // Get current settings
    $current_settings = get_option($option_key, array());
    if (!is_array($current_settings)) {
        $current_settings = array();
    }
    
    // Merge with new settings
    $new_settings = array_merge($current_settings, $settings_array);
    
    // Update the option
    $result = update_option($option_key, $new_settings);
    
    if ($result) {
        echo "✓ Configured shipping instance {$instance_id}\n";
    } else {
        echo "⚠️  Failed to update shipping instance {$instance_id}\n";
    }
    
    return $result;
}

// ============================================================================
// 1. BASIC WOOCOMMERCE CONFIGURATION
// ============================================================================

echo "⚙️  Configuring basic WooCommerce settings...\n";

// Store configuration
$store_options = [
    'woocommerce_store_address' => '123 Test Street',
    'woocommerce_store_address_2' => 'Suite 100',
    'woocommerce_store_city' => 'Test City',
    'woocommerce_default_country' => 'US:CA',
    'woocommerce_store_postcode' => '12345',
    'woocommerce_currency' => 'USD',
    'woocommerce_product_type' => 'both',
    'woocommerce_allow_tracking' => 'no',
    
    // General settings
    'woocommerce_calc_taxes' => 'no',
    'woocommerce_enable_reviews' => 'yes',
    'woocommerce_review_rating_required' => 'no',
    'woocommerce_enable_review_rating' => 'yes',
    'woocommerce_manage_stock' => 'yes',
    'woocommerce_hold_stock_minutes' => '60',
    'woocommerce_notify_low_stock' => 'yes',
    'woocommerce_notify_no_stock' => 'yes',
    'woocommerce_stock_email_recipient' => 'admin@example.com',
    'woocommerce_notify_low_stock_amount' => '2',
    'woocommerce_notify_no_stock_amount' => '0',
    
    // Checkout settings
    'woocommerce_checkout_highlight_required_fields' => 'yes',
    'woocommerce_checkout_process_checkout_nonce_logged_out' => 'no',
    'woocommerce_registration_generate_username' => 'yes',
    'woocommerce_registration_generate_password' => 'yes',
    'woocommerce_enable_guest_checkout' => 'yes',
    'woocommerce_enable_checkout_login_reminder' => 'yes',
    'woocommerce_enable_signup_and_login_from_checkout' => 'yes',
    
    // Weight and dimensions
    'woocommerce_weight_unit' => 'lbs',
    'woocommerce_dimension_unit' => 'in',
    'woocommerce_enable_shipping_calc' => 'yes',
    'woocommerce_shipping_cost_requires_address' => 'no',
    'woocommerce_ship_to_countries' => 'all',
    'woocommerce_ship_to_destinations' => 'countries',
    'woocommerce_shipping_debug_mode' => 'yes',
];

// Skip WooCommerce onboarding wizard completely
$onboarding_options = [
    'woocommerce_onboarding_skipped' => '1',
    'woocommerce_task_list_complete' => '1',
    'woocommerce_extended_task_list_complete' => '1',
    'woocommerce_admin_install_timestamp' => time(),
    'woocommerce_show_marketplace_suggestions' => 'no',
    'woocommerce_onboarding_profile_completed' => '1',
    'woocommerce_onboarding_opt_in' => 'no',
    'woocommerce_setup_jetpack_opted_in' => '0',
    'woocommerce_setup_storefront_opted_in' => '0',
    'woocommerce_marketing_overview_multichannel_banner_dismissed' => 'yes',
    'woocommerce_admin_dismissed_reviews_banner' => '1',
    'woocommerce_admin_onboarding_profile_skipped' => 'yes',
];

// Onboarding profile
$onboarding_profile = [
    'business_extensions' => [],
    'completed' => true,
    'industry' => [['slug' => 'other']],
    'number_employees' => '1-10',
    'other_platform' => 'none',
    'other_platform_name' => '',
    'product_count' => '1-10',
    'product_types' => ['physical'],
    'revenue' => 'none',
    'selling_venues' => 'no',
    'setup_client' => false,
    'store_email' => 'admin@example.com',
    'wccom_connected' => false
];

// Apply all basic options
foreach ($store_options as $option => $value) {
    update_option($option, $value);
}

foreach ($onboarding_options as $option => $value) {
    update_option($option, $value);
}

update_option('woocommerce_onboarding_profile', $onboarding_profile);

echo "✅ Basic WooCommerce settings configured\n";

// ============================================================================
// 2. USER META CONFIGURATION (BYPASS WIZARD)
// ============================================================================

echo "🎯 Setting admin user meta to bypass WooCommerce setup wizard...\n";

$admin_user = get_user_by('login', 'admin');
if ($admin_user) {
    $user_meta = [
        'woocommerce_admin_core_profiler_completed' => '1',
        'woocommerce_admin_core_profiler_skipped' => '1',
        'woocommerce_admin_onboarding_profile_skipped' => '1',
        'woocommerce_admin_task_list_tracked_completed_tasks' => json_encode([
            'store_details', 'purchase', 'products', 'woocommerce-payments', 
            'marketing', 'appearance', 'tax', 'shipping', 'payments'
        ]),
        'woocommerce_admin_dismissed_reviews_banner' => '1',
        'woocommerce_admin_setup_wizard_completed' => '1',
    ];
    
    foreach ($user_meta as $meta_key => $meta_value) {
        update_user_meta($admin_user->ID, $meta_key, $meta_value);
    }
    
    echo "✅ Admin user meta configured to bypass wizard\n";
} else {
    echo "⚠️  Admin user not found, skipping user meta configuration\n";
}

// ============================================================================
// 3. PAYMENT METHODS CONFIGURATION
// ============================================================================

echo "💳 Configuring payment methods with COD as default...\n";

// Enable payment methods
update_option('woocommerce_bacs_enabled', 'yes');
update_option('woocommerce_cheque_enabled', 'yes');
update_option('woocommerce_cod_enabled', 'yes');
update_option('woocommerce_default_gateway', 'cod');

// Configure COD settings
$cod_settings = [
    'enabled' => 'yes',
    'title' => 'Cash on Delivery',
    'description' => 'Pay with cash upon delivery.',
    'instructions' => 'Pay with cash when your order is delivered.',
    'enable_for_methods' => [],
    'enable_for_virtual' => 'no'
];
update_option('woocommerce_cod_settings', $cod_settings);

echo "✅ Payment methods configured (COD as default)\n";

// ============================================================================
// 4. SHIPPING CLASSES CONFIGURATION
// ============================================================================

echo "📦 Creating shipping classes...\n";

$shipping_classes = [
    'standard' => [
        'name' => 'Standard',
        'description' => 'Standard shipping for regular items'
    ],
    'heavy' => [
        'name' => 'Heavy Items',
        'description' => 'Heavy items requiring special handling'
    ],
    'fragile' => [
        'name' => 'Fragile',
        'description' => 'Fragile items requiring careful handling'
    ],
    'express-only' => [
        'name' => 'Express Only',
        'description' => 'Items available for express delivery only'
    ]
];

$class_ids = [];
foreach ($shipping_classes as $slug => $class_data) {
    $existing_term = get_term_by('slug', $slug, 'product_shipping_class');
    if (!$existing_term) {
        $term = wp_insert_term(
            $class_data['name'],
            'product_shipping_class',
            [
                'description' => $class_data['description'],
                'slug' => $slug
            ]
        );
        
        if (!is_wp_error($term)) {
            $class_ids[$slug] = $term['term_id'];
            echo "✓ Created shipping class: {$class_data['name']}\n";
        }
    } else {
        $class_ids[$slug] = $existing_term->term_id;
        echo "✓ Shipping class already exists: {$class_data['name']}\n";
    }
}

// ============================================================================
// 5. SHIPPING ZONES AND METHODS CONFIGURATION
// ============================================================================

echo "🌍 Creating shipping zones and methods...\n";

// Clear existing zones (except zone 0 which is Rest of World)
$zones = WC_Shipping_Zones::get_zones();
foreach($zones as $zone) {
    WC_Shipping_Zones::delete_zone($zone['id']);
}

// 1. United States Zone
$us_zone = new WC_Shipping_Zone();
$us_zone->set_zone_name('United States');
$us_zone->set_zone_order(1);
$us_zone->add_location('US', 'country');
$us_zone->save();

// Add US shipping methods with Free Shipping as default (first in order)
$method = $us_zone->add_shipping_method('free_shipping');
configure_shipping_method_instance($method, [
    'title' => 'Free Shipping',
    'requires' => 'min_amount',
    'min_amount' => '0'
]);

$method = $us_zone->add_shipping_method('flat_rate');
configure_shipping_method_instance($method, [
    'title' => 'Standard Shipping (3-5 business days)',
    'cost' => '8.99'
]);

$method = $us_zone->add_shipping_method('flat_rate');
configure_shipping_method_instance($method, [
    'title' => 'Express Shipping (1-2 business days)',
    'cost' => '19.99'
]);

$method = $us_zone->add_shipping_method('flat_rate');
configure_shipping_method_instance($method, [
    'title' => 'Overnight Shipping (Next business day)',
    'cost' => '39.99'
]);

$method = $us_zone->add_shipping_method('local_pickup');
configure_shipping_method_instance($method, [
    'title' => 'Local Pickup',
    'cost' => '0'
]);

echo "✓ US zone created with 5 shipping methods\n";

// 2. Canada Zone
$canada_zone = new WC_Shipping_Zone();
$canada_zone->set_zone_name('Canada');
$canada_zone->set_zone_order(2);
$canada_zone->add_location('CA', 'country');
$canada_zone->save();

$method = $canada_zone->add_shipping_method('free_shipping');
configure_shipping_method_instance($method, [
    'title' => 'Free Shipping to Canada',
    'requires' => 'min_amount',
    'min_amount' => '0'
]);

$method = $canada_zone->add_shipping_method('flat_rate');
configure_shipping_method_instance($method, [
    'title' => 'Standard Shipping to Canada (5-8 business days)',
    'cost' => '15.99'
]);

$method = $canada_zone->add_shipping_method('flat_rate');
configure_shipping_method_instance($method, [
    'title' => 'Express Shipping to Canada (3-5 business days)',
    'cost' => '29.99'
]);

echo "✓ Canada zone created with 3 shipping methods\n";

// 3. Europe Zone
$europe_zone = new WC_Shipping_Zone();
$europe_zone->set_zone_name('Europe');
$europe_zone->set_zone_order(3);
$countries = array('GB', 'DE', 'FR', 'IT', 'ES', 'NL', 'BE', 'AT', 'CH', 'SE', 'NO', 'DK', 'FI');
foreach($countries as $country) {
    $europe_zone->add_location($country, 'country');
}
$europe_zone->save();

$method = $europe_zone->add_shipping_method('free_shipping');
configure_shipping_method_instance($method, [
    'title' => 'Free Shipping to Europe',
    'requires' => 'min_amount',
    'min_amount' => '0'
]);

$method = $europe_zone->add_shipping_method('flat_rate');
configure_shipping_method_instance($method, [
    'title' => 'Standard Shipping to Europe (7-14 business days)',
    'cost' => '19.99'
]);

$method = $europe_zone->add_shipping_method('flat_rate');
configure_shipping_method_instance($method, [
    'title' => 'Express Shipping to Europe (3-7 business days)',
    'cost' => '39.99'
]);

echo "✓ Europe zone created with 3 shipping methods\n";

// 4. Rest of World (Zone 0)
$row_zone = WC_Shipping_Zones::get_zone(0);

$method = $row_zone->add_shipping_method('free_shipping');
configure_shipping_method_instance($method, [
    'title' => 'Free International Shipping',
    'requires' => 'min_amount',
    'min_amount' => '0'
]);

$method = $row_zone->add_shipping_method('flat_rate');
configure_shipping_method_instance($method, [
    'title' => 'International Shipping (10-21 business days)',
    'cost' => '29.99'
]);

$method = $row_zone->add_shipping_method('flat_rate');
configure_shipping_method_instance($method, [
    'title' => 'Express International (5-10 business days)',
    'cost' => '49.99'
]);

echo "✓ Rest of World zone configured with 3 shipping methods\n";

// ============================================================================
// 6. ASSIGN SHIPPING CLASSES TO EXISTING PRODUCTS
// ============================================================================

echo "📋 Assigning shipping classes to existing products...\n";

// Map products to shipping classes by SKU
$product_shipping_assignments = [
    'WMS-EL-001' => 'fragile',    // Bluetooth Headphones
    'WMS-EL-002' => 'express-only', // Smart Watch
    'WMS-HG-001' => 'heavy',      // Coffee Mug Set
];

foreach ($product_shipping_assignments as $sku => $class_slug) {
    if (isset($class_ids[$class_slug])) {
        $products = wc_get_products(['sku' => $sku, 'limit' => 1]);
        if (!empty($products)) {
            $product = $products[0];
            $product->set_shipping_class_id($class_ids[$class_slug]);
            $product->save();
            echo "✓ Assigned {$class_slug} shipping class to {$sku}\n";
        }
    }
}

// ============================================================================
// 7. FINAL CONFIGURATION AND CLEANUP
// ============================================================================

echo "🔄 Finalizing configuration...\n";

// Set some final options
update_option('woocommerce_cart_redirect_after_add', 'no');
update_option('woocommerce_enable_ajax_add_to_cart', 'yes');
update_option('woocommerce_placeholder_image', '');

// Clear any WooCommerce caches
if (function_exists('wc_delete_product_transients')) {
    wc_delete_product_transients();
}

if (function_exists('wc_delete_shop_order_transients')) {
    wc_delete_shop_order_transients();
}

echo "\n✅ WooCommerce configuration complete!\n";
echo "\n🚚 **Shipping Setup Summary:**\n";
echo "• 🇺🇸 United States (5 methods): Free Shipping (default), Standard, Express, Overnight, Local Pickup\n";
echo "• 🇨🇦 Canada (3 methods): Free Shipping (default), Standard, Express\n"; 
echo "• 🇪🇺 Europe (3 methods): Free Shipping (default), Standard, Express\n";
echo "• 🌏 Rest of World (3 methods): Free Shipping (default), International, Express International\n";
echo "• 📦 Shipping Classes: Standard, Heavy Items, Fragile, Express Only\n";
echo "• 💳 Payment Default: Cash on Delivery (COD)\n";
echo "• 🎯 Setup Wizard: Completely bypassed\n";
echo "\n🎉 Ready for WMS integration development!\n";

?>
