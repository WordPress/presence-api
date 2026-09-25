<?php
/**
 * Tests for the post-lock bridge.
 *
 * @package Presence_API
 *
 * @group presence
 */
class WP_Test_Presence_Post_Lock_Bridge extends WP_Presence_UnitTestCase {

	private static $editor_id;
	private static $other_editor_id;
	private static $subscriber_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		require_once ABSPATH . 'wp-admin/includes/post.php';

		self::$editor_id       = $factory->user->create( array( 'role' => 'editor' ) );
		self::$other_editor_id = $factory->user->create( array( 'role' => 'editor' ) );
		self::$subscriber_id   = $factory->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * @covers ::wp_presence_bridge_post_lock
	 */
	public function test_post_lock_bridge_requires_edit_cap() {
		$post_id = self::factory()->post->create();

		wp_set_current_user( self::$subscriber_id );

		$response = wp_presence_bridge_post_lock(
			array(),
			array(
				'wp-refresh-post-lock' => array(
					'post_id' => $post_id,
				),
			),
			'post'
		);

		$entries = wp_get_presence( wp_presence_post_room( $post_id ), 300 );
		$this->assertCount( 0, $entries, 'Subscriber should not create a presence entry for a post they cannot edit.' );
	}

	/**
	 * @covers ::wp_presence_bridge_post_lock
	 */
	public function test_post_lock_bridge_creates_presence() {
		$post_id = self::factory()->post->create();

		wp_set_current_user( self::$editor_id );

		wp_presence_bridge_post_lock(
			array(),
			array(
				'wp-refresh-post-lock' => array(
					'post_id' => $post_id,
				),
			),
			'post'
		);

		$room    = wp_presence_post_room( $post_id );
		$entries = wp_get_presence( $room );

		$this->assertCount( 1, $entries );
		$this->assertSame( 'editor-' . self::$editor_id, $entries[0]->client_id );
		$this->assertTrue( $entries[0]->data['locked'] );
	}

	/**
	 * The editor handler writes the same entry from the same payload, so the
	 * bridge writing again would only cost a second query for the same row.
	 *
	 * @covers ::wp_presence_bridge_post_lock
	 */
	public function test_post_lock_bridge_defers_to_the_editor_ping() {
		$post_id = self::factory()->post->create();

		wp_set_current_user( self::$editor_id );

		wp_presence_bridge_post_lock(
			array(),
			array(
				'wp-refresh-post-lock'  => array(
					'post_id' => $post_id,
				),
				'presence-editor-ping' => array(
					'post_id' => $post_id,
				),
			),
			'post'
		);

		$this->assertCount(
			0,
			wp_get_presence( wp_presence_post_room( $post_id ) ),
			'The bridge should stand down when the editor ping already covers this post.'
		);
	}

	/**
	 * @covers ::wp_presence_bridge_post_lock
	 */
	public function test_post_lock_bridge_writes_when_the_editor_ping_is_for_another_post() {
		$locked_id = self::factory()->post->create();
		$other_id  = self::factory()->post->create();

		wp_set_current_user( self::$editor_id );

		wp_presence_bridge_post_lock(
			array(),
			array(
				'wp-refresh-post-lock'  => array(
					'post_id' => $locked_id,
				),
				'presence-editor-ping' => array(
					'post_id' => $other_id,
				),
			),
			'post'
		);

		$entries = wp_get_presence( wp_presence_post_room( $locked_id ) );

		$this->assertCount( 1, $entries );
		$this->assertSame( 'editor-' . self::$editor_id, $entries[0]->client_id );
	}

	/**
	 * Asserts the cache key itself, since a meta write anywhere would bump it.
	 *
	 * @covers ::wp_presence_update_post_lock
	 * @covers ::wp_presence_get_post_lock
	 * @covers ::wp_presence_post_lock_room
	 * @covers ::wp_presence_post_lock_value
	 * @covers ::wp_presence_post_lock_client_id
	 */
	public function test_refreshing_a_post_lock_leaves_cached_post_queries_valid() {
		$post_id = self::factory()->post->create();

		wp_set_current_user( self::$editor_id );
		wp_set_post_lock( $post_id );
		$last_changed = wp_cache_get_last_changed( 'posts' );
		wp_set_post_lock( $post_id );

		$this->assertSame( $last_changed, wp_cache_get_last_changed( 'posts' ) );

		wp_set_current_user( self::$other_editor_id );
		$this->assertSame( self::$editor_id, wp_check_post_lock( $post_id ), 'A second user should still see the post as locked.' );
	}

	/**
	 * @covers ::wp_presence_delete_post_lock
	 */
	public function test_deleting_a_post_lock_releases_the_post() {
		$post_id = self::factory()->post->create();

		wp_set_current_user( self::$editor_id );
		wp_set_post_lock( $post_id );
		delete_post_meta( $post_id, '_edit_lock' );

		wp_set_current_user( self::$other_editor_id );
		$this->assertFalse( wp_check_post_lock( $post_id ) );
	}

	/**
	 * Core releases a lock on unload only if it still holds it, by passing it as $prev_value.
	 *
	 * @covers ::wp_presence_update_post_lock
	 */
	public function test_a_stale_previous_lock_leaves_the_current_one() {
		$post_id = self::factory()->post->create();

		wp_set_current_user( self::$editor_id );
		wp_set_post_lock( $post_id );

		$released = update_post_meta( $post_id, '_edit_lock', '1:' . self::$other_editor_id, '1:' . self::$other_editor_id );

		$this->assertFalse( $released );
		wp_set_current_user( self::$other_editor_id );
		$this->assertSame( self::$editor_id, wp_check_post_lock( $post_id ) );
	}

	/**
	 * @covers ::wp_presence_update_post_lock
	 */
	public function test_post_lock_stays_in_meta_when_recording_is_off() {
		add_filter( 'wp_presence_recording_enabled', '__return_false' );
		$post_id = self::factory()->post->create();

		wp_set_current_user( self::$editor_id );
		wp_set_post_lock( $post_id );

		wp_set_current_user( self::$other_editor_id );
		$this->assertSame( self::$editor_id, wp_check_post_lock( $post_id ) );
	}
}
