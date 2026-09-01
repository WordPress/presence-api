<?php
/**
 * Presence API network functions.
 *
 * Reading who is online across a network means reading every site's presence
 * table, and there is no way to do that from one site without switching into
 * each of them. This file provisions the alternative: one shared, network-wide
 * summary table holding a row per site, so the network can be read with a
 * single query against a single table.
 *
 * Each site keeps its own row current, pushing a snapshot of who is online
 * there whenever its presence data changes, so nothing has to go looking.
 *
 * The table is registered as an ms_global_tables entry on $wpdb->base_prefix
 * (see wp_presence_register_network_summary_table() in presence-api.php), the
 * same way core registers blogs and sitemeta, so it is created once per
 * network rather than once per site.
 *
 * Reading splits in two. The snapshot is one query returning user IDs and
 * nothing else, and it is what most callers want. Resolving those IDs into
 * display names and avatar URLs is the part that costs, and no surface draws
 * every user online, so callers ask the summary for the slice they are about
 * to render rather than taking the whole network and cutting it afterwards.
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// How many people a network surface draws per site. Every one of them costs a
// user load and an avatar URL, so this is also what the read path is asked to
// resolve; the headcount beside the stack is the site's real total.
if ( ! defined( 'WP_PRESENCE_NETWORK_AVATARS' ) ) {
	define( 'WP_PRESENCE_NETWORK_AVATARS', 4 );
}

/**
 * Checks whether the network-wide presence summary table exists.
 *
 * Hits the database, so this is for provisioning only, where the point is to
 * catch a table dropped out from under the option. Read and write paths use
 * wp_presence_has_network_summary_table().
 *
 * @access private
 * @return bool
 */
function wp_presence_network_summary_table_exists() {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->presence_network_summary ) ) );

	return $found === $wpdb->presence_network_summary;
}

/**
 * Whether the network has a summary table to read from or push into.
 *
 * The network counterpart of wp_presence_has_table(): an option read rather
 * than a SHOW TABLES, since this is what the hot paths check rather than
 * something consulted once at provisioning time.
 *
 * @access private
 * @return bool
 */
function wp_presence_has_network_summary_table() {
	return (int) get_site_option( 'wp_presence_network_summary_db_version' ) === WP_PRESENCE_NETWORK_SUMMARY_DB_VERSION;
}

/**
 * Whether this network aggregates presence into the summary table.
 *
 * The network-wide half of the controller-level switch, independent of
 * wp_presence_recording_enabled(): a network can go on recording presence site
 * by site and still decline to assemble the view across all of them.
 *
 * An ms_global_tables entry pins the summary table to the global cluster on a
 * sharded network, so write concentration grows with the site count however
 * rarely any single site pushes. Defaults off above wp_is_large_network()
 * rather than having a network inherit that cost from installing the plugin.
 * That default answers write concentration rather than policy, so a network
 * with a view either way should set it deliberately.
 *
 * The read path checks this too, or a network that stopped aggregating would go
 * on drawing the rows it already had with nothing to say the feed had stopped.
 *
 * @since 0.2.0
 * @since 0.3.0 Promoted from an @access private helper to the public API.
 *
 * @return bool
 */
function wp_presence_network_aggregation_enabled() {
	// Load-bearing rather than redundant: wp_is_large_network() is undefined
	// outside multisite, and callers here do not check first.
	if ( ! is_multisite() ) {
		return false;
	}

	/**
	 * Filters whether network-wide presence aggregation runs.
	 *
	 * Gates the push and the reads behind the Network Admin screens.
	 *
	 * @since 0.2.0
	 *
	 * @param bool $enabled Default false above wp_is_large_network().
	 */
	return (bool) apply_filters( 'wp_presence_network_aggregation_enabled', ! wp_is_large_network() );
}

/**
 * Creates or updates the network-wide presence summary table if needed.
 *
 * One table for the whole network rather than one per site, since it exists
 * to be read without switching into any of them. Mirrors
 * wp_maybe_create_presence_table()'s self-healing pattern, using site options
 * instead of per-site ones since this table is provisioned once per network,
 * not once per site.
 *
 * @access private
 */
