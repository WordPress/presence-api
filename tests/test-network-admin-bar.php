<?php
/**
 * Tests for the admin bar presence node in Network Admin.
 *
 * @package Presence_API
 *
 * @group presence
 * @group ms-required
 *
 * @covers ::wp_presence_admin_bar_node
 */
class WP_Test_Network_Admin_Bar extends WP_Presence_Network_UnitTestCase {

	public function set_up() {
		parent::set_up();
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';

		$admin_id = $this->become_network_admin();
		wp_set_presence( 'admin/online', 'user-' . $admin_id, array( 'screen' => 'users-network' ), $admin_id );

		$this->set_network_summary_row( get_current_blog_id(), array( $admin_id ) );
		$this->set_network_summary_row( $this->create_blog(), self::factory()->user->create_many( 2 ) );
	}

	/**
	 * Renders the node for a screen and returns it.
	 *
	 * @param string $screen The screen to render for.
	 * @return object The presence-online node.
	 */
	private function render_node( $screen ) {
		$bar = new WP_Admin_Bar();
		wp_presence_admin_bar_node( $bar, $screen );

		return $bar->get_node( 'presence-online' );
	}

	public function test_a_network_screen_counts_the_network_and_links_its_online_view() {
		$node = $this->render_node( 'users-network' );

		$this->assertStringContainsString( '3 online', $node->title );
		$this->assertSame( wp_nonce_url( network_admin_url( 'users.php?presence_status=online' ), 'presence_online_filter' ), $node->href );
	}

	public function test_a_site_screen_counts_the_site() {
		$this->assertStringContainsString( '1 online', $this->render_node( 'users' )->title );
	}

	public function test_a_network_screen_counts_the_site_without_the_network_capability() {
		add_filter(
			'wp_presence_network_capability',
			static function () {
				return 'do_not_allow';
			}
		);

		$node = $this->render_node( 'users-network' );

		$this->assertStringContainsString( '1 online', $node->title );
		$this->assertSame( wp_nonce_url( admin_url( 'users.php?presence_status=online' ), 'presence_online_filter' ), $node->href );
	}

	public function test_a_network_screen_counts_the_site_when_the_network_does_not_aggregate() {
		add_filter( 'wp_presence_network_aggregation_enabled', '__return_false' );

		$this->assertStringContainsString( '1 online', $this->render_node( 'users-network' )->title );
	}
}
