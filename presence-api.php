<?php
/**
 * Plugin Name: Presence API
 * Description: System-wide presence and awareness for WordPress.
 * Version: 0.1.24
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

define( 'WP_PRESENCE_VERSION', '0.1.24' );
define( 'WP_PRESENCE_DB_VERSION', 2 );
define( 'WP_PRESENCE_NETWORK_SUMMARY_DB_VERSION', 1 );
define( 'WP_PRESENCE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_PRESENCE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Width of the room and client_id columns, and therefore the longest value the
// REST layer accepts. MySQL would otherwise truncate silently, which collapses
// two distinct clients onto one UNIQUE KEY (room, client_id) row.
define( 'WP_PRESENCE_MAX_KEY_LENGTH', 191 );

if ( ! defined( 'WP_PRESENCE_DEFAULT_TTL' ) ) {
	define( 'WP_PRESENCE_DEFAULT_TTL', 60 );
}

/**
 * Registers the presence table name on $wpdb.
 */
function wp_presence_register_table() {
	global $wpdb;
	$wpdb->presence = $wpdb->prefix . 'presence';

	// Runs at require time and again on init, so the append has to be idempotent.
	if ( ! in_array( 'presence', $wpdb->tables, true ) ) {
		$wpdb->tables[] = 'presence';
	}
}
wp_presence_register_table();

/**
 * Registers the network-wide presence summary table name on $wpdb.
 *
 * One table for the whole network rather than one per site: registered as an
 * ms_global_tables entry, using base_prefix, the same way core registers
 * blogs/site/sitemeta.
 */
function wp_presence_register_network_summary_table() {
	if ( ! is_multisite() ) {
		return;
	}
	global $wpdb;
	$wpdb->presence_network_summary = $wpdb->base_prefix . 'presence_network_summary';
	$wpdb->ms_global_tables[]       = 'presence_network_summary';
}
wp_presence_register_network_summary_table();

require_once WP_PRESENCE_PLUGIN_DIR . 'includes/functions.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/class-wp-rest-presence-controller.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/heartbeat.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/cron.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/post-lock-bridge.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/screen-revisions.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/lifecycle.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/admin-bar.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/user-list.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/post-list.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/widgets/class-wp-presence-widget-whos-online.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/widgets/class-wp-presence-widget-active-posts.php';

if ( is_multisite() ) {
	require_once WP_PRESENCE_PLUGIN_DIR . 'includes/network-functions.php';
}

if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
	// Developer tooling is excluded from the distributed build (see .distignore),
	// so guard the includes for installs that ship without these files.
	$presence_debug_dir = WP_PRESENCE_PLUGIN_DIR . 'includes/';
	if ( file_exists( $presence_debug_dir . 'debugger-widget.php' ) ) {
		require_once $presence_debug_dir . 'debugger-widget.php';
	}
	if ( file_exists( $presence_debug_dir . 'db-viewer.php' ) ) {
		require_once $presence_debug_dir . 'db-viewer.php';
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once WP_PRESENCE_PLUGIN_DIR . 'includes/cli/class-wp-presence-cli-command.php';
	WP_CLI::add_command( 'presence', 'WP_Presence_CLI_Command' );
}

/**
 * Registers presence support for core post types.
 *
 * Plugins can opt in their own post types with:
 *     add_post_type_support( 'product', 'presence' );
 */
function wp_presence_register_post_type_support() {
	add_post_type_support( 'post', 'presence' );
	add_post_type_support( 'page', 'presence' );
}

/**
 * Registers the presence REST routes.
 */
function wp_presence_register_rest_routes() {
	$controller = new WP_REST_Presence_Controller();
	$controller->register_routes();
}

/**
 * Creates the presence table and schedules cleanup for the current site.
 *
 * @access private
 */
function wp_presence_provision_site() {
	wp_maybe_create_presence_table();
	wp_presence_schedule_cleanup();
}

/**
 * Handles plugin activation.
 *
 * Follows how core provisions per-site tables: they are created up front, at
 * activation for sites that already exist and at site creation for sites added
 * later. Nothing creates schema from a front-end request.
 *
 * Large networks are skipped, matching core's own guard against iterating every
 * site in one request. Those sites are provisioned the first time an admin
 * screen loads, and presence reads and writes are a no-op until then.
 *
 * @param bool $network_wide Whether the plugin is being activated for the network.
 */