function wp_maybe_create_presence_network_summary_table() {
	if ( ! is_multisite() ) {
		return;
	}

	$provisioned = (int) get_site_option( 'wp_presence_network_summary_db_version' ) === WP_PRESENCE_NETWORK_SUMMARY_DB_VERSION;

	if ( $provisioned && ( wp_doing_ajax() || wp_presence_network_summary_table_exists() ) ) {
		return;
	}

	$lock_option = 'wp_presence_network_summary_table.lock';

	// Mirrors wp_presence_create_lock(), scoped to the network via site
	// options: add_site_option() only succeeds if the key doesn't already
	// exist, so two requests racing to provision this table on the same
	// network resolve to one winner. A stale lock (the holder never released
	// it) is stolen after a minute rather than blocking forever.
	if ( ! add_site_option( $lock_option, time() ) ) {
		$held_since = (int) get_site_option( $lock_option );
		if ( $held_since && $held_since > time() - MINUTE_IN_SECONDS ) {
			return;
		}
		update_site_option( $lock_option, time() );
	}

	global $wpdb;

	$charset_collate = $wpdb->get_charset_collate();

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	dbDelta(
		"CREATE TABLE {$wpdb->presence_network_summary} (
			blog_id bigint(20) unsigned NOT NULL,
			data longtext NOT NULL,
			updated_gmt datetime NOT NULL default '0000-00-00 00:00:00',
			PRIMARY KEY  (blog_id),
			KEY updated_gmt (updated_gmt)
		) {$charset_collate};"
	);

	update_site_option( 'wp_presence_network_summary_db_version', WP_PRESENCE_NETWORK_SUMMARY_DB_VERSION );

	delete_site_option( $lock_option );
}

/**
 * Returns how stale a site's summary row may get before a push rewrites it.
 *
 * Bounded by the read cutoff minus the idle heartbeat interval, the longest
 * gap between two pushes, so the next push always lands before the row
 * expires. Filterable downward; clamped so a filter can't widen it past that.
 *
 * @access private
 * @return int Seconds.
 */
function wp_presence_network_summary_refresh_interval() {
	$timeout = wp_presence_get_timeout( WP_PRESENCE_DEFAULT_TTL );
	$maximum = max( 1, $timeout - wp_presence_get_heartbeat_idle_interval() );

	/**
	 * Filters how stale a site's network summary row may get before a push rewrites it.
	 *
	 * Clamped to the read cutoff minus the idle heartbeat interval.
	 *
	 * @since 0.2.0
	 *
	 * @param int $interval Seconds. Default is the clamp maximum.
	 */
	$interval = (int) apply_filters( 'wp_presence_network_summary_refresh_interval', $maximum );

	return max( 1, min( $interval, $maximum ) );
}

/**
 * Pushes the current site's online-user snapshot into the network-wide
 * summary table.
 *
 * Runs on wp_presence_admin_room_changed, so an arrival or a departure reaches
 * the network within the request that caused it: logout and the pagehide delete
 * clear the site immediately instead of waiting for its entry to age out.
 *
 * A tick usually finds the same people as the tick before it, and that case
 * returns before touching the summary table. See
 * wp_presence_network_summary_needs_push() for why the test is worth making
 * here rather than in the upsert.
 *
 * The row records no per-user timestamp; its updated_gmt carries freshness for
 * every ID in it, which wp_get_presence() already TTL-filtered on this site.
 *
 * @access private
 */
function wp_presence_push_network_summary() {
	if ( ! wp_presence_network_aggregation_enabled() || ! wp_presence_has_network_summary_table() ) {
		return;
	}

	global $wpdb;

	$user_ids = array();
	foreach ( wp_get_presence( wp_presence_admin_room() ) as $entry ) {
		$user_ids[ (int) $entry->user_id ] = true;
	}

	$user_ids = array_keys( $user_ids );

	// Sorted so an unchanged room always encodes to a byte-identical string;
	// wp_get_presence() orders by date_gmt, which reshuffles as people tick.
	sort( $user_ids );

	if ( ! wp_presence_network_summary_needs_push( $user_ids ) ) {
		return;
	}

	$blog_id = get_current_blog_id();
	$now     = gmdate( 'Y-m-d H:i:s' );

	// Unconditional: the gate above already ruled out unchanged writes, and the
	// IF() this replaced was silently dropped by SQLite, freezing updated_gmt.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$result = $wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$wpdb->presence_network_summary} (blog_id, data, updated_gmt)
			VALUES (%d, %s, %s)
			ON DUPLICATE KEY UPDATE
				updated_gmt = VALUES(updated_gmt),
				data = VALUES(data)",
			$blog_id,
			wp_presence_encode_network_summary_row( $blog_id, $user_ids ),
			$now
		)
	);

	if ( false !== $result ) {
		wp_presence_record_network_summary_push( $user_ids );
	}
}

