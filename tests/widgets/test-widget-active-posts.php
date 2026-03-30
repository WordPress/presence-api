<?php
/**
 * Tests for the Active Posts dashboard widget.
 *
 * @package Presence_API
 * @since 7.1.0
 *
 * @group presence
 */
class WP_Test_Presence_Widget_Active_Posts extends WP_UnitTestCase {

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
	private static $editor2_id;

	/**
	 * Test post ID.
	 *
	 * @var int
	 */
	private static $post_id;

	/**
	 * Sets up fixtures before any tests run.
	 *
	 * @param WP_UnitTest_Factory $factory Test factory.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id  = $factory->user->create( array( 'role' => 'editor' ) );
		self::$editor2_id = $factory->user->create( array( 'role' => 'editor' ) );
		self::$post_id    = $factory->post->create(
			array(
				'post_title' => 'Test Post',
				'post_type'  => 'post',
			)
		);
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
	 * Tests the heartbeat handler returns active post data grouped by post.
	 *
	 * @covers WP_Presence_Widget_Active_Posts::heartbeat_received
	 */
	public function test_heartbeat_received_returns_active_posts() {
		wp_set_current_user( self::$editor_id );

		$room = wp_presence_post_room( self::$post_id );
		wp_set_presence( $room, 'lock-' . self::$editor_id, array(), self::$editor_id );

		$response = WP_Presence_Widget_Active_Posts::heartbeat_received(
			array(),
			array( 'presence-active-posts-ping' => true ),
			'dashboard'
		);

		$this->assertArrayHasKey( 'presence-active-posts', $response );
		$this->assertCount( 1, $response['presence-active-posts'] );

		$post_entry = $response['presence-active-posts'][0];
		$this->assertSame( self::$post_id, $post_entry['post_id'] );
		$this->assertSame( 'Test Post', $post_entry['post_title'] );
		$this->assertSame( 'post', $post_entry['post_type'] );
		$this->assertArrayHasKey( 'edit_url', $post_entry );
		$this->assertArrayHasKey( 'editors', $post_entry );
		$this->assertCount( 1, $post_entry['editors'] );

		$editor = $post_entry['editors'][0];
		$this->assertSame( (int) self::$editor_id, $editor['user_id'] );
		$this->assertArrayHasKey( 'avatar_url', $editor );
		$this->assertArrayHasKey( 'status', $editor );
	}

	/**
	 * Tests the heartbeat handler ignores requests without the ping key.
	 *
	 * @covers WP_Presence_Widget_Active_Posts::heartbeat_received
	 */
	public function test_heartbeat_received_ignores_without_ping() {
		$response = WP_Presence_Widget_Active_Posts::heartbeat_received(
			array( 'existing' => true ),
			array(),
			'dashboard'
		);

		$this->assertArrayNotHasKey( 'presence-active-posts', $response );
		$this->assertArrayHasKey( 'existing', $response );
	}

	/**
	 * Tests that a recently active user shows as "active".
	 *
	 * @covers WP_Presence_Widget_Active_Posts::heartbeat_received
	 */
	public function test_active_status() {
		wp_set_current_user( self::$editor_id );

		$room = wp_presence_post_room( self::$post_id );
		wp_set_presence( $room, 'lock-' . self::$editor_id, array(), self::$editor_id );

		$response = WP_Presence_Widget_Active_Posts::heartbeat_received(
			array(),
			array( 'presence-active-posts-ping' => true ),
			'dashboard'
		);

		$this->assertSame( 'active', $response['presence-active-posts'][0]['editors'][0]['status'] );
	}

	/**
	 * Tests that a stale presence entry shows as "idle".
	 *
	 * @covers WP_Presence_Widget_Active_Posts::heartbeat_received
	 */
	public function test_idle_status() {
		global $wpdb;

		wp_set_current_user( self::$editor_id );

		$room = wp_presence_post_room( self::$post_id );
		wp_set_presence( $room, 'lock-' . self::$editor_id, array(), self::$editor_id );

		// Backdate the entry to exceed idle threshold.
		$wpdb->update(
			$wpdb->presence,
			array( 'date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 45 ) ),
			array( 'client_id' => 'lock-' . self::$editor_id ),
			array( '%s' ),
			array( '%s' )
		);

		$response = WP_Presence_Widget_Active_Posts::heartbeat_received(
			array(),
			array( 'presence-active-posts-ping' => true ),
			'dashboard'
		);

		$this->assertSame( 'idle', $response['presence-active-posts'][0]['editors'][0]['status'] );
	}

	/**
	 * Tests that multiple users editing different posts are returned.
	 *
	 * @covers WP_Presence_Widget_Active_Posts::heartbeat_received
	 */
	public function test_multiple_users_editing() {
		wp_set_current_user( self::$editor_id );

		$post2_id = self::factory()->post->create( array( 'post_title' => 'Second Post' ) );

		$room1 = wp_presence_post_room( self::$post_id );
		$room2 = wp_presence_post_room( $post2_id );

		wp_set_presence( $room1, 'lock-' . self::$editor_id, array(), self::$editor_id );
		wp_set_presence( $room2, 'lock-' . self::$editor2_id, array(), self::$editor2_id );

		$response = WP_Presence_Widget_Active_Posts::heartbeat_received(
			array(),
			array( 'presence-active-posts-ping' => true ),
			'dashboard'
		);

		$this->assertCount( 2, $response['presence-active-posts'] );
	}

	/**
	 * Tests that admin/online entries are not included.
	 *
	 * @covers WP_Presence_Widget_Active_Posts::heartbeat_received
	 */
	public function test_excludes_non_post_rooms() {
		wp_set_current_user( self::$editor_id );

		wp_set_presence( 'admin/online', 'user-' . self::$editor_id, array(), self::$editor_id );

		$response = WP_Presence_Widget_Active_Posts::heartbeat_received(
			array(),
			array( 'presence-active-posts-ping' => true ),
			'dashboard'
		);

		$this->assertCount( 0, $response['presence-active-posts'] );
	}
}
