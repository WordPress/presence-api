<?php
/**
 * Tests for reading network-wide presence.
 *
 * The whole point of the aggregation layer is reading across sites without
 * paying for switch_to_blog() on every page load or heartbeat tick, so that
 * property is asserted directly rather than only implied by correct output.
 * The same goes for the cap: what a caller asks to resolve and what the
 * network really holds are two different numbers, and the read reports both.
 *
 * @package Presence_API
 *
 * @group presence
 * @group ms-required
 */
class WP_Test_Network_Presence extends WP_Presence_Network_UnitTestCase {

	private static $editor_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id = $factory->user->create( array( 'role' => 'editor' ) );
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
	 * A site's link must carry the scheme it was pushed under, not whichever
	 * scheme the viewing request happens to be on.
	 *
	 * @covers ::wp_presence_get_network_summary
	 * @covers ::wp_presence_encode_network_summary_row
	 * @covers ::wp_presence_decode_network_summary_scheme
	 * @covers ::wp_presence_hydrate_network_snapshot
	 */
	public function test_site_url_uses_the_scheme_the_site_pushed_under() {
		$https_blog_id = $this->create_blog();
		$http_blog_id  = $this->create_blog();

		$_SERVER['HTTPS'] = 'on';
		$this->set_presence_on_site( $https_blog_id, self::$editor_id );

		unset( $_SERVER['HTTPS'] );
		$this->set_presence_on_site( $http_blog_id, self::$editor_id );

		// Viewer is on http, same as $http_blog_id's own push -- only
		// $https_blog_id's link can tell the fix apart from the bug.
		$summary = wp_presence_get_network_summary();

		$urls = array();
		foreach ( $summary['sites'] as $site ) {
			$urls[ $site['blog_id'] ] = $site['url'];
		}

		$this->assertStringStartsWith( 'https://', $urls[ $https_blog_id ] );
		$this->assertStringStartsWith( 'http://', $urls[ $http_blog_id ] );
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
	 * The read only hides an expired row. Nothing else takes it off disk, so a
	 * site that goes idle would hold the user IDs it last pushed indefinitely.
	 *
	 * @covers ::wp_presence_delete_expired_network_summary_rows
	 */
	public function test_cleanup_deletes_a_row_past_the_read_cutoff() {
		$blog_id = $this->create_blog();

		$this->set_network_summary_row(
			$blog_id,
			array( self::$editor_id ),
			gmdate( 'Y-m-d H:i:s', time() - WP_PRESENCE_DEFAULT_TTL - 1 )
		);

		wp_presence_delete_expired_network_summary_rows();

		$this->assertNull( $this->get_network_summary_row( $blog_id ) );
	}

	/**
	 * @covers ::wp_presence_delete_expired_network_summary_rows
	 */
	public function test_cleanup_leaves_a_row_inside_the_read_cutoff() {
		$blog_id = $this->create_blog();

		$this->set_network_summary_row( $blog_id, array( self::$editor_id ) );

		wp_presence_delete_expired_network_summary_rows();

		$this->assertNotNull( $this->get_network_summary_row( $blog_id ) );
	}

	/**
	 * A single invocation deletes at most batch_size * max_passes rows and
	 * leaves the remainder for the next scheduled run.
	 *
	 * @covers ::wp_presence_delete_expired_network_summary_rows
	 */
	public function test_cleanup_is_bounded_per_invocation() {
		global $wpdb;

		$expired = gmdate( 'Y-m-d H:i:s', time() - WP_PRESENCE_DEFAULT_TTL - 1 );

		for ( $i = 0; $i < 5; $i++ ) {
			$this->set_network_summary_row( $this->create_blog(), array( self::$editor_id ), $expired );
		}

		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->presence_network_summary}" );

		$two = static function () {
			return 2;
		};
		add_filter( 'wp_presence_cleanup_batch_size', $two );
		add_filter( 'wp_presence_cleanup_max_passes', $two );

		wp_presence_delete_expired_network_summary_rows();

		$this->assertSame(
			$before - 4,
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->presence_network_summary}" ),
			'One invocation should delete batch_size * max_passes (4) rows and leave the rest.'
		);

