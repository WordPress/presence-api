<?php
/**
 * Presence API functions.
 *
 * Public API (7 functions):
 *   wp_get_presence()
 *   wp_set_presence()
 *   wp_remove_presence()
 *   wp_remove_user_presence()
 *   wp_can_access_presence_room()
 *   wp_presence_post_room()
 *   wp_presence_admin_room()
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}



/**
 * Whether the current site has a presence table to query.
 *
 * Sites are provisioned at activation and at site creation, but a site on a
 * large network, or one added while the plugin was not network active, can
 * serve requests before either has happened. Presence is not essential to
 * rendering a page, so those requests return nothing instead of raising a
 * database error.
 *
 * The option is set by wp_maybe_create_presence_table() and is autoloaded, so
 * this costs nothing beyond a cache lookup. Any value counts, including one
 * from an older schema: the table is there, and the admin upgrade path will
 * bring it current.
 *
 * @access private
 * @return bool Whether presence storage is available on this site.
 */
function wp_presence_has_table() {
	return (bool) get_option( 'wp_presence_db_version' );
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

	if ( ! wp_presence_has_table() ) {
		return array();
	}

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

	if ( ! wp_presence_has_table() ) {
		return false;
	}

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

	if ( ! wp_presence_has_table() ) {
		return false;
	}

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

	if ( ! wp_presence_has_table() ) {
		return false;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$result = $wpdb->delete(
		$wpdb->presence,
		array( 'user_id' => $user_id ),
		array( '%d' )
	);

	return false !== $result;
}

/**
 * Parses a room identifier.
 *
 * Room format: `postType/{post_type}:{post_id}`
 *
 * @access private
 * @param string $room The room identifier.
 * @return array|false An array containing 'post_type' and 'post_id' on success, false otherwise.
 */
function wp_presence_parse_room( $room ) {
	if ( preg_match( '#^postType/([^:]+):(\d+)$#', $room, $matches ) ) {
		return array(
			'post_type' => $matches[1],
			'post_id'   => (int) $matches[2],
		);
	}

	return false;
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

	$parsed = wp_presence_parse_room( $room );
	if ( $parsed ) {
		return user_can( $user_id, 'edit_post', $parsed['post_id'] );
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

/**
 * Returns the presence room identifier for the admin "who's online" list.
 *
 * @return string The room identifier.
 */
function wp_presence_admin_room() {
	return 'admin/online';
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

	if ( ! wp_presence_has_table() ) {
		return array();
	}

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

	if ( ! wp_presence_has_table() ) {
		return array();
	}

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

	$summary = array(
		'total_entries' => 0,
		'total_users'   => 0,
		'by_prefix'     => array(),
	);

	if ( ! wp_presence_has_table() ) {
		return $summary;
	}

	$timeout = wp_presence_get_timeout( $timeout );
	$cutoff  = gmdate( 'Y-m-d H:i:s', time() - $timeout );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$room_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT room, COUNT(*) AS entries FROM {$wpdb->presence} WHERE date_gmt > %s GROUP BY room",
			$cutoff
		)
	);

	if ( ! $room_rows ) {
		return $summary;
	}

	// Grouped by prefix in PHP to avoid MySQL-specific SUBSTRING_INDEX().
	// Distinct user counts aren't additive across rooms, so those come from
	// a per-prefix query below rather than being summed here.
	$rooms_by_prefix = array();

	foreach ( $room_rows as $row ) {
		$prefix  = explode( '/', $row->room, 2 )[0];
		$entries = (int) $row->entries;

		if ( ! isset( $summary['by_prefix'][ $prefix ] ) ) {
			$summary['by_prefix'][ $prefix ] = array(
				'entries' => 0,
				'users'   => 0,
			);
			$rooms_by_prefix[ $prefix ]      = array();
		}

		$summary['by_prefix'][ $prefix ]['entries'] += $entries;
		$summary['total_entries']                   += $entries;
		$rooms_by_prefix[ $prefix ][]                = $row->room;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$summary['total_users'] = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(DISTINCT user_id) FROM {$wpdb->presence} WHERE date_gmt > %s",
			$cutoff
		)
	);

	foreach ( $rooms_by_prefix as $prefix => $rooms ) {
		$placeholders = implode( ', ', array_fill( 0, count( $rooms ), '%s' ) );

		// $placeholders holds only %s tokens generated above, so the interpolation is safe.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$summary['by_prefix'][ $prefix ]['users'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->presence} WHERE date_gmt > %s AND room IN ( $placeholders )", array_merge( array( $cutoff ), $rooms ) ) );
	}

	return $summary;
}

