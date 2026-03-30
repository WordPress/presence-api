<?php
/**
 * Tests for the post-lock bridge.
 *
 * @package Presence_API
 *
 * @group presence
 */
class WP_Test_Presence_Post_Lock_Bridge extends WP_UnitTestCase {

	private static $editor_id;
	private static $subscriber_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id     = $factory->user->create( array( 'role' => 'editor' ) );
		self::$subscriber_id = $factory->user->create( array( 'role' => 'subscriber' ) );
	}

	public function tear_down() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "TRUNCATE TABLE {$wpdb->presence}" );
		parent::tear_down();
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
		$this->assertSame( 'lock-' . self::$editor_id, $entries[0]->client_id );
	}
}
