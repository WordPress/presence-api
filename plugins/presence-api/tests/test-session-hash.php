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
	 * Two tabs of one login share a value and a second login gets another, and neither is the token.
	 *
	 * @covers ::wp_presence_session_hash
	 * @covers ::wp_set_presence
	 */
	public function test_tabs_share_a_session_and_browsers_do_not() {
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
		$this->assertStringNotContainsString( $first, $laptop );
		$this->assertStringNotContainsString( $this->stored_hash( $room, 'tab-3' ), $phone );
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
	 * The REST collection returns the hash a browser wrote.
	 *
	 * @covers WP_REST_Presence_Controller::prepare_item_for_response
	 */
	public function test_rest_returns_the_hash() {
		$room = 'postType/post:' . self::factory()->post->create( array( 'post_author' => self::$editor_id ) );
		$this->sign_in();
		wp_set_presence( $room, 'tab-1', array(), array( 'user_id' => self::$editor_id ) );

		$request = new WP_REST_Request( 'GET', '/wp-presence/v1/presence' );
		$request->set_param( 'room', $room );
		$data = rest_get_server()->dispatch( $request )->get_data();

		$this->assertSame( wp_presence_session_hash( $room ), $data[0]['session_hash'] );
	}
}
