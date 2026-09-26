<?php
/**
 * Plugin Name: Presence API
 * Description: System-wide presence and awareness for WordPress.
 * Version: 0.8.0
 * Requires at least: 7.0
 * Requires PHP: 7.4
 * Author: WordPress Core Team
 * Author URI: https://make.wordpress.org/core/
 * Text Domain: presence-api
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wp_version;
if ( version_compare( $wp_version, '7.0-alpha', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Presence API requires WordPress 7.0 or later.', 'presence-api' );
			echo '</p></div>';
		}
	);
	return;
}

// If core or another plugin already provides the presence table, this plugin is not needed.
global $wpdb;
if ( isset( $wpdb->presence ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-warning"><p>';
			echo esc_html__( 'Presence API: The presence table is already registered by WordPress or another plugin.', 'presence-api' );
			echo '</p></div>';
		}
	);
	return;
}

define( 'WP_PRESENCE_VERSION', '0.8.0' );
define( 'WP_PRESENCE_DB_VERSION', 3 );
define( 'WP_PRESENCE_NETWORK_SUMMARY_DB_VERSION', 1 );
define( 'WP_PRESENCE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_PRESENCE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Width of the room and client_id columns, and therefore the longest value the
// REST layer accepts. MySQL would otherwise truncate silently, which collapses
// two distinct clients onto one UNIQUE KEY (room, client_id) row.
define( 'WP_PRESENCE_MAX_KEY_LENGTH', 191 );

// A client_id starting with this is the plugin's own bookkeeping rather than a
// participant, so room reads drop it and the REST layer refuses to write one.
define( 'WP_PRESENCE_RESERVED_PREFIX', '_' );

// Core pins an unfocused, or five-minute-idle, tab to a 120-second Heartbeat
// interval that no client-side call can shorten, so a shorter TTL drops a tab
// that is still open and still pinging. Core sizes post locks at 150 the same way.
if ( ! defined( 'WP_PRESENCE_DEFAULT_TTL' ) ) {
	define( 'WP_PRESENCE_DEFAULT_TTL', 150 );
}

// Bound for core: storage first, then the features built on it.
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/schema.php';
wp_presence_register_table();
wp_presence_register_network_summary_table();

require_once WP_PRESENCE_PLUGIN_DIR . 'includes/presence.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/cron.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/lifecycle.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/privacy.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/post-lock-bridge.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/heartbeat.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/site-health.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/rest-api.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/rest-api/endpoints/class-wp-rest-presence-controller.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/screen-revisions.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/avatar-stack.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/admin-bar.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/user-list.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/post-list.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/widgets/class-wp-presence-widget-whos-online.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/widgets/class-wp-presence-widget-active-posts.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/default-filters.php';

if ( is_multisite() ) {
	require_once WP_PRESENCE_PLUGIN_DIR . 'includes/network-functions.php';
	require_once WP_PRESENCE_PLUGIN_DIR . 'includes/rest-api/endpoints/class-wp-rest-presence-network-controller.php';
	require_once WP_PRESENCE_PLUGIN_DIR . 'includes/network-sites-list.php';
	require_once WP_PRESENCE_PLUGIN_DIR . 'includes/network-user-list.php';
	require_once WP_PRESENCE_PLUGIN_DIR . 'includes/widgets/class-wp-presence-network-widget-whos-online.php';
	require_once WP_PRESENCE_PLUGIN_DIR . 'includes/ms-default-filters.php';
}

// Plugin only.
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/plugin.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/settings.php';

if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
	// Developer tooling is excluded from the distributed build (see .distignore),
	// so guard the includes for installs that ship without these files.
	if ( file_exists( WP_PRESENCE_PLUGIN_DIR . 'includes/debugger-widget.php' ) ) {
		require_once WP_PRESENCE_PLUGIN_DIR . 'includes/debugger-widget.php';
		add_action( 'wp_dashboard_setup', 'wp_presence_heartbeat_widget_register' );
		add_filter( 'heartbeat_received', 'wp_presence_heartbeat_widget_received', 10, 3 );
	}
	if ( file_exists( WP_PRESENCE_PLUGIN_DIR . 'includes/db-viewer.php' ) ) {
		require_once WP_PRESENCE_PLUGIN_DIR . 'includes/db-viewer.php';
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once WP_PRESENCE_PLUGIN_DIR . 'includes/cli/class-wp-presence-cli-command.php';
	WP_CLI::add_command( 'presence', 'WP_Presence_CLI_Command' );
}

// Stands in for core's table registration and upgrade routine, catching any site missed at activation or creation.
add_action( 'init', 'wp_presence_register_table', 0 );
add_action( 'init', 'wp_presence_register_network_summary_table', 0 );
add_action( 'admin_init', 'wp_maybe_create_presence_table' );
add_action( 'cli_init', 'wp_maybe_create_presence_table' );

add_action( 'admin_init', 'wp_presence_register_settings' );

add_filter( 'get_user_option_meta-box-order_dashboard', 'wp_presence_default_widget_order' );
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wp_presence_plugin_action_links' );

if ( is_multisite() ) {
	// Global so the keys are not prefixed with a blog ID. The push runs inside
	// switch_to_blog(), and a per-site group would file the invalidation under
	// the switched-to site while the reader looks under the current one.
	wp_cache_add_global_groups( wp_presence_network_cache_group() );

	// Non-persistent on purpose. Every site's push invalidates the group, so on
	// a network of any size last_changed moves faster than a shared cache could
	// be read, and a persistent store would take the writes and the evicted
	// keys for a hit rate near zero. Deduplicating within the request is the
	// part worth having.
	wp_cache_add_non_persistent_groups( wp_presence_network_cache_group() );

	add_action( 'admin_init', 'wp_maybe_create_presence_network_summary_table' );
	add_action( 'cli_init', 'wp_maybe_create_presence_network_summary_table' );
	add_action( 'wpmu_options', 'wp_presence_render_network_settings' );
	add_action( 'update_wpmu_options', 'wp_presence_save_network_settings' );
	add_filter( 'network_admin_plugin_action_links_' . plugin_basename( __FILE__ ), 'wp_presence_network_plugin_action_links' );
}

// Priority 99 to run after core's wp_initialize_site() at 10.
add_action( 'wp_initialize_site', 'wp_presence_on_initialize_site', 99 );

register_activation_hook( __FILE__, 'wp_presence_activate' );
register_deactivation_hook( __FILE__, 'wp_presence_deactivate' );
