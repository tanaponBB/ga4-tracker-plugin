<?php
/**
 * GA4 Dynamic Tracker - Security Helper Class
 *
 * @package GA4_Dynamic_Tracker
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * GA4DT Security Class
 */
class GA4DT_Security {

    /**
     * Sanitize product ID
     */
    public static function sanitize_product_id($id) {
        return absint($id);
    }

    /**
     * Sanitize SKU
     */
    public static function sanitize_sku($sku) {
        return sanitize_text_field(wp_unslash($sku));
    }

    /**
     * Sanitize product name
     */
    public static function sanitize_product_name($name) {
        return sanitize_text_field(wp_strip_all_tags($name));
    }

    /**
     * Sanitize price (always returns 2 decimal places)
     */
    public static function sanitize_price($price) {
        $price = preg_replace('/[^0-9.]/', '', $price);
        return number_format(floatval($price), 2, '.', '');
    }

    /**
     * Sanitize category name
     */
    public static function sanitize_category($category) {
        return sanitize_text_field(wp_strip_all_tags($category));
    }

    /**
     * Sanitize quantity
     */
    public static function sanitize_quantity($quantity) {
        return absint($quantity);
    }

    /**
     * Sanitize user status
     */
    public static function sanitize_user_status($status) {
        $allowed = ['Guest', 'Registered', 'Customer'];
        $status = sanitize_text_field($status);
        return in_array($status, $allowed, true) ? $status : 'Guest';
    }

