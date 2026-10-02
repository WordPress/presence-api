<?php
/**
 * Presence API: primitives, rooms, timing, and cleanup.
 *
 * Public API:
 *   wp_get_presence()
 *   wp_set_presence()
 *   wp_remove_presence()
 *   wp_presence_exchange()
 *   wp_presence_leave()
 *   wp_remove_user_presence()
 *   wp_can_access_presence_room()
 *   wp_presence_post_room()
 *   wp_presence_admin_room()
 *   wp_presence_recording_enabled()
 *   wp_presence_is_available()
 *   wp_presence_is_agent_user()
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
 *
 * @since 0.1.17
 * @return bool Whether presence storage is available on this site.
 */
function wp_presence_has_table() {
	return (bool) get_option( 'wp_presence_db_version' );
}

/**
 * Returns the date_gmt floor a read applies on top of a row's own expiry.
 *
 * A caller that named no window has no opinion about staleness, so the row's
 * expiry is the only bound and this floor matches every stored row.
 *
 * @access private
 *
 * @since 0.7.0
 *
 * @param int|null $timeout The caller's window in seconds, or null for none.
 * @return string A floor in `Y-m-d H:i:s`, UTC.
 */
function wp_presence_read_floor( $timeout ) {
	if ( null === $timeout ) {
		return '1000-01-01 00:00:00';
	}

	return gmdate( 'Y-m-d H:i:s', time() - wp_presence_get_timeout( $timeout ) );
}

/**
 * Gets all present clients in a room, filtered by TTL.
 *
 * Reserved rows are left out whatever the prefix, so `_` returns nothing.
 *
 * A `$timeout` given here is the window used, on every site. Omit it to take
 * the site's own TTL, which is what `wp_presence_default_ttl` filters.
 *
 * @since 0.1.1
 * @since 0.7.0 Added the `$client_prefix` parameter.
 * @since 0.7.0 An explicit `$timeout` is no longer overridden by `wp_presence_default_ttl`.
 *
 * @param string $room          The room identifier.
 * @param int    $timeout       Optional. Timeout in seconds. Default null, the site's filtered TTL.
 * @param string $client_prefix Optional. Only return clients whose client_id starts with this.
 *                              Default empty.
 * @return array Array of presence entry objects.
 */
function wp_get_presence( $room, $timeout = null, $client_prefix = '' ) {
	return wp_presence_client_rows( wp_presence_room_rows( $room, $timeout, $client_prefix ) );
}

/**
 * Whether a client_id is the plugin's own bookkeeping rather than a client.
 *
 * @access private
 *
 * @since 0.6.0
 *
 * @param string $client_id The client identifier.
 * @return bool Whether the id is reserved.
 */
function wp_presence_is_reserved_client_id( $client_id ) {
	return str_starts_with( (string) $client_id, WP_PRESENCE_RESERVED_PREFIX );
}

/**
 * Returns the LIKE pattern matching every reserved client_id.
 *
 * For the queries that read rows by room in SQL rather than through
 * wp_get_presence(), which filters them out in PHP.
 *
 * @access private
 *
 * @since 0.6.0
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 *
 * @return string An escaped LIKE pattern.
 */
function wp_presence_reserved_client_id_pattern() {
	global $wpdb;

	return $wpdb->esc_like( WP_PRESENCE_RESERVED_PREFIX ) . '%';
}

/**
 * Drops the reserved rows from a set of rows.
 *
 * @access private
 *
 * @since 0.6.0
 *
 * @param array $rows Rows as returned by wp_presence_room_rows().
 * @return array The rows that belong to clients.
 */
function wp_presence_client_rows( $rows ) {
	return array_values(
		array_filter(
			$rows,
			static function ( $row ) {
				return ! wp_presence_is_reserved_client_id( $row->client_id );
			}
		)
	);
}

/**
 * Gets every live row in a room, the reserved rows included.
 *
 * One query serves both the participants and the plugin's own state for the
 * room, so reading that state costs nothing on top of the read the caller
 * was already making.
 *
 * @access private
 *
 * @since 0.6.0
 * @since 0.7.0 Added the `$client_prefix` parameter.
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 *
 * @param string $room          The room identifier.
 * @param int    $timeout       Optional. Timeout in seconds. Default null, the site's filtered TTL.
 * @param string $client_prefix Optional. Only return rows whose client_id starts with this.
 *                              Default empty.
 * @return array Array of presence row objects.
 */
function wp_presence_room_rows( $room, $timeout = null, $client_prefix = '' ) {
	global $wpdb;

	if ( ! wp_presence_has_table() ) {
		return array();
	}

	$cutoff = gmdate( 'Y-m-d H:i:s' );
	$stale  = wp_presence_read_floor( $timeout );

	$client_clause = '';
	$args          = array( $room, $cutoff, $stale );

	if ( '' !== (string) $client_prefix ) {
		$client_clause = ' AND client_id LIKE %s';
		// Escaped, since LIKE reads `_` and `%` as wildcards.
		$args[] = $wpdb->esc_like( (string) $client_prefix ) . '%';
	}

	return wp_presence_cached_rows(
		"room:{$room}:{$client_prefix}",
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT room, client_id, user_id, data, date_gmt FROM {$wpdb->presence} WHERE room = %s AND expires_gmt > %s AND date_gmt > %s{$client_clause} ORDER BY date_gmt DESC",
			...$args
		)
	);
}

/**
 * Runs a presence read, reusing its rows for the rest of the request until the table changes.
 *
 * Salted with the SQL, which holds the current second, so a cached read lapses as rows expire
 * while each read keeps one entry however long the process runs.
 *
 * @access private
 *
 * @since 0.12.2
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 *
 * @param string $read  Names the read, without the times in its SQL.
 * @param string $query Prepared SQL selecting presence rows.
 * @return array Array of presence row objects, with `data` decoded.
 */
