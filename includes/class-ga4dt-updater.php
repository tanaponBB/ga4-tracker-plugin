<?php
/**
 * GA4 Dynamic Tracker - Plugin Auto-Updater
 *
 * Handles automatic plugin updates from Google Cloud Storage.
 *
 * @package GA4_Dynamic_Tracker
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class GA4DT_Plugin_Updater {

    /**
     * Single instance
     */
    private static $instance = null;

    /**
     * Plugin slug
     */
    private $plugin_slug;

    /**
     * Plugin basename
     */
    private $plugin_basename;

    /**
     * Current version
     */
    private $current_version;

    /**
     * Update URL
     */
    private $update_url;

    /**
     * Cache key
     */
    private $cache_key = 'ga4dt_update_data';

    /**
     * Cache expiration (12 hours)
     */
    private $cache_expiration = 43200;

    /**
     * Get instance
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Prevent cloning
     */
    private function __clone() {}

    /**
     * Prevent unserialization
     */
    public function __wakeup() {
        throw new \Exception('Cannot unserialize singleton');
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->plugin_slug = 'ga4-dynamic-tracker';
        $this->plugin_basename = 'ga4-dynamic-tracker/ga4-dynamic-tracker.php';
        $this->current_version = defined('GA4DT_VERSION') ? GA4DT_VERSION : '1.0.0';
        $this->update_url = defined('GA4DT_UPDATE_URL') ? GA4DT_UPDATE_URL : '';

        // Allow filter for cache expiration
        $this->cache_expiration = apply_filters('ga4dt_update_cache_expiration', $this->cache_expiration);

        if (!empty($this->update_url) && $this->is_valid_update_url()) {
            $this->init_hooks();
        }
    }

    /**
     * Validate update URL
     */
    private function is_valid_update_url() {
        return filter_var($this->update_url, FILTER_VALIDATE_URL) 
            && strpos($this->update_url, 'storage.googleapis.com') !== false;
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Check for updates
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
        
        // Plugin info popup
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
        
        // Clear cache after update
        add_action('upgrader_process_complete', [$this, 'clear_cache'], 10, 2);
        
        // Add action links (Check for updates)
        add_filter('plugin_action_links_' . $this->plugin_basename, [$this, 'add_action_links']);
        
        // Add row meta (version info)
        add_filter('plugin_row_meta', [$this, 'add_row_meta'], 10, 2);
        
        // Handle manual update check
        add_action('admin_init', [$this, 'handle_manual_check']);
        
        // Admin notice after check
        add_action('admin_notices', [$this, 'show_check_notice']);

        // Log for debugging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            add_action('admin_init', [$this, 'debug_log']);
        }
    }

    /**
     * Check for updates
     */
    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $remote = $this->get_remote_info();

        if ($remote && isset($remote->version)) {
            if (version_compare($this->current_version, $remote->version, '<')) {
                // Update available
                $obj = new stdClass();
                $obj->slug = $this->plugin_slug;
                $obj->plugin = $this->plugin_basename;
                $obj->new_version = $remote->version;
                $obj->url = $remote->homepage ?? '';
                $obj->package = $remote->download_url ?? '';
                $obj->icons = isset($remote->icons) ? (array) $remote->icons : [];
                $obj->banners = isset($remote->banners) ? (array) $remote->banners : [];
                $obj->tested = $remote->tested ?? '';
                $obj->requires_php = $remote->requires_php ?? '7.4';
                $obj->compatibility = new stdClass();

                $transient->response[$this->plugin_basename] = $obj;
            } else {
                // No update - add to no_update list (required for WP 5.8+)
                $obj = new stdClass();
                $obj->slug = $this->plugin_slug;
                $obj->plugin = $this->plugin_basename;
                $obj->new_version = $this->current_version;
                $obj->url = '';
                $obj->package = '';

                $transient->no_update[$this->plugin_basename] = $obj;
            }
        }

        return $transient;
    }

    /**
     * Plugin info popup
     */
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (!isset($args->slug) || $args->slug !== $this->plugin_slug) {
            return $result;
        }

        $remote = $this->get_remote_info();
        if (!$remote) {
            return $result;
        }

        $info = new stdClass();
        $info->name = $remote->name ?? 'GA4 Dynamic Tracker';
        $info->slug = $this->plugin_slug;
        $info->version = $remote->version ?? $this->current_version;
        $info->author = $remote->author ?? '';
        $info->author_profile = $remote->author_profile ?? '';
        $info->homepage = $remote->homepage ?? '';
        $info->requires = $remote->requires ?? '5.8';
        $info->tested = $remote->tested ?? '';
        $info->requires_php = $remote->requires_php ?? '7.4';
        $info->downloaded = $remote->downloaded ?? 0;
        $info->last_updated = $remote->last_updated ?? '';
        $info->download_link = $remote->download_url ?? '';

        // Sections
        if (isset($remote->sections)) {
            $info->sections = [];
            foreach ($remote->sections as $key => $value) {
                $info->sections[$key] = $value;
            }
        }

        // Banners
        if (isset($remote->banners)) {
            $info->banners = (array) $remote->banners;
        }

        // Icons
        if (isset($remote->icons)) {
            $info->icons = (array) $remote->icons;
        }

        return $info;
    }

    /**
     * Get remote info from GCS
     */
    private function get_remote_info($force_refresh = false) {
        if (empty($this->update_url)) {
            return false;
        }

        // Check cache first
        if (!$force_refresh) {
            $cached = get_transient($this->cache_key);
            if ($cached !== false) {
                return $cached;
            }
        }

        // Fetch from remote with cache-busting
        $url = add_query_arg('t', time(), $this->update_url);
        
        $response = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => [
                'Accept' => 'application/json',
                'Cache-Control' => 'no-cache',
            ],
        ]);

        if (is_wp_error($response)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('GA4DT Updater Error: ' . $response->get_error_message());
            }
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('GA4DT Updater Error: HTTP ' . $response_code);
            }
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);

        if (json_last_error() !== JSON_ERROR_NONE || !$data) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('GA4DT Updater Error: Invalid JSON');
            }
            return false;
        }

        // Cache the result
        set_transient($this->cache_key, $data, $this->cache_expiration);

        return $data;
    }

    /**
     * Clear cache after update
     */
    public function clear_cache($upgrader, $options) {
        if ($options['action'] === 'update' && $options['type'] === 'plugin') {
            delete_transient($this->cache_key);
            delete_site_transient('update_plugins');
        }
    }

    /**
     * Add action links to plugins page
     */
    public function add_action_links($links) {
        $check_link = sprintf(
            '<a href="%s">%s</a>',
            wp_nonce_url(
                admin_url('plugins.php?ga4dt_check_update=1'),
                'ga4dt_check_update'
            ),
            __('Check for updates', 'ga4-dynamic-tracker')
        );
        
        // Add at the beginning
        array_unshift($links, $check_link);
        
        return $links;
    }

    /**
     * Add row meta (shows remote version)
     */
    public function add_row_meta($links, $file) {
        if ($file !== $this->plugin_basename) {
            return $links;
        }

        $remote = $this->get_remote_info();
        if ($remote && isset($remote->version)) {
            $version_text = sprintf(
                __('Remote version: %s', 'ga4-dynamic-tracker'),
                '<strong>' . esc_html($remote->version) . '</strong>'
            );
            
            if (version_compare($this->current_version, $remote->version, '<')) {
                $version_text .= ' <span style="color: #d63638;">(' . __('Update available!', 'ga4-dynamic-tracker') . ')</span>';
            } else {
                $version_text .= ' <span style="color: #00a32a;">(' . __('Up to date', 'ga4-dynamic-tracker') . ')</span>';
            }
            
            $links[] = $version_text;
        }

        return $links;
    }

    /**
     * Handle manual update check
     */
    public function handle_manual_check() {
        if (!isset($_GET['ga4dt_check_update'])) {
            return;
        }

        if (!wp_verify_nonce($_GET['_wpnonce'], 'ga4dt_check_update')) {
            wp_die(__('Security check failed', 'ga4-dynamic-tracker'));
        }

        if (!current_user_can('update_plugins')) {
            wp_die(__('You do not have permission to update plugins.', 'ga4-dynamic-tracker'));
        }

        // Clear all caches
        delete_transient($this->cache_key);
        delete_site_transient('update_plugins');

        // Force refresh remote info
        $remote = $this->get_remote_info(true);

        // Trigger WordPress update check
        wp_update_plugins();

        // Store result for notice
        $result = [
            'checked' => true,
            'current' => $this->current_version,
            'remote' => $remote ? $remote->version : 'unknown',
            'update_available' => $remote && version_compare($this->current_version, $remote->version, '<'),
        ];
        set_transient('ga4dt_check_result', $result, 60);

        // Redirect back
        wp_redirect(admin_url('plugins.php?ga4dt_checked=1'));
        exit;
    }

    /**
     * Show notice after manual check
     */
    public function show_check_notice() {
        if (!isset($_GET['ga4dt_checked'])) {
            return;
        }

        $result = get_transient('ga4dt_check_result');
        delete_transient('ga4dt_check_result');

        if ($result && isset($result['update_available'])) {
            if ($result['update_available']) {
                $class = 'notice-warning';
                $message = sprintf(
                    __('GA4 Dynamic Tracker: Update available! Current: %s → Remote: %s', 'ga4-dynamic-tracker'),
                    '<strong>' . esc_html($result['current']) . '</strong>',
                    '<strong>' . esc_html($result['remote']) . '</strong>'
                );
            } else {
                $class = 'notice-success';
                $message = sprintf(
                    __('GA4 Dynamic Tracker: You have the latest version (%s)', 'ga4-dynamic-tracker'),
                    '<strong>' . esc_html($result['current']) . '</strong>'
                );
            }
        } else {
            $class = 'notice-info';
            $message = __('GA4 Dynamic Tracker: Update check completed.', 'ga4-dynamic-tracker');
        }
        ?>
        <div class="notice <?php echo esc_attr($class); ?> is-dismissible">
            <p><?php echo wp_kses_post($message); ?></p>
        </div>
        <?php
    }

    /**
     * Debug log
     */
    public function debug_log() {
        if (!isset($_GET['ga4dt_debug'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        $remote = $this->get_remote_info(true);
        
        echo '<pre>';
        echo "Current Version: " . $this->current_version . "\n";
        echo "Update URL: " . $this->update_url . "\n";
        echo "Remote Info:\n";
        print_r($remote);
        echo '</pre>';
        exit;
    }

    /**
     * Get current version
     */
    public function get_current_version() {
        return $this->current_version;
    }

    /**
     * Get update URL
     */
    public function get_update_url() {
        return $this->update_url;
    }
}
