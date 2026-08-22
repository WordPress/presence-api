<?php
/**
 * Tests for the network Who's Online dashboard widget.
 *
 * @package Presence_API
 *
 * @group presence
 * @group ms-required
 *
 * @covers WP_Presence_Network_Widget_Whos_Online
 */
class WP_Test_Presence_Network_Widget_Whos_Online extends WP_Presence_UnitTestCase {

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
		// loading never makes true on its own.
		$this->network_plugins = get_site_option( 'active_sitewide_plugins' );
		update_site_option( 'active_sitewide_plugins', array( 'presence-api/presence-api.php' => time() ) );

		wp_maybe_create_presence_network_summary_table();

		// The summary table is real rather than temporary, so rows can outlive
		// the test that wrote them. Start from empty rather than trusting the
		// last tear_down.
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

		// This class's own set_up() disables the temporary-table DDL rewrite,
		// so summary rows survive past this test's transaction rollback.
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
	 * Grants the current test a network-capable user.
	 *
	 * @return int The admin's user ID.
	 */
	private function become_network_admin() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $admin_id );
		wp_set_current_user( $admin_id );

		return $admin_id;
	}

	/**
	 * Sends a widget ping through the heartbeat handler.
	 *
	 * @param array $extra Additional top-level Heartbeat data keys.
	 * @return array The Heartbeat response.
	 */
	private function tick( $extra = array() ) {
		return WP_Presence_Network_Widget_Whos_Online::heartbeat_received(
			array(),
			array_merge( array( 'presence-network-widget-ping' => true ), $extra ),
			'dashboard-network'
		);
	}

	/**
	 * The widget reads presence for every site on the network, so a user who
	 * cannot administer the network must not get it on their dashboard.
	 */
	public function test_register_requires_capability() {
		global $wp_meta_boxes;

		wp_set_current_user( self::$editor_id );

		WP_Presence_Network_Widget_Whos_Online::register();

		$this->assertArrayNotHasKey( 'dashboard-network', (array) $wp_meta_boxes );
	}

	public function test_register_adds_the_widget_for_a_network_admin() {
		global $wp_meta_boxes;

		require_once ABSPATH . 'wp-admin/includes/dashboard.php';

		$this->become_network_admin();
		set_current_screen( 'dashboard-network' );

		WP_Presence_Network_Widget_Whos_Online::register();

		$this->assertArrayHasKey(
			'presence_network_whos_online',
			$wp_meta_boxes['dashboard-network']['normal']['core']
		);
	}

	/**
	 * The widget only exists on the dashboard, so every other admin screen has
	 * to load without its script or style.
	 */
	public function test_scripts_are_only_enqueued_on_the_dashboard() {
		WP_Presence_Network_Widget_Whos_Online::enqueue_scripts( 'sites.php' );

		$this->assertFalse( wp_style_is( 'presence-network-widget', 'enqueued' ) );

		WP_Presence_Network_Widget_Whos_Online::enqueue_scripts( 'index.php' );

		$this->assertTrue( wp_script_is( 'heartbeat', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'presence-network-widget', 'enqueued' ) );
	}

	public function test_heartbeat_ignores_without_ping() {
		$response = WP_Presence_Network_Widget_Whos_Online::heartbeat_received( array( 'existing' => true ), array(), 'dashboard-network' );

		$this->assertSame( array( 'existing' => true ), $response, 'A tick with no ping key should be left untouched.' );
	}

	public function test_heartbeat_requires_capability() {
		wp_set_current_user( self::$editor_id );

		$this->assertArrayNotHasKey( 'presence-network-widget', $this->tick() );
	}

	public function test_heartbeat_returns_a_hash_on_first_ping() {
		$this->become_network_admin();
		$this->set_presence_on_site( $this->create_blog(), self::$editor_id );

		$response = $this->tick();

		$this->assertArrayHasKey( 'presence-network-widget', $response );
		$this->assertSame( 32, strlen( $response['presence-network-widget-hash'] ) );
	}

	/**
	 * The hash is what keeps an idle network dashboard from re-sending the
	 * whole site list every tick.
	 */
	public function test_heartbeat_reports_unchanged_when_hash_matches() {
		$this->become_network_admin();
		$this->set_presence_on_site( $this->create_blog(), self::$editor_id );

		$first  = $this->tick();
		$second = $this->tick( array( 'presence-network-widget-hash' => $first['presence-network-widget-hash'] ) );

		$this->assertArrayNotHasKey( 'presence-network-widget', $second );
		$this->assertTrue( $second['presence-network-widget-unchanged'] );
	}

	public function test_heartbeat_sends_a_fresh_payload_once_someone_comes_online() {
		$this->become_network_admin();
		$blog_id = $this->create_blog();
		$this->set_presence_on_site( $blog_id, self::$editor_id );

		$first = $this->tick();

		$this->set_presence_on_site( $this->create_blog(), self::$editor_id );

		$second = $this->tick( array( 'presence-network-widget-hash' => $first['presence-network-widget-hash'] ) );

		$this->assertArrayHasKey( 'presence-network-widget', $second );
		$this->assertNotSame( $first['presence-network-widget-hash'], $second['presence-network-widget-hash'] );
	}

	public function test_render_lists_sites_with_online_users() {
		$this->set_presence_on_site( $this->create_blog(), self::$editor_id );

		ob_start();
		WP_Presence_Network_Widget_Whos_Online::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'presence-avatar-stack', $output );
		$this->assertStringContainsString( 'localhost', $output );
	}

	public function test_render_reports_nobody_online() {
		ob_start();
		WP_Presence_Network_Widget_Whos_Online::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'No users are currently online', $output );
	}
}
