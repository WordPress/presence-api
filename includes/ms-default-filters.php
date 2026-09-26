<?php
/**
 * Multisite hooks for the parts of the plugin bound for core.
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_presence_admin_room_changed', 'wp_presence_push_network_summary' );
add_action( 'wp_presence_admin_room_changed', 'wp_presence_flush_network_summary_cache' );
add_action( 'wp_delete_site', 'wp_presence_on_delete_site' );
add_action( 'wp_delete_expired_presence_data', 'wp_presence_delete_expired_network_summary_rows' );

add_filter( 'wpmu_blogs_columns', 'wp_presence_register_network_sites_column' );
add_action( 'manage_sites_custom_column', 'wp_presence_render_network_sites_column', 10, 2 );
add_action( 'admin_enqueue_scripts', 'wp_presence_enqueue_network_sites_assets' );
add_action( 'network_admin_notices', 'wp_presence_network_aggregation_notice' );

add_filter( 'views_users-network', 'wp_presence_network_users_views' );
add_filter( 'users_list_table_query_args', 'wp_presence_filter_network_online_users' );
add_filter( 'wpmu_users_columns', 'wp_presence_register_network_users_column' );
add_filter( 'manage_users-network_custom_column', 'wp_presence_render_network_users_column', 10, 3 );

add_action( 'wp_network_dashboard_setup', array( 'WP_Presence_Network_Widget_Whos_Online', 'register' ) );
add_filter( 'heartbeat_received', array( 'WP_Presence_Network_Widget_Whos_Online', 'heartbeat_received' ), 10, 3 );
