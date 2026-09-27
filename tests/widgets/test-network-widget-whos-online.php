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
class WP_Test_Presence_Network_Widget_Whos_Online extends WP_Presence_Network_UnitTestCase {

	private static $editor_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id = $factory->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * Sends a widget ping through the heartbeat handler.
	 *
	 * @return array The Heartbeat response.
	 */
	private function tick() {
		return WP_Presence_Network_Widget_Whos_Online::heartbeat_received(
			array(),
			array( 'presence-fragments' => array( 'network-widget' => true ) ),
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
	 * to load without its style.
	 *
	 * @covers ::wp_presence_enqueue_avatar_stack_style
	 */
	public function test_styles_are_only_enqueued_on_the_dashboard() {
		WP_Presence_Network_Widget_Whos_Online::enqueue_scripts( 'sites.php' );

		$this->assertFalse( wp_style_is( 'presence-network-widget', 'enqueued' ) );

		WP_Presence_Network_Widget_Whos_Online::enqueue_scripts( 'index.php' );

		$this->assertTrue( wp_style_is( 'presence-network-widget', 'enqueued' ) );
	}

	/**
	 * The network capability does not imply edit_posts, which the ping's own enqueue requires.
	 *
	 * @covers ::wp_presence_enqueue_ping_script
	 */
	public function test_the_dashboard_loads_the_script_that_keeps_the_widget_current() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		WP_Presence_Network_Widget_Whos_Online::enqueue_scripts( 'index.php' );

		$this->assertTrue( wp_script_is( 'wp-presence-ping' ) );
	}

	public function test_heartbeat_ignores_without_ping() {
		$response = WP_Presence_Network_Widget_Whos_Online::heartbeat_received( array( 'existing' => true ), array(), 'dashboard-network' );

		$this->assertSame( array( 'existing' => true ), $response, 'A tick with no ping key should be left untouched.' );
	}

	public function test_heartbeat_requires_capability() {
		wp_set_current_user( self::$editor_id );

		$this->assertSame( array(), $this->tick() );
	}

	public function test_heartbeat_sends_the_site_list_it_renders() {
		$this->become_network_admin();
		$this->set_presence_on_site( $this->create_blog(), self::$editor_id );

		ob_start();
		WP_Presence_Network_Widget_Whos_Online::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( $this->tick()['presence-fragments']['network-widget'], $output );
	}

	public function test_render_lists_sites_with_online_users() {
		$this->set_presence_on_site( $this->create_blog(), self::$editor_id );

		ob_start();
		WP_Presence_Network_Widget_Whos_Online::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'presence-avatar-stack', $output );
		$this->assertStringContainsString( 'localhost', $output );
	}

	public function test_sites_are_named_by_title_and_link_to_their_settings() {
		$this->become_network_admin();
		$blog_id = $this->create_blog();
		update_blog_option( $blog_id, 'blogname', 'Team & Co' );
		$this->set_presence_on_site( $blog_id, self::$editor_id );

		ob_start();
		WP_Presence_Network_Widget_Whos_Online::render();
		$output = ob_get_clean();

		$this->assertStringContainsString(
			'<a href="' . esc_url( network_admin_url( 'site-info.php?id=' . $blog_id ) ) . '">Team &amp; Co</a>',
			$output
		);
	}

	public function test_a_site_without_a_title_is_named_by_its_address() {
		$this->become_network_admin();
		$blog_id = $this->create_blog();
		update_blog_option( $blog_id, 'blogname', '' );
		$this->set_presence_on_site( $blog_id, self::$editor_id );

		$site = get_site( $blog_id );
		$this->assertStringContainsString( '>' . esc_html( untrailingslashit( $site->domain . $site->path ) ) . '</a>', $this->tick()['presence-fragments']['network-widget'] );
	}

	/**
	 * The widget draws five sites and links out for the rest, so it asks the
	 * read path for five rather than pulling the whole network across and
	 * throwing most of it away. What is left over is then a count off the
	 * network total, not the length of a list that was already cut.
	 */
	public function test_the_widget_sends_only_the_sites_it_draws_and_counts_the_rest() {
		$this->become_network_admin();

		$visible = WP_Presence_Network_Widget_Whos_Online::VISIBLE_SITES;

		for ( $i = 0; $i <= $visible; $i++ ) {
			$this->set_presence_on_site( $this->create_blog(), self::$editor_id );
		}

		$output = $this->tick()['presence-fragments']['network-widget'];

		$this->assertSame( $visible, substr_count( $output, 'presence-site-item' ) );
		$this->assertStringContainsString( '+1 more site', $output );
	}

	public function test_render_reports_nobody_online() {
		ob_start();
		WP_Presence_Network_Widget_Whos_Online::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'No users are currently online', $output );
	}

	/**
	 * A network that has stopped aggregating knows nothing about who is online,
	 * so reporting that nobody is would be an answer it cannot give.
	 */
	public function test_render_reports_that_the_network_does_not_aggregate() {
		$this->set_presence_on_site( $this->create_blog(), self::$editor_id );

		add_filter( 'wp_presence_network_aggregation_enabled', '__return_false' );
		wp_presence_flush_network_summary_cache();

		ob_start();
		WP_Presence_Network_Widget_Whos_Online::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'not aggregated across this network', $output );
		$this->assertStringNotContainsString( 'No users are currently online', $output );
	}
}
