# 🧠 VSCode Autocomplete for WordPress & WooCommerce

This guide enables full autocompletion for WordPress and WooCommerce functions (like `add_action()`, `get_option()`, `wc_get_order()`, etc.) using only the **free version** of Intelephense — no paid license, stubs, or submodules needed.

---

## ✅ 1. Clone WordPress & WooCommerce Source Locally

Run this from the root of your project:

```bash
mkdir -p .vscode/includes
git clone --depth=1 https://github.com/WordPress/WordPress.git .vscode/includes/wordpress
git clone --depth=1 https://github.com/woocommerce/woocommerce.git .vscode/includes/woocommerce
```

## ⚙️ 2. Configure VSCode for Intelephense

Create or edit `.vscode/settings.json` with the following content:

```json
{
    "intelephense.files.associations": ["*.php"],
    "intelephense.files.exclude": [
        "**/.vscode/includes/wordpress/wp-content/**",
        "**/.vscode/includes/wordpress/node_modules/**", 
        "**/.vscode/includes/woocommerce/node_modules/**",
        "**/.vscode/includes/**/tests/**"
    ]
}
```

## 🎯 3. What You Get

After setup, you'll have **full autocomplete** for:

### WordPress Functions
- `add_action()`, `add_filter()`, `remove_action()`
- `get_option()`, `update_option()`, `delete_option()`
- `wp_schedule_event()`, `wp_next_scheduled()`
- `get_current_user_id()`, `current_user_can()`
- `wp_send_json_success()`, `wp_send_json_error()`

### WooCommerce Functions  
- `wc_get_order()`, `wc_get_product()`, `wc_get_customer()`
- `wc_price()`, `wc_format_decimal()`
- `WC()`, `WC()->cart`, `WC()->session`
- All WooCommerce classes and methods

### WordPress Classes
- `WP_Query`, `WP_User`, `WP_Post`
- `WP_REST_Request`, `WP_REST_Response`
- `WP_Error` and error handling

### WooCommerce Classes
- `WC_Order`, `WC_Product`, `WC_Customer`
- `WC_Cart`, `WC_Session`, `WC_Payment_Gateway`

## 🔧 4. Additional Optimizations

### Exclude Large Directories
Add to `.vscode/settings.json` to improve performance:

```json
{
    "files.exclude": {
        "**/.vscode/includes/wordpress/wp-content/themes/twenty*": true,
        "**/.vscode/includes/woocommerce/packages": true,
        "**/.vscode/includes/**/vendor": true
    }
}
```

### PHP Validation
Enable PHP syntax checking:

```json
{
    "php.validate.enable": true,
    "php.validate.run": "onType"
}
```

## 🚫 5. Add to .gitignore

Add these lines to your `.gitignore`:

```gitignore
# VSCode autocomplete includes
.vscode/includes/
```

## 🔄 6. Update Sources (Optional)

To get the latest WordPress/WooCommerce updates:

```bash
cd .vscode/includes/wordpress && git pull
cd ../woocommerce && git pull
```

## ✨ 7. Verification

Open any PHP file in your WMS integration and test:

1. Type `add_action(` - should show parameter hints
2. Type `wc_get_order(` - should show WooCommerce autocomplete
3. Type `WC_Order::` - should show class methods
4. Hover over any WordPress function - should show documentation

## 🎉 Benefits

- **Free**: No paid Intelephense Pro required
- **Complete**: Full WordPress + WooCommerce autocomplete
- **Fast**: Local source files for instant suggestions  
- **Accurate**: Always up-to-date with official repositories
- **Simple**: No complex stub management or configuration

## 💡 Pro Tips

### Use with Our WMS Integration
This autocomplete is especially helpful when working with:
- WordPress hooks: `add_action('woocommerce_new_order', ...)`
- WooCommerce orders: `$order = wc_get_order($order_id)`
- WordPress options: `get_option('wc_wms_api_key')`
- Cron jobs: `wp_schedule_event(time(), 'hourly', 'wc_wms_sync')`

### Quick Function Lookup
- **Ctrl+Click** on any WordPress/WooCommerce function to see its source
- **F12** to go to definition
- **Shift+F12** to find all references

---

**🚀 Result**: Professional WordPress/WooCommerce development experience with zero cost and maximum productivity!