function wp_presence_cached_rows( $read, $query ) {
	global $wpdb;

	$key     = md5( $read );
	$salt    = array( wp_cache_get_last_changed( 'presence' ), md5( $query ) );
	$results = wp_cache_get_salted( $key, 'presence', $salt );

	if ( false === $results ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results( $query );
		$results = $results ? $results : array();

		foreach ( $results as $row ) {
			$decoded   = json_decode( $row->data, true );
			$row->data = is_array( $decoded ) ? $decoded : array();
		}

		wp_cache_set_salted( $key, $results, 'presence', $salt );
	}

	return $results;
}

/**
 * Returns the user IDs present in a set of entries, the current user included.
 *
 * The current user's own row is absent on screens that never ping and once it
 * ages past the TTL, so it is added by identity rather than by adding one,
 * which would double-count whenever the row is there.
 *
 * @access private
 *
 * @since 0.3.0
 *
 * @param array $entries Presence entries, as returned by wp_get_presence().
 * @return int[] Unique user IDs.
 */
function wp_presence_online_user_ids( $entries ) {
	return array_values( wp_parse_id_list( wp_list_pluck( wp_presence_with_current_user( $entries ), 'user_id' ) ) );
}

/**
 * Adds an entry for the current user to a set of entries when their row is absent.
 *
 * @access private
 *
 * @since 0.3.0
 *
 * @param array $entries Presence entries, as returned by wp_get_presence().
 * @return array Entries with the current user included.
 */
function wp_presence_with_current_user( $entries ) {
	$current_id = get_current_user_id();

	if ( ! $current_id ) {
		return $entries;
	}

	foreach ( $entries as $entry ) {
		if ( (int) $entry->user_id === $current_id ) {
			return $entries;
		}
	}

	$entries[] = (object) array(
		'user_id'  => $current_id,
		'date_gmt' => current_time( 'mysql', true ),
		'data'     => array(),
	);

	return $entries;
}

/**
 * Whether presence is recorded on this site.
 *
 * The controller-level switch, checked at the single write path. Nothing new is
 * stored while this is false and every surface empties within one
 * WP_PRESENCE_DEFAULT_TTL as the rows already there expire, so there is no
 * separate teardown to run.
 *
 * Recording is on by default. Presence is a negative signal, and the post lock
 * bridge is where its absence stops two people overwriting each other, so the
 * default is the safe one; a site that would rather not process it at all
 * switches it off here and says so in its privacy policy.
 *
 * The stored options are passed as the filters' defaults, so a filter always
 * has the last word over whatever the checkbox says.
 *
 * Aggregating those rows into the network-wide view is a separate switch. See
 * wp_presence_network_aggregation_enabled().
 *
 * @since 0.3.0
 *
 * @return bool Whether presence is recorded.
 */
function wp_presence_recording_enabled() {
	/**
	 * Filters whether presence is recorded on this site.
	 *
	 * @since 0.3.0
	 *
	 * @param bool $enabled Whether to record presence. Default is the
	 *                      wp_presence_recording option, true on a new install.
	 */
	$enabled = (bool) apply_filters( 'wp_presence_recording_enabled', (bool) get_option( 'wp_presence_recording', true ) );

	if ( ! $enabled || ! is_multisite() ) {
		return $enabled;
	}

	/**
	 * Filters whether presence is recorded anywhere on this network.
	 *
	 * Consulted only once the site-level filter has allowed recording, so
	 * either switch turning off wins and neither can turn the other back on.
	 *
	 * @since 0.3.0
	 *
	 * @param bool $enabled Whether to record presence. Default is the
	 *                      wp_presence_network_recording site option, true on a
	 *                      new install.
	 */
	return (bool) apply_filters( 'wp_presence_network_recording_enabled', (bool) get_site_option( 'wp_presence_network_recording', true ) );
}

/**
 * Whether presence can be read and written on this site.
 *
 * The check an integrator makes before relying on presence. On a site with
 * no table, or with recording switched off, wp_set_presence() returns false
 * and wp_get_presence() returns an empty array, which reads as an empty room
 * rather than an unavailable backend. Guard with function_exists() first to
 * cover the plugin not being loaded.
 *
 * @since 0.6.0
 *
 * @return bool Whether the table exists and presence is recorded.
 */
function wp_presence_is_available() {
	return wp_presence_has_table() && wp_presence_recording_enabled();
}

/**
 * Returns how long until the client is expected to send its next Heartbeat.
 *
 * @access private
 *
 * @since 0.4.0
 *
 * @return int Seconds.
 */
