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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "TRUNCATE TABLE {$wpdb->presence}" );

		// The network summary is held for the life of a request, which in a
		// test run is the whole suite. Nothing in production needs this.
		if ( function_exists( 'wp_presence_flush_network_summary_cache' ) ) {
			wp_presence_flush_network_summary_cache();
		}

		parent::tear_down();
	}
}