/**
 * Deletes stale presence entries older than the default TTL.
 *
 * Runs on the every-minute cron event. Rather than looping without a ceiling
 * over the MySQL-only `DELETE ... LIMIT` construct, this selects a bounded
 * page of primary keys older than the cutoff and deletes them by key, for a
 * fixed number of passes per invocation. Any remaining backlog is left for the
 * next cron run, so a single request cannot run until `max_execution_time`
 * when a site returns from a cron outage with a large backlog. Deleting by
 * primary key also keeps the query portable to non-MySQL backends, such as the
 * SQLite integration Playground uses to run the demo blueprints.
 *
 * @access private
 */
function wp_delete_expired_presence_data() {
	global $wpdb;

	if ( ! wp_presence_has_table() ) {
		return;
	}

	$timeout = wp_presence_get_timeout( WP_PRESENCE_DEFAULT_TTL );
	$cutoff  = gmdate( 'Y-m-d H:i:s', time() - $timeout );

	/**
	 * Filters the number of expired rows deleted per pass.
	 *
	 * @param int $batch_size Rows per pass. Default 1000.
	 */
	$batch_size = (int) apply_filters( 'wp_presence_cleanup_batch_size', 1000 );

	/**
	 * Filters the maximum number of delete passes per cron invocation.
	 *
	 * The remainder is left for the next scheduled run, bounding the work a
	 * single request performs.
	 *
	 * @param int $max_passes Passes per invocation. Default 10.
	 */
	$max_passes = (int) apply_filters( 'wp_presence_cleanup_max_passes', 10 );

	if ( $batch_size < 1 || $max_passes < 1 ) {
		return;
	}

	for ( $pass = 0; $pass < $max_passes; $pass++ ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->presence} WHERE date_gmt < %s ORDER BY id ASC LIMIT %d",
				$cutoff,
				$batch_size
			)
		);

		if ( empty( $ids ) ) {
			break;
		}

		$ids          = array_map( 'intval', $ids );
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// IDs are cast to integers above and passed to prepare() as %d
		// replacements, so the interpolated placeholder list is safe.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->presence} WHERE id IN ( $placeholders )", $ids ) );

		if ( count( $ids ) < $batch_size ) {
			break;
		}
	}
}

/**
 * Checks the database directly for the presence table.
 *
 * Only for the provisioning path. Request paths use wp_presence_has_table(),
 * which reads an autoloaded option and costs nothing.
 *
 * @access private
 * @return bool Whether the table exists on the current site.
 */
function wp_presence_table_exists() {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->presence ) ) );

	return $found === $wpdb->presence;
}

/**
 * Takes an exclusive lock, or reports that another request already holds it.
 *
 * Mirrors WP_Upgrader::create_lock(), which core uses to keep its own upgrade
 * routines from running twice over, in WP_Core_Upgrader::upgrade() and in
 * WP_Automatic_Updater::run(). The insert is the lock: option_name is unique,
 * so exactly one concurrent caller can create the row. That also means it holds
 * on sites with no persistent object cache, where wp_cache_add() is per request
 * and would coordinate nothing.
 *
 * Written out here rather than calling WP_Upgrader::create_lock() so that
 * provisioning does not have to load the whole upgrader stack on admin_init and
 * cli_init for the sake of one static method.
 *
 * @access private
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 *
 * @param string $lock_name       Name of the lock.
 * @param int    $release_timeout Optional. Seconds after which an unreleased
 *                                lock is treated as abandoned. Default
 *                                MINUTE_IN_SECONDS.
 * @return bool Whether the lock was taken.
 */
function wp_presence_create_lock( $lock_name, $release_timeout = null ) {
	global $wpdb;

	if ( ! $release_timeout ) {
		$release_timeout = MINUTE_IN_SECONDS;
	}

	$lock_option = $lock_name . '.lock';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$lock_result = $wpdb->query(
		$wpdb->prepare(
			"INSERT IGNORE INTO `$wpdb->options` ( `option_name`, `option_value`, `autoload` ) VALUES (%s, %s, 'off') /* LOCK */",
			$lock_option,
			time()
		)
	);

	if ( ! $lock_result ) {
		$lock_result = get_option( $lock_option );

		// No lock and no row to read means the insert failed for another reason.
		if ( ! $lock_result ) {
			return false;
		}

		// Someone else holds it and has not had long enough to be abandoned.
		if ( $lock_result > ( time() - $release_timeout ) ) {
			return false;
		}

		// A request died holding it. Clear it and take it, so one lost request
		// cannot leave the site unprovisionable.
		wp_presence_release_lock( $lock_name );

		return wp_presence_create_lock( $lock_name, $release_timeout );
	}

	// The insert above bypassed the options cache, so bring it back in line.
	update_option( $lock_option, time(), false );

	return true;
}

