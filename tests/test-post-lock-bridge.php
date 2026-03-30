<?php
/**
 * Tests for the post-lock bridge.
 *
 * @package Presence_API
 * @since 7.1.0
 *
 * @group presence
 */
class WP_Test_Presence_Post_Lock_Bridge extends WP_UnitTestCase {

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	private static $editor_id;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	private static $subscriber_id;

	/**
	 * Sets up fixtures before any tests run.
	 *
	 * @param WP_UnitTest_Factory $factory Test factory.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id     = $factory->user->create( array( 'role' => 'editor' ) );
		self::$subscriber_id = $factory->user->create( array( 'role' => 'subscriber' ) );
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
	 * Tests the post-lock bridge checks edit capability.
	 *
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
	 * Tests the post-lock bridge creates presence for authorized users.
	 *
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