function wp_presence_next_tick_gap() {
	// Core's scheduleNextTick() overrides the interval to 120s whenever the
	// window is blurred, and never reflects that back into the interval it
	// reports in the same request, so the reported value understates the real
	// gap on an unfocused tab. That is the worst case, and the assumption to
	// make whenever the request does not say otherwise.
	$blurred = 120;

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled by WordPress in wp_ajax_heartbeat() before any of this runs.
	if ( ! isset( $_POST['interval'], $_POST['has_focus'] ) || 'true' !== $_POST['has_focus'] ) {
		return $blurred;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- As above.
	$interval = absint( $_POST['interval'] );

	return $interval > 0 ? $interval : $blurred;
}

/**
 * Returns how stale a presence row is allowed to get while its client is still
 * pinging.
 *
 * A row's date_gmt is the only evidence a reader has that its client is still
 * there, so a write skipped to save a query is indistinguishable from a client
 * that left. This bounds how long that ambiguity lasts.
 *
 * @access private
 *
 * @since 0.6.0
 *
 * @return int Age in seconds.
 */
function wp_presence_max_staleness() {
	return 30;
}

/**
 * Returns the age past which a presence row means its client has gone quiet.
 *
 * A row this old cannot be explained by a skipped write followed by the widest
 * gap a pinging client leaves, so the client really has stopped. Anything
 * reading date_gmt to tell active from idle has to use this rather than a
 * figure of its own, or a client that is merely economising on writes reads as
 * one that walked away.
 *
 * @access private
 *
 * @since 0.6.0
 *
 * @return int Age in seconds.
 */
function wp_presence_idle_threshold() {
	return wp_presence_max_staleness() + wp_presence_get_heartbeat_idle_interval();
}

/**
 * Returns the age at which an unchanged presence row still has to be rewritten.
 *
 * Skipping a write leaves the row's existing date_gmt in place, so it is only
 * safe while the row will still be inside wp_get_presence()'s cutoff when the
 * next tick arrives. Deriving this from wp_presence_get_timeout() rather than
 * WP_PRESENCE_DEFAULT_TTL matters: a site filtering the TTL below the tick
 * interval would otherwise make its users blink offline.
 *
 * Staying inside the cutoff is not enough on its own, since a row can sit
 * unwritten well inside the TTL and still read as long gone, so
 * wp_presence_max_staleness() caps it as well.
 *
 * @access private
 *
 * @since 0.4.0
 *
 * @return int Age in seconds. 0 means never skip.
 */
function wp_presence_refresh_threshold() {
	$timeout = wp_presence_get_timeout();

	return max( 0, min( $timeout - wp_presence_ttl_margin() - wp_presence_next_tick_gap(), wp_presence_max_staleness() ) );
}

/**
 * Returns the slice of the TTL kept in reserve rather than spent on waiting.
 *
 * Both sides of the plugin push their timing as close to the TTL as they dare,
 * the server when it skips a write and the client when it widens its interval.
 * Either one landing late drops a present user out of the room, so they hold
 * back by the same amount, and presence-ping.js is passed this figure.
 *
 * @access private
 *
 * @since 0.6.0
 *
 * @return int Seconds.
 */
function wp_presence_ttl_margin() {
	return 15;
}

/**
 * Returns the date_gmt below which an unchanged row still has to be refreshed.
 *
 * @access private
 *
 * @since 0.7.0
 *
 * @param string $room The room identifier.
 * @return string A 'Y-m-d H:i:s' GMT timestamp, or an empty string when the
 *                write must land whatever the stored row holds.
 */
function wp_presence_refresh_cutoff( $room ) {
	$threshold = wp_presence_refresh_threshold();

	if ( $threshold <= 0 ) {
		return '';
	}

	// The network summary push hangs off wp_presence_admin_room_changed, so an
	// admin-room write that is skipped also skips the push. Loaded on multisite
	// only, hence the guard.
	if ( wp_presence_admin_room() === $room
		&& function_exists( 'wp_presence_network_summary_push_is_due' )
		&& wp_presence_network_summary_push_is_due()
	) {
		return '';
	}

	return gmdate( 'Y-m-d H:i:s', time() - $threshold );
}

/**
 * Whether a string is a real calendar date in 'Y-m-d H:i:s' format.
 *
 * Same approach as wp_resolve_post_date(): a preg_match on the literal shape
 * plus wp_checkdate() to catch a well-formed but impossible date (2026-02-30).
 *
 * @access private
 *
 * @since 0.5.0
 *
 * @param string $date_gmt The timestamp to validate.
 * @return bool Whether the timestamp is well-formed and real.
 */
function wp_presence_is_valid_date_gmt( $date_gmt ) {
	if ( ! is_string( $date_gmt )
		|| ! preg_match( '/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/', $date_gmt, $matches )
	) {
		return false;
	}

	list( , $year, $month, $day, $hour, $minute, $second ) = $matches;

	if ( (int) $hour > 23 || (int) $minute > 59 || (int) $second > 59 ) {
		return false;
	}

	return wp_checkdate( (int) $month, (int) $day, (int) $year, $date_gmt );
}

/**
 * Upserts a client's presence state in a room.
 *
 * Uses INSERT ... ON DUPLICATE KEY UPDATE for atomic upserts
 * via the UNIQUE KEY (room, client_id).
 *
 * @since 0.1.1
 * @since 0.5.0 Added the $date_gmt parameter.
 * @since 0.7.0 Added the $expires_in parameter.
 *
 * @param string      $room      The room identifier.
 * @param string      $client_id The client identifier.
 * @param array       $state     The presence state data.
 * @param int         $user_id   Optional. The user ID. Default 0.
 * @param string|null $date_gmt  Optional. The GMT timestamp to stamp the row
 *                                with, as 'Y-m-d H:i:s' (the same shape
 *                                `wp_get_presence()` returns as `date_gmt`).
 *                                For a caller relaying awareness on behalf of
 *                                other clients, so it can preserve their
 *                                timestamps instead of stamping every
 *                                relayed row with its own clock. A value in
 *                                the future is clamped to now, since
 *                                otherwise a caller could pin a row past the
 *                                TTL indefinitely. Must be a real calendar
 *                                date or the write is rejected. Default null
 *                                (now).
 * @param int|null    $expires_in Optional. Seconds from `$date_gmt` that the
 *                                row counts as present, for a caller that
 *                                knows when its clients leave and removes
 *                                their rows itself: the window is then the
 *                                backstop for a departure that never arrived,
 *                                rather than the interval it has to keep
 *                                re-stamping inside. Capped by the
 *                                `wp_presence_max_expires_in` filter, and a
 *                                value below one second is rejected. Default
 *                                null (the site TTL).
 * @return bool True on success, false on failure (including a malformed
 *              $date_gmt or an unusable $expires_in).
 */
function wp_set_presence( $room, $client_id, $state, $user_id = 0, $date_gmt = null, $expires_in = null ) {
	if ( ! wp_presence_recording_enabled() ) {
		return false;
	}

	if ( ! wp_presence_has_table() ) {
		return false;
	}

	if ( null !== $date_gmt && ! wp_presence_is_valid_date_gmt( $date_gmt ) ) {
		return false;
	}

	if ( null !== $expires_in && ( ! is_numeric( $expires_in ) || (int) $expires_in < 1 ) ) {
		return false;
	}

	$data_json = wp_json_encode( $state );
	$current   = gmdate( 'Y-m-d H:i:s' );
	$now       = null === $date_gmt ? $current : min( $date_gmt, $current );

	$expires_gmt = wp_presence_expiry_for( $now, $expires_in );

	/*
	 * An explicit timestamp is how a relay backdates a collaborator who has
	 * since left; skipping it here would leave them looking present. An
	 * explicit expiry is the same kind of deliberate write, and skipping one
	 * would drop the extension the caller asked for.
	 */
	$refresh_cutoff = null === $date_gmt && null === $expires_in ? wp_presence_refresh_cutoff( $room ) : '';

	return wp_presence_write_row( $room, $client_id, $user_id, $data_json, $now, $expires_gmt, $refresh_cutoff );
}

/**
 * Upserts a client's presence state into a room the caller has already read.
 *
 * For a caller that needs the room's rows anyway. The client's own row is
 * among them, so the rule the upsert applies in SQL (same data, stamped after
 * the refresh cutoff) can be applied here instead, and an unchanged tick costs
 * no write at all.
 *
 * @access private
 *
 * @since 0.9.0
 *
 * @param array  $rows      Rows for `$room`, as returned by wp_presence_room_rows()
 *                          with no client prefix.
 * @param string $room      The room identifier.
 * @param string $client_id The client identifier.
 * @param array  $state     The presence state data.
 * @param int    $user_id   Optional. The user ID. Default 0.
 * @return array The rows, with the client's own row as it now stands.
 */
function wp_presence_set_presence_in_rows( $rows, $room, $client_id, $state, $user_id = 0 ) {
	$own = null;

	foreach ( $rows as $index => $row ) {
		if ( $client_id === $row->client_id ) {
			$own = $index;
			break;
		}
	}

	$cutoff = wp_presence_refresh_cutoff( $room );

	if ( null !== $own
		&& '' !== $cutoff
		&& $rows[ $own ]->date_gmt >= $cutoff
		&& wp_json_encode( $rows[ $own ]->data ) === wp_json_encode( $state )
	) {
		return $rows;
	}

	if ( ! wp_set_presence( $room, $client_id, $state, $user_id ) ) {
		return $rows;
	}

	if ( null !== $own ) {
		unset( $rows[ $own ] );
	}

	// Newest first, the order wp_presence_room_rows() returns.
	array_unshift(
		$rows,
		(object) array(
			'room'      => $room,
			'client_id' => $client_id,
			'user_id'   => (string) $user_id,
			'data'      => $state,
			'date_gmt'  => gmdate( 'Y-m-d H:i:s' ),
		)
	);

	return array_values( $rows );
}

/**
 * The expiry stamped on a row, from the window its writer asked for.
 *
 * A writer that knows when its clients leave, such as one relaying a socket's
 * lifetime, asks for a long window and removes the row itself; the expiry is
 * then the backstop for a departure that never arrives rather than the signal
 * a reader waits on. Without a window the site TTL applies, which is what
 * every heartbeat-backed writer wants.
 *
 * Measured from the row's own timestamp, so a backdated row expires on the
 * writer's clock rather than this one.
 *
 * @access private
 *
 * @since 0.7.0
 *
 * @param string   $date_gmt   The row's timestamp, `Y-m-d H:i:s` in UTC.
 * @param int|null $expires_in Optional. Seconds the row stays present. Default the site TTL.
 * @return string The expiry, `Y-m-d H:i:s` in UTC.
 */
function wp_presence_expiry_for( $date_gmt, $expires_in = null ) {
	if ( null === $expires_in ) {
		$expires_in = wp_presence_get_timeout();
	}

	$expires_in = min( max( 1, (int) $expires_in ), wp_presence_max_expires_in() );

	return gmdate( 'Y-m-d H:i:s', strtotime( $date_gmt . ' UTC' ) + $expires_in );
}

/**
 * The longest window any row may carry, in seconds.
 *
 * The ceiling on the same hole the `$date_gmt` clamp closes from the other
 * end: without it a caller could keep a row indefinitely, which is what the
 * TTL exists to prevent. It is also the most a row can outlive its last
 * activity, so the privacy policy text and the personal data export report
 * this figure rather than the TTL.
 *
 * @access private
 *
 * @since 0.7.0
 *
 * @return int Seconds, at least 1.
 */
function wp_presence_max_expires_in() {
	/**
	 * Filters the longest window a writer may ask for through `$expires_in`.
	 *
	 * @since 0.7.0
	 *
	 * @param int $max Seconds. Default HOUR_IN_SECONDS.
	 */
	return max( 1, (int) apply_filters( 'wp_presence_max_expires_in', HOUR_IN_SECONDS ) );
}

/**
 * Upserts a presence row.
 *
 * Given a refresh cutoff, an unchanged row newer than it keeps its date_gmt, so
 * no row is affected and the admin-room signal stays quiet.
 *
 * @access private
 *
 * @since 0.6.0
 * @since 0.7.0 Added the `$expires_gmt` and `$refresh_cutoff` parameters.
 * @since 0.11.0 No longer checks whether recording is on, so post locks can bypass it.
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 *
 * @param string      $room           The room identifier.
 * @param string      $client_id      The client identifier.
 * @param int         $user_id        The user ID.
 * @param string      $data_json      The presence state, JSON encoded.
 * @param string      $date_gmt       The GMT timestamp to stamp the row with.
 * @param string|null $expires_gmt    Optional. When the row stops counting as present.
 *                                    Default the site TTL from `$date_gmt`.
 * @param string      $refresh_cutoff Optional. As returned by wp_presence_refresh_cutoff().
 *                                    Default empty, which always stamps $date_gmt.
 * @return bool True on success, false on failure.
 */
function wp_presence_write_row( $room, $client_id, $user_id, $data_json, $date_gmt, $expires_gmt = null, $refresh_cutoff = '' ) {
	global $wpdb;

	if ( ! wp_presence_has_table() ) {
		return false;
	}

	if ( null === $expires_gmt ) {
		$expires_gmt = wp_presence_expiry_for( $date_gmt );
	}

	$date_clause = 'date_gmt = VALUES(date_gmt), expires_gmt = VALUES(expires_gmt)';
	$args        = array( $room, $client_id, $user_id, $data_json, $date_gmt, $expires_gmt );

	if ( '' !== $refresh_cutoff ) {
		// MySQL assigns left to right, so expires_gmt is tested first, while
		// date_gmt and data still hold the values the test is asking about.
		// CASE rather than IF(), which the SQLite integration evaluates as always false.
		$taken       = 'data <> VALUES(data) OR date_gmt < %s';
		$date_clause = "expires_gmt = CASE WHEN {$taken} THEN VALUES(expires_gmt) ELSE expires_gmt END, date_gmt = CASE WHEN {$taken} THEN VALUES(date_gmt) ELSE date_gmt END";
		$args[]      = $refresh_cutoff;
		$args[]      = $refresh_cutoff;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$result = $wpdb->query(
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"INSERT INTO {$wpdb->presence} (room, client_id, user_id, data, date_gmt, expires_gmt) VALUES (%s, %s, %d, %s, %s, %s) ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), {$date_clause}, data = VALUES(data)",
			...$args
		)
	);

	if ( false === $result ) {
		wp_presence_forget_missing_table();
	}

	if ( $result > 0 ) {
		wp_cache_set_last_changed( 'presence' );

		if ( wp_presence_admin_room() === $room ) {
			wp_presence_admin_room_changed();
		}
	}

	return false !== $result;
}

/**
 * Removes a client from a room.
 *
 * @since 0.1.1
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

	if ( $result > 0 ) {
		wp_cache_set_last_changed( 'presence' );

		if ( wp_presence_admin_room() === $room ) {
			wp_presence_admin_room_changed();
		}
	}

	return false !== $result;
}

/**
 * Upserts a client's presence state and returns the room as it stands afterwards.
 *
 * For a caller that reads the room back after every write, such as an
 * awareness backend. The read is scoped to `$client_prefix`, so it returns the
 * caller's own rows without the ones other clients keep in the same room.
 *
 * @since 0.8.0
 *
 * @param string $room          The room identifier.
 * @param string $client_id     The client identifier.
 * @param array  $state         The presence state data.
 * @param int    $user_id       Optional. The user ID. Default 0.
 * @param int    $timeout       Optional. Timeout in seconds. Default null, the site's filtered TTL.
 * @param string $client_prefix Optional. Only return clients whose client_id starts with this.
 *                              Default empty.
 * @return array Array of presence entry objects, as returned by wp_get_presence().
 */
function wp_presence_exchange( $room, $client_id, $state, $user_id = 0, $timeout = null, $client_prefix = '' ) {
	wp_set_presence( $room, $client_id, $state, $user_id );

	return wp_get_presence( $room, $timeout, $client_prefix );
}

/**
 * Removes a client from a room and returns the room as it stands afterwards.
 *
 * The removal counterpart to wp_presence_exchange().
 *
 * @since 0.8.0
 *
 * @param string $room          The room identifier.
 * @param string $client_id     The client identifier.
 * @param int    $timeout       Optional. Timeout in seconds. Default null, the site's filtered TTL.
 * @param string $client_prefix Optional. Only return clients whose client_id starts with this.
 *                              Default empty.
 * @return array Array of presence entry objects, as returned by wp_get_presence().
 */
function wp_presence_leave( $room, $client_id, $timeout = null, $client_prefix = '' ) {
	wp_remove_presence( $room, $client_id );

	return wp_get_presence( $room, $timeout, $client_prefix );
}

/**
 * Removes all presence entries for a given user across all rooms.
 *
 * @since 0.1.1
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

	// Deletes across every room, so the admin room is always among them.
	if ( $result > 0 ) {
		wp_cache_set_last_changed( 'presence' );
		wp_presence_admin_room_changed();
	}

	return false !== $result;
}

/**
 * Signals that a write may have changed who's online on this site.
 *
 * Called from every path that writes the admin room: the heartbeat tick, the
 * server-side write on page render, login, logout, and the REST set/delete
 * behind the pagehide handler.
 *
 * @access private
 *
 * @since 0.2.0
 */
function wp_presence_admin_room_changed() {
	/**
	 * Fires after a write that may have changed who's online on this site.
	 *
	 * Fires when an admin-room write changes at least one row.
	 *
	 * @since 0.2.0
	 */
	do_action( 'wp_presence_admin_room_changed' );
}

/**
 * Parses a room identifier.
 *
 * Room format: `postType/{post_type}:{post_id}`
 *
 * @access private
 *
 * @since 0.1.11
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
 * Maps the capability to see which screen a user is on.
 *
 * Anyone may see their own; others need `list_users`, since where people are is user-directory information.
 *
 * @since 0.9.0
 *
 * @param string[] $caps    Primitive capabilities the user must have.
 * @param string   $cap     Capability being checked.
 * @param int      $user_id The user ID being checked.
 * @param array    $args    The user whose location is being viewed, at index 0.
 * @return string[] Primitive capabilities the user must have.
 */
function wp_presence_map_meta_cap( $caps, $cap, $user_id, $args ) {
	if ( 'view_presence_location' !== $cap ) {
		return $caps;
	}

	if ( $user_id && isset( $args[0] ) && (int) $args[0] === (int) $user_id ) {
		return array();
	}

	return array( 'list_users' );
}

/**
 * Returns the screen an entry's user is on, if the current user may see it.
 *
 * @since 0.9.0
 *
 * @param object $entry Presence entry from wp_get_presence().
 * @return string The screen ID, or an empty string.
 */
function wp_presence_get_entry_screen( $entry ) {
	if ( ! isset( $entry->data['screen'] ) || ! current_user_can( 'view_presence_location', $entry->user_id ) ) {
		return '';
	}

	return (string) $entry->data['screen'];
}

/**
 * Checks if a user can access a presence room.
 *
 * @since 0.1.1
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
		return get_post_type( $parsed['post_id'] ) === $parsed['post_type'] && user_can( $user_id, 'edit_post', $parsed['post_id'] );
	}

	// Any post type shown in the admin will do, so a role that edits only pages or a custom post type is included.
	foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $post_type ) {
		if ( user_can( $user_id, $post_type->cap->edit_posts ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Returns the presence room identifier for a given post.
 *
 * Room format: `postType/{post_type}:{post_id}`
 *
 * @since 0.1.1
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
 * @since 0.1.14
 *
 * @return string The room identifier.
 */
function wp_presence_admin_room() {
	return 'admin/online';
}

/**
 * Returns the color the block editor gives a user in a post room.
 *
 * @since 0.9.0
 *
 * @param int $user_id User ID.
 * @return string A `#RRGGBB` hex color from Gutenberg's collaborator palette.
 */
function wp_presence_get_user_color( $user_id ) {
	$palette = array( '#6F42C1', '#D94145', '#FBBF24', '#FF35EE', '#879F11', '#0F766E', '#00CFFF' );

	return $palette[ absint( $user_id ) % count( $palette ) ];
}

/**
 * Whether a presence row's user is an AI agent rather than a person.
 *
 * A row already carries `user_id`, so telling an agent from a person is a
 * lookup rather than a schema change. Nothing here decides who counts as an
 * agent: that question belongs to whichever plugin marks the `WP_User`, most
 * likely the Agent Users work from the WordPress AI team
 * (https://github.com/WordPress/ai/pull/961), reached here through
 * `wpai_is_agent_user()` when it is loaded. No default, so a site with no
 * such plugin never labels anyone as an agent.
 *
 * @since 0.9.0
 *
 * @param int $user_id The user ID.
 * @return bool Whether the user is an agent.
 */
function wp_presence_is_agent_user( $user_id ) {
	/**
	 * Filters whether a user is an AI agent, for labelling its presence rows.
	 *
	 * Every column a presence row's `user_id` might have come from — the
	 * database, JSON, this function's own callers — reads back as a string,
	 * so it is cast to an int before it ever reaches a filter, and a filter
	 * can compare against a plain int without tripping over the difference.
	 *
	 * @since 0.9.0
	 *
	 * @param bool $is_agent Whether the user is an agent. Default false.
	 * @param int  $user_id  The user ID.
	 */
	return (bool) apply_filters( 'wp_presence_is_agent_user', false, (int) $user_id );
}

/**
 * Bridges wp_presence_is_agent_user() to the Agent Users plugin, when loaded.
 *
 * A separate function, rather than an inline closure, so it shows up by name
 * in a debugger or a `has_filter()` check.
 *
 * @access private
 *
 * @since 0.9.0
 *
 * @param bool $is_agent Whether the user is already known to be an agent.
 * @param int  $user_id  The user ID.
 * @return bool Whether the user is an agent.
 */
function wp_presence_is_agent_user_via_wpai( $is_agent, $user_id ) {
	return function_exists( 'wpai_is_agent_user' ) ? wpai_is_agent_user( $user_id ) : $is_agent;
}
add_filter( 'wp_presence_is_agent_user', 'wp_presence_is_agent_user_via_wpai', 10, 2 );

/**
 * Writes an agent's presence row in the post room it just saved.
 *
 * An agent has no Heartbeat, so each of its saves writes a row that lasts
 * `wp_presence_idle_threshold()`, skipping auto-drafts, trashed posts and post
 * types without presence support, which covers revisions and autosaves.
 *
 * @access private
 *
 * @since 0.13.0
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post object.
 */
function wp_presence_on_agent_post_saved( $post_id, $post ) {
	$user_id = get_current_user_id();

	if ( ! $user_id || in_array( $post->post_status, array( 'auto-draft', 'trash' ), true ) ) {
		return;
	}

	if ( ! wp_presence_is_agent_user( $user_id ) ) {
		return;
	}

	$room = wp_presence_post_room( $post );

	if ( ! $room ) {
		return;
	}

	wp_set_presence( $room, 'agent-' . $user_id, array(), $user_id, null, wp_presence_idle_threshold() );
}

/**
 * Returns the admin room's entries, agent rows from post rooms merged in.
 *
 * An agent runs no Heartbeat and joins no `admin/online` room of its own; it
 * writes only the post room it is editing (see the "Agents" section of the
 * README). Rather than have it write a second room, Who's Online and the
 * admin bar both call this instead of `wp_get_presence( wp_presence_admin_room() )`
 * directly, so an agent's row is picked up from wherever it already is.
 *
 * An agent user with a row of its own in `admin/online` is left as that row
 * reads; only agent users absent from it are backfilled from a post room,
 * so an agent that does run Heartbeat is never listed twice.
 *
 * The post room's screen is reported as the post's own post type, which is
 * what a human editor's Heartbeat reports on `post.php` (the screen ID
 * `post.php` renders under is the post type itself), so an agent's row
 * groups with theirs under "On this page" and picks up the same post title
 * lookup the admin bar already does for that screen.
 *
 * @access private
 *
 * @since 0.9.0
 *
 * @param int|null $timeout Optional. Timeout in seconds. Default null, the site's filtered TTL.
 * @return array Array of presence entry objects, as returned by wp_get_presence().
 */
function wp_presence_admin_room_entries( $timeout = null ) {
	$entries = wp_get_presence( wp_presence_admin_room(), $timeout );

	$known_user_ids = array_map( 'intval', wp_list_pluck( $entries, 'user_id' ) );

	foreach ( wp_get_presence_by_room_prefix( 'postType/', $timeout ) as $row ) {
		$user_id = (int) $row->user_id;

		if ( in_array( $user_id, $known_user_ids, true ) || ! wp_presence_is_agent_user( $user_id ) ) {
			continue;
		}

		$parsed = wp_presence_parse_room( $row->room );

		$entries[] = (object) array(
			'room'      => $row->room,
			'client_id' => $row->client_id,
			'user_id'   => (string) $user_id,
			'date_gmt'  => $row->date_gmt,
			'data'      => array(
				'screen'   => $parsed ? $parsed['post_type'] : '',
				'post_id'  => $parsed ? $parsed['post_id'] : 0,
				'is_agent' => true,
			),
		);

		$known_user_ids[] = $user_id;
	}

	return $entries;
}

/*
 *
 * The following functions are used by the plugin's widgets, CLI, REST
 * controller, and cron jobs. They are not part of the public API contract
 * and may change or be removed without notice. Do not depend on them.
 */

/**
 * Resolves a timeout, falling back to the site's filtered TTL.
 *
 * A caller that named a window gets that window on every site. Only the
 * fallback is filtered, so a site cannot widen someone else's liveness check.
 *
 * @access private
 *
 * @since 0.1.1
 * @param int|null $timeout Timeout in seconds, or null for the site's TTL.
 * @return int The timeout in seconds.
 */
function wp_presence_get_timeout( $timeout = null ) {
	if ( null !== $timeout ) {
		return max( 0, (int) $timeout );
	}

	/**
	 * Filters the presence TTL (time-to-live) used when a caller names no window.
	 *
	 * @since 0.1.1
	 *
	 * @param int $timeout The timeout in seconds. Default WP_PRESENCE_DEFAULT_TTL (150).
	 */
	return max( 0, (int) apply_filters( 'wp_presence_default_ttl', WP_PRESENCE_DEFAULT_TTL ) );
}

/**
 * Gets all presence entries for rooms matching a prefix.
 *
 * @access private
 *
 * @since 0.1.1
 * @param string $prefix  The room prefix to match (e.g., 'postType/').
 * @param int    $timeout Optional. Timeout in seconds. Default null, the site's filtered TTL.
 * @return array Array of presence entry objects.
 */
function wp_get_presence_by_room_prefix( $prefix, $timeout = null ) {
	global $wpdb;

	if ( ! wp_presence_has_table() ) {
		return array();
	}

	$cutoff = gmdate( 'Y-m-d H:i:s' );
	$stale  = wp_presence_read_floor( $timeout );

	return wp_presence_cached_rows(
		"prefix:{$prefix}",
		$wpdb->prepare(
			"SELECT room, client_id, user_id, data, date_gmt FROM {$wpdb->presence} WHERE room LIKE %s AND expires_gmt > %s AND date_gmt > %s AND client_id NOT LIKE %s ORDER BY date_gmt DESC",
			$wpdb->esc_like( $prefix ) . '%',
			$cutoff,
			$stale,
			wp_presence_reserved_client_id_pattern()
		)
	);
}

/**
 * Returns a site-wide presence summary grouped by room prefix.
 *
 * @access private
 *
 * @since 0.1.1
 * @param int $timeout Optional. Timeout in seconds. Default null, the site's filtered TTL.
 * @return array {
 *     @type int   $total_entries Total presence entries.
 *     @type int   $total_users   Distinct user count.
 *     @type array $by_prefix     Associative array keyed by prefix, each with 'entries' and 'users'.
 * }
 */
function wp_get_presence_summary( $timeout = null ) {
	global $wpdb;

	$summary = array(
		'total_entries' => 0,
		'total_users'   => 0,
		'by_prefix'     => array(),
	);

	if ( ! wp_presence_has_table() ) {
		return $summary;
	}

	$cutoff = gmdate( 'Y-m-d H:i:s' );
	$stale  = wp_presence_read_floor( $timeout );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$room_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT room, COUNT(*) AS entries FROM {$wpdb->presence} WHERE expires_gmt > %s AND date_gmt > %s AND client_id NOT LIKE %s GROUP BY room",
			$cutoff,
			$stale,
			wp_presence_reserved_client_id_pattern()
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
			"SELECT COUNT(DISTINCT user_id) FROM {$wpdb->presence} WHERE expires_gmt > %s AND date_gmt > %s AND client_id NOT LIKE %s",
			$cutoff,
			$stale,
			wp_presence_reserved_client_id_pattern()
		)
	);

	foreach ( $rooms_by_prefix as $prefix => $rooms ) {
		$placeholders = implode( ', ', array_fill( 0, count( $rooms ), '%s' ) );

		// $placeholders holds only %s tokens generated above, so the interpolation is safe.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$summary['by_prefix'][ $prefix ]['users'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->presence} WHERE expires_gmt > %s AND date_gmt > %s AND client_id NOT LIKE %s AND room IN ( $placeholders )", array_merge( array( $cutoff, $stale, wp_presence_reserved_client_id_pattern() ), $rooms ) ) );
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
 *
 * @since 0.1.1
 */
function wp_delete_expired_presence_data() {
	global $wpdb;

	if ( ! wp_presence_has_table() ) {
		return;
	}

	$cutoff = gmdate( 'Y-m-d H:i:s' );

	/**
	 * Filters the number of expired rows deleted per pass.
	 *
	 * @since 0.1.18
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
	 * @since 0.1.18
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
				"SELECT id FROM {$wpdb->presence} WHERE expires_gmt <= %s ORDER BY id ASC LIMIT %d",
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
 * Returns all active rooms with their user counts and member lists.
 *
 * @access private
 *
 * @since 0.1.1
 *
 * @param int  $timeout        Optional. Timeout in seconds. Default null, the site's filtered TTL.
 * @param bool $hydrate_users  Optional. Whether to hydrate user data. Default true.
 * @return array Array of room objects, each with 'room', 'user_count', and optionally 'users'.
 */
function wp_get_active_rooms( $timeout = null, $hydrate_users = true ) {
	global $wpdb;

	if ( ! wp_presence_has_table() ) {
		return array();
	}

	$cutoff = gmdate( 'Y-m-d H:i:s' );
	$stale  = wp_presence_read_floor( $timeout );

	// First pass: get room names and counts only (no user IDs).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$room_stats = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT room, COUNT(DISTINCT user_id) as user_count
			FROM {$wpdb->presence}
			WHERE expires_gmt > %s AND date_gmt > %s AND client_id NOT LIKE %s
			GROUP BY room",
			$cutoff,
			$stale,
			wp_presence_reserved_client_id_pattern()
		)
	);

	if ( ! $room_stats ) {
		return array();
	}

	// Sort by user count descending, then room name.
	usort(
		$room_stats,
		function ( $a, $b ) {
			if ( $a->user_count === $b->user_count ) {
				return strcmp( $a->room, $b->room );
			}
			return $b->user_count <=> $a->user_count;
		}
	);

	$rooms = array();

	foreach ( $room_stats as $stat ) {
		$rooms[] = array(
			'room'       => $stat->room,
			'user_count' => (int) $stat->user_count,
		);
	}

	return $hydrate_users ? wp_presence_hydrate_room_users( $rooms, $timeout ) : $rooms;
}

