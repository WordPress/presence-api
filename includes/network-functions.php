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
 * Pushes the current site's online-user snapshot into the network-wide
 * summary table.
 *
 * Called after a presence write on the current site (see
 * wp_presence_admin_heartbeat_received()) rather than on a schedule: presence
 * writes already happen on every heartbeat tick from every open admin tab, so
 * this rides along with work that's already happening instead of adding a
 * separate write path. wp_get_presence() is a query against this one site's
 * own table, already TTL-filtered and already indexed by room -- the same
 * query the single-site Who's Online widget already runs on every tick.
 *
 * A site with nobody left online still pushes an empty snapshot rather than
 * leaving the last non-empty one in place; the alternative is waiting for
 * updated_gmt to age out of the read-time cutoff, which would show a site as
 * having online users for up to WP_PRESENCE_DEFAULT_TTL after the last of
 * them left.
 *
 * @access private
 */
function wp_presence_push_network_summary() {
	if ( ! is_multisite() || ! wp_presence_network_summary_table_exists() ) {
		return;
	}

	global $wpdb;

	$entries = wp_get_presence( wp_presence_admin_room() );

	$data = array();
	foreach ( $entries as $entry ) {
		$data[] = array(
			'user_id'  => (int) $entry->user_id,
			'date_gmt' => $entry->date_gmt,
		);
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$wpdb->presence_network_summary} (blog_id, data, updated_gmt)
			VALUES (%d, %s, %s)
			ON DUPLICATE KEY UPDATE data = VALUES(data), updated_gmt = VALUES(updated_gmt)",
			get_current_blog_id(),
			wp_json_encode( $data ),
			gmdate( 'Y-m-d H:i:s' )
		)
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
 * Returns the distinct user IDs online anywhere on the network.
 *
 * @access private
 * @return int[] User IDs.
 */
function wp_presence_get_network_online_user_ids() {
	$summary  = wp_presence_get_network_summary();
	$user_ids = array();

	foreach ( $summary['sites'] as $site ) {
		foreach ( $site['users'] as $user ) {
			$user_ids[ $user['user_id'] ] = true;
		}
	}

	return array_keys( $user_ids );
}

/**
 * Returns the network-wide presence summary.
 *
 * Reads the shared wp_presence_network_summary table -- one row per site,
 * kept current by wp_presence_push_network_summary() -- rather than querying
 * every site's own presence table, so this is a single query regardless of
 * how many sites are on the network.
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
 *                                       (user_id, display_name, avatar_url,
 *                                       date_gmt), user_count.
 *     @type int   $total_sites_online
 *     @type int   $total_users_online
 * }
 */
function wp_presence_get_network_summary( array $args = array() ) {
	$timeout = wp_presence_get_timeout( $args['timeout'] ?? WP_PRESENCE_DEFAULT_TTL );
	$raw     = wp_presence_compute_network_summary( $timeout );

	return wp_presence_filter_network_summary( $raw, $timeout );
}

/**
 * Narrows a raw network summary down to entries still fresh within $timeout.
 *
 * A site's row can only get more stale between pushes, never fresher, so
 * this only ever needs to remove entries -- it can't miss one that's about
 * to become fresh.
 *
 * @access private
 * @param array $raw     Unfiltered summary from wp_presence_compute_network_summary().
 * @param int   $timeout TTL in seconds; entries older than this are dropped.
 * @return array See wp_presence_get_network_summary().
 */
function wp_presence_filter_network_summary( array $raw, $timeout ) {
	$cutoff      = gmdate( 'Y-m-d H:i:s', time() - $timeout );
	$sites       = array();
	$total_users = 0;

	foreach ( $raw['sites'] as $site ) {
		$fresh = array_values(
			array_filter(
				$site['users'],
				static function ( $user ) use ( $cutoff ) {
					return $user['date_gmt'] > $cutoff;
				}
			)
		);

		if ( ! $fresh ) {
			continue;
		}

		$site['users']      = $fresh;
		$site['user_count'] = count( $fresh );
		$sites[]            = $site;
		$total_users       += count( $fresh );
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
 * Reads every site's pushed snapshot from the network summary table.
 *
 * A single query against one small, indexed table, not one query per site.
 * $timeout bounds it at the SQL level too: a row's updated_gmt is always at
 * least as recent as any user_id inside it (it's the moment that snapshot was
 * taken), so a row untouched for longer than $timeout can only contain users
 * who'd fail wp_presence_filter_network_summary()'s per-user check anyway --
 * excluding it here is a query-size optimization, not a separate cutoff that
 * could disagree with the per-user filter.
 *
 * @access private
 * @param int $timeout TTL in seconds; rows untouched longer than this are skipped.
 * @return array Unfiltered summary; pass to wp_presence_filter_network_summary() before use.
 */
function wp_presence_compute_network_summary( $timeout ) {
	global $wpdb;

	if ( ! wp_presence_network_summary_table_exists() ) {
		return array( 'sites' => array() );
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
		return array( 'sites' => array() );
	}

	$all_user_ids = array();
	$by_site      = array();

	foreach ( $rows as $row ) {
		$entries = json_decode( $row->data, true );

		if ( ! is_array( $entries ) || ! $entries ) {
			continue;
		}

		$by_site[ (int) $row->blog_id ] = $entries;

		foreach ( $entries as $entry ) {
			$all_user_ids[ (int) $entry['user_id'] ] = true;
		}
	}

	if ( ! $by_site ) {
		return array( 'sites' => array() );
	}

	cache_users( array_keys( $all_user_ids ) );

	$sites = array();

	foreach ( $by_site as $blog_id => $entries ) {
		$site = get_site( $blog_id );

		if ( ! $site ) {
			continue;
		}

		$hydrated = array();

		foreach ( $entries as $entry ) {
			$user = get_userdata( (int) $entry['user_id'] );

			if ( ! $user ) {
				continue;
			}

			$hydrated[] = array(
				'user_id'      => (int) $entry['user_id'],
				'display_name' => $user->display_name,
				'avatar_url'   => get_avatar_url( $user->ID, array( 'size' => 32 ) ),
				'date_gmt'     => $entry['date_gmt'],
			);
		}

		if ( ! $hydrated ) {
			continue;
		}

		usort(
			$hydrated,
			static function ( $a, $b ) {
				return strcmp( $b['date_gmt'], $a['date_gmt'] );
			}
		);

		// user_count and site order are meaningless until
		// wp_presence_filter_network_summary() narrows $hydrated down to
		// what's actually still fresh; left out here rather than computed
		// twice.
		$sites[] = array(
			'blog_id' => $blog_id,
			'domain'  => $site->domain,
			'path'    => $site->path,
			// get_site_url()/get_blog_option() switch blogs on every call; the raw
			// WP_Site fields don't, at the cost of not reflecting a mapped domain.
			'url'     => ( is_ssl() ? 'https://' : 'http://' ) . $site->domain . $site->path,
			'users'   => $hydrated,
		);
	}

	return array( 'sites' => $sites );
}
