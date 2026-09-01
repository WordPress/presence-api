<?php
/**
 * Shared base test case for the Presence API test suite.
 *
 * @package Presence_API
 */

/**
 * Base test case that truncates the presence table after every test.
 */
abstract class WP_Presence_UnitTestCase extends WP_UnitTestCase {

	public function tear_down() {
		global $wpdb;
		// The TRUNCATE below commits, so an option a test wrote outlives the
		// rollback. Cleared first, where the same commit carries the delete;
		// after it the statement lands in a fresh transaction and is rolled back.
		delete_option( 'wp_presence_recording' );
		delete_site_option( 'wp_presence_network_recording' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "TRUNCATE TABLE {$wpdb->presence}" );

		// The network summary is held for the life of a request, which in a
		// test run is the whole suite. Nothing in production needs this.
		if ( function_exists( 'wp_presence_flush_network_summary_cache' ) ) {
			wp_presence_flush_network_summary_cache();
		}

		parent::tear_down();
	}

	/**
	 * Returns every live presence entry for one user, across all rooms.
	 *
	 * Reads the table directly. Nothing in the plugin needs a per-user view, so
	 * this stays a test affordance rather than an API function kept alive only
	 * by the assertions below it.
	 *
	 * @param int $user_id The user whose entries to read.
	 * @param int $timeout Optional. TTL in seconds. Default WP_PRESENCE_DEFAULT_TTL.
	 * @return object[] Entries with room, client_id, user_id, data (array), date_gmt.
	 */
	protected function presence_for_user( $user_id, $timeout = WP_PRESENCE_DEFAULT_TTL ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT room, client_id, user_id, data, date_gmt FROM {$wpdb->presence} WHERE user_id = %d AND date_gmt > %s ORDER BY date_gmt DESC",
				$user_id,
				gmdate( 'Y-m-d H:i:s', time() - wp_presence_get_timeout( $timeout ) )
			)
		);

		foreach ( $rows as $row ) {
			$decoded   = json_decode( $row->data, true );
			$row->data = is_array( $decoded ) ? $decoded : array();
		}

		return $rows;
	}
}