/**
 * Hydrates user data for a list of rooms.
 *
 * @access private
 *
 * @since 0.1.23
 *
 * @param array $rooms   Array of room data (each with a 'room' key).
 * @param int   $timeout Optional. Timeout in seconds. Default null, the site's filtered TTL.
 * @return array Rooms with hydrated user arrays.
 */
function wp_presence_hydrate_room_users( $rooms, $timeout = null ) {
	global $wpdb;

	if ( empty( $rooms ) ) {
		return $rooms;
	}

	$cutoff = gmdate( 'Y-m-d H:i:s' );
	$stale  = wp_presence_read_floor( $timeout );

	// Get user IDs for all rooms in one query.
	$room_names   = wp_list_pluck( $rooms, 'room' );
	$placeholders = implode( ', ', array_fill( 0, count( $room_names ), '%s' ) );

	// Dynamic IN clause: $placeholders is "%s, %s, ..." built from count, not user data.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			"SELECT room, user_id
			FROM {$wpdb->presence}
			WHERE room IN ($placeholders) AND expires_gmt > %s AND date_gmt > %s AND client_id NOT LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			array_merge( $room_names, array( $cutoff, $stale, wp_presence_reserved_client_id_pattern() ) )
		)
	);

	// Group user IDs by room.
	$room_user_ids = array();
	$all_user_ids  = array();
	foreach ( $rows as $row ) {
		$uid                           = (int) $row->user_id;
		$room_user_ids[ $row->room ][] = $uid;
		$all_user_ids[ $uid ]          = true;
	}

	// Prime user cache.
	if ( ! empty( $all_user_ids ) ) {
		cache_users( array_keys( $all_user_ids ) );
	}

	// Hydrate each room.
	foreach ( $rooms as &$room ) {
		$users = array();

		if ( isset( $room_user_ids[ $room['room'] ] ) ) {
			foreach ( array_unique( $room_user_ids[ $room['room'] ] ) as $uid ) {
				$user = get_userdata( $uid );

				if ( ! $user ) {
					continue;
				}

				$users[] = array(
					'user_id'      => $uid,
					'display_name' => $user->display_name,
					'avatar_url'   => get_avatar_url( $uid, array( 'size' => 48 ) ),
				);
			}
		}

		$room['users']      = $users;
		$room['user_count'] = count( $users );
	}

	return $rooms;
}

