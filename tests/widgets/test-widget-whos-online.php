<?php
/**
 * Tests for the Who's Online dashboard widget.
 *
 * @package Presence_API
 *
 * @group presence
 */
class WP_Test_Presence_Widget_Whos_Online extends WP_UnitTestCase {

	private static $editor_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id = $factory->user->create( array( 'role' => 'editor' ) );
	}

	public function tear_down() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "TRUNCATE TABLE {$wpdb->presence}" );
		parent::tear_down();
	}

	/**
	 * @covers WP_Presence_Widget_Whos_Online::heartbeat_received
	 */
	public function test_heartbeat_received_sets_presence() {
		wp_set_current_user( self::$editor_id );

		$response = WP_Presence_Widget_Whos_Online::heartbeat_received(
			array(),
			array(
				'presence-ping' => array(
					'screen' => 'dashboard',
				),
			),
			'dashboard'
		);

		$this->assertArrayHasKey( 'presence-online', $response );
		$this->assertCount( 1, $response['presence-online'] );
		$this->assertSame( self::$editor_id, $response['presence-online'][0]['user_id'] );
		$this->assertArrayHasKey( 'avatar_url', $response['presence-online'][0] );
		$this->assertArrayHasKey( 'date_gmt', $response['presence-online'][0] );
	}

	/**
	 * @covers WP_Presence_Widget_Whos_Online::heartbeat_received
	 */
	public function test_heartbeat_received_ignores_without_ping() {
		$response = WP_Presence_Widget_Whos_Online::heartbeat_received(
			array( 'existing' => true ),
			array(),
			'dashboard'
		);

		$this->assertArrayNotHasKey( 'presence-online', $response );
		$this->assertArrayHasKey( 'existing', $response );
	}

	/**
	 * @covers WP_Presence_Widget_Whos_Online::heartbeat_received
	 */
	public function test_heartbeat_response_returns_structured_data() {
		wp_set_current_user( self::$editor_id );

		$response = WP_Presence_Widget_Whos_Online::heartbeat_received(
			array(),
			array(
				'presence-ping' => array(
					'screen' => 'dashboard',
				),
			),
			'dashboard'
		);

		$entry = $response['presence-online'][0];

		// avatar_url should be a URL string, not HTML.
		$this->assertStringStartsWith( 'http', $entry['avatar_url'] );
		$this->assertStringNotContainsString( '<img', $entry['avatar_url'] );

		// date_gmt should be a datetime string, not pre-formatted.
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $entry['date_gmt'] );
	}

	/**
	 * @covers WP_Presence_Widget_Whos_Online::render
	 */
	public function test_render_includes_avatar_with_alt_text() {
		$user_id = self::factory()->user->create( array(
			'role'         => 'editor',
			'display_name' => 'Jane Doe',
		) );

		wp_set_current_user( $user_id );

		$data = array( 'presence-ping' => array( 'screen' => 'dashboard' ) );
		wp_presence_admin_heartbeat_received( array(), $data, 'dashboard' );

		wp_set_current_user( self::$editor_id );

		ob_start();
		WP_Presence_Widget_Whos_Online::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'class="presence-user-item"', $output );
		$this->assertMatchesRegularExpression( '/alt=["\']Jane Doe["\']/', $output );
	}
}

