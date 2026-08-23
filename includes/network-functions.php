<?php
/**
 * Presence API network functions.
 *
 * Aggregates presence across every site on the network without switching
 * blogs or fanning a query out across every site's own presence table. Each
 * site pushes a snapshot of who's currently online there into one shared,
 * network-wide table (wp_presence_network_summary) whenever its own presence
 * data changes; reading the network summary is a single query against that
 * table, not a query per site.
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
 * than a SHOW TABLES, since this runs on every push.
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
 * Runs on wp_presence_admin_room_changed, so every admin-room write pushes,
 * not just the heartbeat tick: logout and the pagehide delete clear the site
 * immediately instead of waiting for its entry to age out.
 *
 * A tick usually finds the same people as the tick before it, so the upsert
 * rewrites the row only when the user set changes or the row is older than
 * wp_presence_network_summary_refresh_interval(). Both tests run in SQL, so an
 * unchanged push resolves to zero changed rows.
 *
 * The row records no per-user timestamp for that reason; its updated_gmt
 * carries freshness for every ID in it, which wp_get_presence() already
 * TTL-filtered on this site.
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

	$blog_id = get_current_blog_id();
	$now     = gmdate( 'Y-m-d H:i:s' );
	$refresh = gmdate( 'Y-m-d H:i:s', time() - wp_presence_network_summary_refresh_interval() );

	// updated_gmt is assigned before data because MySQL applies the assignments
	// in order, so reading `data` after `data = VALUES(data)` would compare the
	// new value against itself and never detect a change.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
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
 * Returns the network-wide presence summary.
 *
 * Reads the shared wp_presence_network_summary table -- one row per site,
 * kept current by wp_presence_push_network_summary() -- rather than querying
 * every site's own presence table, so this is a single query regardless of
 * how many sites are on the network.
 *
 * Held for the rest of the request. List table columns call this once per row
 * and the build is not free: it decodes every site's row, then resolves a
 * display name and an avatar URL for every user on every site. The push
 * invalidates it, so a read after a write on this site still sees the write.
 *
 * @access private
 * @param array $args {
 *     Optional.
 *
 *     @type int $timeout TTL in seconds. Default WP_PRESENCE_DEFAULT_TTL.
 * }
 * @return array {
 *     @type array $sites               One entry per site with a live user:
 *                                       blog_id, domain, path, url, users
 *                                       (user_id, display_name, avatar_url),
 *                                       user_count. Ordered by user_count
 *                                       descending, then by domain and path.
 *     @type int   $total_sites_online
 *     @type int   $total_users_online
 * }
 */
function wp_presence_get_network_summary( array $args = array() ) {
	$timeout = wp_presence_get_timeout( $args['timeout'] ?? WP_PRESENCE_DEFAULT_TTL );
	$cached  = wp_presence_network_summary_cache();

	if ( isset( $cached[ $timeout ] ) ) {
		return $cached[ $timeout ];
	}

	$summary = wp_presence_compute_network_summary( $timeout );

	$cached[ $timeout ] = $summary;
	wp_presence_network_summary_cache( $cached );

	return $summary;
}

/**
 * Reads, and optionally replaces, the request's built network summaries.
 *
 * Keyed by timeout, since a caller may ask for a window other than the default.
 *
 * @access private
 * @param array|null $replace Optional. Summaries to store, keyed by timeout.
 * @return array Summaries keyed by timeout.
 */
function wp_presence_network_summary_cache( array $replace = null ) {
	static $cache = array();

	if ( null !== $replace ) {
		$cache = $replace;
	}

	return $cache;
}

/**
 * Drops the request's built network summaries.
 *
 * Runs on wp_presence_admin_room_changed, alongside the push that gave this
 * site a new row.
 *
 * @access private
 */
function wp_presence_flush_network_summary_cache() {
	wp_presence_network_summary_cache( array() );
}

/**
 * Reads every site's pushed snapshot from the network summary table.
 *
 * A single query against one small, indexed table, not one query per site.
 * $timeout applies once, against each row's updated_gmt; there is no second
 * per-user cutoff to disagree with it, since a row's IDs are what that site's
 * TTL-filtered read returned when it pushed, and a membership change pushes
 * again immediately.
 *
 * @access private
 * @param int $timeout TTL in seconds; rows untouched longer than this are skipped.
 * @return array See wp_presence_get_network_summary().
 */
function wp_presence_compute_network_summary( $timeout ) {
	global $wpdb;

	if ( ! wp_presence_has_network_summary_table() ) {
		return wp_presence_empty_network_summary();
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

	$all_user_ids = array();
	$by_site      = array();

	foreach ( $rows as $row ) {
		$entries = wp_presence_decode_network_summary_row( $row->data );

		if ( ! $entries ) {
			continue;
		}

		$by_site[ (int) $row->blog_id ] = $entries;

		foreach ( $entries as $user_id ) {
			$all_user_ids[ $user_id ] = true;
		}
	}

	if ( ! $by_site ) {
		return wp_presence_empty_network_summary();
	}

	cache_users( array_keys( $all_user_ids ) );

	// One site query for the whole set. get_site() per row is one query each
	// on a cold cache, which is the shape this table exists to avoid.
	$found_sites = array();
	$found       = get_sites(
		array(
			'site__in' => array_keys( $by_site ),
			'number'   => 0,
		)
	);
	foreach ( $found as $found_site ) {
		$found_sites[ (int) $found_site->blog_id ] = $found_site;
	}

	$sites       = array();
	$total_users = 0;

	foreach ( $by_site as $blog_id => $entries ) {
		$site = $found_sites[ $blog_id ] ?? null;

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

		$total_users += count( $hydrated );

		$sites[] = array(
			'blog_id'    => $blog_id,
			'domain'     => $site->domain,
			'path'       => $site->path,
			// get_site_url()/get_blog_option() switch blogs on every call; the raw
			// WP_Site fields don't, at the cost of not reflecting a mapped domain.
			'url'        => ( is_ssl() ? 'https://' : 'http://' ) . $site->domain . $site->path,
			'users'      => $hydrated,
			'user_count' => count( $hydrated ),
		);
	}

	usort(
		$sites,
		static function ( $a, $b ) {
			if ( $a['user_count'] === $b['user_count'] ) {
				return strcmp( $a['domain'] . $a['path'], $b['domain'] . $b['path'] );
			}
			return $b['user_count'] <=> $a['user_count'];
		}
	);

	return array(
		'sites'              => $sites,
		'total_sites_online' => count( $sites ),
		'total_users_online' => $total_users,
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
 * Returns the shape wp_presence_get_network_summary() returns when nobody is online.
 *
 * @access private
 * @return array See wp_presence_get_network_summary().
 */
function wp_presence_empty_network_summary() {
	return array(
		'sites'              => array(),
		'total_sites_online' => 0,
		'total_users_online' => 0,
	);
}
