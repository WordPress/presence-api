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
		parent::tear_down();
	}
}