function wp_presence_activate( $network_wide = false ) {
	if ( $network_wide && is_multisite() ) {
		wp_maybe_create_presence_network_summary_table();

		if ( ! wp_is_large_network() ) {
			foreach ( wp_presence_get_network_site_ids() as $site_id ) {
				switch_to_blog( $site_id );
				wp_presence_provision_site();
				restore_current_blog();
			}

			return;
		}
	}

	wp_presence_provision_site();
}

/**
 * Provisions a site created after the plugin was network activated.
 *
 * Core hooks its own wp_initialize_site() onto this action at priority 10 to
 * create the site's tables, so this runs after it. The action fires from
 * wp_insert_site() outside of any blog switch, hence the switch here.
 *
 * @param WP_Site $site The site that was just created.
 */
function wp_presence_on_initialize_site( $site ) {
	$network_plugins = get_site_option( 'active_sitewide_plugins', array() );

	// A site-by-site activation says nothing about this new site, so leave it alone.
	if ( ! isset( $network_plugins[ plugin_basename( __FILE__ ) ] ) ) {
		return;
	}

	switch_to_blog( $site->id );
	wp_presence_provision_site();
	restore_current_blog();
}

/**
 * Returns every site ID on the current network.
 *
 * @access private
 * @return int[] Site IDs.
 */
function wp_presence_get_network_site_ids() {
	return get_sites(
		array(
			'fields'                 => 'ids',
			'number'                 => 0,
			'update_site_meta_cache' => false,
		)
	);
}

/**
 * Returns the default dashboard widget order when the user has no stored preference.
 *
 * @param array|false $result Stored meta value, or false if not set.
 * @return array|false Original value, or a default order with presence widgets first.
 */
function wp_presence_default_widget_order( $result ) {
	if ( $result ) {
		return $result;
	}
	return array(
		'normal' => 'presence_whos_online,presence_active_posts,dashboard_right_now,dashboard_activity',
		'side'   => 'dashboard_quick_press,dashboard_primary',
	);
}

/**
 * Cleans up on plugin deactivation.
 *
 * Cron events are stored per site, so a network deactivation has to clear each
 * one or every site keeps rescheduling an event with no callback behind it.
 *
 * @param bool $network_wide Whether the plugin is being deactivated for the network.
 */
function wp_presence_deactivate( $network_wide = false ) {
	if ( $network_wide && is_multisite() && ! wp_is_large_network() ) {
		foreach ( wp_presence_get_network_site_ids() as $site_id ) {
			switch_to_blog( $site_id );
			wp_clear_scheduled_hook( 'wp_delete_expired_presence_data' );
			restore_current_blog();
		}

		return;
	}

	wp_clear_scheduled_hook( 'wp_delete_expired_presence_data' );
}

/**
 * Adds action links to the plugin list table.
 *
 * The plugin has no settings screen, so the link points at the Users list
 * filtered to the users who are currently online.
 *
 * @param string[] $links Existing plugin action links.
 * @return string[] Action links with the online users link prepended.
 */
function wp_presence_plugin_action_links( $links ) {
	$online_users_link = sprintf(
		'<a href="%1$s">%2$s</a>',
		esc_url( admin_url( 'users.php?presence_status=online' ) ),
		esc_html__( 'View Online Users', 'presence-api' )
	);

	array_unshift( $links, $online_users_link );

	return $links;
}

add_action( 'init', 'wp_presence_register_table', 0 );
add_action( 'init', 'wp_presence_register_network_summary_table', 0 );
add_action( 'init', 'wp_presence_register_post_type_support' );

