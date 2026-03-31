<?php
/**
 * Presence API functions.
 *
 * Public API (6 functions):
 *   wp_get_presence()
 *   wp_set_presence()
 *   wp_remove_presence()
 *   wp_remove_user_presence()
 *   wp_can_access_presence_room()
 *   wp_presence_post_room()
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}



/**
 * Gets all present clients in a room, filtered by TTL.
 *
 * @param string $room    The room identifier.
 * @param int    $timeout Optional. Timeout in seconds. Default WP_PRESENCE_DEFAULT_TTL.
 * @return array Array of presence entry objects.
 */
function wp_get_presence( $room, $timeout = WP_PRESENCE_DEFAULT_TTL ) {
	global $wpdb;

	$timeout = wp_presence_get_timeout( $timeout );
	$cutoff  = gmdate( 'Y-m-d H:i:s', time() - $timeout );

	// Presence data is ephemeral and changes on every heartbeat; caching would serve stale data.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$results = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT room, client_id, user_id, data, date_gmt FROM {$wpdb->presence} WHERE room = %s AND date_gmt > %s ORDER BY date_gmt DESC",
			$room,
			$cutoff
		)
	);

	if ( ! $results ) {
		return array();
	}

	foreach ( $results as $row ) {
		$decoded   = json_decode( $row->data, true );
		$row->data = is_array( $decoded ) ? $decoded : array();
	}

	return $results;
}

/**
 * Upserts a client's presence state in a room.
 *
 * Uses INSERT ... ON DUPLICATE KEY UPDATE for atomic upserts
 * via the UNIQUE KEY (room, client_id).
 *
 * @param string $room      The room identifier.
 * @param string $client_id The client identifier.
 * @param array  $state     The presence state data.
 * @param int    $user_id   Optional. The user ID. Default 0.
 * @return bool True on success, false on failure.
 */
function wp_set_presence( $room, $client_id, $state, $user_id = 0 ) {
	global $wpdb;

	$data_json = wp_json_encode( $state );
	$now       = gmdate( 'Y-m-d H:i:s' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$result = $wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$wpdb->presence} (room, client_id, user_id, data, date_gmt)
			VALUES (%s, %s, %d, %s, %s)
			ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), data = VALUES(data), date_gmt = VALUES(date_gmt)",
			$room,
			$client_id,
			$user_id,
			$data_json,
			$now
		)
	);

	return false !== $result;
}

/**
 * Removes a client from a room.
 *
 * @param string $room      The room identifier.
 * @param string $client_id The client identifier.
 * @return bool True on success, false on failure.
 */
function wp_remove_presence( $room, $client_id ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$result = $wpdb->delete(
		$wpdb->presence,
		array(
			'room'      => $room,
			'client_id' => $client_id,
		),
		array( '%s', '%s' )
	);

	return false !== $result;
}

/**
 * Removes all presence entries for a given user across all rooms.
 *
 * @param int $user_id The user ID.
 * @return bool True on success, false on failure.
 */
function wp_remove_user_presence( $user_id ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$result = $wpdb->delete(
		$wpdb->presence,
		array( 'user_id' => $user_id ),
		array( '%d' )
	);

	return false !== $result;
}

/**
 * Checks if a user can access a presence room.
 *
 * @param string $room    The room identifier.
 * @param int    $user_id Optional. The user ID. Default 0 (current user).
 * @return bool True if the user can access the room, false otherwise.
 */
function wp_can_access_presence_room( $room, $user_id = 0 ) {
	if ( ! $user_id ) {
		$user_id = get_current_user_id();
	}

	if ( ! $user_id ) {
		return false;
	}

	return user_can( $user_id, 'edit_posts' );
}

/**
 * Returns the presence room identifier for a given post.
 *
 * Room format: `postType/{post_type}:{post_id}`
 *
 * @param int|WP_Post $post The post ID or post object.
 * @return string|false The room identifier, or false if the post doesn't exist
 *                      or its post type does not support presence.
 */
function wp_presence_post_room( $post ) {
	$post = get_post( $post );

	if ( ! $post ) {
		return false;
	}

	if ( ! post_type_supports( $post->post_type, 'presence' ) ) {
		return false;
	}

	return 'postType/' . $post->post_type . ':' . $post->ID;
}

/*
 *
 * The following functions are used by the plugin's widgets, CLI, REST
 * controller, and cron jobs. They are not part of the public API contract
 * and may change or be removed without notice. Do not depend on them.
 */

/**
 * Filters and returns the presence timeout value.
 *
 * @access private
 * @param int $timeout The timeout in seconds.
 * @return int The filtered timeout in seconds.
 */
