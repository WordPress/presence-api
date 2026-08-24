<?php
/**
 * Tests for network-wide presence aggregation.
 *
 * The whole point of the aggregation layer is reading across sites without
 * paying for switch_to_blog() on every page load or heartbeat tick, so that
 * property is asserted directly rather than only implied by correct output.
 *
 * @package Presence_API
 *
 * @group presence
 * @group ms-required
 */
class WP_Test_Network_Presence extends WP_Presence_UnitTestCase {

	private static $editor_id;

	/**
	 * Blogs created by the current test, deleted in tear_down().
	 *
	 * A network summary sums every site on the network, so a blog left behind
	 * by one test would otherwise leak into the next test's totals — unlike
	 * the rest of this suite, which only ever asserts against specific blog
	 * IDs and so doesn't care what else exists.
	 *
	 * @var int[]
	 */
	private $blog_ids = array();

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
		global $wpdb;

		parent::set_up();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}

		// WP_UnitTestCase rewrites DDL to CREATE/DROP TEMPORARY TABLE. The
		// network summary table is created with real, non-temporary DDL (see
		// wp_maybe_create_presence_network_summary_table()), so this suite
		// needs that rewrite disabled to provision it at all.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		// wp_presence_on_initialize_site() only provisions a new blog's table when
		// the plugin is network active, which the test bootstrap's mu-plugin-style
		// loading never makes true on its own. A network summary is only
		// meaningful when every site actually has a table, so fake that here the
		// same way test-table-creation.php does for its own network-active tests.
		$this->network_plugins = get_site_option( 'active_sitewide_plugins' );
		update_site_option( 'active_sitewide_plugins', array( 'presence-api/presence-api.php' => time() ) );

		wp_maybe_create_presence_network_summary_table();

		// Every admin-room write anywhere in the suite now pushes, and this
		// table is real rather than temporary, so rows can outlive the test that
		// wrote them. Start from empty rather than trusting the last tear_down.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->presence_network_summary}" );

		wp_set_current_user( 0 );
	}

	public function tear_down() {
		global $wpdb;

		foreach ( $this->blog_ids as $blog_id ) {
			wp_delete_site( $blog_id );
		}
		$this->blog_ids = array();

		if ( false === $this->network_plugins ) {
			delete_site_option( 'active_sitewide_plugins' );
		} else {
			update_site_option( 'active_sitewide_plugins', $this->network_plugins );
		}

		// This class's own set_up() disables the temporary-table DDL rewrite
		// (see above), so writes to the real, non-temporary network summary
		// table survive past this test's own transaction rollback -- exactly
		// why blog_ids and active_sitewide_plugins above are cleaned up by
		// hand instead of relying on that rollback. Rows pushed mid-test need
		// the same treatment; the table itself stays (dbDelta is idempotent).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->presence_network_summary}" );

		parent::tear_down();
	}

	/**
	 * Creates a blog and schedules it for deletion at the end of the test.
	 *
	 * @return int The new blog ID.
	 */
	private function create_blog() {
		$blog_id          = self::factory()->blog->create();
		$this->blog_ids[] = $blog_id;

		return $blog_id;
	}

	/**
	 * Writes a presence entry on a given site, without leaving the site
	 * switched-to.
	 *
	 * No explicit push: wp_set_presence() fires wp_presence_admin_room_changed,
	 * which is what the push hangs off, so calling one by hand here would test
	 * a path production doesn't take.
	 *
	 * @param int $blog_id The site to write on.
	 * @param int $user_id The user the entry belongs to.
	 */
	private function set_presence_on_site( $blog_id, $user_id ) {
		switch_to_blog( $blog_id );
		wp_set_presence( 'admin/online', 'user-' . $user_id, array( 'screen' => 'dashboard' ), $user_id );
		restore_current_blog();
	}

	/**
	 * Removes a user's presence entry on a given site, without leaving the site
	 * switched-to.
	 *
	 * @param int $blog_id The site to remove on.
	 * @param int $user_id The user whose entry to remove.
	 */
	private function remove_presence_on_site( $blog_id, $user_id ) {
		switch_to_blog( $blog_id );
		wp_remove_presence( 'admin/online', 'user-' . $user_id );
		restore_current_blog();
	}

	/**
	 * Overwrites a site's pushed row in the network summary table directly,
	 * for tests that need to control its content or age precisely rather
	 * than going through a real presence write.
	 *
	 * @param int    $blog_id     The site whose row to overwrite.
	 * @param int[]  $user_ids    User IDs for the data column.
	 * @param string $updated_gmt Optional. Value for the updated_gmt column. Default now.
	 */
	private function set_network_summary_row( $blog_id, array $user_ids, $updated_gmt = null ) {
		global $wpdb;

		$updated_gmt = $updated_gmt ?? gmdate( 'Y-m-d H:i:s' );

		$wpdb->replace(
			$wpdb->presence_network_summary,
			array(
				'blog_id'     => $blog_id,
				'data'        => wp_presence_encode_network_summary_row( $blog_id, $user_ids ),
				'updated_gmt' => $updated_gmt,
			)
		);

		// A real push also leaves a record of itself on the site that pushed,
		// which is what wp_presence_network_summary_needs_push() reads. Writing
		// only the row would leave the two disagreeing about when this site last
		// pushed, a state no push produces. Skipped for a blog_id with no site
		// behind it, which is a row left over from a deleted site and has no
		// options table to record anything in.
		if ( ! get_site( $blog_id ) ) {
			return;
		}

		sort( $user_ids );

		switch_to_blog( $blog_id );
		update_option(
			'wp_presence_network_pushed',
			array(
				'users' => implode( ',', $user_ids ),
				'time'  => strtotime( $updated_gmt . ' UTC' ),
			),
			true
		);
		restore_current_blog();
	}

	/**
	 * Counts the statements naming the summary table that a callback produces.
	 *
	 * @param callable $during Code to run while counting.
	 * @return int Statement count.
	 */
	private function count_summary_table_statements( callable $during ) {
		global $wpdb;

		$count = 0;
		$table = $wpdb->presence_network_summary;

		$counter = static function ( $query ) use ( &$count, $table ) {
			if ( false !== strpos( $query, $table ) ) {
				++$count;
			}

			return $query;
		};

		add_filter( 'query', $counter );
		$during();
		remove_filter( 'query', $counter );

		return $count;
	}

	/**
	 * Returns a site's raw summary row.
	 *
	 * @param int $blog_id The site whose row to read.
	 * @return object|null Row with data and updated_gmt, null if the site never pushed.
	 */
	private function get_network_summary_row( $blog_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT data, updated_gmt FROM {$wpdb->presence_network_summary} WHERE blog_id = %d", $blog_id )
		);
	}

	/**
	 * @covers ::wp_presence_get_network_summary
	 * @covers ::wp_presence_get_network_snapshot
	 * @covers ::wp_presence_compute_network_snapshot
	 * @covers ::wp_presence_filter_network_snapshot_users
	 * @covers ::wp_presence_decode_network_summary_row
	 */
	public function test_summary_aggregates_across_sites() {
		$blog_ids = array(
			$this->create_blog(),
			$this->create_blog(),
		);

		$this->set_presence_on_site( $blog_ids[0], self::$editor_id );
		$this->set_presence_on_site( $blog_ids[1], self::$editor_id );

		$summary = wp_presence_get_network_summary();

		$this->assertSame( 2, $summary['total_sites_online'] );
		$this->assertSame( 2, $summary['total_users_online'] );

		$seen_blog_ids = wp_list_pluck( $summary['sites'], 'blog_id' );
		sort( $seen_blog_ids );
		$this->assertSame( $blog_ids, $seen_blog_ids );

		foreach ( $summary['sites'] as $site ) {
			$this->assertSame( self::$editor_id, $site['users'][0]['user_id'] );
			$this->assertArrayHasKey( 'avatar_url', $site['users'][0] );
		}
	}

	/**
	 * A site that hasn't pushed a row (never had any admin activity yet) has
	 * to be silently absent from the result rather than fatal the whole
	 * aggregation.
	 *
	 * @covers ::wp_presence_get_network_summary
	 * @covers ::wp_presence_compute_network_snapshot
	 */
	public function test_summary_tolerates_a_site_with_no_pushed_row() {
		$blog_ids = array(
			$this->create_blog(),
			$this->create_blog(),
		);

		$this->set_presence_on_site( $blog_ids[0], self::$editor_id );
		// blog_ids[1] is created but never pushes anything.

		$summary = wp_presence_get_network_summary();

		$this->assertSame( array( $blog_ids[0] ), wp_list_pluck( $summary['sites'], 'blog_id' ) );
	}

	/**
	 * The property this whole aggregation layer exists to have: reading across
	 * sites must not switch blogs, even transiently. A before/after comparison
	 * alone could miss a switch that nets out to no visible state change, so
	 * this listens for the action both switch_to_blog() and
	 * restore_current_blog() fire.
	 *
	 * @covers ::wp_presence_get_network_summary
	 */
	public function test_summary_never_switches_blogs() {
		$blog_id = $this->create_blog();
		$this->set_presence_on_site( $blog_id, self::$editor_id );

		$switch_count = 0;
		add_action(
			'switch_blog',
			function () use ( &$switch_count ) {
				++$switch_count;
			}
		);

		$blog_id_before = get_current_blog_id();
		$prefix_before  = $GLOBALS['wpdb']->prefix;

		wp_presence_get_network_summary();

		$this->assertSame( 0, $switch_count, 'Aggregation must never fire switch_blog.' );
		$this->assertSame( $blog_id_before, get_current_blog_id() );
		$this->assertSame( $prefix_before, $GLOBALS['wpdb']->prefix );
	}

	/**
	 * @covers ::wp_presence_push_network_summary
	 */
	public function test_push_upserts_rather_than_duplicates() {
		global $wpdb;

		$blog_id = $this->create_blog();

		$this->set_presence_on_site( $blog_id, self::$editor_id );
		$this->set_presence_on_site( $blog_id, self::$editor_id );

		$row_count = $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->presence_network_summary} WHERE blog_id = %d", $blog_id )
		);

		$this->assertSame( '1', $row_count, 'A second push for the same site should replace its row, not add another.' );
	}

	/**
	 * The defect this push model had at first: only the heartbeat handler
	 * pushed, so the pagehide delete and logout left the summary showing people
	 * who were gone until their entry aged out. Removal has to clear the site
	 * with no heartbeat tick anywhere in the picture.
	 *
	 * @covers ::wp_presence_push_network_summary
	 */
	public function test_removing_the_last_user_clears_the_site_without_a_tick() {
		$blog_id = $this->create_blog();
		$this->set_presence_on_site( $blog_id, self::$editor_id );

		$this->remove_presence_on_site( $blog_id, self::$editor_id );

		$summary = wp_presence_get_network_summary();

		$this->assertSame( array(), $summary['sites'] );
	}

	/**
	 * wp_remove_user_presence() deletes across every room at once, which is the
	 * logout path.
	 *
	 * @covers ::wp_presence_push_network_summary
	 */
	public function test_removing_all_of_a_users_presence_clears_the_site() {
		$blog_id = $this->create_blog();
		$this->set_presence_on_site( $blog_id, self::$editor_id );

		switch_to_blog( $blog_id );
		wp_remove_user_presence( self::$editor_id );
		restore_current_blog();

		$this->assertSame( array(), wp_presence_get_network_summary()['sites'] );
	}

	/**
	 * A tick that finds the same people online as the last one must not rewrite
	 * the row. Presence writes happen on every tick from every open tab, so a
	 * push that always wrote would put one row update per tab per tick on a
	 * single network-wide table.
	 *
	 * @covers ::wp_presence_push_network_summary
	 */
	public function test_push_does_not_rewrite_the_row_when_nobody_changed() {
		$blog_id = $this->create_blog();
		$this->set_presence_on_site( $blog_id, self::$editor_id );

		// Age the row enough to tell a rewrite apart from the original write,
		// but not past the refresh interval, which would license a rewrite.
		$stamp = gmdate( 'Y-m-d H:i:s', time() - 1 );
		$this->set_network_summary_row( $blog_id, array( self::$editor_id ), $stamp );

		$this->set_presence_on_site( $blog_id, self::$editor_id );

		$this->assertSame( $stamp, $this->get_network_summary_row( $blog_id )->updated_gmt );
	}

	/**
	 * Same case, one level down. An unchanged row proves the upsert resolved to
	 * no change, not that it was skipped, and the statement itself is the cost
	 * on a sharded network: the summary table is pinned to the global cluster,
	 * and every admin pageview writes the admin room. See #310.
	 *
	 * @covers ::wp_presence_push_network_summary
	 * @covers ::wp_presence_network_summary_needs_push
	 * @covers ::wp_presence_record_network_summary_push
	 */
	public function test_an_unchanged_tick_sends_no_statement_to_the_summary_table() {
		$blog_id = $this->create_blog();
		$this->set_presence_on_site( $blog_id, self::$editor_id );

		$statements = $this->count_summary_table_statements(
			function () use ( $blog_id ) {
				$this->set_presence_on_site( $blog_id, self::$editor_id );
			}
		);

		$this->assertSame( 0, $statements );
	}

	/**
	 * The flip side: a row older than the refresh interval has to be rewritten
	 * even though nobody changed, or it ages past the read cutoff and the site
	 * drops out of the network view while people are still on it.
	 *
	 * @covers ::wp_presence_push_network_summary
	 * @covers ::wp_presence_network_summary_refresh_interval
	 */
	public function test_push_refreshes_a_stale_row_when_nobody_changed() {
		$blog_id = $this->create_blog();
		$this->set_presence_on_site( $blog_id, self::$editor_id );

		$stamp = gmdate( 'Y-m-d H:i:s', time() - wp_presence_network_summary_refresh_interval() - 1 );
		$this->set_network_summary_row( $blog_id, array( self::$editor_id ), $stamp );

		$this->set_presence_on_site( $blog_id, self::$editor_id );

		$this->assertGreaterThan( $stamp, $this->get_network_summary_row( $blog_id )->updated_gmt );
	}

	/**
	 * A membership change is not subject to the refresh interval; it writes
	 * straight away.
	 *
	 * @covers ::wp_presence_push_network_summary
	 * @covers ::wp_presence_network_summary_needs_push
	 */
	public function test_push_rewrites_the_row_as_soon_as_the_user_set_changes() {
		$blog_id  = $this->create_blog();
		$second   = self::factory()->user->create( array( 'role' => 'editor' ) );
		$expected = array( self::$editor_id, $second );
		sort( $expected );

		$this->set_presence_on_site( $blog_id, self::$editor_id );

		$stamp = gmdate( 'Y-m-d H:i:s', time() - 1 );
		$this->set_network_summary_row( $blog_id, array( self::$editor_id ), $stamp );

		$this->set_presence_on_site( $blog_id, $second );

		$row = $this->get_network_summary_row( $blog_id );

		$this->assertGreaterThan( $stamp, $row->updated_gmt );
		$this->assertSame( $expected, wp_presence_decode_network_summary_row( $row->data ) );
	}

	/**
	 * The refresh interval has to stay under the read cutoff by at least the
	 * longest gap between two pushes, or a site with only idle tabs flickers
	 * out of the network view between refreshes.
	 *
	 * @covers ::wp_presence_network_summary_refresh_interval
	 */
	public function test_refresh_interval_leaves_room_for_the_slowest_heartbeat() {
		$slack = wp_presence_get_timeout( WP_PRESENCE_DEFAULT_TTL ) - wp_presence_get_heartbeat_idle_interval();

		$this->assertLessThanOrEqual( $slack, wp_presence_network_summary_refresh_interval() );

		add_filter( 'wp_presence_network_summary_refresh_interval', '__return_zero' );
		$this->assertSame( 1, wp_presence_network_summary_refresh_interval(), 'Must not fall to zero.' );
		remove_filter( 'wp_presence_network_summary_refresh_interval', '__return_zero' );

		add_filter( 'wp_presence_network_summary_refresh_interval', fn() => YEAR_IN_SECONDS );
		$this->assertSame( $slack, wp_presence_network_summary_refresh_interval(), 'A filter must not widen it.' );
	}

	/**
	 * The data column is read by people with a database client open, so it
	 * names what it holds rather than storing a bare list of numbers.
	 *
	 * @covers ::wp_presence_encode_network_summary_row
	 * @covers ::wp_presence_decode_network_summary_row
	 */
	public function test_row_data_is_readable_on_its_own() {
		$blog_id = $this->create_blog();
		$this->set_presence_on_site( $blog_id, self::$editor_id );

		$data    = $this->get_network_summary_row( $blog_id )->data;
		$decoded = json_decode( $data, true );
		$site    = get_site( $blog_id );

		$this->assertSame( $site->domain . $site->path, $decoded['site'] );
		$this->assertSame( array( self::$editor_id ), $decoded['online_user_ids'] );
		$this->assertStringContainsString( "\n", $data, 'Stored pretty-printed to stay readable.' );
	}

	/**
	 * A row whose data column will not decode must drop out rather than fatal
	 * the whole aggregation.
	 *
	 * @covers ::wp_presence_decode_network_summary_row
	 * @covers ::wp_presence_compute_network_snapshot
	 * @covers ::wp_presence_empty_network_summary
	 */
	public function test_summary_tolerates_a_malformed_row() {
		global $wpdb;

		$blog_id = $this->create_blog();
		$this->set_presence_on_site( $blog_id, self::$editor_id );

		$wpdb->update(
			$wpdb->presence_network_summary,
			array( 'data' => 'not json' ),
			array( 'blog_id' => $blog_id )
		);

		$this->assertSame( array(), wp_presence_get_network_summary()['sites'] );
	}

	/**
	 * A row not pushed to within the live timeout is excluded at the SQL level,
	 * which is the only freshness cutoff the read path applies.
	 *
	 * @covers ::wp_presence_compute_network_snapshot
	 * @covers ::wp_presence_empty_network_summary
	 */
	public function test_summary_excludes_a_row_not_pushed_within_the_timeout() {
		$blog_id = $this->create_blog();

		$this->set_network_summary_row(
			$blog_id,
			array( self::$editor_id ),
			gmdate( 'Y-m-d H:i:s', time() - WP_PRESENCE_DEFAULT_TTL - 1 )
		);

		$summary = wp_presence_get_network_summary();

		$this->assertSame( array(), $summary['sites'] );
	}

	/**
	 * The read path is reached on a network whose table has not been provisioned
	 * yet -- the plugin is network activated one request before the first push --
	 * so it answers from the empty shape rather than querying a missing table.
	 *
	 * @covers ::wp_presence_compute_network_snapshot
	 * @covers ::wp_presence_empty_network_summary
	 */
	public function test_summary_is_empty_before_the_table_is_provisioned() {
		$blog_id = $this->create_blog();
		$this->set_network_summary_row( $blog_id, array( self::$editor_id ) );

		$version = get_site_option( 'wp_presence_network_summary_db_version' );
		delete_site_option( 'wp_presence_network_summary_db_version' );

		$summary = wp_presence_get_network_summary();

		update_site_option( 'wp_presence_network_summary_db_version', $version );

		$this->assertSame( wp_presence_empty_network_summary(), $summary );
	}

	/**
	 * Deleting a site leaves its row behind until it ages out, so the row can
	 * outlive the site it names.
	 *
	 * @covers ::wp_presence_compute_network_snapshot
	 */
	public function test_summary_skips_a_row_for_a_site_that_no_longer_exists() {
		$this->set_network_summary_row( 999901, array( self::$editor_id ) );

		$this->assertSame( array(), wp_presence_get_network_summary()['sites'] );
	}

	/**
	 * A row names user IDs and nothing else, so it outlives the accounts it
	 * points at. A deleted account has to drop out of its site rather than out
	 * of the whole aggregation, and a site left with nobody real drops out
	 * entirely rather than showing as online with an empty list.
	 *
	 * @covers ::wp_presence_compute_network_snapshot
	 * @covers ::wp_presence_filter_network_snapshot_users
	 */
	public function test_summary_skips_users_who_no_longer_exist() {
		$kept    = $this->create_blog();
		$emptied = $this->create_blog();

		$this->set_network_summary_row( $kept, array( 999902, self::$editor_id ) );
		$this->set_network_summary_row( $emptied, array( 999903 ) );

		$summary = wp_presence_get_network_summary();

		$this->assertSame( array( $kept ), wp_list_pluck( $summary['sites'], 'blog_id' ) );
		$this->assertSame( array( self::$editor_id ), wp_list_pluck( $summary['sites'][0]['users'], 'user_id' ) );
		$this->assertSame( 1, $summary['total_users_online'] );
	}

	/**
	 * A row carries no per-user timestamp to order by, so the people on a site
	 * are ordered by name to keep the list from reshuffling between reads.
	 *
	 * @covers ::wp_presence_compute_network_snapshot
	 */
	public function test_summary_lists_the_users_on_a_site_by_name() {
		$blog_id = $this->create_blog();
		$zoe     = self::factory()->user->create( array( 'display_name' => 'Zoe' ) );
		$ana     = self::factory()->user->create( array( 'display_name' => 'Ana' ) );

		$this->set_network_summary_row( $blog_id, array( $zoe, $ana ) );

		$summary = wp_presence_get_network_summary();

		$this->assertSame( array( 'Ana', 'Zoe' ), wp_list_pluck( $summary['sites'][0]['users'], 'display_name' ) );
	}

	/**
	 * The network view is a list of where the activity is, so the busiest site
	 * leads it, and a capped read keeps the sites worth showing. Sites tied on
	 * headcount fall back to blog ID, which is what keeps the order stable
	 * without resolving every site to order them.
	 *
	 * @covers ::wp_presence_compute_network_snapshot
	 */
	public function test_summary_lists_the_busiest_site_first() {
		$quiet  = $this->create_blog();
		$busy   = $this->create_blog();
		$second = self::factory()->user->create();

		$this->set_network_summary_row( $quiet, array( self::$editor_id ) );
		$this->set_network_summary_row( $busy, array( self::$editor_id, $second ) );

		$summary = wp_presence_get_network_summary();

		$this->assertSame( array( $busy, $quiet ), wp_list_pluck( $summary['sites'], 'blog_id' ) );
		$this->assertSame( 2, $summary['total_sites_online'] );
		$this->assertSame( 3, $summary['total_users_online'] );
	}

	/**
	 * The Sites and Users list columns call this once per row, and a build
	 * resolves a display name and an avatar URL for every user on every site,
	 * so a second read in the same request must not repeat it.
	 *
	 * @covers ::wp_presence_get_network_summary
	 * @covers ::wp_presence_network_cached
	 * @covers ::wp_presence_network_cache_group
	 * @covers ::wp_presence_flush_network_summary_cache
	 */
	public function test_the_summary_is_built_once_per_request_and_dropped_by_a_push() {
		global $wpdb;

		$blog_id = $this->create_blog();
		$this->set_network_summary_row( $blog_id, array( self::$editor_id ) );

		$first = wp_presence_get_network_summary();

		$queries = $wpdb->num_queries;
		$second  = wp_presence_get_network_summary();

		$this->assertSame( $queries, $wpdb->num_queries, 'A second read in the same request rebuilt the summary.' );
		$this->assertSame( $first, $second );

		// A real write on this site pushes a fresh row, which the held build
		// would otherwise hide for the rest of the request.
		$arriving_id = self::factory()->user->create();
		$this->set_presence_on_site( $blog_id, $arriving_id );

		$after = wp_presence_get_network_summary();

		$this->assertSame(
			array( $arriving_id ),
			wp_list_pluck( $after['sites'][0]['users'], 'user_id' ),
			'A read after a push returned the build from before it.'
		);
	}

	/**
	 * Every caller renders a slice: the widget shows five sites with a few
	 * avatars each. Resolving a display name and an avatar URL for every user
	 * on every site to render twenty of them is what made the read scale with
	 * the network rather than with the view, so the caps apply before anything
	 * is loaded. The counts stay network-wide, since they are what tells a
	 * network admin the slice is a slice.
	 *
	 * @covers ::wp_presence_get_network_summary
	 * @covers ::wp_presence_hydrate_network_snapshot
	 */
	public function test_summary_caps_what_it_resolves_without_capping_what_it_reports() {
		$busy  = $this->create_blog();
		$quiet = $this->create_blog();

		$this->set_network_summary_row( $busy, self::factory()->user->create_many( 3 ) );
		$this->set_network_summary_row( $quiet, array( self::$editor_id ) );

		$summary = wp_presence_get_network_summary(
			array(
				'sites'          => 1,
				'users_per_site' => 2,
			)
		);

		$this->assertSame( array( $busy ), wp_list_pluck( $summary['sites'], 'blog_id' ) );
		$this->assertCount( 2, $summary['sites'][0]['users'] );
		$this->assertSame( 3, $summary['sites'][0]['user_count'], 'A capped list reported its own length as the headcount.' );
		$this->assertSame( 2, $summary['total_sites_online'] );
		$this->assertSame( 4, $summary['total_users_online'] );
	}

	/**
	 * The Sites list column renders one row at a time, so it asks for one site
	 * rather than filtering the whole network down in PHP on every row.
	 *
	 * @covers ::wp_presence_get_network_summary
	 * @covers ::wp_presence_hydrate_network_snapshot
	 */
	public function test_summary_can_be_narrowed_to_one_site() {
		$wanted = $this->create_blog();
		$other  = $this->create_blog();

		$this->set_network_summary_row( $wanted, array( self::$editor_id ) );
		$this->set_network_summary_row( $other, array( self::$editor_id ) );

		$summary = wp_presence_get_network_summary( array( 'blog_id' => $wanted ) );

		$this->assertSame( array( $wanted ), wp_list_pluck( $summary['sites'], 'blog_id' ) );
		$this->assertSame( 2, $summary['total_sites_online'] );

		// Most rows on a Sites list are quiet ones, and that narrowing still has
		// a network behind it to report totals for.
		$quiet = wp_presence_get_network_summary( array( 'blog_id' => $this->create_blog() ) );

		$this->assertSame( array(), $quiet['sites'] );
		$this->assertSame( 2, $quiet['total_sites_online'] );
	}

	/**
	 * The summary table is network-global, so a deleted site's row is not
	 * dropped with that site's own tables and would sit there until it ages
	 * out, pushing a real site off the end of every capped read.
	 *
	 * @covers ::wp_presence_on_delete_site
	 */
	public function test_deleting_a_site_drops_its_summary_row() {
		$blog_id = $this->create_blog();
		$this->set_network_summary_row( $blog_id, array( self::$editor_id ) );

		$this->assertNotNull( $this->get_network_summary_row( $blog_id ) );

		wp_delete_site( get_site( $blog_id ) );

		$this->assertNull( $this->get_network_summary_row( $blog_id ) );
	}

	/**
	 * Network presence data spans every site on the install, so the bar to see
	 * it is a network capability, and a network that delegates differently can
	 * move it.
	 *
	 * @covers ::wp_presence_network_capability
	 */
	public function test_the_network_capability_is_filterable() {
		$this->assertSame( 'manage_network', wp_presence_network_capability() );

		add_filter( 'wp_presence_network_capability', fn() => 'manage_sites' );

		$this->assertSame( 'manage_sites', wp_presence_network_capability() );
	}
	/**
	 * One person signed in on two sites is one person online, which is the
	 * distinction the network user list filters on.
	 *
	 * @covers ::wp_presence_get_network_online_user_ids
	 */
	public function test_online_user_ids_are_deduplicated_across_sites() {
		$first  = $this->create_blog();
		$second = $this->create_blog();
		$other  = self::factory()->user->create();

		$this->set_network_summary_row( $first, array( self::$editor_id ) );
		$this->set_network_summary_row( $second, array( self::$editor_id, $other ) );

		$ids = wp_presence_get_network_online_user_ids();
		sort( $ids );

		$expected = array( self::$editor_id, $other );
		sort( $expected );

		$this->assertSame( $expected, $ids );
	}

}
