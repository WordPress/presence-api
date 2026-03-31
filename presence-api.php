<?php
/**
 * Plugin Name: Presence API
 * Description: System-wide presence and awareness for WordPress.
 * Version:     0.1.0
 * Requires at least: 7.0
 * Requires PHP: 7.4
 * Author:      Joe Fusco
 * Text Domain: presence-api
 * License:     GPL-2.0-or-later
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

define( 'WP_PRESENCE_VERSION', '0.1.0' );
define( 'WP_PRESENCE_DB_VERSION', '1.4' );
define( 'WP_PRESENCE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
if ( ! defined( 'WP_PRESENCE_DEFAULT_TTL' ) ) {
	define( 'WP_PRESENCE_DEFAULT_TTL', 60 );
}

/**
 * Registers the presence table name on $wpdb.
 */
function wp_presence_register_table() {
	global $wpdb;
	$wpdb->presence = $wpdb->prefix . 'presence';
	$wpdb->tables[] = 'presence';
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
 * Handles plugin activation.
 */
function wp_presence_activate() {
	wp_maybe_create_presence_table();
	wp_presence_schedule_cleanup();
}

/**
 * Cleans up on plugin deactivation.
 */
function wp_presence_deactivate() {
	wp_clear_scheduled_hook( 'wp_delete_expired_presence_data' );
}

// Register table immediately and on init.
wp_presence_register_table();
add_action( 'init', 'wp_presence_register_table', 0 );
add_action( 'init', 'wp_presence_register_post_type_support' );
add_action(
	'init',
	function () {
		load_plugin_textdomain( 'presence-api', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);

// Includes.
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/functions.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/class-wp-rest-presence-controller.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/heartbeat.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/cron.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/post-lock-bridge.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/lifecycle.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/admin-bar.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/user-list.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/post-list.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/widgets/class-wp-presence-widget-whos-online.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/widgets/class-wp-presence-widget-active-posts.php';

if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
	require_once WP_PRESENCE_PLUGIN_DIR . 'includes/debugger-widget.php';
	require_once WP_PRESENCE_PLUGIN_DIR . 'includes/db-viewer.php';
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once WP_PRESENCE_PLUGIN_DIR . 'includes/cli/class-wp-presence-cli-command.php';
	WP_CLI::add_command( 'presence', 'WP_Presence_CLI_Command' );
}

// Schema.
add_action( 'admin_init', 'wp_maybe_create_presence_table' );
add_action( 'cli_init', 'wp_maybe_create_presence_table' );

// REST.
add_action( 'rest_api_init', 'wp_presence_register_rest_routes' );

// Cron.
add_action( 'wp_delete_expired_presence_data', 'wp_delete_expired_presence_data' );
add_action( 'admin_init', 'wp_presence_schedule_cleanup' );
// phpcs:ignore WordPress.WP.CronInterval -- 60-second interval is intentional for presence cleanup.
add_filter( 'cron_schedules', 'wp_presence_cron_schedules' );

// Heartbeat.
add_action( 'admin_enqueue_scripts', 'wp_presence_enqueue_heartbeat_ping' );
add_action( 'wp_enqueue_scripts', 'wp_presence_enqueue_heartbeat_ping' );
add_action( 'admin_enqueue_scripts', 'wp_presence_enqueue_editor_ping' );
add_filter( 'heartbeat_received', 'wp_presence_editor_heartbeat_received', 10, 3 );
add_filter( 'heartbeat_received', 'wp_presence_bridge_post_lock', 11, 3 );

// Lifecycle.
add_action( 'wp_login', 'wp_presence_on_login', 10, 2 );
add_action( 'wp_logout', 'wp_presence_on_logout' );

// Admin bar.
add_action( 'admin_bar_menu', 'wp_presence_admin_bar_node', 80 );
add_action( 'admin_enqueue_scripts', 'wp_presence_admin_bar_assets' );
add_action( 'wp_enqueue_scripts', 'wp_presence_admin_bar_assets' );

// User list.
add_filter( 'views_users', 'wp_presence_users_views' );
add_action( 'pre_get_users', 'wp_presence_filter_online_users' );

// Post list.
add_action( 'admin_init', 'wp_presence_register_post_list_columns' );

// Dashboard widgets.
add_action( 'wp_dashboard_setup', array( 'WP_Presence_Widget_Whos_Online', 'register' ) );
add_filter( 'heartbeat_received', array( 'WP_Presence_Widget_Whos_Online', 'heartbeat_received' ), 10, 3 );
add_action( 'wp_dashboard_setup', array( 'WP_Presence_Widget_Active_Posts', 'register' ) );
add_filter( 'heartbeat_received', array( 'WP_Presence_Widget_Active_Posts', 'heartbeat_received' ), 10, 3 );

if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
	add_action( 'wp_dashboard_setup', 'wp_presence_heartbeat_widget_register' );
	add_filter( 'heartbeat_received', 'wp_presence_heartbeat_widget_received', 10, 3 );
}

// Activation.
register_activation_hook( __FILE__, 'wp_presence_activate' );
register_deactivation_hook( __FILE__, 'wp_presence_deactivate' );