function wp_presence_get_timeout( $timeout ) {
	/**
	 * Filters the presence TTL (time-to-live) used for queries and cleanup.
	 *
	 * @param int $timeout The timeout in seconds. Default WP_PRESENCE_DEFAULT_TTL (60).
	 */
	return (int) apply_filters( 'wp_presence_default_ttl', $timeout );
}

/**
 * Gets all presence entries for a given user across all rooms.
 *
 * @access private
 * @param int $user_id The user ID.
 * @param int $timeout Optional. Timeout in seconds. Default WP_PRESENCE_DEFAULT_TTL.
 * @return array Array of presence entry objects.
 */
function wp_get_user_presence( $user_id, $timeout = WP_PRESENCE_DEFAULT_TTL ) {
	global $wpdb;

	$timeout = wp_presence_get_timeout( $timeout );
	$cutoff  = gmdate( 'Y-m-d H:i:s', time() - $timeout );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$results = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT room, client_id, user_id, data, date_gmt FROM {$wpdb->presence} WHERE user_id = %d AND date_gmt > %s ORDER BY date_gmt DESC",
			$user_id,
			$cutoff
		)
	);

	if ( ! $results ) {
		return array();
	}

	foreach ( $results as $row ) {
		$decoded   = json_decode( $row->data, true );
		$row->data = is_array( $decoded ) ? $decoded : array();
	}

	return $results;
}

/**
 * Gets all presence entries for rooms matching a prefix.
 *
 * @access private
 * @param string $prefix  The room prefix to match (e.g., 'postType/').
 * @param int    $timeout Optional. Timeout in seconds. Default WP_PRESENCE_DEFAULT_TTL.
 * @return array Array of presence entry objects.
 */
function wp_get_presence_by_room_prefix( $prefix, $timeout = WP_PRESENCE_DEFAULT_TTL ) {
	global $wpdb;

	$timeout = wp_presence_get_timeout( $timeout );
	$cutoff  = gmdate( 'Y-m-d H:i:s', time() - $timeout );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$results = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT room, client_id, user_id, data, date_gmt FROM {$wpdb->presence} WHERE room LIKE %s AND date_gmt > %s ORDER BY date_gmt DESC",
			$wpdb->esc_like( $prefix ) . '%',
			$cutoff
		)
	);

	if ( ! $results ) {
		return array();
	}

	foreach ( $results as $row ) {
		$decoded   = json_decode( $row->data, true );
		$row->data = is_array( $decoded ) ? $decoded : array();
	}

	return $results;
}

/**
 * Returns a site-wide presence summary grouped by room prefix.
 *
 * @access private
 * @param int $timeout Optional. Timeout in seconds. Default WP_PRESENCE_DEFAULT_TTL.
 * @return array {
 *     @type int   $total_entries Total presence entries.
 *     @type int   $total_users   Distinct user count.
 *     @type array $by_prefix     Associative array keyed by prefix, each with 'entries' and 'users'.
 * }
 */
function wp_get_presence_summary( $timeout = WP_PRESENCE_DEFAULT_TTL ) {
	global $wpdb;

	$timeout = wp_presence_get_timeout( $timeout );
	$cutoff  = gmdate( 'Y-m-d H:i:s', time() - $timeout );

	// Increase GROUP_CONCAT limit to prevent silent truncation with many users per room.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( 'SET SESSION group_concat_max_len = 1000000' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT room, COUNT(*) AS entries, COUNT(DISTINCT user_id) AS users, GROUP_CONCAT(DISTINCT user_id) AS user_ids FROM {$wpdb->presence} WHERE date_gmt > %s GROUP BY room",
			$cutoff
		)
	);

	// Always reset GROUP_CONCAT limit regardless of query success.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( 'SET SESSION group_concat_max_len = DEFAULT' );

	$summary = array(
		'total_entries' => 0,
		'total_users'   => 0,
		'by_prefix'     => array(),
	);

	if ( ! $rows ) {
		return $summary;
	}

	// Group by prefix in PHP to avoid MySQL-specific SUBSTRING_INDEX().
	// Collect all user IDs across rooms to compute distinct totals in PHP.
	$all_user_ids    = array();
	$prefix_user_ids = array();

	foreach ( $rows as $row ) {
		$prefix = explode( '/', $row->room, 2 )[0];

		if ( ! isset( $summary['by_prefix'][ $prefix ] ) ) {
			$summary['by_prefix'][ $prefix ] = array(
				'entries' => 0,
				'users'   => 0,
			);
			$prefix_user_ids[ $prefix ]      = array();
		}

		$summary['by_prefix'][ $prefix ]['entries'] += (int) $row->entries;
		$summary['total_entries']                   += (int) $row->entries;

		if ( ! empty( $row->user_ids ) ) {
			$room_user_ids              = explode( ',', $row->user_ids );
			$all_user_ids               = array_merge( $all_user_ids, $room_user_ids );
			$prefix_user_ids[ $prefix ] = array_merge( $prefix_user_ids[ $prefix ], $room_user_ids );
		}
	}

	foreach ( $prefix_user_ids as $prefix => $user_ids ) {
		$summary['by_prefix'][ $prefix ]['users'] = count( array_unique( $user_ids ) );
	}

	$summary['total_users'] = count( array_unique( $all_user_ids ) );

	return $summary;
}

