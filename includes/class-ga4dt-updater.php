<?php
/**
 * GA4 Dynamic Tracker - Plugin Auto-Updater
 *
 * Handles automatic plugin updates from Google Cloud Storage.
 *
 * @package GA4_Dynamic_Tracker
 * @since 1.0.1
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
    private $plugin_slug = 'ga4-dynamic-tracker';

    /**
     * Plugin basename
     */
    private $plugin_basename = 'ga4-dynamic-tracker/ga4-dynamic-tracker.php';

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
     * Constructor
     */
    private function __construct() {
        $this->current_version = GA4DT_VERSION;
        $this->update_url = defined('GA4DT_UPDATE_URL') ? GA4DT_UPDATE_URL : '';

        if (!empty($this->update_url)) {
            $this->init_hooks();
        }
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
        add_action('upgrader_process_complete', [$this, 'clear_cache'], 10, 2);
        add_filter('plugin_action_links_' . $this->plugin_basename, [$this, 'add_action_links']);
        add_action('admin_init', [$this, 'handle_manual_check']);
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

                $transient->response[$this->plugin_basename] = $obj;
            } else {
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
        if ($action !== 'plugin_information' || !isset($args->slug) || $args->slug !== $this->plugin_slug) {
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
        $info->homepage = $remote->homepage ?? '';
        $info->requires = $remote->requires ?? '5.8';
        $info->tested = $remote->tested ?? '';
        $info->requires_php = $remote->requires_php ?? '7.4';
        $info->downloaded = $remote->downloaded ?? 0;
        $info->last_updated = $remote->last_updated ?? '';
        $info->download_link = $remote->download_url ?? '';

        if (isset($remote->sections)) {
            $info->sections = (array) $remote->sections;
        }

        if (isset($remote->banners)) {
            $info->banners = (array) $remote->banners;
        }

        if (isset($remote->icons)) {
            $info->icons = (array) $remote->icons;
        }

        return $info;
    }

    /**
     * Get remote info
     */
    private function get_remote_info($force = false) {
        if (empty($this->update_url)) {
            return false;
        }

        if (!$force) {
            $cached = get_transient($this->cache_key);
            if ($cached !== false) {
                return $cached;
            }
        }

        $response = wp_remote_get($this->update_url, [
            'timeout' => 15,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }

        $data = json_decode(wp_remote_retrieve_body($response));
        if (json_last_error() !== JSON_ERROR_NONE || !$data) {
            return false;
        }

        set_transient($this->cache_key, $data, $this->cache_expiration);
        return $data;
    }

    /**
     * Clear cache after update
     */
    public function clear_cache($upgrader, $options) {
        if ($options['action'] === 'update' && $options['type'] === 'plugin') {
            delete_transient($this->cache_key);
        }
    }

    /**
     * Add action links
     */
    public function add_action_links($links) {
        $link = sprintf(
            '<a href="%s">%s</a>',
            wp_nonce_url(admin_url('plugins.php?ga4dt_check_update=1'), 'ga4dt_check_update'),
            __('Check for updates', 'ga4-dynamic-tracker')
        );
        array_unshift($links, $link);
        return $links;
    }

    /**
     * Handle manual check
     */
    public function handle_manual_check() {
        if (!isset($_GET['ga4dt_check_update'])) {
            return;
        }

        if (!wp_verify_nonce($_GET['_wpnonce'], 'ga4dt_check_update') || !current_user_can('update_plugins')) {
            return;
        }

        delete_transient($this->cache_key);
        delete_site_transient('update_plugins');
        wp_update_plugins();

        wp_redirect(admin_url('plugins.php?ga4dt_checked=1'));
        exit;
    }
}
