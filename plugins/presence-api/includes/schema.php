<?php
/**
 * Presence API: table registration and provisioning.
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the presence table name on $wpdb.
 *
 * @since 0.1.1
 */
function wp_presence_register_table() {
	global $wpdb;
	$wpdb->presence = $wpdb->prefix . 'presence';

	// Runs at require time and again on init, so the append has to be idempotent.
	if ( ! in_array( 'presence', $wpdb->tables, true ) ) {
		$wpdb->tables[] = 'presence';
	}
}

/**
 * Registers the network-wide presence summary table name on $wpdb.
 *
 * One table for the whole network rather than one per site: registered as an
 * ms_global_tables entry, using base_prefix, the same way core registers
 * blogs/site/sitemeta.
 *
 * @since 0.2.0
 */
function wp_presence_register_network_summary_table() {
	if ( ! is_multisite() ) {
		return;
	}
	global $wpdb;
	$wpdb->presence_network_summary = $wpdb->base_prefix . 'presence_network_summary';

	if ( ! in_array( 'presence_network_summary', $wpdb->ms_global_tables, true ) ) {
		$wpdb->ms_global_tables[] = 'presence_network_summary';
	}
}

/**
 * Checks the database directly for the presence table.
 *
 * Only for activation and failed writes. Request paths use wp_presence_has_table().
 *
 * @access private
 *
 * @since 0.1.17
 * @return bool Whether the table exists on the current site.
 */
function wp_presence_table_exists() {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->presence ) ) );

	return $found === $wpdb->presence;
}

/**
 * Clears the version option when the presence table is missing.
 *
 * Feature plugin shim, so the next admin or WP-CLI request rebuilds the table.
 *
 * @access private
 *
 * @since 0.12.2
 */
function wp_presence_forget_missing_table() {
	if ( ! wp_presence_table_exists() ) {
		delete_option( 'wp_presence_db_version' );
	}
}

/**
 * Creates or updates the presence table if needed.
 *
 * Feature plugin shim. In core, dbDelta() would create this table from the
 * schema in wp-admin/includes/schema.php during the database upgrade routine.
 *
 * Trusts the version option the way core trusts db_version. A table dropped
 * while the option survives is rebuilt after a write fails, see
 * wp_presence_forget_missing_table(), or when the plugin is reactivated.
 *
 * @access private
 *
 * @since 0.1.1
 */
function wp_maybe_create_presence_table() {
	add_option( 'wp_presence_recording', '1', '', true );

	if ( (int) get_option( 'wp_presence_db_version' ) === WP_PRESENCE_DB_VERSION ) {
		return;
	}

	// admin_init and cli_init have no confirmation step to serialize them the
	// way wp-admin/upgrade.php does for core, so two requests can arrive here at
	// once during a version bump. Whoever loses the race returns and lets the
	// winner finish; the next request repairs anything left over.
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

	// One table's dbDelta() takes seconds, so core's default hour would block provisioning too long after a lost request.
	if ( ! WP_Upgrader::create_lock( 'wp_presence_table', MINUTE_IN_SECONDS ) ) {
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
			session_hash varchar(16) NOT NULL default '',
			data longtext NOT NULL,
			date_gmt datetime NOT NULL default '0000-00-00 00:00:00',
			expires_gmt datetime NOT NULL default '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY room_client (room, client_id),
			KEY date_gmt (date_gmt),
			KEY expires_gmt (expires_gmt),
			KEY user_id (user_id),
			KEY room_date (room(40), date_gmt),
			KEY room_expires (room(40), expires_gmt)
		) {$charset_collate};"
	);

	// Autoloaded explicitly: wp_presence_has_table() reads this on every request
	// that touches presence, so it must not cost a query.
	update_option( 'wp_presence_db_version', WP_PRESENCE_DB_VERSION, true );

	WP_Upgrader::release_lock( 'wp_presence_table' );
}

/**
 * Checks whether the network-wide presence summary table exists.
 *
 * Hits the database, so this is for explicit checks and tests only. Read and
 * write paths use wp_presence_has_network_summary_table().
 *
 * @access private
 *
 * @since 0.2.0
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
 * to be read without switching into any of them. Uses site options since this
 * table is provisioned once per network, not once per site.
 *
 * @access private
 *
 * @since 0.2.0
 */
function wp_maybe_create_presence_network_summary_table() {
	if ( ! is_multisite() ) {
		return;
	}

	if ( wp_presence_has_network_summary_table() ) {
		return;
	}

	$lock_option = 'wp_presence_network_summary_table.lock';

	// Mirrors WP_Upgrader::create_lock(), scoped to the network via site
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
