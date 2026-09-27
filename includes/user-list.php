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
 * @since 0.1.1
 *
 * @param array $views Existing views.
 * @return array Modified views.
 */
function wp_presence_users_views( $views ) {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return $views;
	}

	$entries      = wp_get_presence( wp_presence_admin_room() );
	$online_count = count( wp_presence_online_user_ids( $entries ) );
	$is_current   = isset( $_GET['presence_status'] ) && 'online' === $_GET['presence_status']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	$class = $is_current ? 'current' : '';
	$url   = wp_nonce_url( admin_url( 'users.php?presence_status=online' ), 'presence_online_filter' );

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
 * Stands down in Network Admin. is_admin() is true there too, and the Network
 * Users list runs its own WP_User_Query, so without this the network "Online"
 * view would have its network-wide set of IDs replaced with the ones online on
 * whichever site the request resolved to.
 *
 * @since 0.1.1
 *
 * @param WP_User_Query $query The user query.
 */
function wp_presence_filter_online_users( $query ) {
	if ( ! is_admin() || is_network_admin() || ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	if ( empty( $_GET['presence_status'] ) || 'online' !== $_GET['presence_status'] ) {
		return;
	}

	if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'presence_online_filter' ) ) {
		return;
	}

	$entries = wp_get_presence( wp_presence_admin_room() );

	$query->set( 'include', wp_presence_online_user_ids( $entries ) );
}

/**
 * Sends fresh rows for the Online view of the users list, on a site or the network, with each heartbeat that asks.
 *
 * @since 0.10.0
 *
 * @param array  $response  Heartbeat response data.
 * @param array  $data      Data received from the client.
 * @param string $screen_id The screen ID the heartbeat came from.
 * @return array The Heartbeat response.
 */
function wp_presence_users_list_heartbeat_received( $response, $data, $screen_id = '' ) {
	// The screen ID comes from the client, and the network functions only load on multisite.
	$network = is_multisite() && 'users-network' === $screen_id;
	$allowed = $network
		? current_user_can( 'manage_network_users' ) && current_user_can( wp_presence_network_capability() ) && wp_presence_network_aggregation_enabled()
		: current_user_can( 'list_users' );

	$query = wp_presence_fragment_request( $data, 'users-list' );

	if ( ! $allowed || empty( $query ) || ! is_string( $query ) ) {
		return $response;
	}

	parse_str( ltrim( $query, '?' ), $args );
	if ( ( $args['presence_status'] ?? '' ) !== 'online' || ! is_string( $args['_wpnonce'] ?? null ) || ! wp_verify_nonce( $args['_wpnonce'], 'presence_online_filter' ) ) {
		return $response;
	}

	// The list table and the online filters read the page's own request, so it stands in for this one.
	$saved                  = array( $_REQUEST, $_GET, $_SERVER['REQUEST_URI'] ?? '', $GLOBALS['current_screen'] ?? null ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
	$_REQUEST               = wp_slash( $args );
	$_GET                   = $_REQUEST;
	$_SERVER['REQUEST_URI'] = wp_parse_url( $network ? network_admin_url( 'users.php' ) : admin_url( 'users.php' ), PHP_URL_PATH ) . '?' . http_build_query( $args );
	set_current_screen( $network ? 'users-network' : 'users' );

	$table = _get_list_table( $network ? 'WP_MS_Users_List_Table' : 'WP_Users_List_Table', array( 'screen' => $network ? 'users-network' : 'users' ) );
	$table->prepare_items();
	ob_start();
	$table->display_rows_or_placeholder();
	$rows = (string) ob_get_clean();

	list( $_REQUEST, $_GET, $_SERVER['REQUEST_URI'], $GLOBALS['current_screen'] ) = $saved; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash

	$response['presence-fragments']['users-list'] = $rows;

	return $response;
}

/**
 * Sends a fresh count for the Online view link, on every view of a site or network users list that shows it.
 *
 * @since 0.11.0
 *
 * @param array  $response  Heartbeat response data.
 * @param array  $data      Data received from the client.
 * @param string $screen_id The screen ID the heartbeat came from.
 * @return array The Heartbeat response.
 */
function wp_presence_users_online_count_heartbeat_received( $response, $data, $screen_id = '' ) {
	if ( ! wp_presence_fragment_request( $data, 'users-online-count' ) ) {
		return $response;
	}

	// The same checks that decide whether each screen shows the link at all.
	if ( is_multisite() && 'users-network' === $screen_id ) {
		if ( ! current_user_can( wp_presence_network_capability() ) || ! wp_presence_network_aggregation_enabled() ) {
			return $response;
		}
		$count = count( wp_presence_get_network_online_user_ids() );
	} elseif ( 'users' === $screen_id && current_user_can( 'edit_posts' ) ) {
		$count = count( wp_presence_online_user_ids( wp_get_presence( wp_presence_admin_room() ) ) );
	} else {
		return $response;
	}

	$response['presence-fragments']['users-online-count'] = '(' . $count . ')';

	return $response;
}