/**
 * Deletes stale presence entries older than the default TTL.
 *
 * @access private
 */
function wp_delete_expired_presence_data() {
	global $wpdb;

	$timeout = wp_presence_get_timeout( WP_PRESENCE_DEFAULT_TTL );
	$cutoff  = gmdate( 'Y-m-d H:i:s', time() - $timeout );

	// Delete in batches to avoid locking the table on large datasets.
	do {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->presence} WHERE date_gmt < %s LIMIT 1000",
				$cutoff
			)
		);
	} while ( $deleted > 0 );
}

/**
 * Creates or updates the presence table if needed.
 *
 * Feature plugin shim — in core, this table would be created by dbDelta()
 * during the database upgrade routine in wp-admin/includes/upgrade-schema.php.
 *
 * @access private
 */
function wp_maybe_create_presence_table() {
	if ( get_option( 'wp_presence_db_version' ) === WP_PRESENCE_DB_VERSION ) {
		return;
	}

	global $wpdb;

	$charset_collate  = $wpdb->get_charset_collate();
	$max_index_length = 191;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	dbDelta(
		"CREATE TABLE {$wpdb->presence} (
			id bigint(20) unsigned NOT NULL auto_increment,
			room varchar({$max_index_length}) NOT NULL default '',
			client_id varchar({$max_index_length}) NOT NULL default '',
			user_id bigint(20) unsigned NOT NULL default '0',
			data text NOT NULL,
			date_gmt datetime NOT NULL default '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY room_client (room, client_id),
			KEY date_gmt (date_gmt),
			KEY user_id (user_id),
			KEY room_date (room(40), date_gmt)
		) {$charset_collate};"
	);

	update_option( 'wp_presence_db_version', WP_PRESENCE_DB_VERSION );
}

/**
 * Returns all active rooms with their user counts and member lists.
 *
 * @access private
 * @param int $timeout Optional. Timeout in seconds. Default WP_PRESENCE_DEFAULT_TTL.
 * @return array Array of room objects, each with 'room', 'user_count', and 'users'.
 */
function wp_get_active_rooms( $timeout = WP_PRESENCE_DEFAULT_TTL ) {
	global $wpdb;

	$timeout = wp_presence_get_timeout( $timeout );
	$cutoff  = gmdate( 'Y-m-d H:i:s', time() - $timeout );

	// Increase GROUP_CONCAT limit to prevent silent truncation with many users per room.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( 'SET SESSION group_concat_max_len = 1000000' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT room, GROUP_CONCAT(DISTINCT user_id ORDER BY user_id ASC) AS user_ids
			FROM {$wpdb->presence}
			WHERE date_gmt > %s
			GROUP BY room
			ORDER BY COUNT(*) DESC",
			$cutoff
		)
	);

	// Always reset GROUP_CONCAT limit regardless of query success.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( 'SET SESSION group_concat_max_len = DEFAULT' );

	if ( ! $rows ) {
		return array();
	}

	// Prime the user object cache in a single query.
	$all_user_ids = array();
	foreach ( $rows as $row ) {
		$all_user_ids = array_merge( $all_user_ids, array_map( 'intval', explode( ',', $row->user_ids ) ) );
	}
	cache_users( array_unique( $all_user_ids ) );

	$rooms = array();

	foreach ( $rows as $row ) {
		$user_ids = array_map( 'intval', explode( ',', $row->user_ids ) );
		$users    = array();

		foreach ( $user_ids as $uid ) {
			$user = get_userdata( $uid );

			if ( ! $user ) {
				continue;
			}

			$users[] = array(
				'user_id'      => $uid,
				'display_name' => $user->display_name,
				'avatar_url'   => get_avatar_url( $uid, array( 'size' => 32 ) ),
			);
		}

		$rooms[] = array(
			'room'       => $row->room,
			'user_count' => count( $users ),
			'users'      => $users,
		);
	}

	return $rooms;
}
