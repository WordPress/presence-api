<?php
/**
 * User list: online filter view.
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds an "Online" view to the users list table.
 *
 * Displays a tab alongside the role-based views (All | Administrator | Editor | etc.)
 * that filters the list to only show users with active presence entries.
 *
 * @param array $views Existing views.
 * @return array Modified views.
 */
function wp_presence_users_views( $views ) {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return $views;
	}

	$entries      = wp_get_presence( 'admin/online' );
	$online_ids   = array_unique( wp_list_pluck( $entries, 'user_id' ) );
	$online_count = count( $online_ids );
	$is_current   = isset( $_GET['presence_status'] ) && 'online' === $_GET['presence_status']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	$class = $is_current ? 'current' : '';
	$url   = admin_url( 'users.php?presence_status=online' );

	$views['presence_online'] = sprintf(
		'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
		esc_url( $url ),
		$class,
		esc_html__( 'Online', 'presence-api' ),
		$online_count
	);

	// Remove "current" from "All" when our filter is active.
	if ( $is_current && isset( $views['all'] ) ) {
		$views['all'] = str_replace( 'class="current"', '', $views['all'] );
	}

	return $views;
}

/**
 * Filters the users query to only include online users when presence_status=online.
 *
 * @param WP_User_Query $query The user query.
 */
function wp_presence_filter_online_users( $query ) {
	if ( ! is_admin() || ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	if ( empty( $_GET['presence_status'] ) || 'online' !== $_GET['presence_status'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	$entries    = wp_get_presence( 'admin/online' );
	$online_ids = array_unique( wp_list_pluck( $entries, 'user_id' ) );

	if ( empty( $online_ids ) ) {
		$query->set( 'include', array( 0 ) );
	} else {
		$query->set( 'include', array_map( 'intval', $online_ids ) );
	}
}