		remove_filter( 'wp_presence_cleanup_batch_size', $two );
		remove_filter( 'wp_presence_cleanup_max_passes', $two );
	}

	/**
	 * Cron can fire in the request between network activation and the first
	 * push, before the table exists.
	 *
	 * @covers ::wp_presence_delete_expired_network_summary_rows
	 */
	public function test_cleanup_is_a_no_op_before_the_table_is_provisioned() {
		$version = get_site_option( 'wp_presence_network_summary_db_version' );
		delete_site_option( 'wp_presence_network_summary_db_version' );

		$this->assertNull( wp_presence_delete_expired_network_summary_rows() );

		update_site_option( 'wp_presence_network_summary_db_version', $version );
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
	 * Rows outlive the push that wrote them, so a network that stopped
	 * aggregating would keep serving a snapshot frozen at whatever the last push
	 * left, with nothing on the screen to say the feed behind it had stopped.
	 * Reading empty is the only honest answer once nothing is keeping it current.
	 *
	 * @covers ::wp_presence_compute_network_snapshot
	 * @covers ::wp_presence_network_aggregation_enabled
	 */
	public function test_summary_is_empty_on_a_large_network() {
		$blog_id = $this->create_blog();
		$this->set_network_summary_row( $blog_id, array( self::$editor_id ) );

		add_filter( 'wp_is_large_network', '__return_true' );
		wp_presence_flush_network_summary_cache();

		$this->assertSame( wp_presence_empty_network_summary( false ), wp_presence_get_network_summary() );
		$this->assertSame( array(), wp_presence_get_network_online_user_ids() );
		$this->assertSame( array(), wp_presence_get_network_sites_for_user( self::$editor_id ) );
	}

	/**
	 * A network crosses wp_is_large_network() on its own, so unless the read
	 * itself tells the two apart, the screens go quietly from correct to wrong.
	 *
	 * @covers ::wp_presence_compute_network_snapshot
	 * @covers ::wp_presence_empty_network_summary
	 */
	public function test_summary_reports_that_a_gated_network_does_not_aggregate() {
		add_filter( 'wp_presence_network_aggregation_enabled', '__return_false' );
		wp_presence_flush_network_summary_cache();

		$this->assertFalse( wp_presence_get_network_summary()['aggregating'] );
		$this->assertFalse( wp_presence_get_network_snapshot()['aggregating'] );
	}

	/**
	 * The quiet network the gated one has to be told apart from.
	 *
	 * @covers ::wp_presence_compute_network_snapshot
	 * @covers ::wp_presence_empty_network_summary
	 */
	public function test_summary_reports_an_aggregating_network_with_nobody_online() {
		$this->assertSame( array(), wp_presence_get_network_summary()['sites'] );
		$this->assertTrue( wp_presence_get_network_summary()['aggregating'] );
	}

	/**
	 * Only one half of the gate is a policy: a network activated one request
	 * before its table exists aggregates, and answers quiet rather than gated.
	 *
	 * @covers ::wp_presence_compute_network_snapshot
	 * @covers ::wp_presence_empty_network_summary
	 */
	public function test_summary_still_reports_aggregating_before_the_table_is_provisioned() {
		$version = get_site_option( 'wp_presence_network_summary_db_version' );
		delete_site_option( 'wp_presence_network_summary_db_version' );

		$aggregating = wp_presence_get_network_summary()['aggregating'];

		update_site_option( 'wp_presence_network_summary_db_version', $version );

		$this->assertTrue( $aggregating );
	}

	/**
	 * The flag tracks the feed, not whether the read came back empty.
	 *
	 * @covers ::wp_presence_compute_network_snapshot
	 */
	public function test_summary_reports_aggregating_on_a_populated_network() {
		$blog_id = $this->create_blog();
		$this->set_network_summary_row( $blog_id, array( self::$editor_id ) );
		wp_presence_flush_network_summary_cache();

		$this->assertTrue( wp_presence_get_network_summary()['aggregating'] );
	}

	/**
	 * Every read funnels through one snapshot, so the gate has to be answerable
	 * without a query even when rows are sitting there to be read.
	 *
	 * @covers ::wp_presence_compute_network_snapshot
	 * @covers ::wp_presence_network_aggregation_enabled
	 */
	public function test_a_gated_read_sends_no_statement() {
		$blog_id = $this->create_blog();
		$this->set_network_summary_row( $blog_id, array( self::$editor_id ) );

		add_filter( 'wp_presence_network_aggregation_enabled', '__return_false' );
		wp_presence_flush_network_summary_cache();

		$statements = $this->count_summary_table_statements(
			static function () {
				wp_presence_get_network_summary();
			}
		);

		$this->assertSame( 0, $statements );
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
	 * A paginated caller wants a page of the same busiest-first order, so the
	 * skip happens where the cap does: before anything is resolved.
	 *
	 * @covers ::wp_presence_get_network_summary
	 * @covers ::wp_presence_hydrate_network_snapshot
	 */
	public function test_summary_can_skip_sites_before_resolving_them() {
		$busiest = $this->create_blog();
		$busy    = $this->create_blog();
		$quiet   = $this->create_blog();

		$this->set_network_summary_row( $busiest, self::factory()->user->create_many( 3 ) );
		$this->set_network_summary_row( $busy, self::factory()->user->create_many( 2 ) );
		$this->set_network_summary_row( $quiet, array( self::$editor_id ) );

		$page_two = wp_presence_get_network_summary(
			array(
				'sites'  => 1,
				'offset' => 1,
			)
		);

		$this->assertSame( array( $busy ), wp_list_pluck( $page_two['sites'], 'blog_id' ) );
		$this->assertSame( 3, $page_two['total_sites_online'] );

		// An offset with no cap behind it runs to the end of the network.
		$rest = wp_presence_get_network_summary( array( 'offset' => 1 ) );

		$this->assertSame( array( $busy, $quiet ), wp_list_pluck( $rest['sites'], 'blog_id' ) );

		$past_end = wp_presence_get_network_summary( array( 'offset' => 3 ) );

		$this->assertSame( array(), $past_end['sites'] );
		$this->assertSame( 3, $past_end['total_sites_online'] );
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
}