/**
 * Decides whether this site has anything to write into the summary table.
 *
 * True when the online set differs from the one this site last pushed, or when
 * that push is old enough that the row is approaching the read cutoff.
 *
 * The summary table is registered into $wpdb->ms_global_tables, which pins it
 * to the global cluster on a sharded network, so it is the one table here that
 * cannot be split. Every admin pageview writes the admin room, not just the
 * heartbeat tick, so testing for a change inside the upsert still sends a
 * statement to that cluster per pageview per site. This record lives in an
 * autoloaded option on the site's own options table instead: free to read out
 * of alloptions, and on the site's own shard when it is written.
 *
 * @access private
 * @param int[] $user_ids Online user IDs, pre-sorted.
 * @return bool Whether to push.
 */
function wp_presence_network_summary_needs_push( array $user_ids ) {
	$last = get_option( 'wp_presence_network_pushed' );

	if ( ! is_array( $last ) || ! isset( $last['users'], $last['time'] ) ) {
		return true;
	}

	if ( implode( ',', $user_ids ) !== $last['users'] ) {
		return true;
	}

	return (int) $last['time'] <= time() - wp_presence_network_summary_refresh_interval();
}

/**
 * Records what this site just pushed, for the next call to compare against.
 *
 * Autoloaded: it is read on every admin-room write and written only by a push,
 * which the gate it feeds is there to make rare.
 *
 * @access private
 * @param int[] $user_ids Online user IDs, pre-sorted.
 */
function wp_presence_record_network_summary_push( array $user_ids ) {
	update_option(
		'wp_presence_network_pushed',
		array(
			'users' => implode( ',', $user_ids ),
			'time'  => time(),
		),
		true
	);
}

/**
 * Encodes a site's online user IDs for the summary row's data column.
 *
 * Shaped to be read in a database client, the only place this column is looked
 * at directly:
 *
 *     {"site":"example.com/shop/","scheme":"https","online_user_ids":[3,7]}
 *
 * Three keys stay legible compact, and the read selects this column in full for
 * every site on each network-admin request, so pretty-printing doubled it.
 *
 * The site label is for that reader; the read path resolves the site from
 * blog_id and ignores it. Logins are left out because resolving them would
 * cost a user query per heartbeat tick.
 *
 * The scheme is recorded here because is_ssl() only describes this site's own
 * request right now; a later read runs in a different request, on whichever
 * scheme that one happens to be on.
 *
 * @access private
 * @param int   $blog_id  The site the row belongs to.
 * @param int[] $user_ids Online user IDs, pre-sorted.
 * @return string JSON for the data column.
 */
function wp_presence_encode_network_summary_row( $blog_id, array $user_ids ) {
	$site = get_site( $blog_id );

	return (string) wp_json_encode(
		array(
			'site'            => $site ? $site->domain . $site->path : '',
			'scheme'          => is_ssl() ? 'https' : 'http',
			'online_user_ids' => $user_ids,
		),
		JSON_UNESCAPED_SLASHES
	);
}

/**
 * Decodes a summary row's data column into a list of user IDs.
 *
 * @access private
 * @param string $data The row's JSON-encoded data column.
 * @return int[] User IDs, empty if the column is empty or malformed.
 */
function wp_presence_decode_network_summary_row( $data ) {
	$decoded = json_decode( $data, true );

	if ( ! isset( $decoded['online_user_ids'] ) || ! is_array( $decoded['online_user_ids'] ) ) {
		return array();
	}

	return array_map( 'intval', array_filter( $decoded['online_user_ids'], 'is_numeric' ) );
}

/**
 * Decodes a summary row's data column into the scheme it was pushed under.
 *
 * See wp_presence_encode_network_summary_row() for why this is captured at
 * push time rather than read live off the viewing request.
 *
 * @access private
 * @param string $data The row's JSON-encoded data column.
 * @return string 'http' or 'https'. Defaults to 'https' for a row pushed
 *                before this field existed, or one that does not decode.
 */
