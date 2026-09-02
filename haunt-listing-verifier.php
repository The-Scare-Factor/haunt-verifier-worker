<?php
/**
 * Plugin Name:       Haunt Listing Verifier
 * Plugin URI:        https://thescarefactor.com/
 * Description:        Automated crawl + AI verification of haunt listings (website + social), with a human-reviewed dashboard. The heavy crawl/AI work runs in a separate Sevalla worker; this plugin owns the admin UI, triggers, and publishing.
 * Version:           0.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            The Scare Factor
 * Text Domain:       haunt-listing-verifier
 * Domain Path:       /languages
 *
 * @package HauntListingVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'HLV_VERSION', '0.2.0' );
define( 'HLV_PLUGIN_FILE', __FILE__ );
define( 'HLV_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'HLV_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'HLV_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once HLV_PLUGIN_DIR . 'includes/hlv-field-map.php';
require_once HLV_PLUGIN_DIR . 'includes/hlv-capabilities.php';
require_once HLV_PLUGIN_DIR . 'includes/hlv-worker-client.php';
require_once HLV_PLUGIN_DIR . 'includes/hlv-decision-log.php';
require_once HLV_PLUGIN_DIR . 'includes/hlv-listing-writer.php';
require_once HLV_PLUGIN_DIR . 'includes/hlv-prompt-compiler.php';
require_once HLV_PLUGIN_DIR . 'includes/hlv-crawl-triggers.php';
require_once HLV_PLUGIN_DIR . 'includes/hlv-plugin.php';

// Settings must load in every context (WP-Cron is non-admin but reads settings).
require_once HLV_PLUGIN_DIR . 'admin/hlv-settings.php';

if ( is_admin() ) {
	require_once HLV_PLUGIN_DIR . 'admin/hlv-dashboard.php';
	require_once HLV_PLUGIN_DIR . 'admin/hlv-ajax.php';
}

/**
 * Activation: register the custom capability and version the WP-side log table.
 * Never destroys data on deactivation.
 */
function hlv_activate() {
	HLV_Capabilities::add_caps();
	HLV_Decision_Log::install_table();
	add_option( 'hlv_db_version', HLV_VERSION );
	// Default settings are filled lazily by HLV_Settings::get(); nothing destructive here.
}
register_activation_hook( __FILE__, 'hlv_activate' );

/**
 * Deactivation: remove the cron event only. We intentionally keep the custom
 * capability, settings, and the decision-log table so no review history is lost.
 */
function hlv_deactivate() {
	wp_clear_scheduled_hook( HLV_Crawl_Triggers::CRON_HOOK );
}
register_deactivation_hook( __FILE__, 'hlv_deactivate' );

/**
 * Boot the plugin once all files are loaded.
 */
function hlv() {
	return HLV_Plugin::instance();
}
add_action( 'plugins_loaded', 'hlv' );
