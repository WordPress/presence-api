<?php
/**
 * Hooks for the parts of the plugin bound for core.
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'wp_presence_register_post_type_support' );
add_action( 'rest_api_init', 'wp_presence_register_rest_routes' );

add_action( 'wp_delete_expired_presence_data', 'wp_delete_expired_presence_data' );
add_action( 'admin_init', 'wp_presence_schedule_cleanup' );
add_action( 'admin_init', 'wp_presence_add_privacy_policy_content' );
add_filter( 'wp_privacy_personal_data_exporters', 'wp_presence_register_personal_data_exporter' );
add_filter( 'wp_privacy_personal_data_erasers', 'wp_presence_register_personal_data_eraser' );
// phpcs:ignore WordPress.WP.CronInterval -- 60-second interval is intentional for presence cleanup.
add_filter( 'cron_schedules', 'wp_presence_cron_schedules' );

add_action( 'wp_login', 'wp_presence_on_login', 10, 2 );
add_action( 'wp_logout', 'wp_presence_on_logout', 10, 1 );
add_action( 'deleted_user', 'wp_presence_on_user_removed', 10, 1 );
add_action( 'remove_user_from_blog', 'wp_presence_on_user_removed', 10, 1 );

add_action( 'admin_enqueue_scripts', 'wp_presence_enqueue_heartbeat_ping' );
add_action( 'wp_enqueue_scripts', 'wp_presence_enqueue_heartbeat_ping' );
// Priority 9 so the admin/online write lands before any widget reads the room at 10.
add_filter( 'heartbeat_received', 'wp_presence_admin_heartbeat_received', 9, 3 );
add_filter( 'heartbeat_received', 'wp_presence_editor_heartbeat_received', 10, 3 );
add_filter( 'heartbeat_received', 'wp_presence_bridge_post_lock', 11, 3 );
add_filter( 'heartbeat_received', 'wp_presence_screen_heartbeat_received', 12, 3 );
add_filter( 'heartbeat_received', 'wp_presence_prime_heartbeat_locks', 5, 2 );
add_filter( 'get_post_metadata', 'wp_presence_get_post_lock', 10, 4 );
add_filter( 'the_posts', 'wp_presence_prime_post_list_locks', 10, 2 );
add_filter( 'update_post_metadata', 'wp_presence_update_post_lock', 10, 5 );
add_filter( 'delete_post_metadata', 'wp_presence_delete_post_lock', 10, 5 );
add_filter( 'site_status_tests', 'wp_presence_site_status_tests' );

add_action( 'admin_enqueue_scripts', 'wp_presence_enqueue_stale_screen_banner' );
add_action( 'updated_option', 'wp_presence_on_updated_option' );
add_action( 'post_updated', 'wp_presence_on_post_updated', 10, 3 );
add_action( 'profile_update', 'wp_presence_on_profile_update' );
add_action( 'edited_term', 'wp_presence_on_edited_term', 10, 3 );
add_action( 'edit_comment', 'wp_presence_on_edit_comment' );

add_action( 'admin_bar_menu', 'wp_presence_admin_bar_node', 80 );
add_action( 'admin_enqueue_scripts', 'wp_presence_admin_bar_assets' );
add_action( 'wp_enqueue_scripts', 'wp_presence_admin_bar_assets' );

add_filter( 'views_users', 'wp_presence_users_views' );
add_action( 'pre_get_users', 'wp_presence_filter_online_users' );

add_action( 'admin_init', 'wp_presence_register_post_list_columns' );

add_action( 'wp_dashboard_setup', array( 'WP_Presence_Widget_Whos_Online', 'register' ) );
add_filter( 'heartbeat_received', array( 'WP_Presence_Widget_Whos_Online', 'heartbeat_received' ), 10, 3 );
add_action( 'wp_dashboard_setup', array( 'WP_Presence_Widget_Active_Posts', 'register' ) );
add_filter( 'heartbeat_received', array( 'WP_Presence_Widget_Active_Posts', 'heartbeat_received' ), 10, 3 );
