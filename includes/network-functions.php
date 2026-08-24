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
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
	 * @since 0.1.25
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
	if ( ! is_multisite() || ! wp_presence_has_network_summary_table() ) {
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
	$refresh = gmdate( 'Y-m-d H:i:s', time() - wp_presence_network_summary_refresh_interval() );

	// updated_gmt is assigned before data because MySQL applies the assignments
	// in order, so reading `data` after `data = VALUES(data)` would compare the
	// new value against itself and never detect a change. The gate above already
	// rules out most unchanged writes; this keeps updated_gmt honest for the
	// refresh push, which rewrites the same data on purpose.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$result = $wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$wpdb->presence_network_summary} (blog_id, data, updated_gmt)
			VALUES (%d, %s, %s)
			ON DUPLICATE KEY UPDATE
				updated_gmt = IF( data <> VALUES(data) OR updated_gmt < %s, VALUES(updated_gmt), updated_gmt ),
				data = VALUES(data)",
			$blog_id,
			wp_presence_encode_network_summary_row( $blog_id, $user_ids ),
			$now,
			$refresh
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
 *     {
 *         "site": "example.com/shop/",
 *         "online_user_ids": [ 3, 7 ]
 *     }
 *
 * The site label is for that reader; the read path resolves the site from
 * blog_id and ignores it. Logins are left out because resolving them would
 * cost a user query per heartbeat tick.
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
			'online_user_ids' => $user_ids,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
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
}