/**
 * Renders the small "Agent" badge shown next to an agent's presence row.
 *
 * Shared across every surface that lists a presence row by user — Who's
 * Online, the admin bar, the Active Posts widget and the post list's
 * Editors column — so an agent reads the same way wherever it shows up.
 *
 * @access private
 *
 * @since 0.9.0
 *
 * @param int $user_id The user ID the row belongs to.
 * @return string HTML markup, or an empty string for a user who isn't an agent.
 */
function wp_presence_render_agent_badge( $user_id ) {
	if ( ! wp_presence_is_agent_user( $user_id ) ) {
		return '';
	}

	return ' <span class="presence-agent-badge">' . esc_html__( 'Agent', 'presence-api' ) . '</span>';
}

/**
 * Registers presence support for every post type edited in the admin, including ones registered later.
 *
 * Remove it with `remove_post_type_support()` on `init` at priority 11 or later.
 *
 * @since 0.1.1
 * @since 0.11.0 Covers every post type edited in the admin, not only posts and pages.
 */
function wp_presence_register_post_type_support() {
	foreach ( get_post_types( array(), 'objects' ) as $post_type => $post_type_object ) {
		wp_presence_add_post_type_support( $post_type, $post_type_object );
	}

	add_action( 'registered_post_type', 'wp_presence_add_post_type_support', 10, 2 );
	add_action( 'unregistered_post_type', 'wp_presence_forget_post_type' );
}

