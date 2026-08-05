<?php
/**
 * Tests for the plugin list table action links.
 *
 * @package Presence_API
 *
 * @group presence
 */
class WP_Test_Presence_Plugin_Action_Links extends WP_UnitTestCase {

	/**
	 * A representative set of the links WordPress passes to the filter.
	 *
	 * @return array<string, string>
	 */
	private function core_links() {
		return array(
			'deactivate' => '<a href="plugins.php?action=deactivate">Deactivate</a>',
		);
	}

	/**
	 * @covers ::wp_presence_plugin_action_links
	 */
	public function test_prepends_a_single_link() {
		$links = wp_presence_plugin_action_links( $this->core_links() );

		$this->assertCount( 2, $links );
		$this->assertSame( 'Deactivate', wp_strip_all_tags( end( $links ) ) );
	}

	/**
	 * @covers ::wp_presence_plugin_action_links
	 */
	public function test_preserves_existing_links_and_their_keys() {
		$existing = $this->core_links();

		$links = wp_presence_plugin_action_links( $existing );

		$this->assertArrayHasKey( 'deactivate', $links );
		$this->assertSame( $existing['deactivate'], $links['deactivate'] );
	}

	/**
	 * @covers ::wp_presence_plugin_action_links
	 */
	public function test_returns_only_our_link_when_no_links_exist() {
		$links = wp_presence_plugin_action_links( array() );

		$this->assertCount( 1, $links );
	}

	/**
	 * @covers ::wp_presence_plugin_action_links
	 */
	public function test_link_points_at_the_online_users_filter() {
		$links = wp_presence_plugin_action_links( array() );

		$this->assertSame( 1, preg_match( '#href="([^"]+)"#', $links[0], $matches ) );

		$href = html_entity_decode( $matches[1] );

		$this->assertStringStartsWith( admin_url( 'users.php' ), $href );
		$this->assertStringContainsString( 'presence_status=online', $href );
	}

	/**
	 * @covers ::wp_presence_plugin_action_links
	 */
	public function test_link_markup_is_a_single_anchor_with_a_label() {
		$links = wp_presence_plugin_action_links( array() );

		$this->assertSame( 1, substr_count( $links[0], '<a ' ) );
		$this->assertNotSame( '', trim( wp_strip_all_tags( $links[0] ) ) );
	}

	/**
	 * The URL is escaped on output, so an ampersand from add_query_arg()
	 * must not survive raw in the href.
	 *
	 * @covers ::wp_presence_plugin_action_links
	 */
	public function test_link_url_is_escaped() {
		$links = wp_presence_plugin_action_links( array() );

		$this->assertSame( 1, preg_match( '#href="([^"]+)"#', $links[0], $matches ) );
		$this->assertStringNotContainsString( ' ', $matches[1] );
		$this->assertDoesNotMatchRegularExpression( '/&(?!amp;|#\d+;)/', $matches[1] );
	}

	/**
	 * @covers ::wp_presence_plugin_action_links
	 */
	public function test_filter_is_registered_for_this_plugin_file() {
		$hook = 'plugin_action_links_' . plugin_basename( dirname( __DIR__ ) . '/presence-api.php' );

		$this->assertSame( 10, has_filter( $hook, 'wp_presence_plugin_action_links' ) );
	}

	/**
	 * @covers ::wp_presence_plugin_action_links
	 */
	public function test_filter_output_matches_direct_call() {
		$hook     = 'plugin_action_links_' . plugin_basename( dirname( __DIR__ ) . '/presence-api.php' );
		$existing = $this->core_links();

		$this->assertSame(
			wp_presence_plugin_action_links( $existing ),
			apply_filters( $hook, $existing )
		);
	}
}