// Schema work stays in the admin and CLI, the way core keeps its own upgrade
// routine out of the front end. Sites are provisioned at activation and at site
// creation instead; this is the fallback for a site that missed both.
add_action( 'admin_init', 'wp_maybe_create_presence_table' );
add_action( 'cli_init', 'wp_maybe_create_presence_table' );
if ( is_multisite() ) {
	add_action( 'admin_init', 'wp_maybe_create_presence_network_summary_table' );
	add_action( 'cli_init', 'wp_maybe_create_presence_network_summary_table' );
	add_action( 'wp_presence_admin_room_changed', 'wp_presence_push_network_summary' );
	add_action( 'wp_delete_site', 'wp_presence_on_delete_site' );
}
// Priority 99 to run after core's wp_initialize_site() at 10.
add_action( 'wp_initialize_site', 'wp_presence_on_initialize_site', 99 );
add_action( 'rest_api_init', 'wp_presence_register_rest_routes' );

add_action( 'wp_delete_expired_presence_data', 'wp_delete_expired_presence_data' );
add_action( 'admin_init', 'wp_presence_schedule_cleanup' );
// phpcs:ignore WordPress.WP.CronInterval -- 60-second interval is intentional for presence cleanup.
add_filter( 'cron_schedules', 'wp_presence_cron_schedules' );

add_action( 'admin_enqueue_scripts', 'wp_presence_enqueue_heartbeat_ping' );
add_action( 'wp_enqueue_scripts', 'wp_presence_enqueue_heartbeat_ping' );
// Priority 9 so the admin/online write lands before any widget reads the room at 10.
add_filter( 'heartbeat_received', 'wp_presence_admin_heartbeat_received', 9, 3 );
add_filter( 'heartbeat_received', 'wp_presence_editor_heartbeat_received', 10, 3 );
add_filter( 'heartbeat_received', 'wp_presence_bridge_post_lock', 11, 3 );
add_filter( 'heartbeat_received', 'wp_presence_screen_heartbeat_received', 12, 3 );

add_action( 'admin_enqueue_scripts', 'wp_presence_enqueue_stale_screen_banner' );
add_action( 'updated_option', 'wp_presence_on_updated_option' );
add_action( 'post_updated', 'wp_presence_on_post_updated', 10, 3 );
add_action( 'profile_update', 'wp_presence_on_profile_update' );
add_action( 'edited_term', 'wp_presence_on_edited_term', 10, 3 );
add_action( 'edit_comment', 'wp_presence_on_edit_comment' );

add_action( 'wp_login', 'wp_presence_on_login', 10, 2 );
add_action( 'wp_logout', 'wp_presence_on_logout', 10, 1 );
add_action( 'deleted_user', 'wp_presence_on_user_removed', 10, 1 );
add_action( 'remove_user_from_blog', 'wp_presence_on_user_removed', 10, 1 );

add_action( 'admin_bar_menu', 'wp_presence_admin_bar_node', 80 );
add_action( 'admin_enqueue_scripts', 'wp_presence_admin_bar_assets' );
add_action( 'wp_enqueue_scripts', 'wp_presence_admin_bar_assets' );

add_filter( 'views_users', 'wp_presence_users_views' );
add_action( 'pre_get_users', 'wp_presence_filter_online_users' );

add_action( 'admin_init', 'wp_presence_register_post_list_columns' );

add_filter( 'get_user_option_meta-box-order_dashboard', 'wp_presence_default_widget_order' );
add_action( 'wp_dashboard_setup', array( 'WP_Presence_Widget_Whos_Online', 'register' ) );
add_filter( 'heartbeat_received', array( 'WP_Presence_Widget_Whos_Online', 'heartbeat_received' ), 10, 3 );
add_action( 'wp_dashboard_setup', array( 'WP_Presence_Widget_Active_Posts', 'register' ) );
add_filter( 'heartbeat_received', array( 'WP_Presence_Widget_Active_Posts', 'heartbeat_received' ), 10, 3 );

if ( ( defined( 'WP_DEBUG' ) && WP_DEBUG )
	&& function_exists( 'wp_presence_heartbeat_widget_register' )
	&& function_exists( 'wp_presence_heartbeat_widget_received' ) ) {
	add_action( 'wp_dashboard_setup', 'wp_presence_heartbeat_widget_register' );
	add_filter( 'heartbeat_received', 'wp_presence_heartbeat_widget_received', 10, 3 );
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wp_presence_plugin_action_links' );
register_activation_hook( __FILE__, 'wp_presence_activate' );
register_deactivation_hook( __FILE__, 'wp_presence_deactivate' );
