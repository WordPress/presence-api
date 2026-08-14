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
	private static $subscriber_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id     = $factory->user->create( array( 'role' => 'editor' ) );
		self::$subscriber_id = $factory->user->create( array( 'role' => 'subscriber' ) );
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
}