function wp_presence_decode_network_summary_scheme( $data ) {
	$decoded = json_decode( $data, true );

	return isset( $decoded['scheme'] ) && 'http' === $decoded['scheme'] ? 'http' : 'https';
}

/**
 * Drops a deleted site's row from the network summary table.
 *
 * Runs on wp_delete_site. Without it a row outlives its site indefinitely, in
 * the one table on the network that a shard router cannot split.
 *
 * @access private
 * @param WP_Site $old_site The site that was deleted.
 */
function wp_presence_on_delete_site( $old_site ) {
	global $wpdb;

	if ( ! wp_presence_has_network_summary_table() ) {
		return;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->delete(
		$wpdb->presence_network_summary,
		array( 'blog_id' => (int) $old_site->blog_id ),
		array( '%d' )
	);

	wp_presence_flush_network_summary_cache();
}

/**
 * Deletes summary rows that are already past the read cutoff.
 *
 * Runs on wp_delete_expired_presence_data. Every site prunes the whole table
 * rather than its own row: the site whose row went stale is the one whose cron
 * has stopped firing.
 *
 * Not gated on wp_presence_network_aggregation_enabled(), which stops rows
 * being written without clearing the ones already there.
 *
 * @access private
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 */
function wp_presence_delete_expired_network_summary_rows() {
	global $wpdb;

	if ( ! wp_presence_has_network_summary_table() ) {
		return;
	}

	$timeout = wp_presence_get_timeout( WP_PRESENCE_DEFAULT_TTL );
	$cutoff  = gmdate( 'Y-m-d H:i:s', time() - $timeout );

	/** This filter is documented in includes/functions.php */
	$batch_size = (int) apply_filters( 'wp_presence_cleanup_batch_size', 1000 );

	/** This filter is documented in includes/functions.php */
	$max_passes = (int) apply_filters( 'wp_presence_cleanup_max_passes', 10 );

	if ( $batch_size < 1 || $max_passes < 1 ) {
		return;
	}

	$deleted = false;

	for ( $pass = 0; $pass < $max_passes; $pass++ ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$blog_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT blog_id FROM {$wpdb->presence_network_summary} WHERE updated_gmt <= %s ORDER BY blog_id ASC LIMIT %d",
				$cutoff,
				$batch_size
			)
		);

		if ( empty( $blog_ids ) ) {
			break;
		}

		$blog_ids     = array_map( 'intval', $blog_ids );
		$placeholders = implode( ', ', array_fill( 0, count( $blog_ids ), '%d' ) );

		// The repeated cutoff keeps a row re-pushed since the select above.
		//
		// IDs are cast to integers above and passed to prepare() as %d
		// replacements, so the interpolated placeholder list is safe.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->presence_network_summary} WHERE blog_id IN ( $placeholders ) AND updated_gmt <= %s", array_merge( $blog_ids, array( $cutoff ) ) ) );

		$deleted = true;

		if ( count( $blog_ids ) < $batch_size ) {
			break;
		}
	}

	if ( $deleted ) {
		wp_presence_flush_network_summary_cache();
	}
}

/**
 * Returns the capability required to see network-wide presence data.
 *
 * @access private
 * @return string
 */
function wp_presence_network_capability() {
	/**
	 * Filters the capability required to see network-wide presence data.
	 *
	 * @param string $capability Default 'manage_network'.
	 */
	return apply_filters( 'wp_presence_network_capability', 'manage_network' );
}

/**
 * Returns the distinct user IDs online anywhere on the network.
 *
 * Reads the snapshot rather than the summary: the Users list column asks only
 * whether a given row's user is in this set, so resolving a display name and
 * an avatar URL for everyone online to answer that would be the whole cost of
 * the read for none of its output.
 *
 * @access private
 * @return int[] User IDs.
 */
function wp_presence_get_network_online_user_ids() {
	$user_ids = array();

	foreach ( wp_presence_get_network_snapshot()['sites'] as $site_user_ids ) {
		foreach ( $site_user_ids as $user_id ) {
			$user_ids[ $user_id ] = true;
		}
	}

	return array_keys( $user_ids );
}