    /**
     * Sanitize date
     */
    public static function sanitize_date($date) {
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return '';
        }
        return gmdate('Y-m-d', $timestamp);
    }

    /**
     * Sanitize payment method
     */
    public static function sanitize_payment_method($method) {
        return sanitize_key($method);
    }

    /**
     * Sanitize payment title
     */
    public static function sanitize_payment_title($title) {
        return sanitize_text_field(wp_strip_all_tags($title));
    }

    /**
     * Sanitize order ID
     */
    public static function sanitize_order_id($order_id) {
        return absint($order_id);
    }

    /**
     * Sanitize currency code
     */
    public static function sanitize_currency($currency) {
        $currency = strtoupper(sanitize_text_field($currency));
        return preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'USD';
    }

    /**
     * Sanitize list ID
     */
    public static function sanitize_list_id($list_id) {
        return sanitize_key($list_id);
    }

    /**
     * Sanitize list name
     */
    public static function sanitize_list_name($list_name) {
        return sanitize_text_field(wp_strip_all_tags($list_name));
    }

    /**
     * Generate secure hash for user ID
     */
    public static function hash_user_id($user_id, $prefix = 'ga4dt') {
        if (empty($user_id)) {
            return '';
        }
        return hash('sha256', $prefix . '_' . $user_id . '_' . wp_salt('auth'));
    }

    /**
     * Generate secure hash for guest
     */
    public static function hash_guest_id() {
        $ip = self::get_client_ip();
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) 
            ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) 
            : '';
        
        $guest_identifier = $ip . $user_agent;
        return hash('sha256', 'guest_' . $guest_identifier . '_' . wp_salt('auth'));
    }

    /**
     * Get client IP address securely
     */
    public static function get_client_ip() {
        $ip = '';

        // Check for CloudFlare
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP']));
        }
        // Check for proxy
        elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip_list = explode(',', sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR'])));
            $ip = trim($ip_list[0]);
        }
        // Check for real IP
        elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_REAL_IP']));
        }
        // Default remote address
        elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
        }

        // Validate IP
        $ip = filter_var($ip, FILTER_VALIDATE_IP);
        
        return $ip ? $ip : '0.0.0.0';
    }

    /**
     * Escape for JavaScript output
     */
    public static function esc_js_deep($data) {
        if (is_array($data)) {
            return array_map([self::class, 'esc_js_deep'], $data);
        }
        
        if (is_string($data)) {
            return esc_js($data);
        }
        
        if (is_bool($data)) {
            return $data;
        }
        
        if (is_numeric($data)) {
            return $data;
        }
        
        return $data;
    }

    /**
     * Safely encode data for JSON output in script tags
     */
    public static function json_encode_safe($data) {
        $sanitized = self::sanitize_array_recursive($data);
        return wp_json_encode($sanitized, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    }

    /**
     * Recursively sanitize array data
     */
    public static function sanitize_array_recursive($data) {
        if (!is_array($data)) {
            if (is_string($data)) {
                return sanitize_text_field($data);
            }
            return $data;
        }

        $sanitized = [];
        foreach ($data as $key => $value) {
            $clean_key = sanitize_key($key);
            $sanitized[$clean_key] = self::sanitize_array_recursive($value);
        }

        return $sanitized;
    }

    /**
     * Validate WooCommerce order
     */
    public static function validate_order($order_id) {
        $order_id = self::sanitize_order_id($order_id);
        
        if (!$order_id) {
            return false;
        }

        $order = wc_get_order($order_id);
        
        if (!$order || !is_a($order, 'WC_Order')) {
            return false;
        }

        return $order;
    }

    /**
     * Validate WooCommerce product
     */
    public static function validate_product($product_id) {
        $product_id = self::sanitize_product_id($product_id);
        
        if (!$product_id) {
            return false;
        }

        $product = wc_get_product($product_id);
        
        if (!$product || !is_a($product, 'WC_Product')) {
            return false;
        }

        return $product;
    }

    /**
     * Check if current request is valid frontend
     */
    public static function is_valid_frontend_request() {
        // Not admin area (except AJAX)
        if (is_admin() && !wp_doing_ajax()) {
            return false;
        }

        // Not REST API
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return false;
        }

        // Not cron
        if (wp_doing_cron()) {
            return false;
        }

        // Not CLI
        if (defined('WP_CLI') && WP_CLI) {
            return false;
        }

        return true;
    }

    /**
     * Verify nonce for AJAX requests
     */
    public static function verify_ajax_nonce($nonce_name = 'ga4dt_nonce', $action = 'ga4dt_action') {
        if (!isset($_POST[$nonce_name])) {
            return false;
        }
        
        return wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST[$nonce_name])),
            $action
        );
    }

    /**
     * Create nonce field
     */
    public static function create_nonce($action = 'ga4dt_action') {
        return wp_create_nonce($action);
    }

    /**
     * Rate limiting check
     */
    public static function check_rate_limit($action, $limit = 60, $window = 60) {
        $ip = self::get_client_ip();
        $transient_key = 'ga4dt_rate_' . md5($action . $ip);
        
        $count = get_transient($transient_key);
        
        if ($count === false) {
            set_transient($transient_key, 1, $window);
            return true;
        }
        
        if ($count >= $limit) {
            return false;
        }
        
        set_transient($transient_key, $count + 1, $window);
        return true;
    }

    /**
     * Sanitize cookie value
     */
    public static function sanitize_cookie($value) {
        return sanitize_text_field(wp_unslash($value));
    }

    /**
     * Set secure cookie
     */
    public static function set_secure_cookie($name, $value, $expiry = null) {
        if ($expiry === null) {
            $expiry = time() + (365 * DAY_IN_SECONDS);
        }

        $secure = is_ssl();
        $httponly = true;
        $samesite = 'Lax';

        if (PHP_VERSION_ID >= 70300) {
            setcookie($name, $value, [
                'expires' => $expiry,
                'path' => COOKIEPATH,
                'domain' => COOKIE_DOMAIN,
                'secure' => $secure,
                'httponly' => $httponly,
                'samesite' => $samesite,
            ]);
        } else {
            setcookie(
                $name,
                $value,
                $expiry,
                COOKIEPATH . '; SameSite=' . $samesite,
                COOKIE_DOMAIN,
                $secure,
                $httponly
            );
        }
    }

    /**
     * Get secure cookie
     */
    public static function get_secure_cookie($name) {
        if (!isset($_COOKIE[$name])) {
            return null;
        }
        return self::sanitize_cookie($_COOKIE[$name]);
    }
}
