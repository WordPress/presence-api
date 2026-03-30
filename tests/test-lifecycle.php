<?php
/**
 * Tests for lifecycle hooks (login/logout).
 *
 * @package Presence_API
 * @since 7.1.0
 *
 * @group presence
 */
class WP_Test_Presence_Lifecycle extends WP_UnitTestCase {

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
	 * Tests that wp_presence_on_login sets presence for an editor.
	 *
	 * @covers ::wp_presence_on_login
	 */
	public function test_login_sets_presence() {
		$user = get_userdata( self::$editor_id );
		wp_presence_on_login( $user->user_login, $user );

		$entries = wp_get_user_presence( self::$editor_id );
		$this->assertCount( 1, $entries );
		$this->assertSame( 'admin/online', $entries[0]->room );
		$this->assertSame( 'login', $entries[0]->data['screen'] );
	}

	/**
	 * Tests that wp_presence_on_login skips subscribers.
	 *
	 * @covers ::wp_presence_on_login
	 */
	public function test_login_skips_subscriber() {
		$user = get_userdata( self::$subscriber_id );
		wp_presence_on_login( $user->user_login, $user );

		$entries = wp_get_user_presence( self::$subscriber_id );
		$this->assertCount( 0, $entries );
	}

	/**
	 * Tests that wp_presence_on_logout clears all rooms.
	 *
	 * @covers ::wp_presence_on_logout
	 */
	public function test_logout_clears_all_rooms() {
		wp_set_presence( 'admin/online', 'user-' . self::$editor_id, array(), self::$editor_id );
		wp_set_presence( 'postType/post:1', 'lock-' . self::$editor_id, array(), self::$editor_id );

		wp_set_current_user( self::$editor_id );
		wp_presence_on_logout();

		$this->assertCount( 0, wp_get_user_presence( self::$editor_id ) );
	}

	/**
	 * Tests that wp_presence_on_logout is a no-op for subscribers.
	 *
	 * @covers ::wp_presence_on_logout
	 */
	public function test_logout_skips_subscriber() {
		// Manually insert a presence entry for the subscriber (bypassing cap check).
		wp_set_presence( 'admin/online', 'user-' . self::$subscriber_id, array(), self::$subscriber_id );

		wp_set_current_user( self::$subscriber_id );
		wp_presence_on_logout();

		// Entry should remain because logout skips users without edit_posts.
		$entries = wp_get_user_presence( self::$subscriber_id );
		$this->assertCount( 1, $entries );
	}
}