/**
 * Releases a lock taken by wp_presence_create_lock().
 *
 * @access private
 *
 * @see wp_presence_create_lock()
 *
 * @param string $lock_name Name of the lock.
 * @return bool Whether the lock was released.
 */
function wp_presence_release_lock( $lock_name ) {
	return delete_option( $lock_name . '.lock' );
}

/**
 * Creates or updates the presence table if needed.
 *
 * Feature plugin shim — in core, this table would be created by dbDelta()
 * during the database upgrade routine in wp-admin/includes/upgrade-schema.php.
 *
 * The version option alone is not enough to skip the work. If the table is
 * dropped while the option survives, a partial restore or a hand-run DROP,
 * every read and write fails and nothing reconciles the two.
 *
 * Ajax is excluded from that reconciliation. admin-ajax.php fires admin_init
 * too, and presence heartbeats through it every 15 seconds per open admin tab,
 * so checking there would bill every site continuously for a state almost none
 * of them will reach. The next real admin page load repairs it instead.
 *
 * @access private
 */
function wp_maybe_create_presence_table() {
	$provisioned = (int) get_option( 'wp_presence_db_version' ) === WP_PRESENCE_DB_VERSION;

	if ( $provisioned && ( wp_doing_ajax() || wp_presence_table_exists() ) ) {
		return;
	}

	// admin_init and cli_init have no confirmation step to serialize them the
	// way wp-admin/upgrade.php does for core, so two requests can arrive here at
	// once during a version bump. Whoever loses the race returns and lets the
	// winner finish; the next request repairs anything left over.
	if ( ! wp_presence_create_lock( 'wp_presence_table' ) ) {
		return;
	}

	global $wpdb;

	$charset_collate  = $wpdb->get_charset_collate();
	$max_index_length = WP_PRESENCE_MAX_KEY_LENGTH;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	dbDelta(
		"CREATE TABLE {$wpdb->presence} (
			id bigint(20) unsigned NOT NULL auto_increment,
			room varchar({$max_index_length}) NOT NULL default '',
			client_id varchar({$max_index_length}) NOT NULL default '',
			user_id bigint(20) unsigned NOT NULL default '0',
			data longtext NOT NULL,
			date_gmt datetime NOT NULL default '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY room_client (room, client_id),
			KEY date_gmt (date_gmt),
			KEY user_id (user_id),
			KEY room_date (room(40), date_gmt)
		) {$charset_collate};"
	);

	// Autoloaded explicitly: wp_presence_has_table() reads this on every request
	// that touches presence, so it must not cost a query.
	update_option( 'wp_presence_db_version', WP_PRESENCE_DB_VERSION, true );

	wp_presence_release_lock( 'wp_presence_table' );
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

	if ( ! wp_presence_has_table() ) {
		return array();
	}

	$timeout = wp_presence_get_timeout( $timeout );
	$cutoff  = gmdate( 'Y-m-d H:i:s', time() - $timeout );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT room, user_id
			FROM {$wpdb->presence}
			WHERE date_gmt > %s
			ORDER BY room ASC, user_id ASC",
			$cutoff
		)
	);
	if ( ! $rows ) {
		return array();
	}

	$rooms_by_name = array();
	$all_user_ids  = array();

	foreach ( $rows as $row ) {
		if ( ! isset( $rooms_by_name[ $row->room ] ) ) {
			$rooms_by_name[ $row->room ] = array(
				'room'        => $row->room,
				'entry_count' => 0,
				'user_ids'    => array(),
			);
		}

		$user_id = (int) $row->user_id;

		++$rooms_by_name[ $row->room ]['entry_count'];
		$rooms_by_name[ $row->room ]['user_ids'][ $user_id ] = true;
		$all_user_ids[ $user_id ]                            = true;
	}

	// Prime the user object cache in a single query.
	cache_users( array_keys( $all_user_ids ) );

	uasort(
		$rooms_by_name,
		function ( $room_a, $room_b ) {

			if ( $room_a['entry_count'] === $room_b['entry_count'] ) {
				return strcmp( $room_a['room'], $room_b['room'] );
			}

			return $room_b['entry_count'] <=> $room_a['entry_count'];
		}
	);
	$rooms = array();

	foreach ( $rooms_by_name as $room ) {
		$user_ids = array_keys( $room['user_ids'] );
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
			'room'       => $room['room'],
			'user_count' => count( $users ),
			'users'      => $users,
		);
	}

	return $rooms;
}
