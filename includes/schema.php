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
 * Feature plugin shim. In core, dbDelta() would create this table from the
 * schema in wp-admin/includes/schema.php during the database upgrade routine.
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
	add_option( 'wp_presence_recording', '1', '', true );

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

	wp_presence_release_lock( 'wp_presence_table' );
}
