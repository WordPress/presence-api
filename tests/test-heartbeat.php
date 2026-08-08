<?php
/**
 * Tests for the heartbeat presence write path.
 *
 * @package Presence_API
 *
 * @group presence
 */
class WP_Test_Presence_Heartbeat extends WP_UnitTestCase {

	private static $editor_id;
	private static $subscriber_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id     = $factory->user->create( array( 'role' => 'editor' ) );
		self::$subscriber_id = $factory->user->create( array( 'role' => 'subscriber' ) );
	}

	public function tear_down() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "TRUNCATE TABLE {$wpdb->presence}" );
		parent::tear_down();
	}

	/**
	 * @covers ::wp_presence_admin_heartbeat_received
	 */
	public function test_admin_heartbeat_writes_presence() {
		wp_set_current_user( self::$editor_id );

		$response = wp_presence_admin_heartbeat_received(
			array( 'existing' => true ),
			array(
				'presence-ping' => array(
					'screen' => 'dashboard',
				),
			),
			'dashboard'
		);

		$entries = wp_get_presence( 'admin/online' );

		$this->assertCount( 1, $entries );
		$this->assertSame( self::$editor_id, (int) $entries[0]->user_id );
		$this->assertSame( 'user-' . self::$editor_id, $entries[0]->client_id );
		$this->assertSame( 'dashboard', $entries[0]->data['screen'] );

		// The write path must not touch the response payload.
		$this->assertSame( array( 'existing' => true ), $response );
	}

	/**
	 * @covers ::wp_presence_admin_heartbeat_received
	 */
	public function test_admin_heartbeat_ignores_without_ping() {
		wp_set_current_user( self::$editor_id );

		wp_presence_admin_heartbeat_received( array(), array(), 'dashboard' );

		$this->assertCount( 0, wp_get_presence( 'admin/online' ) );
	}

	/**
	 * @covers ::wp_presence_admin_heartbeat_received
	 */
	public function test_admin_heartbeat_requires_edit_posts() {
		wp_set_current_user( self::$subscriber_id );

		wp_presence_admin_heartbeat_received(
			array(),
			array(
				'presence-ping' => array(
					'screen' => 'dashboard',
				),
			),
			'dashboard'
		);

		$this->assertCount( 0, wp_get_presence( 'admin/online' ) );
	}

	/**
	 * @covers ::wp_presence_admin_heartbeat_received
	 */
	public function test_admin_heartbeat_records_post_status() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		wp_set_current_user( self::$editor_id );

		wp_presence_admin_heartbeat_received(
			array(),
			array(
				'presence-ping'        => array(
					'screen' => 'post',
				),
				'wp-refresh-post-lock' => array(
					'post_id' => $post_id,
				),
			),
			'post'
		);

		$entries = wp_get_presence( 'admin/online' );

		$this->assertCount( 1, $entries );
		$this->assertSame( 'draft', $entries[0]->data['post_status'] );
	}

	/**
	 * @covers ::wp_presence_admin_heartbeat_received
	 */
	public function test_admin_heartbeat_records_front_end_context() {
		$post_id = self::factory()->post->create();

		wp_set_current_user( self::$editor_id );

		wp_presence_admin_heartbeat_received(
			array(),
			array(
				'presence-ping' => array(
					'screen'  => 'front',
					'title'   => 'Hello world!',
					'post_id' => $post_id,
				),
			),
			'front'
		);

		$entries = wp_get_presence( 'admin/online' );

		$this->assertCount( 1, $entries );
		$this->assertSame( 'Hello world!', $entries[0]->data['title'] );
		$this->assertSame( $post_id, $entries[0]->data['post_id'] );
	}

	/**
	 * The write must land before any widget reads the room on the same filter.
	 *
	 * @covers ::wp_presence_admin_heartbeat_received
	 */
	public function test_admin_heartbeat_runs_before_widget_read() {
		$this->assertLessThan(
			has_filter( 'heartbeat_received', array( 'WP_Presence_Widget_Whos_Online', 'heartbeat_received' ) ),
			has_filter( 'heartbeat_received', 'wp_presence_admin_heartbeat_received' )
		);
	}

	/**
	 * @covers ::wp_presence_enqueue_heartbeat_ping
	 */
	public function test_wp_presence_enqueue_heartbeat_ping() {
		wp_set_current_user( self::$editor_id );

		// Reset enqueued scripts.
		$wp_scripts = wp_scripts();
		$wp_scripts->queue = array();
		$wp_scripts->done  = array();

		wp_presence_enqueue_heartbeat_ping();

		$this->assertTrue( wp_script_is( 'wp-presence-ping', 'enqueued' ) );

		$wp_scripts = wp_scripts();
		$this->assertArrayHasKey( 'wp-presence-ping', $wp_scripts->registered );
		$extra = $wp_scripts->registered['wp-presence-ping']->extra;
		$this->assertArrayHasKey( 'before', $extra );

		$found_config = false;
		foreach ( $extra['before'] as $script ) {
			if ( $script && strpos( $script, 'window.wpPresenceConfig =' ) !== false ) {
				$found_config = true;
				break;
			}
		}
		$this->assertTrue( $found_config );
	}
}
