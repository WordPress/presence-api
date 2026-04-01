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
 */
function wp_presence_uninstall_site() {
	global $wpdb;

	$table = $wpdb->prefix . 'presence';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a controlled value from $wpdb->prefix.
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

	delete_option( 'wp_presence_db_version' );
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
