# GA4 Dynamic Tracker (Secure Version)

A WordPress/WooCommerce plugin for ultra-dynamic GA4 (Google Analytics 4) tracking with enhanced security, payment method, and sale price support.

## Version: 1.1.0

## Security Features

### PHP Security
- **Direct Access Prevention**: Multiple checks to prevent direct file access
- **Data Sanitization**: All input data is sanitized using WordPress functions
  - `sanitize_text_field()` for text inputs
  - `absint()` for integers
  - `esc_js()` for JavaScript output
  - `esc_attr()` for HTML attributes
- **Data Escaping**: All output is properly escaped
- **Type Validation**: Strict type checking for WooCommerce objects
- **Secure JSON Encoding**: Uses `wp_json_encode()` with security flags
- **Privacy-Compliant Hashing**: SHA-256 hashing with WordPress salts for user IDs
- **Secure Cookies**: HttpOnly, Secure, SameSite attributes
- **IP Address Handling**: Supports CloudFlare, proxies with validation
- **Rate Limiting**: Built-in rate limiting for abuse prevention
- **Nonce Support**: CSRF protection for AJAX requests

### JavaScript Security
- **DOM-based Sanitization**: Text content sanitization to prevent XSS
- **Number Validation**: Strict number parsing with fallbacks
- **String Length Limits**: Maximum length enforcement
- **Safe Event Handling**: Proper event delegation

### WordPress Integration
- **Capability Checks**: Admin notices only for users with proper permissions
- **Singleton Pattern**: Prevents multiple instantiation
- **Proper Hook Usage**: Uses WordPress action/filter system correctly
- **Version Checks**: Validates PHP and WordPress versions on activation

## GA4 Events Tracked

| Event | Description |
|-------|-------------|
| `pageview` | Page view with user data |
| `view_item_list` | Product list views (shop, category pages) |
| `view_item` | Single product page views |
| `select_item` | Product click in listing |
| `add_to_cart` | Add to cart actions |
| `remove_from_cart` | Remove from cart actions |
| `view_cart` | Cart page views |
| `begin_checkout` | Checkout initiation |
| `add_shipping_info` | Shipping method selection |
| `add_payment_info` | Payment method selection |
| `purchase` | Completed purchase |

## Data Layer Properties

### Product Data
```javascript
{
    item_id: "SKU123",
    item_name: "Product Name",
    item_brand: "Site Name",
    price: 29.99,
    item_original_price: 39.99,
    discount: 10.00,
    discount_percentage: 25.03,
    item_on_sale: true,
    item_category: "Category Name",
    quantity: 1
}
```

### User Data (Privacy Compliant)
```javascript
{
    user_status: "Customer",
    user_type: "Logged In",
    user_id: "sha256_hashed_id",
    first_visit_date: "2024-01-01",
    last_visit_date: "2024-12-01",
    first_purchase_date: "2024-03-15",
    last_purchase_date: "2024-11-20"
}
```

## Installation

1. Upload the `ga4-dynamic-tracker` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Ensure WooCommerce is installed and active
4. Configure GTM to capture the dataLayer events

## Requirements

- WordPress 5.8+
- WooCommerce 5.0+
- PHP 7.4+

## File Structure

```
ga4-dynamic-tracker/
├── ga4-dynamic-tracker.php      # Main plugin file
├── includes/
│   ├── class-ga4dt-tracker.php  # Core tracking functionality
│   └── class-ga4dt-security.php # Security helper class
└── README.md                    # Documentation
```

## Security Helper Methods

```php
// Sanitization
GA4DT_Security::sanitize_product_id($id);
GA4DT_Security::sanitize_sku($sku);
GA4DT_Security::sanitize_product_name($name);
GA4DT_Security::sanitize_price($price);
GA4DT_Security::sanitize_category($category);
GA4DT_Security::sanitize_quantity($quantity);
GA4DT_Security::sanitize_user_status($status);
GA4DT_Security::sanitize_date($date);
GA4DT_Security::sanitize_payment_method($method);
GA4DT_Security::sanitize_order_id($order_id);
GA4DT_Security::sanitize_currency($currency);

// Hashing
GA4DT_Security::hash_user_id($user_id);
GA4DT_Security::hash_guest_id();

// Validation
GA4DT_Security::validate_order($order_id);
GA4DT_Security::validate_product($product_id);
GA4DT_Security::is_valid_frontend_request();

// Secure Output
GA4DT_Security::json_encode_safe($data);
GA4DT_Security::esc_js_deep($data);

// Cookies
GA4DT_Security::set_secure_cookie($name, $value);
GA4DT_Security::get_secure_cookie($name);

// CSRF Protection
GA4DT_Security::create_nonce();
GA4DT_Security::verify_ajax_nonce();

// Rate Limiting
GA4DT_Security::check_rate_limit($action, $limit, $window);
```

## Debug Mode

When `WP_DEBUG` is set to `true`, the plugin will:
- Log all events to browser console
- Show detailed product tracking information
- Display sale items summary
- Include nonce in config for debugging

## Compatibility

- WooCommerce default themes
- Elementor Loop Grid
- Elementor Posts
- Kitify Products
- YITH AJAX Filters
- Any theme using standard WooCommerce markup

## Changelog

### 1.1.0
- Added comprehensive security class
- Implemented data sanitization for all inputs
- Added secure cookie handling with SameSite attribute
- Implemented rate limiting
- Added nonce support for AJAX
- Added IP validation and proxy support
- JavaScript sanitization functions
- String length limits
- Type validation for WooCommerce objects
- Singleton pattern enforcement

### 1.0.0
- Initial release

## License

GPL v2 or later
# ga4-tracker-plugin