/**
 * Returns the sites a given user is currently online on.
 *
 * The summary is keyed by site, and the Network Users list needs it keyed by
 * user, so the inversion is built once for the request and shared by every row
 * rather than rescanning the network per row.
 *
 * @access private
 * @param int $user_id The user to look up.
 * @return WP_Site[] Sites the user is online on, busiest site first.
 */
function wp_presence_get_network_sites_for_user( $user_id ) {
	$by_user = wp_presence_network_cached(
		'sites-by-user',
		static function () {
			$by_user = array();

			foreach ( wp_presence_get_network_snapshot()['sites'] as $blog_id => $site_user_ids ) {
				foreach ( $site_user_ids as $site_user_id ) {
					$by_user[ $site_user_id ][] = $blog_id;
				}
			}

			return $by_user;
		}
	);

	$blog_ids = $by_user[ (int) $user_id ] ?? array();

	if ( ! $blog_ids ) {
		return array();
	}

	// Resolved here rather than in the index above, so a network where nobody
	// is looked up pays for no sites at all. get_sites() primes the site cache,
	// so rows sharing a site resolve it once.
	$sites = get_sites(
		array(
			'site__in' => $blog_ids,
			'number'   => 0,
			'orderby'  => 'site__in',
			'archived' => 0,
			'spam'     => 0,
			'deleted'  => 0,
		)
	);

	return is_array( $sites ) ? $sites : array();
}

/**
 * Returns every site's online user IDs, without resolving any of them.
 *
 * One query against the shared wp_presence_network_summary table -- one row
 * per site, kept current by wp_presence_push_network_summary() -- rather than
 * querying every site's own presence table.
 *
 * This is the cheap half of the read path and the one most callers want.
 * Resolving a display name and an avatar URL for every user on every site is
 * what costs, and no surface renders all of them. Callers that need names and
 * avatars ask wp_presence_get_network_summary() for the slice they are about
 * to render.
 *
 * Held for the rest of the request. The push invalidates it, so a read after a
 * write on this site still sees the write.
 *
 * @access private
 * @param array $args {
 *     Optional.
 *
 *     @type int $timeout TTL in seconds. Default WP_PRESENCE_DEFAULT_TTL.
 * }
 * @return array {
 *     @type array $sites               User IDs keyed by blog_id, busiest site
 *                                       first, then by blog_id. Each list is in
 *                                       the order the site pushed it, which is
 *                                       ascending user ID.
 *     @type array $schemes             'http' or 'https' keyed by blog_id, as
 *                                       pushed from that site's own request.
 *                                       Absent when the summary is empty. Read
 *                                       only by wp_presence_hydrate_network_snapshot().
 *     @type bool  $aggregating         Whether the network aggregates at all.
 *                                       False means nothing is pushing, so the
 *                                       empty read is not a headcount.
 *     @type int   $total_sites_online
 *     @type int   $total_users_online  Summed per site, so a user online on two
 *                                       sites counts twice.
 * }
 */
function wp_presence_get_network_snapshot( array $args = array() ) {
	$timeout = wp_presence_get_timeout( $args['timeout'] ?? WP_PRESENCE_DEFAULT_TTL );

	return wp_presence_network_cached(
		'snapshot:' . $timeout,
		static function () use ( $timeout ) {
			return wp_presence_compute_network_snapshot( $timeout );
		}
	);
}

/**
 * Returns the network-wide presence summary, with names and avatars resolved.
 *
 * Bounded on purpose. Hydration is linear in the number of users asked for, so
 * a caller passes the slice it is about to render rather than taking the whole
 * network and slicing afterwards.
 *
 * @access private
 * @param array $args {
 *     Optional.
 *
 *     @type int $timeout        TTL in seconds. Default WP_PRESENCE_DEFAULT_TTL.
 *     @type int $sites          Maximum sites to resolve, busiest first. Default 0, every site.
 *     @type int $users_per_site Maximum users to resolve per site. Default 0, every user.
 *     @type int $blog_id        Resolve this site only. Default 0, no restriction.
 *     @type int $offset         Sites to skip before resolving. Default 0.
 * }
 * @return array {
 *     @type array $sites               blog_id, domain, path, url, users
 *                                       (user_id, display_name, avatar_url),
 *                                       user_count. Ordered by user_count
 *                                       descending, then by blog_id.
 *                                       user_count is the site's real total,
 *                                       which users is capped below.
 *     @type bool  $aggregating         Whether the network aggregates at all.
 *                                       False means nothing is pushing, so the
 *                                       empty read is not a headcount.
 *     @type int   $total_sites_online  Network-wide, not the resolved count.
 *     @type int   $total_users_online  Network-wide, not the resolved count.
 * }
 */
