<?php
/**
 * Tests for how the presence table is provisioned.
 *
 * Sites get their table up front, at activation for sites that already exist
 * and on `wp_initialize_site` for sites added later, the same way core creates
 * per-site tables. Nothing creates schema from a front-end request, so a site
 * that missed both has to degrade to a no-op rather than query a missing table.
 *
 * @package Presence_API
 *
 * @group presence
 */
class WP_Test_Presence_Table_Creation extends WP_Presence_UnitTestCase {

	/**
	 * The option backing the provisioning lock.
	 */
	private const LOCK_OPTION = 'wp_presence_table.lock';

	private static $editor_id;

	/**
	 * The network's active plugin list as it stood before the test.
	 *
	 * @var array|false
	 */
	private $network_plugins;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id = $factory->user->create( array( 'role' => 'editor' ) );
	}

	public function set_up() {
		parent::set_up();

		// WP_UnitTestCase rewrites DDL to CREATE/DROP TEMPORARY TABLE, which would
		// make dropping the real presence table a silent no-op. These tests need
		// the table to genuinely disappear, so they opt out.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		// DDL commits the surrounding transaction, so a provisioning lock left
		// behind by an earlier run survives into this one and would block every
		// test that provisions. Start from a known-free lock.
		delete_option( self::LOCK_OPTION );

		// Network options survive the rolled-back transaction, so tests that
		// pretend the plugin is network active have to put this back by hand.
		if ( is_multisite() ) {
			$this->network_plugins = get_site_option( 'active_sitewide_plugins' );
		}
	}

	public function tear_down() {
		if ( is_multisite() ) {
			if ( false === $this->network_plugins ) {
				delete_site_option( 'active_sitewide_plugins' );
			} else {
				update_site_option( 'active_sitewide_plugins', $this->network_plugins );
			}
		}

		// DDL commits the surrounding transaction, so clean up by hand. The
		// truncate itself is handled by the parent tear_down.
		wp_presence_register_table();
		delete_option( self::LOCK_OPTION );
		delete_option( 'wp_presence_db_version' );
		wp_presence_provision_site();

		parent::tear_down();
	}

	/**
	 * Removes the current site's presence table and the marker that says it exists.
	 */
	private function drop_presence_table() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->presence}" );
		delete_option( 'wp_presence_db_version' );
	}

	/**
	 * Whether the current site's presence table exists.
	 *
	 * @return bool
	 */
	private function presence_table_exists() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->presence ) );
	}

	/**
	 * Schema work belongs in the admin and CLI, never in a front-end request.
	 */
	public function test_schema_hooks_stay_out_of_the_front_end() {
		$this->assertNotFalse( has_action( 'admin_init', 'wp_maybe_create_presence_table' ) );
		$this->assertNotFalse( has_action( 'cli_init', 'wp_maybe_create_presence_table' ) );
		$this->assertFalse( has_action( 'init', 'wp_maybe_create_presence_table' ) );
		$this->assertFalse( has_action( 'wp_enqueue_scripts', 'wp_maybe_create_presence_table' ) );
	}

	/**
	 * The option says storage is available, the database disagrees. Reads and
	 * writes trust the option, so the two drifting apart is what kills presence.
	 *
	 * @covers ::wp_presence_table_exists
	 */
	public function test_table_exists_reports_the_database_not_the_option() {
		$this->drop_presence_table();
		update_option( 'wp_presence_db_version', WP_PRESENCE_DB_VERSION, true );

		$this->assertTrue( wp_presence_has_table(), 'The option-based check reads the option, so it is fooled.' );
		$this->assertFalse( wp_presence_table_exists(), 'The direct check should not be.' );

		wp_maybe_create_presence_table();

		$this->assertTrue( wp_presence_table_exists(), 'And it should see the table once it is back.' );
	}

	/**
	 * A partial restore or a hand-run DROP removes the table while the version
	 * option survives. Nothing else reconciles the two, so provisioning has to,
	 * otherwise presence stays dead on that site until someone deletes the
	 * option by hand.
	 *
	 * @covers ::wp_maybe_create_presence_table
	 * @covers ::wp_presence_table_exists
	 */
	public function test_a_dropped_table_is_rebuilt_when_the_version_option_survives() {
		$this->drop_presence_table();
		update_option( 'wp_presence_db_version', WP_PRESENCE_DB_VERSION, true );

		wp_maybe_create_presence_table();

		$this->assertTrue( $this->presence_table_exists(), 'Provisioning should rebuild the missing table.' );

		wp_set_presence( 'admin/online', 'user-' . self::$editor_id, array(), self::$editor_id );

		$this->assertCount( 1, wp_get_presence( 'admin/online' ), 'Presence should work again after the rebuild.' );
	}

	/**
	 * Two admin requests can enter provisioning at the same time, and neither
	 * has a confirmation step to serialize them the way wp-admin/upgrade.php
	 * does for core. The one that arrives second has to return rather than run
	 * a second, overlapping dbDelta().
	 *
	 * @covers ::wp_maybe_create_presence_table
	 * @covers ::wp_presence_create_lock
	 */
	public function test_a_locked_request_does_not_run_the_schema_change() {
		$this->drop_presence_table();

		// Stand in for the request that got there first and is still working.
		$this->assertTrue( wp_presence_create_lock( 'wp_presence_table' ), 'Precondition: the lock starts free.' );

		wp_maybe_create_presence_table();

		$this->assertFalse( $this->presence_table_exists(), 'The second request should not have run dbDelta().' );
		$this->assertFalse( get_option( 'wp_presence_db_version' ), 'And it should not have claimed the site is provisioned.' );
	}

	/**
	 * Holding the lock past the work would block provisioning for every later
	 * request, which is a worse failure than the race it guards against.
	 *
	 * @covers ::wp_maybe_create_presence_table
	 * @covers ::wp_presence_release_lock
	 */
	public function test_provisioning_releases_the_lock_for_the_next_request() {
		$this->drop_presence_table();
		wp_maybe_create_presence_table();
		$this->assertTrue( $this->presence_table_exists(), 'Precondition: the first request provisions.' );

		$this->drop_presence_table();
		wp_maybe_create_presence_table();

		$this->assertTrue( $this->presence_table_exists(), 'A later request should not be blocked by a lock nobody holds.' );
	}

	/**
	 * A request that dies between taking the lock and releasing it would
	 * otherwise leave the site unprovisionable. Core's upgrader lock reclaims
	 * on age for the same reason.
	 *
	 * @covers ::wp_maybe_create_presence_table
	 * @covers ::wp_presence_create_lock
	 */
	public function test_an_abandoned_lock_stops_blocking_once_it_ages_out() {
		$this->drop_presence_table();

		// A lock left behind by a request that never got to release it.
		update_option( self::LOCK_OPTION, time() - ( 2 * MINUTE_IN_SECONDS ), false );

		wp_maybe_create_presence_table();

		$this->assertTrue( $this->presence_table_exists(), 'An expired lock should be reclaimed, not respected forever.' );
	}

	/**
	 * The lock has to be exclusive, or it is not a lock.
	 *
	 * @covers ::wp_presence_create_lock
	 * @covers ::wp_presence_release_lock
	 */
	public function test_only_one_caller_holds_the_lock_at_a_time() {
		$this->assertTrue( wp_presence_create_lock( 'wp_presence_table' ), 'The first caller should take the lock.' );
		$this->assertFalse( wp_presence_create_lock( 'wp_presence_table' ), 'The second should be refused while it is held.' );

		wp_presence_release_lock( 'wp_presence_table' );

		$this->assertTrue( wp_presence_create_lock( 'wp_presence_table' ), 'And it should be free again once released.' );
	}

	/**
	 * admin-ajax.php fires admin_init too, and presence heartbeats through it
	 * every 15 seconds per open admin tab. Checking there would bill every site
	 * continuously for a state almost none of them will reach.
	 *
	 * @covers ::wp_maybe_create_presence_table
	 */
	public function test_ajax_requests_do_not_pay_for_the_table_check() {
		global $wpdb;

		// Warm the autoloaded option so it cannot account for a query below.
		get_option( 'wp_presence_db_version' );

		add_filter( 'wp_doing_ajax', '__return_true' );

		$before = $wpdb->num_queries;
		wp_maybe_create_presence_table();

		$this->assertSame( $before, $wpdb->num_queries, 'A heartbeat request should not query at all.' );
	}

	/**
	 * The other half of that trade, stated so it cannot be dropped by accident.
	 * A site in the broken state stays broken through heartbeat traffic alone
	 * and waits for the next real admin page load to repair.
	 *
	 * @covers ::wp_maybe_create_presence_table
	 */
	public function test_ajax_requests_do_not_rebuild_a_dropped_table() {
		$this->drop_presence_table();
		update_option( 'wp_presence_db_version', WP_PRESENCE_DB_VERSION, true );

		add_filter( 'wp_doing_ajax', '__return_true' );

		wp_maybe_create_presence_table();

		$this->assertFalse( $this->presence_table_exists(), 'Heartbeat should not trigger a rebuild.' );
	}

	/**
	 * @covers ::wp_presence_activate
	 * @covers ::wp_presence_provision_site
	 * @covers ::wp_presence_schedule_cleanup
	 */
	public function test_activation_provisions_the_current_site() {
		$this->drop_presence_table();
		wp_clear_scheduled_hook( 'wp_delete_expired_presence_data' );
		$this->assertFalse( $this->presence_table_exists() );

		wp_presence_activate();

		$this->assertTrue( $this->presence_table_exists(), 'Activation should create the table.' );
		$this->assertNotFalse( wp_next_scheduled( 'wp_delete_expired_presence_data' ), 'Activation should schedule cleanup.' );
	}

	/**
	 * @covers ::wp_presence_on_initialize_site
	 * @covers ::wp_presence_provision_site
	 * @covers ::wp_presence_schedule_cleanup
	 */
	public function test_new_site_is_provisioned_on_initialize_site() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}

		// wp_presence_on_initialize_site() only acts when the plugin is network active.
		update_site_option( 'active_sitewide_plugins', array( 'presence-api/presence-api.php' => time() ) );

		$blog_id = self::factory()->blog->create();

		switch_to_blog( $blog_id );
		$this->drop_presence_table();
		restore_current_blog();

		do_action( 'wp_initialize_site', get_site( $blog_id ), array() );

		switch_to_blog( $blog_id );
		$exists = $this->presence_table_exists();
		restore_current_blog();

		$this->assertTrue( $exists, 'A newly created site should get its own presence table.' );
	}

	/**
	 * A network activation has to reach the sites that already exist. Nothing
	 * fires wp_initialize_site for them, so activation is their only chance.
	 *
	 * @covers ::wp_presence_activate
	 * @covers ::wp_presence_get_network_site_ids
	 * @covers ::wp_presence_provision_site
	 * @covers ::wp_presence_schedule_cleanup
	 */
	public function test_network_activation_provisions_every_site() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}

		// Left out of active_sitewide_plugins on purpose: with the plugin not yet
		// network active, wp_presence_on_initialize_site() ignores these sites, so
		// a table on either one can only have come from the activation below.
		delete_site_option( 'active_sitewide_plugins' );
		$blog_ids = array(
			self::factory()->blog->create(),
			self::factory()->blog->create(),
		);

		$this->drop_presence_table();
		wp_clear_scheduled_hook( 'wp_delete_expired_presence_data' );

		wp_presence_activate( true );

		$this->assertTrue( $this->presence_table_exists(), 'The site running the activation should be provisioned.' );
		$this->assertNotFalse( wp_next_scheduled( 'wp_delete_expired_presence_data' ), 'The site running the activation should have cleanup scheduled.' );

		foreach ( $blog_ids as $blog_id ) {
			switch_to_blog( $blog_id );
			$exists    = $this->presence_table_exists();
			$scheduled = wp_next_scheduled( 'wp_delete_expired_presence_data' );
			restore_current_blog();

			$this->assertTrue( $exists, "Site {$blog_id} should have been given its own presence table." );
			$this->assertNotFalse( $scheduled, "Site {$blog_id} should have cleanup scheduled." );
		}
	}

	/**
	 * Cron events are per site, so a network deactivation that only cleared the
	 * current one would leave every other site rescheduling a dead callback.
	 *
	 * @covers ::wp_presence_deactivate
	 * @covers ::wp_presence_get_network_site_ids
	 */
	public function test_network_deactivation_clears_cleanup_on_every_site() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}

		$blog_id = self::factory()->blog->create();

		wp_presence_activate( true );

		switch_to_blog( $blog_id );
		$scheduled_before = wp_next_scheduled( 'wp_delete_expired_presence_data' );
		restore_current_blog();
		$this->assertNotFalse( $scheduled_before, 'Precondition: the other site starts with cleanup scheduled.' );

		wp_presence_deactivate( true );

		switch_to_blog( $blog_id );
		$scheduled_after = wp_next_scheduled( 'wp_delete_expired_presence_data' );
		restore_current_blog();

		$this->assertFalse( $scheduled_after, "Site {$blog_id} should have had its cleanup cleared." );
		$this->assertFalse( wp_next_scheduled( 'wp_delete_expired_presence_data' ), 'The site running the deactivation should have its cleanup cleared.' );
	}

	/**
	 * @covers ::wp_presence_on_initialize_site
	 */
	public function test_new_site_is_skipped_when_not_network_active() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}

		delete_site_option( 'active_sitewide_plugins' );

		$blog_id = self::factory()->blog->create();

		switch_to_blog( $blog_id );
		$this->drop_presence_table();
		restore_current_blog();

		do_action( 'wp_initialize_site', get_site( $blog_id ), array() );

		switch_to_blog( $blog_id );
		$exists = $this->presence_table_exists();
		restore_current_blog();

		$this->assertFalse( $exists, 'A site-by-site activation should not provision unrelated new sites.' );
	}

	/**
	 * The table name has to follow a blog switch, or a network activation would
	 * write every site's presence into the main site's table.
	 */
	public function test_table_name_is_scoped_to_the_switched_site() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}

		global $wpdb;

		$blog_id = self::factory()->blog->create();

		switch_to_blog( $blog_id );
		$switched = $wpdb->presence;
		$prefix   = $wpdb->prefix;
		restore_current_blog();

		$this->assertSame( $prefix . 'presence', $switched );
		$this->assertNotSame( $switched, $wpdb->presence, 'Restoring should put the main site table back.' );
	}

	/**
	 * The residual case the core pattern accepts: a site nothing has provisioned.
	 * It must not raise a database error.
	 *
	 * @covers ::wp_presence_has_table
	 * @covers ::wp_set_presence
	 * @covers ::wp_remove_presence
	 * @covers ::wp_remove_user_presence
	 * @covers ::wp_get_presence
	 * @covers ::wp_get_user_presence
	 * @covers ::wp_get_presence_by_room_prefix
	 * @covers ::wp_get_active_rooms
	 * @covers ::wp_get_presence_summary
	 * @covers ::wp_delete_expired_presence_data
	 */
	public function test_reads_and_writes_are_a_no_op_without_a_table() {
		global $wpdb;

		$this->drop_presence_table();
		$wpdb->last_error = '';

		$this->assertFalse( wp_presence_has_table() );

		$this->assertFalse( wp_set_presence( 'admin/online', 'user-1', array(), self::$editor_id ) );
		$this->assertFalse( wp_remove_presence( 'admin/online', 'user-1' ) );
		$this->assertFalse( wp_remove_user_presence( self::$editor_id ) );
		$this->assertSame( array(), wp_get_presence( 'admin/online' ) );
		$this->assertSame( array(), wp_get_user_presence( self::$editor_id ) );
		$this->assertSame( array(), wp_get_presence_by_room_prefix( 'postType/' ) );
		$this->assertSame( array(), wp_get_active_rooms() );
		$this->assertSame( 0, wp_get_presence_summary()['total_entries'] );
		$this->assertNull( wp_delete_expired_presence_data() );

		// The symptom this guards against: a wpdb error surfaced to the page.
		$this->assertSame( '', $wpdb->last_error, 'No query should have reached the missing table.' );
	}

	/**
	 * The two write paths from the original report: a front-end view and a login
	 * on a site with no presence table.
	 *
	 * @covers ::wp_presence_on_login
	 */
	public function test_login_does_not_error_without_a_table() {
		global $wpdb;

		$this->drop_presence_table();
		$wpdb->last_error = '';

		$user = get_userdata( self::$editor_id );
		wp_presence_on_login( $user->user_login, $user );

		$this->assertSame( '', $wpdb->last_error, 'Logging in should not query a missing table.' );
		$this->assertSame( array(), wp_get_user_presence( self::$editor_id ) );
	}

	/**
	 * @covers WP_REST_Presence_Controller::get_items
	 * @covers WP_REST_Presence_Controller::empty_collection_response
	 */
	public function test_rest_read_returns_an_empty_collection_without_a_table() {
		$this->drop_presence_table();

		wp_set_current_user( self::$editor_id );

		$request = new WP_REST_Request( 'GET', '/wp-presence/v1/presence' );
		$request->set_param( 'room', 'admin/online' );

		$controller = new WP_REST_Presence_Controller();
		$response   = $controller->get_items( $request );

		$this->assertSame( array(), $response->get_data() );
		$this->assertEquals( 0, $response->get_headers()['X-WP-Total'] );
	}

	/**
	 * @covers WP_REST_Presence_Controller::create_item
	 */
	public function test_rest_write_reports_unavailable_without_a_table() {
		$this->drop_presence_table();

		wp_set_current_user( self::$editor_id );

		$request = new WP_REST_Request( 'POST', '/wp-presence/v1/presence' );
		$request->set_param( 'room', 'admin/online' );
		$request->set_param( 'client_id', 'user-' . self::$editor_id );

		$controller = new WP_REST_Presence_Controller();
		$response   = $controller->create_item( $request );

		$this->assertInstanceOf( 'WP_Error', $response );
		$this->assertSame( 'rest_presence_unavailable', $response->get_error_code() );
		$this->assertSame( 503, $response->get_error_data()['status'] );
	}

	/**
	 * The ownership lookup needs storage to read from, so a non-admin deleting
	 * against a missing table has to short-circuit rather than query.
	 *
	 * @covers WP_REST_Presence_Controller::delete_item_permissions_check
	 */
	public function test_rest_delete_is_permitted_without_a_table() {
		global $wpdb;

		$this->drop_presence_table();
		$wpdb->last_error = '';

		wp_set_current_user( self::$editor_id );

		$request = new WP_REST_Request( 'DELETE', '/wp-presence/v1/presence' );
		$request->set_param( 'room', 'admin/online' );
		$request->set_param( 'client_id', 'user-' . self::$editor_id );

		$controller = new WP_REST_Presence_Controller();

		$this->assertTrue( $controller->delete_item_permissions_check( $request ) );
		$this->assertSame( '', $wpdb->last_error, 'The ownership lookup should not have run.' );
	}

	/**
	 * The summary is one table for the whole network, so it hangs off
	 * base_prefix and joins ms_global_tables the way core registers blogs and
	 * sitemeta. A single site has no network to summarise, and registering the
	 * name there would point every caller at a table nothing ever creates.
	 *
	 * @covers ::wp_presence_register_network_summary_table
	 */
	public function test_network_summary_table_is_registered_only_on_a_network() {
		global $wpdb;

		$global_tables = $wpdb->ms_global_tables;
		unset( $wpdb->presence_network_summary );

		wp_presence_register_network_summary_table();

		$registered = $wpdb->presence_network_summary ?? null;
		$is_global  = in_array( 'presence_network_summary', $wpdb->ms_global_tables, true );

		// $wpdb outlives the test, and re-registering appended a second
		// ms_global_tables entry, so put both back before asserting.
		$wpdb->ms_global_tables = $global_tables;

		if ( ! is_multisite() ) {
			$this->assertNull( $registered, 'A single site has no network summary table to name.' );
			return;
		}

		$this->assertSame( $wpdb->base_prefix . 'presence_network_summary', $registered );
		$this->assertTrue( $is_global, 'Core only treats a table as network-wide if it is an ms_global_table.' );
	}
}
