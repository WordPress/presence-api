<?php
/**
 * Network Users list: "Online" view, filter, and column.
 *
 * Mirrors the single-site Users list "Online" view in includes/user-list.php,
 * scoped to users online anywhere on the network rather than the current site.
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds an "Online" view to the Network Users list table.
 *
 * @param array $views Existing views.
 * @return array Modified views.
 */
function wp_presence_network_users_views( $views ) {
	if ( ! current_user_can( wp_presence_network_capability() ) ) {
		return $views;
	}

	$online_count = count( wp_presence_get_network_online_user_ids() );
	$is_current   = isset( $_GET['presence_status'] ) && 'online' === $_GET['presence_status']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	$class = $is_current ? 'current' : '';
	$url   = wp_nonce_url( network_admin_url( 'users.php?presence_status=online' ), 'presence_online_filter' );

	$views['presence_online'] = sprintf(
		'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
		esc_url( $url ),
		$class,
		esc_html__( 'Online', 'presence-api' ),
		$online_count
	);

	if ( $is_current && isset( $views['all'] ) ) {
		$views['all'] = str_replace( 'class="current"', '', $views['all'] );
	}

	return $views;
}

/**
 * Restricts the Network Users list query to users online anywhere on the
 * network, when the "Online" view is active.
 *
 * @param array $args Query args passed to WP_User_Query.
 * @return array Modified query args.
 */
function wp_presence_filter_network_online_users( $args ) {
	if ( ! is_network_admin() || ! current_user_can( wp_presence_network_capability() ) ) {
		return $args;
	}

	if ( empty( $_GET['presence_status'] ) || 'online' !== $_GET['presence_status'] ) {
		return $args;
	}

	if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'presence_online_filter' ) ) {
		return $args;
	}

	$args['include'] = wp_presence_get_network_online_user_ids();

	if ( ! $args['include'] ) {
		// WP_User_Query treats an empty include as "no restriction", not "match
		// nothing," so force a result set of zero rather than falling through
		// to every network user.
		$args['include'] = array( 0 );
	}

	return $args;
}

/**
 * Adds an "Online" column to the Network Users list table.
 *
 * @param array $columns Existing column headers.
 * @return array Column headers with "Online" added.
 */
function wp_presence_register_network_users_column( $columns ) {
	if ( ! current_user_can( wp_presence_network_capability() ) ) {
		return $columns;
	}

	$columns['presence_online'] = __( 'Online', 'presence-api' );

	return $columns;
}

/**
 * Renders the "Online" column for a single row of the Network Users list table.
 *
 * Called once per row. The column names sites, not people, so it reads the
 * user-to-site index rather than the summary: resolving a display name and an
 * avatar URL for everyone online would be the whole cost of the read for
 * output this column never uses.
 *
 * @param string $output      Existing column output.
 * @param string $column_name Column being rendered.
 * @param int    $user_id     The user ID for the current row.
 * @return string Column output.
 */
function wp_presence_render_network_users_column( $output, $column_name, $user_id ) {
	if ( 'presence_online' !== $column_name ) {
		return $output;
	}

	$sites = wp_presence_get_network_sites_for_user( $user_id );

	if ( ! $sites ) {
		return '&#8212;';
	}

	$names = array();

	foreach ( $sites as $site ) {
		$names[] = $site->domain . $site->path;
	}

	return esc_html( implode( ', ', $names ) );
}