function wp_presence_get_network_summary( array $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'timeout'        => WP_PRESENCE_DEFAULT_TTL,
			'sites'          => 0,
			'users_per_site' => 0,
			'blog_id'        => 0,
			'offset'         => 0,
		)
	);

	$timeout = wp_presence_get_timeout( $args['timeout'] );
	$key     = sprintf(
		'summary:%d:%d:%d:%d:%d',
		$timeout,
		(int) $args['sites'],
		(int) $args['users_per_site'],
		(int) $args['blog_id'],
		(int) $args['offset']
	);

	return wp_presence_network_cached(
		$key,
		static function () use ( $args, $timeout ) {
			return wp_presence_hydrate_network_snapshot(
				wp_presence_get_network_snapshot( array( 'timeout' => $timeout ) ),
				(int) $args['sites'],
				(int) $args['users_per_site'],
				(int) $args['blog_id'],
				(int) $args['offset']
			);
		}
	);
}

/**
 * Returns a built network read, building it on first ask.
 *
 * Keyed by what was asked for, since a caller may want a different window or a
 * different slice than the one already built.
 *
 * The group is registered non-persistent in presence-api.php, so this holds for
 * the request and no longer: a summary is derived from rows every site on the
 * network writes, and there is no invalidation event a single site could see.
 *
 * @access private
 * @param string   $key   What is being asked for.
 * @param callable $build Builds the value when it is not already held.
 * @return mixed The held value.
 */
function wp_presence_network_cached( $key, callable $build ) {
	$group = wp_presence_network_cache_group();
	$key   = $key . ':' . wp_cache_get_last_changed( $group );
	$found = false;
	$value = wp_cache_get( $key, $group, false, $found );

	if ( $found ) {
		return $value;
	}

	$value = $build();

	wp_cache_set( $key, $value, $group );

	return $value;
}

/**
 * Returns the object cache group the built network reads live in.
 *
 * @access private
 * @return string Cache group.
 */
function wp_presence_network_cache_group() {
	return 'presence_network';
}

/**
 * Drops the request's built network reads.
 *
 * Runs on wp_presence_admin_room_changed, alongside the push that gave this
 * site a new row. Bumps last_changed rather than deleting keys, the same way
 * core invalidates its own derived query caches, so every slice and window
 * built from the old rows is left behind at once.
 *
 * @access private
 */
function wp_presence_flush_network_summary_cache() {
	wp_cache_set_last_changed( wp_presence_network_cache_group() );
}

/**
 * Drops user IDs that no longer name an account from a snapshot.
 *
 * A summary row stores user IDs and nothing else, and no hook clears a
 * deleted user's presence from the other sites they were online on, so a row
 * outlives the account until that site's next push. Counting one would
 * overstate the network and rendering one would give an empty avatar.
 *
 * IDs only, in one query for the whole snapshot. Loading the accounts is the
 * expensive half and is left to wp_presence_hydrate_network_snapshot(), which
 * runs against a handful of users rather than every user online.
 *
 * @since 0.2.0
 *
 * @param array $by_site Blog ID to array of user IDs.
 * @return array The same map with unknown users, and any site left empty by
 *               their removal, dropped.
 */
function wp_presence_filter_network_snapshot_users( array $by_site ) {
	$all_ids = array();

	foreach ( $by_site as $user_ids ) {
		foreach ( $user_ids as $user_id ) {
			$all_ids[ $user_id ] = true;
		}
	}

	$existing = array_flip(
		array_map(
			'intval',
			get_users(
				array(
					'include' => array_keys( $all_ids ),
					'fields'  => 'ID',
					'blog_id' => 0,
				)
			)
		)
	);

	if ( count( $existing ) === count( $all_ids ) ) {
		return $by_site;
	}

	$filtered = array();

	foreach ( $by_site as $blog_id => $user_ids ) {
		$kept = array_values(
			array_filter(
				$user_ids,
				static function ( $user_id ) use ( $existing ) {
					return isset( $existing[ $user_id ] );
				}
			)
		);

		if ( $kept ) {
			$filtered[ $blog_id ] = $kept;
		}
	}

	return $filtered;
}

