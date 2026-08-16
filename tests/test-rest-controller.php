<?php
/**
 * Tests for the Presence REST controller.
 *
 * @package Presence_API
 *
 * @group presence
 *
 * @covers WP_REST_Presence_Controller
 */
class WP_Test_Presence_REST_Controller extends WP_Presence_UnitTestCase {

	private static $editor_id;
	private static $editor_2_id;
	private static $admin_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id   = $factory->user->create( array( 'role' => 'editor' ) );
		self::$editor_2_id = $factory->user->create( array( 'role' => 'editor' ) );
		self::$admin_id    = $factory->user->create( array( 'role' => 'administrator' ) );
	}

	public function tear_down() {
		delete_option( 'wp_presence_screen_revisions' );
		delete_option( 'wp_presence_screen_rev_options_general' );
		parent::tear_down();
	}

	/**
	 * @covers WP_REST_Presence_Controller::create_item
	 */
	public function test_rest_create_prevents_client_id_spoofing() {
		// Editor 1 sets presence.
		wp_set_presence( 'admin/online', 'user-' . self::$editor_id, array( 'screen' => 'dashboard' ), self::$editor_id );

		// Editor 2 tries to overwrite it via REST.
		wp_set_current_user( self::$editor_2_id );

		$request = new WP_REST_Request( 'POST', '/wp-presence/v1/presence' );
		$request->set_param( 'room', 'admin/online' );
		$request->set_param( 'client_id', 'user-' . self::$editor_id );
		$request->set_param( 'data', array( 'screen' => 'hacked' ) );

		$controller = new WP_REST_Presence_Controller();
		$response   = $controller->create_item( $request );

		$this->assertInstanceOf( 'WP_Error', $response );
		$this->assertSame( 'rest_presence_client_id_conflict', $response->get_error_code() );
	}

	/**
	 * @covers WP_REST_Presence_Controller::delete_item_permissions_check
	 */
	public function test_rest_delete_checks_user_id_ownership() {
		// Editor 1 sets presence.
		wp_set_presence( 'admin/online', 'user-' . self::$editor_id, array(), self::$editor_id );

		// Editor 2 tries to delete it.
		wp_set_current_user( self::$editor_2_id );

		$request = new WP_REST_Request( 'DELETE', '/wp-presence/v1/presence' );
		$request->set_param( 'room', 'admin/online' );
		$request->set_param( 'client_id', 'user-' . self::$editor_id );

		$controller = new WP_REST_Presence_Controller();
		$result     = $controller->delete_item_permissions_check( $request );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	/**
	 * @covers WP_REST_Presence_Controller::delete_item_permissions_check
	 */
	public function test_rest_delete_allows_admin() {
		// Editor sets presence.
		wp_set_presence( 'admin/online', 'user-' . self::$editor_id, array(), self::$editor_id );

		// Admin deletes it.
		wp_set_current_user( self::$admin_id );

		$request = new WP_REST_Request( 'DELETE', '/wp-presence/v1/presence' );
		$request->set_param( 'room', 'admin/online' );
		$request->set_param( 'client_id', 'user-' . self::$editor_id );

		$controller = new WP_REST_Presence_Controller();
		$result     = $controller->delete_item_permissions_check( $request );

		$this->assertTrue( $result );
	}

	/**
	 * @covers WP_REST_Presence_Controller::delete_item_permissions_check
	 */
	public function test_rest_delete_allows_own_lock_entries() {
		$post_id = self::factory()->post->create();
		$room    = 'postType/post:' . $post_id;

		// Editor sets a lock entry.
		wp_set_presence( $room, 'lock-' . self::$editor_id, array(), self::$editor_id );

		// Same editor tries to delete it.
		wp_set_current_user( self::$editor_id );

		$request = new WP_REST_Request( 'DELETE', '/wp-presence/v1/presence' );
		$request->set_param( 'room', $room );
		$request->set_param( 'client_id', 'lock-' . self::$editor_id );

		$controller = new WP_REST_Presence_Controller();
		$result     = $controller->delete_item_permissions_check( $request );

		$this->assertTrue( $result );
	}

	/**
	 * @covers WP_REST_Presence_Controller::sanitize_data_param
	 */
	public function test_sanitize_data_preserves_types() {
		$controller = new WP_REST_Presence_Controller();

		$input = array(
			'string_val' => 'hello',
			'int_val'    => 42,
			'float_val'  => 3.14,
			'bool_val'   => true,
			'nested'     => array(
				'inner' => 'value',
			),
		);

		$result = $controller->sanitize_data_param( $input );

		$this->assertSame( 'hello', $result['string_val'] );
		$this->assertSame( 42, $result['int_val'] );
		$this->assertSame( 3.14, $result['float_val'] );
		$this->assertTrue( $result['bool_val'] );
		$this->assertSame( 'value', $result['nested']['inner'] );
	}

	/**
	 * @covers WP_REST_Presence_Controller::sanitize_data_param
	 */
	public function test_sanitize_data_enforces_depth_limit() {
		$controller = new WP_REST_Presence_Controller();

		$input = array(
			'level1' => array(
				'level2' => array(
					'level3' => array(
						'level4' => 'too deep',
					),
				),
			),
		);

		$result = $controller->sanitize_data_param( $input );

		$this->assertSame( array(), $result['level1']['level2']['level3'] );
	}

	/**
	 * @covers WP_REST_Presence_Controller::prepare_item_for_response
	 */
	public function test_prepare_item_filters_by_context() {
		$controller = new WP_REST_Presence_Controller();

		$entry = (object) array(
			'room'      => 'test/room',
			'client_id' => 'client-1',
			'user_id'   => self::$editor_id,
			'data'      => array( 'screen' => 'dashboard' ),
			'date_gmt'  => gmdate( 'Y-m-d H:i:s' ),
		);

		// 'view' context should include date_gmt (it has context: ['view']).
		$request = new WP_REST_Request( 'GET', '/wp-presence/v1/presence' );
		$request->set_param( 'context', 'view' );

		$response = $controller->prepare_item_for_response( $entry, $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'room', $data );
		$this->assertArrayHasKey( 'date_gmt', $data );
	}

	/**
	 * room and client_id are varchar(191). Anything longer would be truncated by
	 * MySQL, so two distinct clients could collapse onto one UNIQUE KEY row.
	 *
	 * @covers WP_REST_Presence_Controller::register_routes
	 */
	public function test_rest_create_rejects_keys_wider_than_the_column() {
		wp_set_current_user( self::$editor_id );

		$request = new WP_REST_Request( 'POST', '/wp-presence/v1/presence' );
		$request->set_param( 'room', str_repeat( 'a', WP_PRESENCE_MAX_KEY_LENGTH + 1 ) );
		$request->set_param( 'client_id', 'client-1' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
		$this->assertCount( 0, wp_get_presence( str_repeat( 'a', WP_PRESENCE_MAX_KEY_LENGTH ) ), 'Nothing should have been written.' );
	}

	/**
	 * @covers WP_REST_Presence_Controller::register_routes
	 */
	public function test_rest_create_accepts_keys_at_the_column_width() {
		wp_set_current_user( self::$editor_id );

		$room = str_repeat( 'a', WP_PRESENCE_MAX_KEY_LENGTH );

		$request = new WP_REST_Request( 'POST', '/wp-presence/v1/presence' );
		$request->set_param( 'room', $room );
		$request->set_param( 'client_id', str_repeat( 'b', WP_PRESENCE_MAX_KEY_LENGTH ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status(), 'The boundary itself must still be accepted.' );
		$this->assertCount( 1, wp_get_presence( $room ) );
	}

	/**
	 * @covers WP_REST_Presence_Controller::register_routes
	 */
	public function test_rest_create_rejects_an_empty_room() {
		wp_set_current_user( self::$editor_id );

		$request = new WP_REST_Request( 'POST', '/wp-presence/v1/presence' );
		$request->set_param( 'room', '' );
		$request->set_param( 'client_id', 'client-1' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
	}

	/**
	 * The custom validate_callback on screen_key replaces the default one, so the
	 * schema's maxLength only applies if that callback delegates to it.
	 *
	 * @covers WP_REST_Presence_Controller::register_routes
	 */
	public function test_rest_screen_key_is_bounded_by_the_schema() {
		wp_set_current_user( self::$editor_id );

		$request = new WP_REST_Request( 'POST', '/wp-presence/v1/presence/screen-revisions/stale' );
		$request->set_param( 'screen_key', str_repeat( 'a', WP_PRESENCE_SCREEN_KEY_LIMIT + 1 ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
	}

	/**
	 * @covers WP_REST_Presence_Controller::get_rooms_permissions_check
	 */
	public function test_get_rooms_requires_edit_posts() {
		wp_set_current_user( self::$editor_id );
		wp_set_presence( 'admin/online', 'client-1', array(), self::$editor_id );

		$request  = new WP_REST_Request( 'GET', '/wp-presence/v1/presence/rooms' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * @covers WP_REST_Presence_Controller::get_rooms_permissions_check
	 */
	public function test_get_rooms_forbidden_for_subscriber() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$request  = new WP_REST_Request( 'GET', '/wp-presence/v1/presence/rooms' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * @covers WP_REST_Presence_Controller::get_rooms
	 */
	public function test_get_rooms_returns_data() {
		$post_id = self::factory()->post->create();
		$room    = 'postType/post:' . $post_id;

		wp_set_current_user( self::$editor_id );
		wp_set_presence( 'admin/online', 'client-1', array(), self::$editor_id );
		wp_set_presence( $room, 'client-2', array(), self::$editor_2_id );

		$request  = new WP_REST_Request( 'GET', '/wp-presence/v1/presence/rooms' );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 2, $data );
		$this->assertArrayHasKey( 'room', $data[0] );
		$this->assertArrayHasKey( 'user_count', $data[0] );
		$this->assertArrayHasKey( 'users', $data[0] );
	}

	/**
	 * @covers WP_REST_Presence_Controller::sanitize_data_param
	 * @covers WP_REST_Presence_Controller::sanitize_data_recursive
	 */
	public function test_sanitize_data_does_not_strip_html_or_whitespace() {
		$controller = new WP_REST_Presence_Controller();

		$input  = array(
			'html'      => '<script>alert("xss")</script>',
			'multiline' => "line 1\nline 2",
			'spaced'    => '  hello world  ',
		);
		$result = $controller->sanitize_data_param( $input );

		$this->assertSame( '<script>alert("xss")</script>', $result['html'] );
		$this->assertSame( "line 1\nline 2", $result['multiline'] );
		$this->assertSame( '  hello world  ', $result['spaced'] );
	}

	/**
	 * @covers WP_REST_Presence_Controller::create_item
	 */
	public function test_rest_create_requires_room() {
		wp_set_current_user( self::$editor_id );

		$request = new WP_REST_Request( 'POST', '/wp-presence/v1/presence' );
		$request->set_param( 'client_id', 'test-client' );
		// room is missing.

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * @covers WP_REST_Presence_Controller::get_items
	 */
	public function test_get_items_returns_headers() {
		wp_set_current_user( self::$editor_id );

		wp_set_presence( 'admin/online', 'client-1', array(), self::$editor_id );
		wp_set_presence( 'admin/online', 'client-2', array(), self::$editor_2_id );

		$request = new WP_REST_Request( 'GET', '/wp-presence/v1/presence' );
		$request->set_param( 'room', 'admin/online' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 2, (int) $response->get_headers()['X-WP-Total'] );
		$this->assertSame( 1, (int) $response->get_headers()['X-WP-TotalPages'] );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
	}

	/**
	 * @covers WP_REST_Presence_Controller::create_item
	 */
	public function test_rest_update_existing_entry_succeeds_at_limit() {
		wp_set_current_user( self::$editor_id );

		// Fill exactly to the limit (50), including one entry we'll try to refresh.
		wp_set_presence( 'room/target', 'client-target', array(), self::$editor_id );
		for ( $i = 0; $i < 49; $i++ ) {
			wp_set_presence( 'room/test-' . $i, 'client-' . $i, array(), self::$editor_id );
		}

		// Now at exactly 50 entries — refreshing an existing (room, client_id) should succeed.
		$request = new WP_REST_Request( 'POST', '/wp-presence/v1/presence' );
		$request->set_param( 'room', 'room/target' );
		$request->set_param( 'client_id', 'client-target' );
		$request->set_param( 'data', array( 'screen' => 'updated' ) );

		$controller = new WP_REST_Presence_Controller();
		$response   = $controller->create_item( $request );

		$this->assertNotWPError( $response );
	}

	/**
	 * @covers WP_REST_Presence_Controller::create_item
	 */
	public function test_rest_create_enforces_entry_limit() {
		wp_set_current_user( self::$editor_id );

		// Fill up to the limit (50).
		for ( $i = 0; $i < 50; $i++ ) {
			wp_set_presence( 'room/test-' . $i, 'client-' . $i, array(), self::$editor_id );
		}

		$request = new WP_REST_Request( 'POST', '/wp-presence/v1/presence' );
		$request->set_param( 'room', 'room/overflow' );
		$request->set_param( 'client_id', 'client-overflow' );

		$controller = new WP_REST_Presence_Controller();
		$response   = $controller->create_item( $request );

		$this->assertInstanceOf( 'WP_Error', $response );
		$this->assertSame( 'rest_presence_limit_exceeded', $response->get_error_code() );
	}

	/**
	 * @covers WP_REST_Presence_Controller::bump_screen_revision
	 */
	public function test_bump_screen_revision_success() {
		wp_set_current_user( self::$admin_id );

		$before = time();

		$request = new WP_REST_Request( 'POST', '/wp-presence/v1/presence/screen-revisions/stale' );
		$request->set_param( 'screen_key', 'options/general' );

		$controller = new WP_REST_Presence_Controller();
		$response   = $controller->bump_screen_revision( $request );

		$this->assertNotInstanceOf( 'WP_Error', $response );
		$data = $response->get_data();

		$this->assertSame( 'options/general', $data['screen_key'] );
		$this->assertGreaterThanOrEqual( $before, $data['rev'] );
	}

	/**
	 * @covers WP_REST_Presence_Controller::bump_screen_revision_permissions_check
	 */
	public function test_bump_screen_revision_permissions() {
		wp_set_current_user( self::$editor_id );

		$request = new WP_REST_Request( 'POST', '/wp-presence/v1/presence/screen-revisions/stale' );
		$request->set_param( 'screen_key', 'options/general' );

		$controller = new WP_REST_Presence_Controller();
		$result     = $controller->bump_screen_revision_permissions_check( $request );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	/**
	 * @covers WP_REST_Presence_Controller::bump_screen_revision
	 */
	public function test_bump_screen_revision_invalid_key() {
		wp_set_current_user( self::$admin_id );

		$request = new WP_REST_Request( 'POST', '/wp-presence/v1/presence/screen-revisions/stale' );
		$request->set_param( 'screen_key', '' );

		$controller = new WP_REST_Presence_Controller();
		$response   = $controller->bump_screen_revision( $request );

		$this->assertInstanceOf( 'WP_Error', $response );
		$this->assertSame( 'rest_invalid_screen_key', $response->get_error_code() );
	}

	/**
	 * @covers WP_REST_Presence_Controller::get_items
	 */
	public function test_get_items_includes_display_name_and_avatar_url() {
		wp_set_current_user( self::$editor_id );

		wp_set_presence( 'admin/online', 'client-1', array(), self::$editor_id );

		$request = new WP_REST_Request( 'GET', '/wp-presence/v1/presence' );
		$request->set_param( 'room', 'admin/online' );

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $data );

		$user = get_userdata( self::$editor_id );
		$this->assertSame( $user->display_name, $data[0]['display_name'] );
		$this->assertSame( get_avatar_url( self::$editor_id, array( 'size' => 32 ) ), $data[0]['avatar_url'] );
	}

	/**
	 * @covers WP_REST_Presence_Controller::get_items
	 */
	public function test_get_items_handles_deleted_user() {
		wp_set_current_user( self::$admin_id );

		// Create a temporary user.
		$temp_user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_presence( 'admin/online', 'client-temp', array(), $temp_user_id );

		// Delete the user. On multisite, wp_delete_user() only removes the user
		// from the current site, leaving them on the network and still
		// resolvable by get_userdata(), so the network-level delete is needed
		// to reach the "user no longer exists" state this test is about.
		if ( is_multisite() ) {
			require_once ABSPATH . 'wp-admin/includes/ms.php';
			wpmu_delete_user( $temp_user_id );
		} else {
			wp_delete_user( $temp_user_id );
		}

		$request = new WP_REST_Request( 'GET', '/wp-presence/v1/presence' );
		$request->set_param( 'room', 'admin/online' );

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );

		// The row should still be returned, but with empty fallback fields.
		$found_temp = null;
		foreach ( $data as $entry ) {
			if ( 'client-temp' === $entry['client_id'] ) {
				$found_temp = $entry;
				break;
			}
		}

		$this->assertNotNull( $found_temp );
		$this->assertSame( '', $found_temp['display_name'] );
		$this->assertSame( '', $found_temp['avatar_url'] );
	}

	/**
	 * @covers WP_REST_Presence_Controller::get_rooms
	 */
	public function test_get_rooms_filters_unauthorized_rooms() {
		// Create two author users.
		$author_1 = self::factory()->user->create( array( 'role' => 'author' ) );
		$author_2 = self::factory()->user->create( array( 'role' => 'author' ) );

		// Create a draft post owned by author 1.
		$post_id = self::factory()->post->create( array(
			'post_author' => $author_1,
			'post_status' => 'draft',
		) );

		// Set presence for Author 1 in that post room.
		$room = 'postType/post:' . $post_id;
		wp_set_presence( $room, 'client-author1', array(), $author_1 );

		// Also set presence in standard admin/online room for Author 2.
		wp_set_presence( 'admin/online', 'client-author2', array(), $author_2 );

		// Query /presence/rooms as Author 2.
		wp_set_current_user( $author_2 );

		$request  = new WP_REST_Request( 'GET', '/wp-presence/v1/presence/rooms' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data     = $response->get_data();

		// Author 2 should see 'admin/online' but NOT see the draft post room.
		$this->assertCount( 1, $data );
		$this->assertSame( 'admin/online', $data[0]['room'] );
	}

	/**
	 * @covers WP_REST_Presence_Controller::validate_data_param
	 */
	public function test_validate_data_param_rejects_non_object() {
		$controller = new WP_REST_Presence_Controller();

		$request = new WP_REST_Request( 'POST', '/wp-presence/v1/presence' );
		$result  = $controller->validate_data_param( 'not an object', $request, 'data' );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'rest_invalid_type', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * @covers WP_REST_Presence_Controller::validate_data_param
	 */
	public function test_validate_data_param_rejects_oversized_payload() {
		$controller = new WP_REST_Presence_Controller();

		$request   = new WP_REST_Request( 'POST', '/wp-presence/v1/presence' );
		$oversized = array( 'padding' => str_repeat( 'a', WP_REST_Presence_Controller::MAX_DATA_SIZE ) );
		$result    = $controller->validate_data_param( $oversized, $request, 'data' );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'rest_presence_data_too_large', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * @covers WP_REST_Presence_Controller::validate_data_param
	 */
	public function test_validate_data_param_accepts_an_object_within_the_size_limit() {
		$controller = new WP_REST_Presence_Controller();

		$request = new WP_REST_Request( 'POST', '/wp-presence/v1/presence' );
		$result  = $controller->validate_data_param( array( 'screen' => 'dashboard' ), $request, 'data' );

		$this->assertTrue( $result );
	}

	/**
	 * @covers WP_REST_Presence_Controller::delete_item
	 */
	public function test_delete_item_removes_the_entry_and_returns_expected_shape() {
		wp_set_current_user( self::$editor_id );
		wp_set_presence( 'admin/online', 'client-1', array(), self::$editor_id );

		$request = new WP_REST_Request( 'DELETE', '/wp-presence/v1/presence' );
		$request->set_param( 'room', 'admin/online' );
		$request->set_param( 'client_id', 'client-1' );

		$controller = new WP_REST_Presence_Controller();
		$response   = $controller->delete_item( $request );

		$this->assertSame(
			array(
				'deleted' => true,
				'room'    => 'admin/online',
			),
			$response->get_data()
		);
		$this->assertCount( 0, wp_get_presence( 'admin/online' ) );
	}

	/**
	 * rest_authorization_required_code() reports 401 when logged out and 403
	 * when authenticated but lacking the capability, so the two states must
	 * be checked separately rather than just asserting "not allowed".
	 *
	 * @covers WP_REST_Presence_Controller::get_items_permissions_check
	 */
	public function test_get_items_permissions_check_distinguishes_401_from_403() {
		$request    = new WP_REST_Request( 'GET', '/wp-presence/v1/presence' );
		$controller = new WP_REST_Presence_Controller();
		$request->set_param( 'room', 'admin/online' );

		wp_set_current_user( 0 );
		$logged_out = $controller->get_items_permissions_check( $request );
		$this->assertInstanceOf( 'WP_Error', $logged_out );
		$this->assertSame( 401, $logged_out->get_error_data()['status'] );

		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );
		$forbidden = $controller->get_items_permissions_check( $request );
		$this->assertInstanceOf( 'WP_Error', $forbidden );
		$this->assertSame( 403, $forbidden->get_error_data()['status'] );
	}

	/**
	 * @covers WP_REST_Presence_Controller::create_item_permissions_check
	 */
	public function test_create_item_permissions_check_distinguishes_401_from_403() {
		$request    = new WP_REST_Request( 'POST', '/wp-presence/v1/presence' );
		$controller = new WP_REST_Presence_Controller();
		$request->set_param( 'room', 'admin/online' );

		wp_set_current_user( 0 );
		$logged_out = $controller->create_item_permissions_check( $request );
		$this->assertInstanceOf( 'WP_Error', $logged_out );
		$this->assertSame( 401, $logged_out->get_error_data()['status'] );

		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );
		$forbidden = $controller->create_item_permissions_check( $request );
		$this->assertInstanceOf( 'WP_Error', $forbidden );
		$this->assertSame( 403, $forbidden->get_error_data()['status'] );
	}

	/**
	 * @covers WP_REST_Presence_Controller::delete_item_permissions_check
	 */
	public function test_delete_item_permissions_check_distinguishes_401_from_403() {
		$request    = new WP_REST_Request( 'DELETE', '/wp-presence/v1/presence' );
		$controller = new WP_REST_Presence_Controller();
		$request->set_param( 'room', 'admin/online' );
		$request->set_param( 'client_id', 'client-1' );

		wp_set_current_user( 0 );
		$logged_out = $controller->delete_item_permissions_check( $request );
		$this->assertInstanceOf( 'WP_Error', $logged_out );
		$this->assertSame( 401, $logged_out->get_error_data()['status'] );

		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );
		$forbidden = $controller->delete_item_permissions_check( $request );
		$this->assertInstanceOf( 'WP_Error', $forbidden );
		$this->assertSame( 403, $forbidden->get_error_data()['status'] );
	}

	/**
	 * @covers WP_REST_Presence_Controller::get_items
	 */
	public function test_get_items_includes_collaboration_headers_for_post_rooms() {
		$post_id = self::factory()->post->create();
		$room    = 'postType/post:' . $post_id;

		wp_set_current_user( self::$editor_id );

		// Set up 2 editors in the room.
		wp_set_presence( $room, 'editor-' . self::$editor_id, array( 'action' => 'editing' ), self::$editor_id );
		wp_set_presence( $room, 'editor-' . self::$editor_2_id, array( 'action' => 'editing' ), self::$editor_2_id );

		$request = new WP_REST_Request( 'GET', '/wp-presence/v1/presence' );
		$request->set_param( 'room', $room );
		$request->set_param( 'per_page', 100 );
		$request->set_param( 'page', 1 );

		$controller = new WP_REST_Presence_Controller();
		$response   = $controller->get_items( $request );

		$this->assertInstanceOf( 'WP_REST_Response', $response );

		$headers = $response->get_headers();

		$this->assertArrayHasKey( 'X-WP-Collaboration-Active', $headers );
		$this->assertArrayHasKey( 'X-WP-Editor-Count', $headers );

		$this->assertSame( 'true', $headers['X-WP-Collaboration-Active'] );
		$this->assertSame( '2', $headers['X-WP-Editor-Count'] );
	}

	/**
	 * @covers WP_REST_Presence_Controller::get_items
	 */
	public function test_get_items_collaboration_inactive_with_single_editor() {
		$post_id = self::factory()->post->create();
		$room    = 'postType/post:' . $post_id;

		wp_set_current_user( self::$editor_id );

		// Only one editor.
		wp_set_presence( $room, 'editor-' . self::$editor_id, array( 'action' => 'editing' ), self::$editor_id );

		$request = new WP_REST_Request( 'GET', '/wp-presence/v1/presence' );
		$request->set_param( 'room', $room );
		$request->set_param( 'per_page', 100 );
		$request->set_param( 'page', 1 );

		$controller = new WP_REST_Presence_Controller();
		$response   = $controller->get_items( $request );

		$headers = $response->get_headers();

		$this->assertSame( 'false', $headers['X-WP-Collaboration-Active'] );
		$this->assertSame( '1', $headers['X-WP-Editor-Count'] );
	}

	/**
	 * @covers WP_REST_Presence_Controller::get_items
	 */
	public function test_get_items_no_collaboration_headers_for_non_post_rooms() {
		wp_set_current_user( self::$editor_id );

		wp_set_presence( 'admin/online', 'user-' . self::$editor_id, array( 'screen' => 'dashboard' ), self::$editor_id );

		$request = new WP_REST_Request( 'GET', '/wp-presence/v1/presence' );
		$request->set_param( 'room', 'admin/online' );
		$request->set_param( 'per_page', 100 );
		$request->set_param( 'page', 1 );

		$controller = new WP_REST_Presence_Controller();
		$response   = $controller->get_items( $request );

		$headers = $response->get_headers();

		$this->assertArrayNotHasKey( 'X-WP-Collaboration-Active', $headers );
		$this->assertArrayNotHasKey( 'X-WP-Editor-Count', $headers );
	}
}