/**
 * Adds presence support to a post type edited in the admin.
 *
 * @access private
 *
 * @since 0.11.0
 *
 * @param string       $post_type        The post type.
 * @param WP_Post_Type $post_type_object The post type object.
 */
function wp_presence_add_post_type_support( $post_type, $post_type_object ) {
	global $_wp_presence_post_types_seen;

	// Core re-registers its types on every locale switch, which would undo a site's opt-out.
	if ( isset( $_wp_presence_post_types_seen[ $post_type ] ) ) {
		return;
	}

	$_wp_presence_post_types_seen[ $post_type ] = true;

	// Core hides templates from the admin menus, since only the Site Editor edits them.
	$site_editor = in_array( $post_type, array( 'wp_template', 'wp_template_part' ), true );

	if ( ( $post_type_object->show_ui || $site_editor ) && post_type_supports( $post_type, 'editor' ) ) {
		add_post_type_support( $post_type, 'presence' );
	}
}

/**
 * Lets an unregistered post type get presence support again if it is registered anew.
 *
 * @access private
 *
 * @since 0.11.0
 *
 * @param string $post_type The post type.
 */
function wp_presence_forget_post_type( $post_type ) {
	unset( $GLOBALS['_wp_presence_post_types_seen'][ $post_type ] );
}