/**
 * Reads every site's pushed row from the network summary table.
 *
 * A single query against one small, indexed table, not one query per site.
 * $timeout applies once, against each row's updated_gmt; there is no second
 * per-user cutoff to disagree with it, since a row's IDs are what that site's
 * TTL-filtered read returned when it pushed, and a membership change pushes
 * again immediately.
 *
 * Sites and users are checked against the network here, while the result is
 * still a list of IDs, so a stale row is dropped before anything gets loaded.
 *
 * A network that does not aggregate reads as empty rather than as whatever its
 * rows last said, since nothing is keeping them current. That read carries
 * aggregating => false, so callers do not report it as a quiet network.
 *
 * @access private
 * @param int $timeout TTL in seconds; rows untouched longer than this are skipped.
 * @return array See wp_presence_get_network_snapshot().
 */
function wp_presence_compute_network_snapshot( $timeout ) {
	global $wpdb;

	if ( ! wp_presence_network_aggregation_enabled() || ! wp_presence_has_network_summary_table() ) {
		// Only the switch means "not aggregating". A network still waiting on
		// its table aggregates and has nothing yet.
		return wp_presence_empty_network_summary( wp_presence_network_aggregation_enabled() );
	}

	$cutoff = gmdate( 'Y-m-d H:i:s', time() - $timeout );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT blog_id, data FROM {$wpdb->presence_network_summary} WHERE updated_gmt > %s",
			$cutoff
		)
	);

	if ( ! $rows ) {
		return wp_presence_empty_network_summary();
	}

	$by_site = array();
	$schemes = array();

	foreach ( $rows as $row ) {
		$entries = wp_presence_decode_network_summary_row( $row->data );

		if ( $entries ) {
			$blog_id             = (int) $row->blog_id;
			$by_site[ $blog_id ] = $entries;
			$schemes[ $blog_id ] = wp_presence_decode_network_summary_scheme( $row->data );
		}
	}

	if ( ! $by_site ) {
		return wp_presence_empty_network_summary();
	}

	// IDs only. A row outlives the site it belongs to until cleanup runs, and
	// counting one would overstate the network. Resolving the domain and path
	// is left to whichever sites a caller goes on to render.
	$live = get_sites(
		array(
			'site__in' => array_keys( $by_site ),
			'fields'   => 'ids',
			'number'   => 0,
			'archived' => 0,
			'spam'     => 0,
			'deleted'  => 0,
		)
	);

	if ( ! is_array( $live ) ) {
		$live = array();
	}

	$by_site = array_intersect_key( $by_site, array_flip( array_map( 'intval', $live ) ) );

	if ( ! $by_site ) {
		return wp_presence_empty_network_summary();
	}

	$by_site = wp_presence_filter_network_snapshot_users( $by_site );

	if ( ! $by_site ) {
		return wp_presence_empty_network_summary();
	}

	// Busiest first, which is the order every caller renders in, then by
	// blog_id so a tie holds still from one tick to the next. Ordering on
	// domain would mean resolving every site to place five of them.
	uksort(
		$by_site,
		static function ( $a, $b ) use ( $by_site ) {
			$by_count = count( $by_site[ $b ] ) <=> count( $by_site[ $a ] );

			return 0 !== $by_count ? $by_count : $a <=> $b;
		}
	);

	$total_users = 0;

	foreach ( $by_site as $entries ) {
		$total_users += count( $entries );
	}

	return array(
		'sites'              => $by_site,
		'schemes'            => array_intersect_key( $schemes, $by_site ),
		'aggregating'        => true,
		'total_sites_online' => count( $by_site ),
		'total_users_online' => $total_users,
	);
}

/**
 * Resolves names, avatars, and site details for a slice of a snapshot.
 *
 * @access private
 * @param array $snapshot       Return value of wp_presence_get_network_snapshot().
 * @param int   $max_sites      Maximum sites to resolve. 0 for every site.
 * @param int   $users_per_site Maximum users to resolve per site. 0 for every user.
 * @param int   $blog_id        Resolve this site only. 0 for no restriction.
 * @param int   $offset         Sites to skip before resolving. Default 0.
 * @return array See wp_presence_get_network_summary().
 */
