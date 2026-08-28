<?php
/**
 * Tests for how a site keeps its network summary row current.
 *
 * The push runs on every admin-room write, which on a busy site is every
 * pageview and every heartbeat tick, against the one table on the network that
 * a shard router cannot split. How often it declines to write is therefore as
 * much of the contract as what it writes, and both are pinned down here.
 *
 * @package Presence_API
 *
 * @group presence
 * @group ms-required
 */
class WP_Test_Network_Summary_Push extends WP_Presence_Network_UnitTestCase {

	private static $editor_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id = $factory->user->create( array( 'role' => 'editor' ) );
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
	 * @covers ::wp_presence_decode_network_summary_row
	 */
	public function test_removing_the_last_user_clears_the_site_without_a_tick() {
		$blog_id = $this->create_blog();
		$this->set_presence_on_site( $blog_id, self::$editor_id );

		$this->remove_presence_on_site( $blog_id, self::$editor_id );

		$row = $this->get_network_summary_row( $blog_id );

		$this->assertSame( array(), wp_presence_decode_network_summary_row( $row->data ) );
	}

	/**
	 * wp_remove_user_presence() deletes across every room at once, which is the
	 * logout path.
	 *
	 * @covers ::wp_presence_push_network_summary
	 * @covers ::wp_presence_decode_network_summary_row
	 */
	public function test_removing_all_of_a_users_presence_clears_the_site() {
		$blog_id = $this->create_blog();
		$this->set_presence_on_site( $blog_id, self::$editor_id );

		switch_to_blog( $blog_id );
		wp_remove_user_presence( self::$editor_id );
		restore_current_blog();

		$row = $this->get_network_summary_row( $blog_id );

		$this->assertSame( array(), wp_presence_decode_network_summary_row( $row->data ) );
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
		global $wpdb;

		$blog_id = $this->create_blog();
		$this->set_presence_on_site( $blog_id, self::$editor_id );

		// Age the presence row so the second write produces an updated date_gmt
		// and $result > 0. Real heartbeat ticks are always seconds apart.
		switch_to_blog( $blog_id );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->presence} SET date_gmt = %s WHERE room = %s AND client_id = %s",
				gmdate( 'Y-m-d H:i:s', time() - 2 ),
				wp_presence_admin_room(),
				'user-' . self::$editor_id
			)
		);
		restore_current_blog();

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
	 * @covers ::wp_presence_decode_network_summary_row
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
	 * The coalescing above bounds how often one site rewrites its row, not how
	 * many sites write into the one table a shard router cannot split.
	 *
	 * Asserted as a statement count rather than an absent row: declining after a
	 * SELECT against the global cluster still costs what the gate exists to avoid.
	 *
	 * @covers ::wp_presence_push_network_summary
	 * @covers ::wp_presence_network_aggregation_enabled
	 */
	public function test_push_sends_no_statement_on_a_large_network() {
		add_filter( 'wp_is_large_network', '__return_true' );

		$blog_id = $this->create_blog();

		$statements = $this->count_summary_table_statements(
			function () use ( $blog_id ) {
				$this->set_presence_on_site( $blog_id, self::$editor_id );
			}
		);

		$this->assertSame( 0, $statements, 'A large network should not touch the summary table at all.' );
		$this->assertNull( $this->get_network_summary_row( $blog_id ) );
	}

	/**
	 * The gate is a default, not a verdict: a large network sized to carry the
	 * writes has to be able to say so, which the clamped
	 * wp_presence_network_summary_refresh_interval() cannot express.
	 *
	 * @covers ::wp_presence_push_network_summary
	 * @covers ::wp_presence_network_aggregation_enabled
	 */
	public function test_filter_can_aggregate_on_a_large_network() {
		add_filter( 'wp_is_large_network', '__return_true' );
		add_filter( 'wp_presence_network_aggregation_enabled', '__return_true' );

		$blog_id = $this->create_blog();
		$this->set_presence_on_site( $blog_id, self::$editor_id );

		$row = $this->get_network_summary_row( $blog_id );

		$this->assertNotNull( $row );
		$this->assertSame( array( self::$editor_id ), wp_presence_decode_network_summary_row( $row->data ) );
	}

	/**
	 * And the other way, for a network under the threshold that still does not
	 * want the writes.
	 *
	 * @covers ::wp_presence_push_network_summary
	 * @covers ::wp_presence_network_aggregation_enabled
	 */
	public function test_filter_can_stop_aggregating_below_the_threshold() {
		$this->assertTrue( wp_presence_network_aggregation_enabled(), 'The test network is not large.' );

		add_filter( 'wp_presence_network_aggregation_enabled', '__return_false' );

		$blog_id = $this->create_blog();
		$this->set_presence_on_site( $blog_id, self::$editor_id );

		$this->assertNull( $this->get_network_summary_row( $blog_id ) );
	}

	/**
	 * The data column is read by people with a database client open, so it
	 * names what it holds rather than storing a bare list of numbers.
	 *
	 * @covers ::wp_presence_encode_network_summary_row
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
	 * A network-wide deletion should clear, and re-push, presence on every
	 * site the user was online on.
	 *
	 * @covers ::wp_presence_on_user_removed
	 * @covers ::wp_presence_push_network_summary
	 */
	public function test_deleting_a_user_clears_their_presence_on_every_site() {
		require_once ABSPATH . 'wp-admin/includes/ms.php';

		$user_id  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$blog_ids = array( $this->create_blog(), $this->create_blog() );

		foreach ( $blog_ids as $blog_id ) {
			add_user_to_blog( $blog_id, $user_id, 'editor' );
			$this->set_presence_on_site( $blog_id, $user_id );
		}
		$this->set_presence_on_site( $blog_ids[0], self::$editor_id );

		wpmu_delete_user( $user_id );

		foreach ( $blog_ids as $blog_id ) {
			switch_to_blog( $blog_id );
			$this->assertCount( 0, $this->presence_for_user( $user_id ), "Presence should be cleared on blog {$blog_id}." );
			restore_current_blog();

			$row = $this->get_network_summary_row( $blog_id );
			$this->assertNotContains(
				$user_id,
				json_decode( $row->data, true )['online_user_ids'],
				"The network summary row for blog {$blog_id} should no longer list the deleted user."
			);
		}

		switch_to_blog( $blog_ids[0] );
		$this->assertCount( 1, $this->presence_for_user( self::$editor_id ), 'Deleting one user should not clear anybody else.' );
		restore_current_blog();
	}

	/**
	 * A summary row survives until cleanup runs even if the site is later
	 * marked as deleted. A deleted site with a fresh row must not appear in the
	 * network snapshot, or it reads as live on the dashboard widget and the
	 * Users list column.
	 *
	 * @covers ::wp_presence_compute_network_snapshot
	 */
	public function test_snapshot_excludes_deleted_site_with_fresh_row() {
		$blog_id = $this->create_blog();
		$this->set_presence_on_site( $blog_id, self::$editor_id );

		// Mark the site as deleted after the summary row has been pushed.
		update_blog_status( $blog_id, 'deleted', 1 );

		// Flush the request cache so the next read recomputes from the database
		// rather than serving the value built before the site was deleted.
		wp_presence_flush_network_summary_cache();

		$snapshot = wp_presence_get_network_snapshot();

		$this->assertArrayNotHasKey( $blog_id, $snapshot['sites'], 'A deleted site must not appear in the network snapshot.' );
	}
}
