<?php
/**
 * Tests for lifecycle hooks (login/logout).
 *
 * @package Presence_API
 *
 * @group presence
 */
class WP_Test_Presence_Lifecycle extends WP_UnitTestCase {

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
	 * @covers ::wp_presence_on_login
	 */
	public function test_login_skips_subscriber() {
		$user = get_userdata( self::$subscriber_id );
		wp_presence_on_login( $user->user_login, $user );

		$entries = wp_get_user_presence( self::$subscriber_id );
		$this->assertCount( 0, $entries );
	}

	/**
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
