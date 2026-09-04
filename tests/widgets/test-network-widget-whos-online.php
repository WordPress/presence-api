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
	 *
	 * @covers ::wp_presence_enqueue_avatar_stack_style
	 * @covers ::wp_presence_enqueue_avatar_stack_script
	 */
	public function test_scripts_are_only_enqueued_on_the_dashboard() {
		WP_Presence_Network_Widget_Whos_Online::enqueue_scripts( 'sites.php' );

		$this->assertFalse( wp_style_is( 'presence-network-widget', 'enqueued' ) );

		WP_Presence_Network_Widget_Whos_Online::enqueue_scripts( 'index.php' );

		$this->assertTrue( wp_script_is( 'heartbeat', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'presence-network-widget', 'enqueued' ) );

		// The inline script calls into the shared file, so it has to load first.
		$this->assertTrue( wp_script_is( 'wp-presence-avatar-stack', 'enqueued' ) );
		$this->assertContains( 'wp-presence-avatar-stack', wp_scripts()->registered['presence-network-widget']->deps );
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

	public function test_each_rendered_site_carries_its_blog_id() {
		$blog_id = $this->create_blog();
		$this->set_presence_on_site( $blog_id, self::$editor_id );

		ob_start();
		WP_Presence_Network_Widget_Whos_Online::render();
		$output = ob_get_clean();

		// Heartbeat re-renders restore focus by matching this attribute, so a row
		// without one drops a keyboard user to the body on every presence change.
		$this->assertStringContainsString(
			'data-blog-id="' . $blog_id . '"',
			$output,
			'Focus restore has nothing to key on without the blog ID on the row'
		);
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

		$response = $this->tick();

		$this->assertCount( $visible, $response['presence-network-widget'] );
		$this->assertSame( 1, $response['presence-network-widget-overflow'] );

		ob_start();
		WP_Presence_Network_Widget_Whos_Online::render();
		$output = ob_get_clean();

		$this->assertSame( $visible, substr_count( $output, 'presence-site-item' ) );
		$this->assertStringContainsString( '+1 more site', $output );
	}

	/**
	 * The payload carries display names and avatar URLs, not just IDs, so a
	 * hash over the room's membership alone left a rename on the dashboard for
	 * as long as the same people stayed online.
	 */
	public function test_the_widget_repaints_when_an_online_user_is_renamed() {
		$this->become_network_admin();
		$this->set_presence_on_site( $this->create_blog(), self::$editor_id );

		$first = $this->tick();

		wp_update_user(
			array(
				'ID'           => self::$editor_id,
				'display_name' => 'Renamed Editor',
			)
		);

		// Two ticks are two requests, and the summary is only held for the
		// length of one. Nothing here changed the room, so nothing dropped it.
		wp_presence_flush_network_summary_cache();

		$second = $this->tick( array( 'presence-network-widget-hash' => $first['presence-network-widget-hash'] ) );

		$this->assertArrayHasKey( 'presence-network-widget', $second, 'A rename left the old name on the dashboard.' );
		$this->assertSame( 'Renamed Editor', $second['presence-network-widget'][0]['users'][0]['display_name'] );
	}

	/**
	 * The tick replaces the whole list, so anything the server render says has
	 * to be said again by the script that rebuilds it. The list's name and the
	 * overflow link's wording were dropped there and are cheap to lose again.
	 */
	public function test_inline_script_carries_the_accessible_names_the_render_uses() {
		WP_Presence_Network_Widget_Whos_Online::enqueue_scripts( 'index.php' );

		$script = implode( '', (array) wp_scripts()->get_data( 'presence-network-widget', 'before' ) );

		$this->assertStringContainsString( 'Sites with online users', $script, 'The rebuilt list has no accessible name.' );
		$this->assertStringContainsString( '+%d more site \u2014 view all', $script, 'The rebuilt overflow link stops saying what it counts.' );
		$this->assertStringContainsString( '+%d more sites \u2014 view all', $script );
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

	/**
	 * Both states send an empty site list and no overflow, so on a quiet network
	 * the flag is the only thing the hash has to go on: without it the switch
	 * going off reports unchanged and the widget never starts saying so.
	 */
	public function test_heartbeat_repaints_when_a_quiet_network_stops_aggregating() {
		$this->become_network_admin();

		$first = $this->tick();

		$this->assertTrue( $first['presence-network-widget-aggregating'] );
		$this->assertSame( array(), $first['presence-network-widget'] );

		add_filter( 'wp_presence_network_aggregation_enabled', '__return_false' );
		wp_presence_flush_network_summary_cache();

		$second = $this->tick( array( 'presence-network-widget-hash' => $first['presence-network-widget-hash'] ) );

		$this->assertArrayHasKey( 'presence-network-widget', $second, 'The switch going off reported unchanged.' );
		$this->assertFalse( $second['presence-network-widget-aggregating'] );
	}

	/**
	 * The tick replaces the whole list, so a network that stops aggregating
	 * mid-session has to be told about by the script too, not just the render.
	 */
	public function test_inline_script_carries_the_not_aggregated_message() {
		WP_Presence_Network_Widget_Whos_Online::enqueue_scripts( 'index.php' );

		$script = implode( '', (array) wp_scripts()->get_data( 'presence-network-widget', 'before' ) );

		$this->assertStringContainsString( 'not aggregated across this network', $script );
	}
}
