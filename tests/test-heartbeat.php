<?php
/**
 * Tests for the heartbeat presence write path.
 *
 * @package Presence_API
 *
 * @group presence
 */
class WP_Test_Presence_Heartbeat extends WP_Presence_UnitTestCase {

	private static $editor_id;
	private static $subscriber_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id     = $factory->user->create( array( 'role' => 'editor' ) );
		self::$subscriber_id = $factory->user->create( array( 'role' => 'subscriber' ) );
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

	/**
	 * @covers ::wp_presence_editor_heartbeat_received
	 */
	public function test_editor_heartbeat_writes_presence() {
		$post_id = self::factory()->post->create();

		wp_set_current_user( self::$editor_id );

		$response = wp_presence_editor_heartbeat_received(
			array( 'existing' => true ),
			array(
				'presence-editor-ping' => array(
					'post_id' => $post_id,
				),
			),
			'post'
		);

		$entries = wp_get_presence( wp_presence_post_room( $post_id ) );

		$this->assertCount( 1, $entries );
		$this->assertSame( 'editor-' . self::$editor_id, $entries[0]->client_id );
		$this->assertSame( 'editing', $entries[0]->data['action'] );
		$this->assertSame( 'post', $entries[0]->data['screen'] );

		$this->assertSame( array( 'existing' => true ), $response );
	}

	/**
	 * @covers ::wp_presence_editor_heartbeat_received
	 */
	public function test_editor_heartbeat_requires_edit_cap() {
		$post_id = self::factory()->post->create();

		wp_set_current_user( self::$subscriber_id );

		wp_presence_editor_heartbeat_received(
			array(),
			array(
				'presence-editor-ping' => array(
					'post_id' => $post_id,
				),
			),
			'post'
		);

		$this->assertCount( 0, wp_get_presence( wp_presence_post_room( $post_id ) ) );
	}

	/**
	 * @covers ::wp_presence_editor_heartbeat_received
	 */
	public function test_editor_heartbeat_marks_locked_when_the_post_lock_refreshes() {
		$post_id = self::factory()->post->create();

		wp_set_current_user( self::$editor_id );

		wp_presence_editor_heartbeat_received(
			array(),
			array(
				'presence-editor-ping' => array(
					'post_id' => $post_id,
				),
				'wp-refresh-post-lock' => array(
					'post_id' => $post_id,
				),
			),
			'post'
		);

		$entries = wp_get_presence( wp_presence_post_room( $post_id ) );

		$this->assertCount( 1, $entries );
		$this->assertTrue( $entries[0]->data['locked'] );
	}

	/**
	 * A tick without a lock refresh is what a stale lock now looks like, so the
	 * flag has to go back to false rather than linger from the previous write.
	 *
	 * @covers ::wp_presence_editor_heartbeat_received
	 */
	public function test_editor_heartbeat_clears_locked_without_a_post_lock_refresh() {
		$post_id = self::factory()->post->create();
		$payload = array(
			'presence-editor-ping' => array(
				'post_id' => $post_id,
			),
			'wp-refresh-post-lock' => array(
				'post_id' => $post_id,
			),
		);

		wp_set_current_user( self::$editor_id );

		wp_presence_editor_heartbeat_received( array(), $payload, 'post' );

		unset( $payload['wp-refresh-post-lock'] );
		wp_presence_editor_heartbeat_received( array(), $payload, 'post' );

		$entries = wp_get_presence( wp_presence_post_room( $post_id ) );

		$this->assertCount( 1, $entries );
		$this->assertFalse( $entries[0]->data['locked'] );
	}

	/**
	 * The lock refresh is for a post other than the one being pinged, so it says
	 * nothing about this room.
	 *
	 * @covers ::wp_presence_editor_heartbeat_received
	 */
	public function test_editor_heartbeat_ignores_a_lock_refresh_for_another_post() {
		$post_id  = self::factory()->post->create();
		$other_id = self::factory()->post->create();

		wp_set_current_user( self::$editor_id );

		wp_presence_editor_heartbeat_received(
			array(),
			array(
				'presence-editor-ping' => array(
					'post_id' => $post_id,
				),
				'wp-refresh-post-lock' => array(
					'post_id' => $other_id,
				),
			),
			'post'
		);

		$entries = wp_get_presence( wp_presence_post_room( $post_id ) );

		$this->assertCount( 1, $entries );
		$this->assertFalse( $entries[0]->data['locked'] );
	}

	/**
	 * Regression guard for the double entry: one person in one editor is one
	 * row in the post room, however many heartbeat handlers see the tick.
	 *
	 * @covers ::wp_presence_editor_heartbeat_received
	 * @covers ::wp_presence_bridge_post_lock
	 */
	public function test_one_editing_user_occupies_one_row() {
		$post_id = self::factory()->post->create();
		$payload = array(
			'presence-editor-ping' => array(
				'post_id' => $post_id,
			),
			'wp-refresh-post-lock' => array(
				'post_id' => $post_id,
			),
		);

		wp_set_current_user( self::$editor_id );

		wp_presence_editor_heartbeat_received( array(), $payload, 'post' );
		wp_presence_bridge_post_lock( array(), $payload, 'post' );

		$this->assertCount( 1, wp_get_presence( wp_presence_post_room( $post_id ) ) );
	}

	/**
	 * With one entry per editing user there is no second client to clean up on
	 * pagehide, and registering one would DELETE a row that never existed.
	 *
	 * @covers ::wp_presence_enqueue_heartbeat_ping
	 */
	public function test_pagehide_entries_omit_a_separate_lock_client() {
		global $post;

		$post_id = self::factory()->post->create();

		wp_set_current_user( self::$editor_id );

		$post = get_post( $post_id );
		set_current_screen( 'post' );

		// wp_add_inline_script() appends, so a config printed by an earlier test
		// would still be sitting in `extra` and would be the one read back.
		wp_deregister_script( 'wp-presence-ping' );

		$wp_scripts        = wp_scripts();
		$wp_scripts->queue = array();
		$wp_scripts->done  = array();

		wp_presence_enqueue_heartbeat_ping();

		$config     = $this->get_ping_config();
		$client_ids = wp_list_pluck( $config['entries'], 'client_id' );

		$this->assertContains( 'editor-' . self::$editor_id, $client_ids );
		$this->assertNotContains( 'lock-' . self::$editor_id, $client_ids );
	}

	/**
	 * Decodes the wpPresenceConfig object handed to presence-ping.js.
	 *
	 * @return array The decoded config.
	 */
	private function get_ping_config() {
		$extra = wp_scripts()->registered['wp-presence-ping']->extra;

		foreach ( $extra['before'] as $script ) {
			if ( ! $script || false === strpos( $script, 'window.wpPresenceConfig =' ) ) {
				continue;
			}

			$json = trim( substr( $script, strpos( $script, '=' ) + 1 ) );

			return json_decode( rtrim( $json, ';' ), true );
		}

		$this->fail( 'The presence ping config was not printed.' );
	}
}
