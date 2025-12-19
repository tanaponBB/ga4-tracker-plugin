<?php
/**
 * Plugin Name: GA4 Dynamic Tracker
 * Plugin URI: https://example.com/ga4-dynamic-tracker
 * Description: Ultra-dynamic GA4 tracking system with payment method & sale price tracking for WooCommerce
 * Version: 1.0.2
 * Author: TanaponBB
 * Author URI: https://theneighbors.co/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ga4-dynamic-tracker
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

// Prevent direct access
if (!defined("ABSPATH")) {
	exit();
}

// Prevent direct file access
if (!defined("WPINC")) {
	die();
}

// Define plugin constants
define("GA4DT_VERSION", "1.0.1");
define("GA4DT_PLUGIN_DIR", plugin_dir_path(__FILE__));
define("GA4DT_PLUGIN_URL", plugin_dir_url(__FILE__));
define("GA4DT_PLUGIN_BASENAME", plugin_basename(__FILE__));
define("GA4DT_PLUGIN_FILE", __FILE__);

/**
 * Auto-Update Configuration
 * URL to plugin-info.json on Google Cloud Storage
 */
define("GA4DT_UPDATE_URL", "https://storage.googleapis.com/tanapon-wp-plugins/ga4-dynamic-tracker/plugin-info.json");

/**
 * Main Plugin Class
 */
final class GA4_Dynamic_Tracker
{
	/**
	 * Single instance
	 */
	private static $instance = null;

	/**
	 * Get instance (Singleton pattern)
	 */
	public static function instance()
	{
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
	public function __wakeup()
	{
		throw new \Exception("Cannot unserialize singleton");
	}

	/**
	 * Constructor
	 */
	private function __construct()
	{
		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks()
	{
		// Check WooCommerce dependency
		add_action("plugins_loaded", [$this, "check_dependencies"]);

		// Initialize tracking
		add_action("init", [$this, "init_tracking"]);

		// Initialize auto-updater (runs on admin only)
		add_action("admin_init", [$this, "init_updater"]);

		// Register activation/deactivation hooks
		register_activation_hook(GA4DT_PLUGIN_FILE, [$this, "activate"]);
		register_deactivation_hook(GA4DT_PLUGIN_FILE, [$this, "deactivate"]);
	}

	/**
	 * Plugin activation
	 */
	public function activate()
	{
		// Check minimum PHP version
		if (version_compare(PHP_VERSION, "7.4", "<")) {
			deactivate_plugins(GA4DT_PLUGIN_BASENAME);
			wp_die(
				esc_html__(
					"GA4 Dynamic Tracker requires PHP 7.4 or higher.",
					"ga4-dynamic-tracker",
				),
				"Plugin Activation Error",
				["back_link" => true],
			);
		}

		// Check minimum WordPress version
		if (version_compare(get_bloginfo("version"), "5.8", "<")) {
			deactivate_plugins(GA4DT_PLUGIN_BASENAME);
			wp_die(
				esc_html__(
					"GA4 Dynamic Tracker requires WordPress 5.8 or higher.",
					"ga4-dynamic-tracker",
				),
				"Plugin Activation Error",
				["back_link" => true],
			);
		}

		// Set activation flag
		add_option("ga4dt_activated", true);

		// Clear update cache on activation
		delete_transient("ga4dt_update_data");
		delete_site_transient("update_plugins");

		// Flush rewrite rules
		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation
	 */
	public function deactivate()
	{
		delete_option("ga4dt_activated");
		delete_transient("ga4dt_update_data");
		flush_rewrite_rules();
	}

	/**
	 * Check dependencies
	 */
	public function check_dependencies()
	{
		if (!class_exists("WooCommerce")) {
			add_action("admin_notices", [$this, "woocommerce_missing_notice"]);
			return;
		}
	}

	/**
	 * WooCommerce missing notice
	 */
	public function woocommerce_missing_notice()
	{
		if (!current_user_can("activate_plugins")) {
			return;
		} ?>
        <div class="notice notice-error">
            <p><?php esc_html_e(
            	"GA4 Dynamic Tracker requires WooCommerce to be installed and active.",
            	"ga4-dynamic-tracker",
            ); ?></p>
        </div>
        <?php
	}

	/**
	 * Initialize tracking
	 */
	public function init_tracking()
	{
		if (!class_exists("WooCommerce")) {
			return;
		}

		// Load tracking class
		require_once GA4DT_PLUGIN_DIR . "includes/class-ga4dt-tracker.php";
		require_once GA4DT_PLUGIN_DIR . "includes/class-ga4dt-security.php";

		// Initialize tracker
		GA4DT_Tracker::instance();
	}

	/**
	 * Initialize auto-updater
	 */
	public function init_updater()
	{
		// Load updater class
		$updater_file = GA4DT_PLUGIN_DIR . "includes/class-ga4dt-updater.php";
		if (file_exists($updater_file)) {
			require_once $updater_file;
			GA4DT_Plugin_Updater::instance();
		}
	}
}

/**
 * Initialize plugin
 */
function ga4_dynamic_tracker()
{
	return GA4_Dynamic_Tracker::instance();
}

// Start the plugin
ga4_dynamic_tracker();
