<?php
/**
 * Tests for the network-wide presence UI: the Sites list column and the
 * Users list view, filter, and column.
 *
 * The network dashboard widget has its own file alongside the other widgets.
 *
 * @package Presence_API
 *
 * @group presence
 * @group ms-required
 */
class WP_Test_Network_Presence_UI extends WP_Presence_UnitTestCase {

	private static $editor_id;

	/**
	 * Blogs created by the current test, deleted in tear_down().
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
		// hand instead of relying on that rollback.
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
	 * The write pushes into the network summary on its own, via
	 * wp_presence_admin_room_changed.
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
	 * Grants the current test a network-capable user, so capability-gated
	 * hooks (column registration, views, the query filter) actually run.
	 *
	 * @return int The admin's user ID.
	 */
	private function become_network_admin() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $admin_id );
		wp_set_current_user( $admin_id );

		return $admin_id;
	}

	// -----------------------------------------------------------------
	// Network Sites list "Online" column
	// -----------------------------------------------------------------

	/**
	 * @covers ::wp_presence_register_network_sites_column
	 */
	public function test_sites_column_requires_capability() {
		$columns = wp_presence_register_network_sites_column( array( 'blogname' => 'Site' ) );

		$this->assertArrayNotHasKey( 'presence_online', $columns, 'A visitor with no capability should not see the column.' );
	}

	/**
	 * @covers ::wp_presence_register_network_sites_column
	 * @covers ::wp_presence_render_network_sites_column
	 */
	public function test_sites_column_renders_avatar_stack_and_count() {
		$this->become_network_admin();

		$blog_id = $this->create_blog();
		$this->set_presence_on_site( $blog_id, self::$editor_id );

		$columns = wp_presence_register_network_sites_column( array( 'blogname' => 'Site' ) );
		$this->assertArrayHasKey( 'presence_online', $columns );

		ob_start();
		wp_presence_render_network_sites_column( 'presence_online', $blog_id );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'presence-avatar-stack', $output );
		$this->assertStringContainsString( '1', $output );
	}

	/**
	 * @covers ::wp_presence_render_network_sites_column
	 */
	public function test_sites_column_shows_a_dash_for_a_site_with_nobody_online() {
		$this->become_network_admin();

		$blog_id = $this->create_blog();

		ob_start();
		wp_presence_render_network_sites_column( 'presence_online', $blog_id );
		$output = ob_get_clean();

		$this->assertSame( '&#8212;', $output );
	}

	/**
	 * @covers ::wp_presence_render_network_sites_column
	 */
	public function test_sites_column_ignores_other_columns() {
		$this->become_network_admin();

		ob_start();
		wp_presence_render_network_sites_column( 'blogname', 1 );
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	// -----------------------------------------------------------------
	// Network Users list "Online" view, filter, and column
	// -----------------------------------------------------------------

	/**
	 * @covers ::wp_presence_network_users_views
	 */
	public function test_users_view_requires_capability() {
		$views = wp_presence_network_users_views( array() );

		$this->assertArrayNotHasKey( 'presence_online', $views );
	}

	/**
	 * @covers ::wp_presence_network_users_views
	 */
	public function test_users_view_reports_the_network_wide_count() {
		$this->become_network_admin();

		$blog_ids = array( $this->create_blog(), $this->create_blog() );
		$this->set_presence_on_site( $blog_ids[0], self::$editor_id );
		$this->set_presence_on_site( $blog_ids[1], self::$editor_id );

		$views = wp_presence_network_users_views( array() );

		$this->assertArrayHasKey( 'presence_online', $views );
		// One distinct user online across both sites, not two.
		$this->assertStringContainsString( '(1)', $views['presence_online'] );
	}

	/**
	 * @covers ::wp_presence_filter_network_online_users
	 */
	public function test_users_query_filter_requires_network_admin_context() {
		$args = wp_presence_filter_network_online_users( array( 'number' => 10 ) );

		$this->assertArrayNotHasKey( 'include', $args, 'Outside is_network_admin(), the query should be left alone.' );
	}

	/**
	 * @covers ::wp_presence_filter_network_online_users
	 */
	public function test_users_query_filter_restricts_to_online_users() {
		$this->become_network_admin();
		set_current_screen( 'users-network' );

		$blog_id = $this->create_blog();
		$this->set_presence_on_site( $blog_id, self::$editor_id );

		$_GET['presence_status'] = 'online';
		$_GET['_wpnonce']        = wp_create_nonce( 'presence_online_filter' );

		$args = wp_presence_filter_network_online_users( array( 'number' => 10 ) );

		$this->assertSame( array( self::$editor_id ), $args['include'] );
	}

	/**
	 * WP_User_Query treats an empty "include" as no restriction at all, which
	 * would silently fall through to every network user instead of none.
	 *
	 * @covers ::wp_presence_filter_network_online_users
	 */
	public function test_users_query_filter_matches_nobody_when_nobody_is_online() {
		$this->become_network_admin();
		set_current_screen( 'users-network' );

		$_GET['presence_status'] = 'online';
		$_GET['_wpnonce']        = wp_create_nonce( 'presence_online_filter' );

		$args = wp_presence_filter_network_online_users( array( 'number' => 10 ) );

		$this->assertSame( array( 0 ), $args['include'] );
	}

	/**
	 * @covers ::wp_presence_render_network_users_column
	 */
	public function test_users_column_lists_the_sites_a_user_is_online_on() {
		$this->become_network_admin();

		$blog_id = $this->create_blog();
		$this->set_presence_on_site( $blog_id, self::$editor_id );

		$output = wp_presence_render_network_users_column( '', 'presence_online', self::$editor_id );

		$this->assertNotSame( '&#8212;', $output );
	}

	/**
	 * @covers ::wp_presence_render_network_users_column
	 */
	public function test_users_column_shows_a_dash_for_an_offline_user() {
		$output = wp_presence_render_network_users_column( '', 'presence_online', self::$editor_id );

		$this->assertSame( '&#8212;', $output );
	}

	/**
	 * @covers ::wp_presence_register_network_users_column
	 */
	public function test_users_column_requires_capability() {
		$columns = wp_presence_register_network_users_column( array( 'username' => 'Username' ) );

		$this->assertArrayNotHasKey( 'presence_online', $columns );
	}

	/**
	 * @covers ::wp_presence_register_network_users_column
	 */
	public function test_users_column_is_registered_for_a_network_admin() {
		$this->become_network_admin();

		$columns = wp_presence_register_network_users_column( array( 'username' => 'Username' ) );

		$this->assertArrayHasKey( 'presence_online', $columns );
	}
}
