<?php
/**
 * Tests for the Who's Online dashboard widget.
 *
 * @package Presence_API
 *
 * @group presence
 *
 * @covers WP_Presence_Widget_Whos_Online
 */
class WP_Test_Presence_Widget_Whos_Online extends WP_Presence_UnitTestCase {

	private static $editor_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id = $factory->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * Sends a ping through the write handler, then the widget's read handler,
	 * mirroring the priority order they are registered at on heartbeat_received.
	 *
	 * @param array $ping  The presence-ping payload.
	 * @param array $extra Additional top-level Heartbeat data keys.
	 * @return array The Heartbeat response.
	 */
	private function tick( $ping = array( 'screen' => 'dashboard' ), $extra = array() ) {
		$data = array_merge( array( 'presence-ping' => $ping ), $extra );

		wp_presence_admin_heartbeat_received( array(), $data, 'dashboard' );

		return WP_Presence_Widget_Whos_Online::heartbeat_received( array(), $data, 'dashboard' );
	}

	/**
	 * Adds another user to the admin room with an explicit age.
	 *
	 * @param string $screen      The screen slug to record.
	 * @param int    $seconds_ago How far in the past to date the entry.
	 * @return int The new user's ID.
	 */
	private function add_user_to_room( $screen, $seconds_ago ) {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		wp_set_presence( wp_presence_admin_room(), 'user-' . $user_id, array( 'screen' => $screen ), $user_id );

		$this->age_entry( $user_id, $seconds_ago );

		return $user_id;
	}

	/**
	 * Backdates a user's entry, which is what wp_get_presence() orders by.
	 *
	 * @param int $user_id     The user whose entry to backdate.
	 * @param int $seconds_ago How far in the past to date the entry.
	 */
	private function age_entry( $user_id, $seconds_ago ) {
		global $wpdb;

		$wpdb->update(
			$wpdb->presence,
			array( 'date_gmt' => gmdate( 'Y-m-d H:i:s', time() - $seconds_ago ) ),
			array( 'client_id' => 'user-' . $user_id ),
			array( '%s' ),
			array( '%s' )
		);
	}

