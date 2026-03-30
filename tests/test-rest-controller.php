<?php
/**
 * Tests for the Presence REST controller.
 *
 * @package Presence_API
 * @since 7.1.0
 *
 * @group presence
 */
class WP_Test_Presence_REST_Controller extends WP_UnitTestCase {

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	private static $editor_id;

	/**
	 * Second editor user ID.
	 *
	 * @var int
	 */
	private static $editor_2_id;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private static $admin_id;

	/**
	 * Sets up fixtures before any tests run.
	 *
	 * @param WP_UnitTest_Factory $factory Test factory.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id   = $factory->user->create( array( 'role' => 'editor' ) );
		self::$editor_2_id = $factory->user->create( array( 'role' => 'editor' ) );
		self::$admin_id    = $factory->user->create( array( 'role' => 'administrator' ) );
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
	 * Tests that REST create_item prevents overwriting another user's entry.
	 *
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
	 * Tests that REST delete checks ownership via user_id column.
	 *
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
	 * Tests that REST delete allows admin to remove any entry.
	 *
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
	 * Tests that REST delete allows deleting lock- entries owned by the current user.
	 *
	 * @covers WP_REST_Presence_Controller::delete_item_permissions_check
	 */
	public function test_rest_delete_allows_own_lock_entries() {
		// Editor sets a lock entry.
		wp_set_presence( 'postType/post:1', 'lock-' . self::$editor_id, array(), self::$editor_id );

		// Same editor tries to delete it.
		wp_set_current_user( self::$editor_id );

		$request = new WP_REST_Request( 'DELETE', '/wp-presence/v1/presence' );
		$request->set_param( 'room', 'postType/post:1' );
		$request->set_param( 'client_id', 'lock-' . self::$editor_id );

		$controller = new WP_REST_Presence_Controller();
		$result     = $controller->delete_item_permissions_check( $request );

		$this->assertTrue( $result );
	}

	/**
	 * Tests that sanitize_data_param preserves nested arrays and scalar types.
	 *
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
	 * Tests that sanitize_data_param enforces depth limit.
	 *
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
	 * Tests that REST response filters by context.
	 *
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
		wp_set_current_user( self::$editor_id );
		wp_set_presence( 'admin/online', 'client-1', array(), self::$editor_id );
		wp_set_presence( 'postType/post:1', 'client-2', array(), self::$editor_2_id );

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
	 * Tests that sanitize_data_param strips HTML tags from string values.
	 *
	 * @covers WP_REST_Presence_Controller::sanitize_data_param
	 */
	public function test_sanitize_data_strips_html() {
		$controller = new WP_REST_Presence_Controller();

		$input  = array( 'msg' => '<script>alert("xss")</script>' );
		$result = $controller->sanitize_data_param( $input );

		$this->assertStringNotContainsString( '<script>', $result['msg'] );
		$this->assertStringNotContainsString( '</script>', $result['msg'] );
	}

	/**
	 * Tests that REST create_item requires room parameter.
	 *
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
	 * Tests that GET /presence includes pagination and cache headers.
	 *
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
	 * Tests that create_item returns 429 when user exceeds MAX_ENTRIES_PER_USER.
	 *
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
}
