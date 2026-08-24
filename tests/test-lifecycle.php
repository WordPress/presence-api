<?php
/**
 * Tests for lifecycle hooks (login/logout).
 *
 * @package Presence_API
 *
 * @group presence
 */
class WP_Test_Presence_Lifecycle extends WP_Presence_UnitTestCase {

	private static $editor_id;
	private static $subscriber_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id     = $factory->user->create( array( 'role' => 'editor' ) );
		self::$subscriber_id = $factory->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * @covers ::wp_presence_on_login
	 */
	public function test_login_sets_presence() {
		$user = get_userdata( self::$editor_id );
		wp_presence_on_login( $user->user_login, $user );

		$entries = $this->presence_for_user( self::$editor_id );
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

		$entries = $this->presence_for_user( self::$subscriber_id );
		$this->assertCount( 0, $entries );
	}

	/**
	 * @covers ::wp_presence_on_logout
	 */
	public function test_logout_clears_all_rooms() {
		wp_set_presence( 'admin/online', 'user-' . self::$editor_id, array(), self::$editor_id );
		wp_set_presence( 'postType/post:1', 'lock-' . self::$editor_id, array(), self::$editor_id );
		wp_set_presence( 'admin/online', 'user-' . self::$subscriber_id, array(), self::$subscriber_id );

		wp_presence_on_logout( self::$editor_id );

		$this->assertCount( 0, $this->presence_for_user( self::$editor_id ) );
		$this->assertCount( 1, $this->presence_for_user( self::$subscriber_id ), 'Logging one user out should not clear anybody else.' );
	}

	/**
	 * @covers ::wp_presence_on_logout
	 */
	public function test_logout_skips_subscriber() {
		// Manually insert a presence entry for the subscriber (bypassing cap check).
		wp_set_presence( 'admin/online', 'user-' . self::$subscriber_id, array(), self::$subscriber_id );

		wp_presence_on_logout( self::$subscriber_id );

		// Entry should remain because logout skips users without edit_posts.
		$entries = $this->presence_for_user( self::$subscriber_id );
		$this->assertCount( 1, $entries );
	}

	/**
	 * @covers ::wp_presence_on_logout
	 */
	public function test_logout_clears_presence_without_current_user() {
		wp_set_presence( 'admin/online', 'user-' . self::$editor_id, array(), self::$editor_id );
		wp_set_presence( 'postType/post:1', 'lock-' . self::$editor_id, array(), self::$editor_id );
		wp_set_presence( 'admin/online', 'user-' . self::$subscriber_id, array(), self::$subscriber_id );

		// Simulate real wp_logout timing.
		// Auth cookie has been cleared, so get_current_user_id() would return 0.
		wp_set_current_user( 0 );

		wp_presence_on_logout( self::$editor_id );

		$this->assertCount( 0, $this->presence_for_user( self::$editor_id ) );
		$this->assertCount( 1, $this->presence_for_user( self::$subscriber_id ), 'Logging one user out should not clear anybody else.' );
	}

	/**
	 * @covers ::wp_presence_on_user_removed
	 */
	public function test_deleted_user_clears_presence() {
		$temp_user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		wp_set_presence( 'admin/online', 'user-' . $temp_user_id, array(), $temp_user_id );
		wp_set_presence( 'postType/post:1', 'lock-' . $temp_user_id, array(), $temp_user_id );
		wp_set_presence( 'admin/online', 'user-' . self::$editor_id, array(), self::$editor_id );

		wp_delete_user( $temp_user_id );

		$this->assertCount( 0, $this->presence_for_user( $temp_user_id ), 'Deleting a user should clear their presence rows.' );
		$this->assertCount( 1, $this->presence_for_user( self::$editor_id ), 'Deleting one user should not clear anybody else.' );
	}

	/**
	 * Isolates remove_user_from_blog() from deleted_user.
	 *
	 * @covers ::wp_presence_on_user_removed
	 */
	public function test_remove_user_from_blog_clears_presence_on_that_site_only() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}

		$temp_user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		wp_set_presence( 'admin/online', 'user-' . $temp_user_id, array(), $temp_user_id );

		remove_user_from_blog( $temp_user_id, get_current_blog_id() );

		$this->assertCount( 0, $this->presence_for_user( $temp_user_id ), 'Removing a user from a site should clear their presence there.' );
	}
}
