<?php
/**
 * Tests for how the network-wide presence summary table is provisioned.
 *
 * One table for the whole network, so unlike the per-site presence table it is
 * created once at network activation and never again. That makes the recovery
 * paths -- a dropped table, a lock nobody released -- the only way it ever gets
 * rebuilt, which is what this file pins down.
 *
 * @package Presence_API
 *
 * @group presence
 * @group ms-required
 */
class WP_Test_Network_Summary_Table_Creation extends WP_Presence_UnitTestCase {

	/**
	 * The site option backing the provisioning lock.
	 */
	private const LOCK_OPTION = 'wp_presence_network_summary_table.lock';

	/**
	 * The site option recording the provisioned schema version.
	 */
	private const VERSION_OPTION = 'wp_presence_network_summary_db_version';

	public function set_up() {
		parent::set_up();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}

		// WP_UnitTestCase rewrites DDL to CREATE/DROP TEMPORARY TABLE, which would
		// make dropping the real summary table a silent no-op. These tests need
		// the table to genuinely disappear, so they opt out.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		// DDL commits the surrounding transaction, so a lock left behind by an
		// earlier run survives into this one and would block every test that
		// provisions. Start from a known-free lock.
		delete_site_option( self::LOCK_OPTION );
	}

	public function tear_down() {
		// DDL commits the surrounding transaction, so put the table back by hand
		// for whatever runs next. dbDelta is idempotent, so this is safe even
		// when the test never dropped it.
		delete_site_option( self::LOCK_OPTION );
		delete_site_option( self::VERSION_OPTION );
		wp_maybe_create_presence_network_summary_table();

		parent::tear_down();
	}

	/**
	 * Removes the summary table and the marker that says it exists.
	 */
	private function drop_summary_table() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->presence_network_summary}" );
		delete_site_option( self::VERSION_OPTION );
	}

	/**
	 * Marks the network as provisioned without creating anything.
	 */
	private function claim_provisioned() {
		update_site_option( self::VERSION_OPTION, WP_PRESENCE_NETWORK_SUMMARY_DB_VERSION );
	}

	/**
	 * @covers ::wp_maybe_create_presence_network_summary_table
	 * @covers ::wp_presence_network_summary_table_exists
	 * @covers ::wp_presence_has_network_summary_table
	 */
	public function test_provisioning_creates_the_table_and_records_the_version() {
		$this->drop_summary_table();

		wp_maybe_create_presence_network_summary_table();

		$this->assertTrue( wp_presence_network_summary_table_exists(), 'Provisioning should create the table.' );
		$this->assertTrue( wp_presence_has_network_summary_table(), 'And record the version the read and write paths check.' );
	}

	/**
	 * The version option is a site option, so it outlives any single site's
	 * table being dropped. Provisioning has to trust the database over the
	 * option, otherwise the network summary stays dead until someone deletes
	 * the option by hand.
	 *
	 * @covers ::wp_maybe_create_presence_network_summary_table
	 * @covers ::wp_presence_network_summary_table_exists
	 */
	public function test_a_dropped_table_is_rebuilt_when_the_version_option_survives() {
		$this->drop_summary_table();
		$this->claim_provisioned();

		wp_maybe_create_presence_network_summary_table();

		$this->assertTrue( wp_presence_network_summary_table_exists(), 'Provisioning should rebuild the missing table.' );
	}

	/**
	 * Two network admin requests can enter provisioning at the same time. The
	 * one that arrives second has to return rather than run a second,
	 * overlapping dbDelta().
	 *
	 * @covers ::wp_maybe_create_presence_network_summary_table
	 */
	public function test_a_locked_request_does_not_run_the_schema_change() {
		$this->drop_summary_table();

		// Stand in for the request that got there first and is still working.
		add_site_option( self::LOCK_OPTION, time() );

		wp_maybe_create_presence_network_summary_table();

		$this->assertFalse( wp_presence_network_summary_table_exists(), 'The second request should not have run dbDelta().' );
		$this->assertFalse( wp_presence_has_network_summary_table(), 'And it should not have claimed the network is provisioned.' );
	}

	/**
	 * Holding the lock past the work would block provisioning for every later
	 * request, which is a worse failure than the race it guards against.
	 *
	 * @covers ::wp_maybe_create_presence_network_summary_table
	 */
	public function test_provisioning_releases_the_lock_for_the_next_request() {
		$this->drop_summary_table();
		wp_maybe_create_presence_network_summary_table();
		$this->assertTrue( wp_presence_network_summary_table_exists(), 'Precondition: the first request provisions.' );

		$this->drop_summary_table();
		wp_maybe_create_presence_network_summary_table();

		$this->assertTrue( wp_presence_network_summary_table_exists(), 'A later request should not be blocked by a lock nobody holds.' );
	}

	/**
	 * A request that dies between taking the lock and releasing it would
	 * otherwise leave the network unprovisionable. Core's upgrader lock
	 * reclaims on age for the same reason.
	 *
	 * @covers ::wp_maybe_create_presence_network_summary_table
	 */
	public function test_an_abandoned_lock_stops_blocking_once_it_ages_out() {
		$this->drop_summary_table();

		// A lock left behind by a request that never got to release it.
		update_site_option( self::LOCK_OPTION, time() - ( 2 * MINUTE_IN_SECONDS ) );

		wp_maybe_create_presence_network_summary_table();

		$this->assertTrue( wp_presence_network_summary_table_exists(), 'An expired lock should be reclaimed, not respected forever.' );
	}

	/**
	 * admin-ajax.php fires admin_init too, and presence heartbeats through it
	 * every 15 seconds per open admin tab. A SHOW TABLES there would bill the
	 * whole network continuously for a state it will almost never be in.
	 *
	 * @covers ::wp_maybe_create_presence_network_summary_table
	 */
	public function test_ajax_requests_do_not_pay_for_the_table_check() {
		global $wpdb;

		$this->claim_provisioned();

		add_filter( 'wp_doing_ajax', '__return_true' );

		$before = $wpdb->num_queries;
		wp_maybe_create_presence_network_summary_table();

		$this->assertSame( $before, $wpdb->num_queries, 'A heartbeat request should not query at all.' );
	}

	/**
	 * The other half of that trade, stated so it cannot be dropped by accident.
	 * A network in the broken state stays broken through heartbeat traffic
	 * alone and waits for the next real network admin page load to repair.
	 *
	 * @covers ::wp_maybe_create_presence_network_summary_table
	 */
	public function test_ajax_requests_do_not_rebuild_a_dropped_table() {
		$this->drop_summary_table();
		$this->claim_provisioned();

		add_filter( 'wp_doing_ajax', '__return_true' );

		wp_maybe_create_presence_network_summary_table();

		$this->assertFalse( wp_presence_network_summary_table_exists(), 'Heartbeat should not trigger a rebuild.' );
	}
}
