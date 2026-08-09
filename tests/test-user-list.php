<?php
/**
 * Tests for the users list "Online" view and filter.
 *
 * @package Presence_API
 *
 * @group presence
 */
class WP_Test_Presence_User_List extends WP_UnitTestCase {

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
		unset( $_GET['presence_status'], $_GET['_wpnonce'] );
		parent::tear_down();
	}

	public function test_users_views_adds_online_view_with_count() {
		wp_set_current_user( self::$editor_id );

		$other_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_presence( wp_presence_admin_room(), 'client-1', array(), $other_id );

		$views = wp_presence_users_views( array() );

		// The current user is always counted as present, plus the other editor.
		$this->assertStringContainsString( '(2)', $views['presence_online'] );
	}

	public function test_users_views_counts_current_user_when_nobody_else_online() {
		wp_set_current_user( self::$editor_id );

		$views = wp_presence_users_views( array() );

		$this->assertStringContainsString( '(1)', $views['presence_online'] );
	}

	public function test_users_views_does_not_double_count_the_current_user() {
		wp_set_current_user( self::$editor_id );
		wp_set_presence( wp_presence_admin_room(), 'client-self', array(), self::$editor_id );

		$views = wp_presence_users_views( array() );

		$this->assertStringContainsString( '(1)', $views['presence_online'] );
	}

	public function test_users_views_marks_online_view_current() {
		wp_set_current_user( self::$editor_id );
		$_GET['presence_status'] = 'online';

		$views = wp_presence_users_views( array( 'all' => '<a href="#" class="current">All</a>' ) );

		$this->assertStringContainsString( 'class="current"', $views['presence_online'] );
		$this->assertStringNotContainsString( 'class="current"', $views['all'] );
	}

	public function test_users_views_unchanged_without_edit_posts_capability() {
		wp_set_current_user( self::$subscriber_id );

		$views = wp_presence_users_views( array( 'all' => 'All' ) );

		$this->assertSame( array( 'all' => 'All' ), $views );
	}

	/**
	 * Applies the online view's expected request state (current user,
	 * admin screen, status param, valid nonce) so tests below can drop the
	 * one condition they mean to fail.
	 */
	private function go_to_online_users_screen() {
		wp_set_current_user( self::$editor_id );
		set_current_screen( 'users' );
		$_GET['presence_status'] = 'online';
		$_GET['_wpnonce']        = wp_create_nonce( 'presence_online_filter' );
	}

	public function test_filter_online_users_restricts_query_to_online_users() {
		$this->go_to_online_users_screen();

		$other_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_presence( wp_presence_admin_room(), 'client-1', array(), $other_id );

		$query = new WP_User_Query();
		wp_presence_filter_online_users( $query );

		// The other editor is online; the current user is always included too.
		$this->assertEqualsCanonicalizing( array( $other_id, self::$editor_id ), $query->get( 'include' ) );
	}

	public function test_filter_online_users_includes_only_current_user_when_nobody_else_online() {
		$this->go_to_online_users_screen();

		$query = new WP_User_Query();
		wp_presence_filter_online_users( $query );

		$this->assertSame( array( self::$editor_id ), $query->get( 'include' ) );
	}

	public function test_filter_online_users_ignored_outside_admin() {
		$this->go_to_online_users_screen();
		set_current_screen( 'front' );

		$query = new WP_User_Query();
		wp_presence_filter_online_users( $query );

		$this->assertNull( $query->get( 'include' ) );
	}

	public function test_filter_online_users_ignored_without_edit_posts_capability() {
		// The nonce is bound to the user it was created for, so the subscriber
		// has to be current before it is issued. Otherwise the nonce check
		// fails first and the capability guard is never reached.
		wp_set_current_user( self::$subscriber_id );
		set_current_screen( 'users' );
		$_GET['presence_status'] = 'online';
		$_GET['_wpnonce']        = wp_create_nonce( 'presence_online_filter' );

		$query = new WP_User_Query();
		wp_presence_filter_online_users( $query );

		$this->assertNull( $query->get( 'include' ) );
	}

	public function test_filter_online_users_ignored_when_status_not_online() {
		$this->go_to_online_users_screen();
		unset( $_GET['presence_status'] );

		$query = new WP_User_Query();
		wp_presence_filter_online_users( $query );

		$this->assertNull( $query->get( 'include' ) );
	}

	public function test_filter_online_users_ignored_with_invalid_nonce() {
		$this->go_to_online_users_screen();
		$_GET['_wpnonce'] = 'invalid';

		$query = new WP_User_Query();
		wp_presence_filter_online_users( $query );

		$this->assertNull( $query->get( 'include' ) );
	}
}
