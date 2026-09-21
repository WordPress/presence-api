<?php
/**
 * Tests for the Network Admin plugin list table action links.
 *
 * A network-activated install is listed on the Network Admin Plugins screen,
 * which fires network_admin_plugin_action_links_{$plugin_file} rather than the
 * single-site filter, so it needs links of its own.
 *
 * @package Presence_API
 *
 * @group presence
 * @group ms-required
 */
class WP_Test_Presence_Network_Plugin_Action_Links extends WP_UnitTestCase {

	/**
	 * A representative set of the links WordPress passes to the filter.
	 *
	 * @return array<string, string>
	 */
	private function core_links() {
		return array(
			'deactivate' => '<a href="plugins.php?action=deactivate">Network Deactivate</a>',
		);
	}

	/**
	 * The decoded href of one of our links.
	 *
	 * @param string $link Link markup.
	 * @return string
	 */
	private function href_of( $link ) {
		$this->assertSame( 1, preg_match( '#href="([^"]+)"#', $link, $matches ) );

		return html_entity_decode( $matches[1] );
	}

	/**
	 * @covers ::wp_presence_network_plugin_action_links
	 */
	public function test_prepends_both_of_our_links() {
		$links = wp_presence_network_plugin_action_links( $this->core_links() );

		$this->assertCount( 3, $links );
		$this->assertSame( 'Network Deactivate', wp_strip_all_tags( end( $links ) ) );
	}

	/**
	 * @covers ::wp_presence_network_plugin_action_links
	 */
	public function test_preserves_existing_links_and_their_keys() {
		$existing = $this->core_links();

		$links = wp_presence_network_plugin_action_links( $existing );

		$this->assertArrayHasKey( 'deactivate', $links );
		$this->assertSame( $existing['deactivate'], $links['deactivate'] );
	}

	/**
	 * @covers ::wp_presence_network_plugin_action_links
	 */
	public function test_returns_only_our_links_when_no_links_exist() {
		$links = wp_presence_network_plugin_action_links( array() );

		$this->assertCount( 2, $links );
	}

	/**
	 * @covers ::wp_presence_network_plugin_action_links
	 */
	public function test_online_users_link_points_at_the_network_online_users_filter() {
		$links = wp_presence_network_plugin_action_links( array() );

		$href = $this->href_of( $links[0] );

		$this->assertStringStartsWith( network_admin_url( 'users.php' ), $href );
		$this->assertStringContainsString( 'presence_status=online', $href );
		$this->assertSame( 'View Online Users', wp_strip_all_tags( $links[0] ) );
	}

	/**
	 * The network Users filter ignores a request without the
	 * presence_online_filter nonce, so a link without one would list every user.
	 *
	 * @covers ::wp_presence_network_plugin_action_links
	 */
	public function test_online_users_link_carries_a_nonce_the_filter_accepts() {
		$links = wp_presence_network_plugin_action_links( array() );

		wp_parse_str( (string) wp_parse_url( $this->href_of( $links[0] ), PHP_URL_QUERY ), $query );

		$this->assertArrayHasKey( '_wpnonce', $query );
		$this->assertNotFalse( wp_verify_nonce( $query['_wpnonce'], 'presence_online_filter' ) );
	}

	/**
	 * @covers ::wp_presence_network_plugin_action_links
	 */
	public function test_settings_link_points_at_network_settings() {
		$links = wp_presence_network_plugin_action_links( array() );

		$this->assertSame( network_admin_url( 'settings.php' ), $this->href_of( $links[1] ) );
		$this->assertSame( 'Settings', wp_strip_all_tags( $links[1] ) );
	}

	/**
	 * @covers ::wp_presence_network_plugin_action_links
	 */
	public function test_link_markup_is_a_single_anchor_with_a_label() {
		$links = wp_presence_network_plugin_action_links( array() );

		foreach ( $links as $link ) {
			$this->assertSame( 1, substr_count( $link, '<a ' ) );
			$this->assertNotSame( '', trim( wp_strip_all_tags( $link ) ) );
		}
	}

	/**
	 * The URL is escaped on output, so the ampersands from the query string
	 * and the nonce must not survive raw in the href.
	 *
	 * @covers ::wp_presence_network_plugin_action_links
	 */
	public function test_link_url_is_escaped() {
		$links = wp_presence_network_plugin_action_links( array() );

		$this->assertSame( 1, preg_match( '#href="([^"]+)"#', $links[0], $matches ) );
		$this->assertStringNotContainsString( ' ', $matches[1] );
		$this->assertDoesNotMatchRegularExpression( '/&(?!amp;|#\d+;)/', $matches[1] );
	}

	/**
	 * @covers ::wp_presence_network_plugin_action_links
	 */
	public function test_filter_is_registered_for_this_plugin_file() {
		$hook = 'network_admin_plugin_action_links_' . plugin_basename( dirname( __DIR__ ) . '/presence-api.php' );

		$this->assertSame( 10, has_filter( $hook, 'wp_presence_network_plugin_action_links' ) );
	}

	/**
	 * @covers ::wp_presence_network_plugin_action_links
	 */
	public function test_filter_output_matches_direct_call() {
		$hook     = 'network_admin_plugin_action_links_' . plugin_basename( dirname( __DIR__ ) . '/presence-api.php' );
		$existing = $this->core_links();

		$this->assertSame(
			wp_presence_network_plugin_action_links( $existing ),
			apply_filters( $hook, $existing )
		);
	}
}
