<?php
/**
 * Tests for the Who's Online dashboard widget.
 *
 * @package Presence_API
 * @since 7.1.0
 *
 * @group presence
 */
class WP_Test_Presence_Widget_Whos_Online extends WP_UnitTestCase {

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	private static $editor_id;

	/**
	 * Sets up fixtures before any tests run.
	 *
	 * @param WP_UnitTest_Factory $factory Test factory.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id = $factory->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * Cleans up the presence table after each test.
	 */
	public function tear_down() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "TRUNCATE TABLE {$wpdb->presence}" );
		parent::tear_down();
	}

	/**
	 * Tests the heartbeat handler sets presence and returns data.
	 *
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
	 * Tests the heartbeat handler ignores requests without presence-ping.
	 *
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
	 * Tests that heartbeat response returns structured data, not HTML.
	 *
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
}
