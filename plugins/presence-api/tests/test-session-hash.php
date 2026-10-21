<?php
/**
 * Tests for the session hash that tells a second browser from a second tab.
 *
 * @package Presence_API
 *
 * @group presence
 */
class WP_Test_Presence_Session_Hash extends WP_Presence_UnitTestCase {

	/**
	 * Editor who writes the rows.
	 *
	 * @var int
	 */
	protected static $editor_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id = $factory->user->create( array( 'role' => 'editor' ) );
	}

	public function tear_down() {
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		parent::tear_down();
	}

	/**
	 * Signs the editor in with a fresh login session, as a browser of their own would.
	 *
	 * @return string The session token.
	 */
	private function sign_in() {
		$expiration = time() + DAY_IN_SECONDS;
		$token      = WP_Session_Tokens::get_instance( self::$editor_id )->create( $expiration );

		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( self::$editor_id, $expiration, 'logged_in', $token );
		wp_set_current_user( self::$editor_id );

		return $token;
	}

	/**
	 * Returns the session_hash stored for a client.
	 *
	 * @param string $room      Room.
	 * @param string $client_id Client ID.
	 * @return string The stored value.
	 */
	private function stored_hash( $room, $client_id ) {
		foreach ( wp_get_presence( $room ) as $entry ) {
			if ( $entry->client_id === $client_id ) {
				return $entry->session_hash;
			}
		}
		return null;
	}

	/**
	 * Two tabs of one login share a value and a second login gets another, and no token reaches the table.
	 *
	 * @covers ::wp_presence_session_hash
	 * @covers ::wp_set_presence
	 */
	public function test_tabs_share_a_session_and_browsers_do_not() {
		global $wpdb;

		$room = 'postType/post:1';

		$laptop = $this->sign_in();
		wp_set_presence( $room, 'tab-1', array(), array( 'user_id' => self::$editor_id ) );
		wp_set_presence( $room, 'tab-2', array(), array( 'user_id' => self::$editor_id ) );

		$phone = $this->sign_in();
		wp_set_presence( $room, 'tab-3', array(), array( 'user_id' => self::$editor_id ) );

		$first = $this->stored_hash( $room, 'tab-1' );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $first );
		$this->assertSame( $first, $this->stored_hash( $room, 'tab-2' ) );
		$this->assertNotSame( $first, $this->stored_hash( $room, 'tab-3' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = wp_json_encode( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->presence} WHERE room = %s", $room ) ) );
		$this->assertStringNotContainsString( $laptop, $rows );
		$this->assertStringNotContainsString( $phone, $rows );
	}

	/**
	 * Signing in again in the same tab changes what peers see, so it moves the room version like any other change.
	 *
	 * @covers ::wp_presence_write_row
	 */
	public function test_a_new_login_in_the_same_tab_moves_the_version() {
		$room = 'postType/post:1';

		$this->sign_in();
		wp_set_presence( $room, 'tab-1', array(), array( 'user_id' => self::$editor_id ) );
		$before = wp_get_presence_room_versions( $room )[ $room ];

		$this->sign_in();
		wp_set_presence( $room, 'tab-1', array(), array( 'user_id' => self::$editor_id ) );

		$this->assertSame( (string) ( $before + 1 ), wp_get_presence_room_versions( $room )[ $room ] );
	}

	/**
	 * The admin room keeps one row per person, so two browsers taking turns on it store no hash and move no version.
	 *
	 * @covers ::wp_presence_write_row
	 */
	public function test_browsers_taking_turns_on_the_admin_room_change_nothing() {
		if ( $GLOBALS['wpdb'] instanceof WP_SQLite_DB ) {
			$this->markTestSkipped( 'The SQLite integration counts every write as one a peer sees.' );
		}

		$room   = wp_presence_admin_room();
		$client = 'user-' . self::$editor_id;

		$this->sign_in();
		wp_set_presence( $room, $client, array(), array( 'user_id' => self::$editor_id ) );
		$before = wp_get_presence_room_versions( $room )[ $room ];

		$this->sign_in();
		wp_set_presence( $room, $client, array(), array( 'user_id' => self::$editor_id ) );

		$this->assertSame( '', $this->stored_hash( $room, $client ) );
		$this->assertSame( $before, wp_get_presence_room_versions( $room )[ $room ] );
	}

	/**
	 * A post room's `editor-{id}` row is one per person too, while a tab's own row in the same room keeps its hash.
	 *
	 * @covers ::wp_presence_session_hash
	 * @covers ::wp_presence_write_row
	 */
	public function test_browsers_taking_turns_on_a_post_rooms_editor_row_change_nothing() {
		if ( $GLOBALS['wpdb'] instanceof WP_SQLite_DB ) {
			$this->markTestSkipped( 'The SQLite integration counts every write as one a peer sees.' );
		}

		$room   = 'postType/post:1';
		$client = 'editor-' . self::$editor_id;

		$this->sign_in();
		wp_set_presence( $room, $client, array(), array( 'user_id' => self::$editor_id ) );
		wp_set_presence( $room, 'tab-1', array(), array( 'user_id' => self::$editor_id ) );
		$before = wp_get_presence_room_versions( $room )[ $room ];

		$this->sign_in();
		wp_set_presence( $room, $client, array(), array( 'user_id' => self::$editor_id ) );

		$this->assertSame( '', $this->stored_hash( $room, $client ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $this->stored_hash( $room, 'tab-1' ) );
		$this->assertSame( $before, wp_get_presence_room_versions( $room )[ $room ] );
	}

	/**
	 * One session hashes differently per room, so the value cannot follow it from room to room.
	 *
	 * @covers ::wp_presence_session_hash
	 */
	public function test_one_session_differs_per_room() {
		$this->sign_in();

		$this->assertNotSame( wp_presence_session_hash( 'postType/post:1' ), wp_presence_session_hash( 'postType/post:2' ) );
	}

	/**
	 * A write without the user's own session, or for another user, records no browser.
	 *
	 * @covers ::wp_set_presence
	 */
	public function test_writes_without_the_users_session_stay_empty() {
		$room = 'postType/post:1';

		wp_set_current_user( self::$editor_id );
		wp_set_presence( $room, 'cli-1', array(), array( 'user_id' => self::$editor_id ) );
		$this->assertSame( '', $this->stored_hash( $room, 'cli-1' ) );

		$this->sign_in();
		wp_set_presence( $room, 'agent-1', array(), array( 'user_id' => self::factory()->user->create() ) );
		$this->assertSame( '', $this->stored_hash( $room, 'agent-1' ) );
	}

	/**
	 * The personal data export names the browser a row came from, and leaves it out for a row with none.
	 *
	 * @covers ::wp_presence_personal_data_exporter
	 */
	public function test_the_export_includes_the_browser() {
		$room = 'postType/post:1';
		$this->sign_in();
		wp_set_presence( $room, 'tab-1', array(), array( 'user_id' => self::$editor_id ) );

		$export = wp_presence_personal_data_exporter( get_userdata( self::$editor_id )->user_email );
		$this->assertContains( wp_presence_session_hash( $room ), wp_list_pluck( $export['data'][0]['data'], 'value' ) );

		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		wp_set_presence( $room, 'tab-1', array(), array( 'user_id' => self::$editor_id ) );

		$export = wp_presence_personal_data_exporter( get_userdata( self::$editor_id )->user_email );
		$this->assertNotContains( 'Browser', wp_list_pluck( $export['data'][0]['data'], 'name' ) );
	}

	/**
	 * The REST collection returns the hash a browser wrote, and never the token behind it.
	 *
	 * @covers WP_REST_Presence_Controller::prepare_item_for_response
	 */
	public function test_rest_returns_the_hash() {
		$room  = 'postType/post:' . self::factory()->post->create( array( 'post_author' => self::$editor_id ) );
		$token = $this->sign_in();
		wp_set_presence( $room, 'tab-1', array(), array( 'user_id' => self::$editor_id ) );

		$request = new WP_REST_Request( 'GET', '/wp-presence/v1/presence' );
		$request->set_param( 'room', $room );
		$data = rest_get_server()->dispatch( $request )->get_data();

		$this->assertSame( wp_presence_session_hash( $room ), $data[0]['session_hash'] );
		$this->assertStringNotContainsString( $token, wp_json_encode( $data ) );
	}

	/**
	 * A REST write to the admin room answers with the empty hash it stored.
	 *
	 * @covers WP_REST_Presence_Controller::create_item
	 */
	public function test_rest_write_to_the_admin_room_returns_no_hash() {
		$this->sign_in();

		$request = new WP_REST_Request( 'POST', '/wp-presence/v1/presence' );
		$request->set_param( 'room', wp_presence_admin_room() );
		$request->set_param( 'client_id', 'tab-1' );
		$data = rest_get_server()->dispatch( $request )->get_data();

		$this->assertSame( '', $data['session_hash'] );
	}
}
