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
	 * Core pins an unfocused tab to a 120-second interval no client-side call can
	 * shorten, leaving the TTL as the only thing holding it in its room.
	 *
	 * @covers ::wp_presence_admin_heartbeat_received
	 */
	public function test_presence_outlives_the_unfocused_heartbeat_interval() {
		global $wpdb;

		// scheduleNextTick() in wp-includes/js/heartbeat.js.
		$unfocused_interval = 120;

		wp_set_current_user( self::$editor_id );

		wp_presence_admin_heartbeat_received(
			array(),
			array( 'presence-ping' => array( 'screen' => 'dashboard' ) ),
			'dashboard'
		);

		$wpdb->update(
			$wpdb->presence,
			array( 'date_gmt' => gmdate( 'Y-m-d H:i:s', time() - $unfocused_interval ) ),
			array( 'client_id' => 'user-' . self::$editor_id ),
			array( '%s' ),
			array( '%s' )
		);

		$this->assertCount(
			1,
			wp_get_presence( wp_presence_admin_room() ),
			'A tab pinging at the unfocused interval must not expire between ticks.'
		);
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
	 * @covers ::wp_presence_enqueue_heartbeat_ping
	 * @covers ::wp_presence_get_heartbeat_idle_ticks
	 * @covers ::wp_presence_get_heartbeat_idle_interval
	 */
	public function test_ping_config_carries_idle_backoff_settings() {
		wp_set_current_user( self::$editor_id );

		$wp_scripts        = wp_scripts();
		$wp_scripts->queue = array();
		$wp_scripts->done  = array();
		wp_deregister_script( 'wp-presence-ping' );

		wp_presence_enqueue_heartbeat_ping();

		$config = $this->get_ping_config();

		$this->assertSame( 5, $config['idleTicks'] );
		$this->assertSame( 45, $config['idleInterval'] );
		$this->assertSame( WP_PRESENCE_DEFAULT_TTL, $config['ttl'] );
	}

	/**
	 * @covers ::wp_presence_get_heartbeat_idle_ticks
	 */
	public function test_idle_ticks_filter_overrides_default() {
		add_filter(
			'wp_presence_heartbeat_idle_ticks',
			function () {
				return 3;
			}
		);

		$this->assertSame( 3, wp_presence_get_heartbeat_idle_ticks() );
	}

	/**
	 * @covers ::wp_presence_get_heartbeat_idle_interval
	 */
	public function test_idle_interval_filter_overrides_default() {
		add_filter(
			'wp_presence_heartbeat_idle_interval',
			function () {
				return 90;
			}
		);

		$this->assertSame( 90, wp_presence_get_heartbeat_idle_interval() );
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
	 * @covers ::wp_presence_editor_heartbeat_received
	 */
	public function test_editor_state_filter() {
		$post_id = self::factory()->post->create();

		wp_set_current_user( self::$editor_id );

		$filter_ran = false;
		add_filter(
			'wp_presence_editor_state',
			function ( $state, $passed_post_id, $passed_user_id ) use ( $post_id, &$filter_ran ) {
				$filter_ran           = true;
				$state['custom_data'] = 'test';
				$this->assertSame( $post_id, $passed_post_id );
				$this->assertSame( self::$editor_id, $passed_user_id );
				return $state;
			},
			10,
			3
		);

		wp_presence_editor_heartbeat_received(
			array(),
			array(
				'presence-editor-ping' => array(
					'post_id' => $post_id,
				),
			),
			'post'
		);

		$this->assertTrue( $filter_ran );

		$entries = wp_get_presence( wp_presence_post_room( $post_id ) );

		$this->assertCount( 1, $entries );
		$this->assertSame( 'test', $entries[0]->data['custom_data'] );
	}

	/**
	 * @covers ::wp_presence_check_collaboration_threshold
	 */
	public function test_collaboration_started_action() {
		$post_id  = self::factory()->post->create();
		$editor_2 = self::factory()->user->create( array( 'role' => 'editor' ) );

		$room = wp_presence_post_room( $post_id );

		$action_ran      = false;
		$passed_room     = null;
		$passed_entries  = null;

		add_action(
			'wp_presence_collaboration_started',
			function ( $room, $entries ) use ( &$action_ran, &$passed_room, &$passed_entries ) {
				$action_ran     = true;
				$passed_room    = $room;
				$passed_entries = $entries;
			},
			10,
			2
		);

		// First editor joins.
		wp_set_current_user( self::$editor_id );
		wp_presence_editor_heartbeat_received(
			array(),
			array(
				'presence-editor-ping' => array(
					'post_id' => $post_id,
				),
			),
			'post'
		);

		$this->assertFalse( $action_ran, 'Action should not fire with only 1 editor' );

		// Second editor joins.
		wp_set_current_user( $editor_2 );
		wp_presence_editor_heartbeat_received(
			array(),
			array(
				'presence-editor-ping' => array(
					'post_id' => $post_id,
				),
			),
			'post'
		);

		$this->assertTrue( $action_ran, 'Action should fire when 2nd editor joins' );
		$this->assertSame( $room, $passed_room );
		$this->assertCount( 2, $passed_entries );
	}

	/**
	 * @covers ::wp_presence_check_collaboration_threshold
	 */
	public function test_collaboration_ended_action() {
		$post_id  = self::factory()->post->create();
		$editor_2 = self::factory()->user->create( array( 'role' => 'editor' ) );

		$room = wp_presence_post_room( $post_id );

		// Set up 2 editors.
		wp_set_current_user( self::$editor_id );
		wp_presence_editor_heartbeat_received(
			array(),
			array(
				'presence-editor-ping' => array(
					'post_id' => $post_id,
				),
			),
			'post'
		);

		wp_set_current_user( $editor_2 );
		wp_presence_editor_heartbeat_received(
			array(),
			array(
				'presence-editor-ping' => array(
					'post_id' => $post_id,
				),
			),
			'post'
		);

		$action_ran     = false;
		$passed_room    = null;
		$passed_entries = null;

		add_action(
			'wp_presence_collaboration_ended',
			function ( $room, $entries ) use ( &$action_ran, &$passed_room, &$passed_entries ) {
				$action_ran     = true;
				$passed_room    = $room;
				$passed_entries = $entries;
			},
			10,
			2
		);

		// Remove second editor by letting their presence expire.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->presence,
			array( 'client_id' => 'editor-' . $editor_2 ),
			array( '%s' )
		);

		// First editor ticks again (now alone).
		wp_set_current_user( self::$editor_id );
		wp_presence_editor_heartbeat_received(
			array(),
			array(
				'presence-editor-ping' => array(
					'post_id' => $post_id,
				),
			),
			'post'
		);

		$this->assertTrue( $action_ran, 'Action should fire when going from 2 to 1 editor' );
		$this->assertSame( $room, $passed_room );
		$this->assertCount( 1, $passed_entries );
	}

	/**
	 * A tick is its own request, so the previous editor count has to be read
	 * back from storage rather than from anything held in memory. Seeding that
	 * storage is what stands in for "an earlier request already ran".
	 *
	 * @covers ::wp_presence_check_collaboration_threshold
	 */
	public function test_collaboration_ended_fires_on_state_left_by_an_earlier_request() {
		$post_id = self::factory()->post->create();
		$room    = wp_presence_post_room( $post_id );

		set_transient( wp_presence_collaboration_state_key( $room ), 2, HOUR_IN_SECONDS );

		$action_ran = false;
		add_action(
			'wp_presence_collaboration_ended',
			function () use ( &$action_ran ) {
				$action_ran = true;
			}
		);

		// One editor present, where an earlier request saw two.
		wp_set_current_user( self::$editor_id );
		wp_presence_editor_heartbeat_received(
			array(),
			array( 'presence-editor-ping' => array( 'post_id' => $post_id ) ),
			'post'
		);

		$this->assertTrue( $action_ran, 'Going 2 to 1 across requests should fire the end action' );
	}

	/**
	 * @covers ::wp_presence_check_collaboration_threshold
	 */
	public function test_collaboration_started_does_not_refire_while_two_editors_stay() {
		$post_id  = self::factory()->post->create();
		$editor_2 = self::factory()->user->create( array( 'role' => 'editor' ) );
		$room     = wp_presence_post_room( $post_id );

		wp_set_presence( $room, 'editor-' . $editor_2, array( 'screen' => 'post' ), $editor_2 );
		set_transient( wp_presence_collaboration_state_key( $room ), 2, HOUR_IN_SECONDS );

		$times_fired = 0;
		add_action(
			'wp_presence_collaboration_started',
			function () use ( &$times_fired ) {
				++$times_fired;
			}
		);

		// Three more ticks with both editors still here.
		for ( $i = 0; $i < 3; $i++ ) {
			wp_set_current_user( self::$editor_id );
			wp_presence_editor_heartbeat_received(
				array(),
				array( 'presence-editor-ping' => array( 'post_id' => $post_id ) ),
				'post'
			);
		}

		$this->assertSame( 0, $times_fired, 'Collaboration already started should not re-announce' );
	}

	/**
	 * @covers ::wp_presence_check_collaboration_threshold
	 */
	public function test_collaboration_state_survives_the_request_that_wrote_it() {
		$post_id  = self::factory()->post->create();
		$editor_2 = self::factory()->user->create( array( 'role' => 'editor' ) );
		$room     = wp_presence_post_room( $post_id );

		wp_set_presence( $room, 'editor-' . $editor_2, array( 'screen' => 'post' ), $editor_2 );

		wp_set_current_user( self::$editor_id );
		wp_presence_editor_heartbeat_received(
			array(),
			array( 'presence-editor-ping' => array( 'post_id' => $post_id ) ),
			'post'
		);

		$this->assertSame(
			2,
			get_transient( wp_presence_collaboration_state_key( $room ) ),
			'The count a request observed has to outlive it'
		);
	}

	/**
	 * @covers ::wp_presence_check_collaboration_threshold
	 */
	public function test_collaboration_state_expiry_is_pushed_forward_on_every_tick() {
		$post_id  = self::factory()->post->create();
		$editor_2 = self::factory()->user->create( array( 'role' => 'editor' ) );
		$room     = wp_presence_post_room( $post_id );
		$key      = wp_presence_collaboration_state_key( $room );

		wp_set_presence( $room, 'editor-' . $editor_2, array( 'screen' => 'post' ), $editor_2 );

		// Two editors already recorded, but about to lapse.
		set_transient( $key, 2, 5 );

		wp_set_current_user( self::$editor_id );
		wp_presence_editor_heartbeat_received(
			array(),
			array( 'presence-editor-ping' => array( 'post_id' => $post_id ) ),
			'post'
		);

		// Writing only when the count moves would leave the old 5s expiry in
		// place, and the pair would re-announce itself once it ran out. Derived
		// from the TTL rather than a literal so a change to it cannot quietly
		// make this assertion meaningless.
		$this->assertGreaterThan(
			time() + (int) floor( wp_presence_get_timeout( WP_PRESENCE_DEFAULT_TTL ) / 2 ),
			(int) get_option( '_transient_timeout_' . $key ),
			'An unchanged count still has to push the expiry forward'
		);
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
