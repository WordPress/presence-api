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
add_action( 'remove_user_from_blog', 'wp_presence_on_user_removed', 10, 1 );
add_action( 'wp_delete_site', 'wp_presence_on_delete_site' );

add_action( 'wp_delete_expired_presence_data', 'wp_presence_delete_expired_network_summary_rows' );
