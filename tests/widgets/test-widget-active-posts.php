<?php
/**
 * Tests for the Active Posts dashboard widget.
 *
 * @package Presence_API
 *
 * @group presence
 *
 * @covers WP_Presence_Widget_Active_Posts
 */
class WP_Test_Presence_Widget_Active_Posts extends WP_Presence_UnitTestCase {

	private static $editor_id;
	private static $editor2_id;
	private static $contributor_id;
	private static $post_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id      = $factory->user->create( array( 'role' => 'editor' ) );
		self::$editor2_id     = $factory->user->create( array( 'role' => 'editor' ) );
		self::$contributor_id = $factory->user->create( array( 'role' => 'contributor' ) );
		self::$post_id        = $factory->post->create(
			array(
				'post_title' => 'Test Post',
				'post_type'  => 'post',
			)
		);
	}

	/**
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

	/**
	 * A contributor has `edit_posts`, which is all the widget itself requires,
	 * but cannot edit someone else's post and must not learn it is being
	 * worked on.
	 *
	 * @covers WP_Presence_Widget_Active_Posts::heartbeat_received
	 */
	public function test_heartbeat_excludes_posts_the_user_cannot_edit() {
		$room = wp_presence_post_room( self::$post_id );
		wp_set_presence( $room, 'lock-' . self::$editor_id, array(), self::$editor_id );

		wp_set_current_user( self::$contributor_id );

		$response = WP_Presence_Widget_Active_Posts::heartbeat_received(
			array(),
			array( 'presence-active-posts-ping' => true ),
			'dashboard'
		);

		$this->assertCount( 0, $response['presence-active-posts'] );
	}

	/**
	 * @covers WP_Presence_Widget_Active_Posts::heartbeat_received
	 */
	public function test_heartbeat_includes_posts_the_user_can_edit() {
		$draft_id = self::factory()->post->create(
			array(
				'post_author' => self::$contributor_id,
				'post_status' => 'draft',
				'post_title'  => 'Contributor Draft',
			)
		);

		$room = wp_presence_post_room( $draft_id );
		wp_set_presence( $room, 'lock-' . self::$contributor_id, array(), self::$contributor_id );

		wp_set_current_user( self::$contributor_id );

		$response = WP_Presence_Widget_Active_Posts::heartbeat_received(
			array(),
			array( 'presence-active-posts-ping' => true ),
			'dashboard'
		);

		$this->assertCount( 1, $response['presence-active-posts'] );
		$this->assertSame( $draft_id, $response['presence-active-posts'][0]['post_id'] );
	}

	/**
	 * Only the rooms the user cannot reach are dropped, not the whole response.
	 *
	 * @covers WP_Presence_Widget_Active_Posts::heartbeat_received
	 */
	public function test_heartbeat_filters_per_post_rather_than_all_or_nothing() {
		$draft_id = self::factory()->post->create(
			array(
				'post_author' => self::$contributor_id,
				'post_status' => 'draft',
			)
		);

		wp_set_presence( wp_presence_post_room( self::$post_id ), 'lock-a', array(), self::$editor_id );
		wp_set_presence( wp_presence_post_room( $draft_id ), 'lock-b', array(), self::$contributor_id );

		wp_set_current_user( self::$contributor_id );

		$response = WP_Presence_Widget_Active_Posts::heartbeat_received(
			array(),
			array( 'presence-active-posts-ping' => true ),
			'dashboard'
		);

		$this->assertCount( 1, $response['presence-active-posts'] );
		$this->assertSame( $draft_id, $response['presence-active-posts'][0]['post_id'] );
	}

	/**
	 * Nothing guarantees one row per user in a room, so the widget counts
	 * people rather than rows.
	 *
	 * @covers WP_Presence_Widget_Active_Posts::heartbeat_received
	 */
	public function test_a_user_holding_two_entries_is_counted_once() {
		wp_set_current_user( self::$editor_id );

		$room = wp_presence_post_room( self::$post_id );
		wp_set_presence( $room, 'editor-' . self::$editor_id, array(), self::$editor_id );
		wp_set_presence( $room, 'other-' . self::$editor_id, array(), self::$editor_id );

		$response = WP_Presence_Widget_Active_Posts::heartbeat_received(
			array(),
			array( 'presence-active-posts-ping' => true ),
			'dashboard'
		);

		$this->assertCount( 1, $response['presence-active-posts'][0]['editors'] );
		$this->assertSame( self::$editor_id, $response['presence-active-posts'][0]['editors'][0]['user_id'] );
	}

	/**
	 * @covers WP_Presence_Widget_Active_Posts::heartbeat_received
	 */
	public function test_a_users_freshest_entry_decides_their_status() {
		global $wpdb;

		wp_set_current_user( self::$editor_id );

		$room = wp_presence_post_room( self::$post_id );

		wp_set_presence( $room, 'stale-' . self::$editor_id, array(), self::$editor_id );
		$wpdb->update(
			$wpdb->presence,
			array( 'date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 45 ) ),
			array( 'client_id' => 'stale-' . self::$editor_id ),
			array( '%s' ),
			array( '%s' )
		);

		wp_set_presence( $room, 'editor-' . self::$editor_id, array(), self::$editor_id );

		$response = WP_Presence_Widget_Active_Posts::heartbeat_received(
			array(),
			array( 'presence-active-posts-ping' => true ),
			'dashboard'
		);

		$editors = $response['presence-active-posts'][0]['editors'];

		$this->assertCount( 1, $editors );
		$this->assertSame( 'active', $editors[0]['status'] );
	}

	/**
	 * The response is JSON, so the editors list has to stay a list.
	 *
	 * @covers WP_Presence_Widget_Active_Posts::heartbeat_received
	 */
	public function test_editors_encode_as_a_json_array() {
		wp_set_current_user( self::$editor_id );

		$room = wp_presence_post_room( self::$post_id );
		wp_set_presence( $room, 'editor-' . self::$editor_id, array(), self::$editor_id );
		wp_set_presence( $room, 'other-' . self::$editor_id, array(), self::$editor_id );

		$response = WP_Presence_Widget_Active_Posts::heartbeat_received(
			array(),
			array( 'presence-active-posts-ping' => true ),
			'dashboard'
		);

		$encoded = wp_json_encode( $response['presence-active-posts'][0]['editors'] );

		$this->assertStringStartsWith( '[', $encoded );
	}
}