	/**
	 * @covers WP_Presence_Widget_Whos_Online::heartbeat_received
	 */
	public function test_heartbeat_received_returns_online_users() {
		wp_set_current_user( self::$editor_id );

		$response = $this->tick();

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

		$response = $this->tick();

		$entry = $response['presence-online'][0];

		// avatar_url should be a URL string, not HTML.
		$this->assertStringStartsWith( 'http', $entry['avatar_url'] );
		$this->assertStringNotContainsString( '<img', $entry['avatar_url'] );

		// date_gmt should be a datetime string, not pre-formatted.
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $entry['date_gmt'] );
	}

	/**
	 * @covers WP_Presence_Widget_Whos_Online::heartbeat_received
	 */
	public function test_heartbeat_returns_state_hash_alongside_payload() {
		wp_set_current_user( self::$editor_id );

		$response = $this->tick();

		$this->assertArrayHasKey( 'presence-online-hash', $response );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $response['presence-online-hash'] );
	}

	/**
	 * @covers WP_Presence_Widget_Whos_Online::heartbeat_received
	 */
	public function test_heartbeat_skips_payload_when_hash_matches() {
		wp_set_current_user( self::$editor_id );

		$hash = $this->tick()['presence-online-hash'];

		$response = $this->tick( array( 'screen' => 'dashboard' ), array( 'presence-online-hash' => $hash ) );

		$this->assertArrayHasKey( 'presence-online-unchanged', $response );
		$this->assertArrayNotHasKey( 'presence-online', $response );
	}

	/**
	 * The unchanged reply is all that keeps the idle dots honest, so it has to
	 * carry real last-seen times rather than a bare flag.
	 *
	 * @covers WP_Presence_Widget_Whos_Online::heartbeat_received
	 */
	public function test_unchanged_response_carries_last_seen_timestamps() {
		$idle_id = $this->add_user_to_room( 'dashboard', 40 );

		wp_set_current_user( self::$editor_id );

		$hash = $this->tick()['presence-online-hash'];

		$before = time();
		$seen   = $this->tick( array( 'screen' => 'dashboard' ), array( 'presence-online-hash' => $hash ) )['presence-online-unchanged'];
		$after  = time();

		$this->assertSame(
			gmdate( 'Y-m-d H:i:s', time() - 40 ),
			$seen->{$idle_id},
			'A user who stopped pinging must keep their stale timestamp.'
		);

		// The pinging user's timestamp should be within the tick window.
		$actual   = strtotime( $seen->{self::$editor_id} );
		$this->assertGreaterThanOrEqual( $before, $actual, 'Timestamp should not be before tick started.' );
		$this->assertLessThanOrEqual( $after, $actual, 'Timestamp should not be after tick finished.' );
	}

	/**
	 * @covers WP_Presence_Widget_Whos_Online::heartbeat_received
	 */
	public function test_heartbeat_returns_payload_when_hash_is_stale() {
		wp_set_current_user( self::$editor_id );

		$this->tick();

		$response = $this->tick( array( 'screen' => 'dashboard' ), array( 'presence-online-hash' => 'stale' ) );

		$this->assertArrayHasKey( 'presence-online', $response );
		$this->assertArrayNotHasKey( 'presence-online-unchanged', $response );
	}

	/**
	 * The optimization is worthless if the hash moves on its own: every tick
	 * rewrites the pinging user's timestamp, and wp_get_presence() orders by it.
	 *
	 * @covers WP_Presence_Widget_Whos_Online::heartbeat_received
	 */
	public function test_hash_is_stable_when_only_timestamps_change() {
		$older = $this->add_user_to_room( 'edit', 20 );
		$this->add_user_to_room( 'upload', 10 );

		wp_set_current_user( self::$editor_id );

		$first = $this->tick()['presence-online-hash'];

		// Overtake the other entry, flipping the order the rows come back in.
		$this->age_entry( $older, 5 );

		$second = $this->tick()['presence-online-hash'];

		$this->assertSame( $first, $second );
	}

	/**
	 * @covers WP_Presence_Widget_Whos_Online::heartbeat_received
	 */
	public function test_hash_changes_when_a_users_screen_changes() {
		$other_id = $this->add_user_to_room( 'edit', 10 );

		wp_set_current_user( self::$editor_id );

		$first = $this->tick()['presence-online-hash'];

		wp_set_presence( wp_presence_admin_room(), 'user-' . $other_id, array( 'screen' => 'upload' ), $other_id );

		$second = $this->tick()['presence-online-hash'];

		$this->assertNotSame( $first, $second );
	}

	/**
	 * @covers WP_Presence_Widget_Whos_Online::heartbeat_received
	 */
	public function test_hash_changes_when_a_user_leaves() {
		$other_id = $this->add_user_to_room( 'edit', 10 );

		wp_set_current_user( self::$editor_id );

		$first = $this->tick()['presence-online-hash'];

		wp_remove_presence( wp_presence_admin_room(), 'user-' . $other_id );

		$second = $this->tick()['presence-online-hash'];

		$this->assertNotSame( $first, $second );
	}

	/**
	 * Heartbeat payload is capped to visible rows plus overflow threshold.
	 *
	 * @covers WP_Presence_Widget_Whos_Online::heartbeat_received
	 */
	public function test_heartbeat_payload_is_capped_to_expandable_list_max() {
		wp_set_current_user( self::$editor_id );

		// Create more users than VISIBLE_ROWS + OVERFLOW_THRESHOLD (3 + 20 = 23).
		for ( $i = 0; $i < 30; $i++ ) {
			$this->add_user_to_room( 'dashboard', 0 );
		}

		$response = $this->tick();

		$this->assertArrayHasKey( 'presence-online', $response );
		$this->assertArrayHasKey( 'presence-online-total', $response );

		// Should only send VISIBLE_ROWS + OVERFLOW_THRESHOLD entries.
		$this->assertCount( 23, $response['presence-online'] );

		// Total should reflect all users.
		$this->assertSame( 31, $response['presence-online-total'] );
	}

	/**
	 * @covers WP_Presence_Widget_Whos_Online::render
	 * @covers WP_Presence_Widget_Whos_Online::render_user_row
	 */
	public function test_render_includes_avatar_with_alt_text() {
		$user_id = self::factory()->user->create( array(
			'role'         => 'editor',
			'display_name' => 'John Doe',
		) );

		wp_set_presence(
			wp_presence_admin_room(),
			'client-1',
			array(),
			$user_id
		);

		wp_set_current_user( self::$editor_id );

		ob_start();
		WP_Presence_Widget_Whos_Online::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'class="presence-user-item"', $output );
		$this->assertMatchesRegularExpression( '/alt=["\']John Doe["\']/', $output );
	}
}
