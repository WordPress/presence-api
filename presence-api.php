<?php
/**
 * Plugin Name: Presence API
 * Description: Core presence/awareness table and internal API for WordPress.
 * Version: 0.4.0
 * Requires at least: 7.0
 * Requires PHP: 7.4
 * Author: Joe Fusco
 * Text Domain: presence-api
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Feature plugin: enforce minimum WordPress version at runtime.
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

define( 'WP_PRESENCE_VERSION', '0.4.0' );
define( 'WP_PRESENCE_DB_VERSION', '1.4' );
define( 'WP_PRESENCE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
if ( ! defined( 'WP_PRESENCE_DEFAULT_TTL' ) ) {
	define( 'WP_PRESENCE_DEFAULT_TTL', 60 );
}

// Load translations.
add_action(
	'init',
	function () {
		load_plugin_textdomain( 'presence-api', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);

/**
 * Registers the presence table name on $wpdb.
 *
 * @since 7.1.0
 */
function wp_presence_register_table() {
	global $wpdb;
	$wpdb->presence = $wpdb->prefix . 'presence';
	$wpdb->tables[] = 'presence';
}
add_action( 'init', 'wp_presence_register_table', 0 );
// Also call immediately so the table name is available during plugin load.
wp_presence_register_table();

// Core files.
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/functions.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/post-lock-bridge.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/lifecycle.php';

/*
 * Feature plugin shim: ensure the presence table exists.
 *
 * In core, this table would be created by dbDelta() during the database
 * upgrade routine in wp-admin/includes/upgrade-schema.php. This hook is
 * only needed while Presence API ships as a standalone plugin.
 */
add_action( 'admin_init', 'wp_maybe_create_presence_table' );
add_action( 'cli_init', 'wp_maybe_create_presence_table' );

// Cron cleanup.
add_action( 'wp_delete_expired_presence_data', 'wp_delete_expired_presence_data' );

// Schedule cron if not already scheduled (fallback for missed activation).
add_action( 'admin_init', 'wp_presence_schedule_cleanup' );

// Register presence support for core post types.
add_action( 'init', 'wp_presence_register_post_type_support' );

// Enqueue heartbeat presence ping on all admin pages.
add_action( 'admin_enqueue_scripts', 'wp_presence_enqueue_heartbeat_ping' );

// Block editor: send presence via heartbeat when editing a post.
add_action( 'admin_enqueue_scripts', 'wp_presence_enqueue_editor_ping' );
add_filter( 'heartbeat_received', 'wp_presence_editor_heartbeat_received', 10, 3 );

// Post-lock bridge.
add_filter( 'heartbeat_received', 'wp_presence_bridge_post_lock', 11, 3 );

// Login/logout presence.
add_action( 'wp_login', 'wp_presence_on_login', 10, 2 );
add_action( 'wp_logout', 'wp_presence_on_logout' );

/**
 * Registers presence support for core post types.
 *
 * Plugins can opt in their own post types with:
 *     add_post_type_support( 'product', 'presence' );
 *
 * @since 7.1.0
 */
function wp_presence_register_post_type_support() {
	add_post_type_support( 'post', 'presence' );
	add_post_type_support( 'page', 'presence' );
}

/**
 * Enqueues heartbeat and the presence ping script on admin pages.
 *
 * @since 7.1.0
 */
function wp_presence_enqueue_heartbeat_ping() {
	if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	wp_enqueue_script( 'heartbeat' );

	wp_add_inline_script(
		'heartbeat',
		'(function($) {
			if (typeof wp === "undefined" || typeof wp.heartbeat === "undefined") { return; }
			$(document).on("heartbeat-send", function(event, data) {
				data["presence-ping"] = { screen: window.pagenow || "unknown" };
			});
		})(jQuery);'
	);
}

/**
 * Enqueues a heartbeat ping for the block editor that reports which post is being edited.
 *
 * @since 7.1.0
 *
 * @param string $hook_suffix The current admin page.
 */
function wp_presence_enqueue_editor_ping( $hook_suffix ) {
	if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
		return;
	}

	$post = get_post();

	if ( ! $post || ! post_type_supports( $post->post_type, 'presence' ) ) {
		return;
	}

	wp_add_inline_script(
		'heartbeat',
		sprintf(
			'(function($) {
			if (typeof wp === "undefined" || typeof wp.heartbeat === "undefined") { return; }
			$(document).on("heartbeat-send", function(event, data) {
				data["presence-editor-ping"] = { post_id: %d };
			});
		})(jQuery);',
			$post->ID
		)
	);
}

/**
 * Handles the editor presence heartbeat and creates a presence entry for the post being edited.
 *
 * @since 7.1.0
 *
 * @param array  $response  The Heartbeat response.
 * @param array  $data      The $_POST data sent.
 * @param string $screen_id The screen ID.
 * @return array The Heartbeat response.
 */
function wp_presence_editor_heartbeat_received( $response, $data, $screen_id ) {
	if ( empty( $data['presence-editor-ping']['post_id'] ) ) {
		return $response;
	}

	$post_id = absint( $data['presence-editor-ping']['post_id'] );
	$user_id = get_current_user_id();

	if ( ! $user_id || ! current_user_can( 'edit_post', $post_id ) ) {
		return $response;
	}

	$room = wp_presence_post_room( $post_id );

	if ( ! $room ) {
		return $response;
	}

	wp_set_presence(
		$room,
		'editor-' . $user_id,
		array(
			'action' => 'editing',
			'screen' => $screen_id,
		),
		$user_id
	);

	return $response;
}

/**
 * Schedules the presence cleanup cron event.
 *
 * @since 7.1.0
 */
function wp_presence_schedule_cleanup() {
	if ( ! wp_next_scheduled( 'wp_delete_expired_presence_data' ) ) {
		wp_schedule_event( time(), 'wp_presence_every_minute', 'wp_delete_expired_presence_data' );
	}
}

/**
 * Adds a custom one-minute cron interval.
 *
 * @since 7.1.0
 *
 * @param array $schedules Existing cron schedules.
 * @return array Modified cron schedules.
 */
function wp_presence_cron_schedules( $schedules ) {
	$schedules['wp_presence_every_minute'] = array(
		'interval' => 60,
		'display'  => __( 'Every Minute (Presence Cleanup)', 'presence-api' ),
	);
	return $schedules;
}
// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- 60-second interval is intentional for presence cleanup.
add_filter( 'cron_schedules', 'wp_presence_cron_schedules' );

// Activation hook for cron scheduling.
register_activation_hook( __FILE__, 'wp_presence_activate' );

/**
 * Handles plugin activation.
 *
 * @since 7.1.0
 */
function wp_presence_activate() {
	wp_maybe_create_presence_table();
	wp_presence_schedule_cleanup();
}

// Cleanup on deactivation.
register_deactivation_hook( __FILE__, 'wp_presence_deactivate' );

/**
 * Cleans up on plugin deactivation.
 *
 * @since 7.1.0
 */
function wp_presence_deactivate() {
	wp_clear_scheduled_hook( 'wp_delete_expired_presence_data' );
}
