<?php
/**
 * Presence API uninstall handler.
 *
 * Removes the presence table and related options when the plugin is deleted.
 *
 * @package Presence_API
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Drops the presence table and deletes options for a single site.
 *
 * Terms and comments are per-site, like the table itself, so their meta is
 * cleaned up here. Users are shared across a network; that cleanup runs once,
 * below, rather than once per site.
 */
function wp_presence_uninstall_site() {
	global $wpdb;

	$table = $wpdb->prefix . 'presence';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a controlled value from $wpdb->prefix.
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

	delete_option( 'wp_presence_db_version' );
	delete_option( 'wp_presence_screen_revisions' );

	// Matches wp_presence_known_options_pages() in includes/screen-revisions.php.
	// Not required here, since this file runs standalone without the rest of
	// the plugin loaded.
	foreach ( array( 'general', 'writing', 'reading', 'discussion', 'media', 'permalink' ) as $page ) {
		delete_option( 'wp_presence_screen_rev_options_' . $page );
	}

	delete_metadata( 'term', 0, '_wp_presence_screen_rev', '', true );
	delete_metadata( 'comment', 0, '_wp_presence_screen_rev', '', true );
}

if ( is_multisite() ) {
	$sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $sites as $site_id ) {
		switch_to_blog( $site_id );
		wp_presence_uninstall_site();
		restore_current_blog();
	}
} else {
	wp_presence_uninstall_site();
}

delete_metadata( 'user', 0, '_wp_presence_screen_rev', '', true );
