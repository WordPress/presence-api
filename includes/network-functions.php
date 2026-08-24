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
