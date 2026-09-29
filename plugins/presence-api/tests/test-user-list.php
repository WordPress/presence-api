<?php
/**
 * Tests for the users list "Online" view and filter.
 *
 * @package Presence_API
 *
 * @group presence
 *
 * @covers ::wp_presence_users_views
 * @covers ::wp_presence_filter_online_users
 * @covers ::wp_presence_users_list_heartbeat_received
 * @covers ::wp_presence_online_users_url
 */
class WP_Test_Presence_User_List extends WP_Presence_UnitTestCase {

	private static $editor_id;
	private static $subscriber_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id     = $factory->user->create( array( 'role' => 'editor' ) );
		self::$subscriber_id = $factory->user->create( array( 'role' => 'subscriber' ) );
	}

	public function tear_down() {
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

	/**
	 * Sends a heartbeat from the Online view as an administrator.
	 *
	 * @param string $nonce The nonce the view's URL carries.
	 * @return array The Heartbeat response.
	 */
	private function tick_online_view( $nonce = null ) {
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'users' );

		$query = '?presence_status=online&_wpnonce=' . ( $nonce ?? wp_create_nonce( 'presence_online_filter' ) );

		return wp_presence_users_list_heartbeat_received( array(), array( 'presence-fragments' => array( 'users-list' => $query ) ) );
	}

	public function test_the_online_view_gets_fresh_rows_each_heartbeat() {
		wp_set_presence( wp_presence_admin_room(), 'client-1', array(), self::$editor_id );

		$rows = $this->tick_online_view()['presence-fragments']['users-list'];

		$this->assertStringContainsString( "id='user-" . self::$editor_id . "'", $rows );
		$this->assertStringNotContainsString( "id='user-" . self::$subscriber_id . "'", $rows );
		$this->assertStringContainsString( 'wp_http_referer=%2Fwp-admin%2Fusers.php%3Fpresence_status%3Donline', $rows );
		$this->assertArrayNotHasKey( 'presence_status', $_GET );
	}

	/**
	 * @covers ::wp_presence_users_online_count_heartbeat_received
	 */
	public function test_every_users_view_gets_a_fresh_online_count() {
		wp_set_current_user( self::$editor_id );
		wp_set_presence( wp_presence_admin_room(), 'client-1', array(), self::$editor_id );
		$ask = array( 'presence-fragments' => array( 'users-online-count' => true ) );

		$this->assertSame( '(1)', wp_presence_users_online_count_heartbeat_received( array(), $ask, 'users' )['presence-fragments']['users-online-count'] );
		$this->assertSame( array(), wp_presence_users_online_count_heartbeat_received( array(), $ask, 'users-network' ) );
	}

	public function test_the_online_view_needs_its_nonce() {
		$this->assertSame( array(), $this->tick_online_view( 'invalid' ) );
	}

	/**
	 * Every link to the Online view is built by the helper, so a URL from it
	 * has to open the filtered list rather than every user.
	 */
	public function test_online_users_url_opens_the_filtered_view() {
		wp_set_current_user( self::$editor_id );
		set_current_screen( 'users' );

		$other_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_presence( wp_presence_admin_room(), 'client-1', array(), $other_id );

		$url = wp_presence_online_users_url();
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $_GET );

		$query = new WP_User_Query();
		wp_presence_filter_online_users( $query );

		$this->assertStringStartsWith( admin_url( 'users.php?' ), $url );
		$this->assertEqualsCanonicalizing( array( $other_id, self::$editor_id ), $query->get( 'include' ) );
	}

	/**
	 * Callers escape the URL on output, so the helper hands it back raw.
	 */
	public function test_online_users_url_is_unescaped() {
		$this->assertStringNotContainsString( '&amp;', wp_presence_online_users_url() );
	}
}