function wp_presence_hydrate_network_snapshot( array $snapshot, $max_sites, $users_per_site, $blog_id, $offset = 0 ) {
	$by_site = $snapshot['sites'];
	// Absent from the empty shape wp_presence_empty_network_summary() returns.
	$schemes = $snapshot['schemes'] ?? array();

	if ( $blog_id ) {
		$by_site = isset( $by_site[ $blog_id ] ) ? array( $blog_id => $by_site[ $blog_id ] ) : array();
	}

	if ( $offset > 0 || $max_sites > 0 ) {
		$by_site = array_slice( $by_site, $offset, $max_sites > 0 ? $max_sites : null, true );
	}

	if ( ! $by_site ) {
		return array(
			'sites'              => array(),
			'aggregating'        => $snapshot['aggregating'],
			'total_sites_online' => $snapshot['total_sites_online'],
			'total_users_online' => $snapshot['total_users_online'],
		);
	}

	// Capped before resolving, not after. The cap is what makes this bounded,
	// and the users it keeps are the lowest IDs on the site, which is stable
	// across ticks in a way an alphabetical cut would not be.
	$shown  = array();
	$needed = array();

	foreach ( $by_site as $site_id => $entries ) {
		$shown[ $site_id ] = $users_per_site > 0 ? array_slice( $entries, 0, $users_per_site ) : $entries;

		foreach ( $shown[ $site_id ] as $user_id ) {
			$needed[ $user_id ] = true;
		}
	}

	cache_users( array_keys( $needed ) );

	// One site query for the slice. get_site() per row is one query each on a
	// cold cache, which is the shape this table exists to avoid.
	$found_sites = array();

	$found = get_sites(
		array(
			'site__in' => array_keys( $by_site ),
			'number'   => 0,
			'archived' => 0,
			'spam'     => 0,
			'deleted'  => 0,
		)
	);

	if ( ! is_array( $found ) ) {
		$found = array();
	}

	foreach ( $found as $found_site ) {
		$found_sites[ (int) $found_site->blog_id ] = $found_site;
	}

	$sites = array();

	foreach ( $shown as $site_id => $entries ) {
		$site = $found_sites[ $site_id ] ?? null;

		if ( ! $site ) {
			continue;
		}

		$hydrated = array();

		foreach ( $entries as $user_id ) {
			$user = get_userdata( $user_id );

			if ( ! $user ) {
				continue;
			}

			$hydrated[] = array(
				'user_id'      => $user_id,
				'display_name' => $user->display_name,
				'avatar_url'   => get_avatar_url( $user->ID, array( 'size' => 32 ) ),
			);
		}

		if ( ! $hydrated ) {
			continue;
		}

		// No per-user timestamp to order by, so order by name.
		usort(
			$hydrated,
			static function ( $a, $b ) {
				return strcmp( $a['display_name'], $b['display_name'] );
			}
		);

		$sites[] = array(
			'blog_id'    => $site_id,
			'domain'     => $site->domain,
			'path'       => $site->path,
			// get_site_url()/get_blog_option() switch blogs on every call; the raw
			// WP_Site fields don't, at the cost of not reflecting a mapped domain.
			// Scheme comes from $schemes, not is_ssl(), for the same reason.
			'url'        => ( ( $schemes[ $site_id ] ?? 'https' ) === 'http' ? 'http://' : 'https://' ) . $site->domain . $site->path,
			'users'      => $hydrated,
			// The site's real total, which users is capped below.
			'user_count' => count( $by_site[ $site_id ] ),
		);
	}

	return array(
		'sites'              => $sites,
		'aggregating'        => $snapshot['aggregating'],
		'total_sites_online' => $snapshot['total_sites_online'],
		'total_users_online' => $snapshot['total_users_online'],
	);
}

/**
 * Returns the shape wp_presence_get_network_summary() returns when nobody is online.
 *
 * @access private
 * @param bool $aggregating Optional. Default true, a network that aggregates
 *                          and is simply quiet.
 * @return array See wp_presence_get_network_summary().
 */
function wp_presence_empty_network_summary( $aggregating = true ) {
	return array(
		'sites'              => array(),
		'aggregating'        => (bool) $aggregating,
		'total_sites_online' => 0,
		'total_users_online' => 0,
	);
}
