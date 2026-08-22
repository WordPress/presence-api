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
	 * Writes a presence entry on a given site and pushes it into the network
	 * summary table, without leaving the site switched-to.
	 *
	 * Real presence writes always push (see wp_presence_admin_heartbeat_received()),
	 * so this helper does both together rather than making every test call
	 * wp_presence_push_network_summary() separately.
	 *
	 * @param int $blog_id The site to write on.
	 * @param int $user_id The user the entry belongs to.
	 */
	private function set_presence_on_site( $blog_id, $user_id ) {
		switch_to_blog( $blog_id );
		wp_set_presence( 'admin/online', 'user-' . $user_id, array( 'screen' => 'dashboard' ), $user_id );
		wp_presence_push_network_summary();
		restore_current_blog();
	}

	/**
	 * Overwrites a site's pushed row in the network summary table directly,
	 * for tests that need to control its content or age precisely rather
	 * than going through a real presence write.
	 *
	 * @param int    $blog_id     The site whose row to overwrite.
	 * @param array  $entries     Raw {user_id, date_gmt} pairs for the data column.
	 * @param string $updated_gmt Optional. Value for the updated_gmt column. Default now.
	 */
	private function set_network_summary_row( $blog_id, array $entries, $updated_gmt = null ) {
		global $wpdb;

		$wpdb->replace(
			$wpdb->presence_network_summary,
			array(
				'blog_id'     => $blog_id,
				'data'        => wp_json_encode( $entries ),
				'updated_gmt' => $updated_gmt ?? gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * @covers ::wp_presence_get_network_summary
	 * @covers ::wp_presence_compute_network_summary
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
	 * @covers ::wp_presence_compute_network_summary
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
	 * A site going from one online user to zero has to push that immediately;
	 * otherwise it would keep showing its last non-empty snapshot until
	 * updated_gmt aged past the read-time cutoff on its own.
	 *
	 * @covers ::wp_presence_push_network_summary
	 */
	public function test_push_clears_a_site_with_nobody_left_online() {
		$blog_id = $this->create_blog();
		$this->set_presence_on_site( $blog_id, self::$editor_id );

		switch_to_blog( $blog_id );
		wp_remove_presence( 'admin/online', 'user-' . self::$editor_id );
		wp_presence_push_network_summary();
		restore_current_blog();

		$summary = wp_presence_get_network_summary();

		$this->assertSame( array(), $summary['sites'] );
	}

	/**
	 * A user whose pushed date_gmt is past the live timeout must not show as
	 * online, even though the row that holds it is otherwise fresh.
	 *
	 * @covers ::wp_presence_get_network_summary
	 * @covers ::wp_presence_filter_network_summary
	 */
	public function test_summary_excludes_a_user_past_the_live_timeout() {
		$blog_id = $this->create_blog();

		$this->set_network_summary_row(
			$blog_id,
			array(
				array(
					'user_id'  => self::$editor_id,
					'date_gmt' => gmdate( 'Y-m-d H:i:s', time() - WP_PRESENCE_DEFAULT_TTL - 1 ),
				),
			)
		);

		$summary = wp_presence_get_network_summary();

		$this->assertSame( array(), $summary['sites'] );
	}

	/**
	 * A row not pushed to within the live timeout is excluded at the SQL
	 * level in wp_presence_compute_network_summary(), before the per-user
	 * filter in wp_presence_filter_network_summary() ever runs.
	 *
	 * @covers ::wp_presence_compute_network_summary
	 */
	public function test_summary_excludes_a_row_not_pushed_within_the_timeout() {
		$blog_id = $this->create_blog();

		$this->set_network_summary_row(
			$blog_id,
			array( array( 'user_id' => self::$editor_id, 'date_gmt' => gmdate( 'Y-m-d H:i:s' ) ) ),
			gmdate( 'Y-m-d H:i:s', time() - WP_PRESENCE_DEFAULT_TTL - 1 )
		);

		$summary = wp_presence_get_network_summary();

		$this->assertSame( array(), $summary['sites'] );
	}
}
