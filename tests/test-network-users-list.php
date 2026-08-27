<?php
/**
 * Tests for the "Online" view, query filter, and column on the Network Admin
 * Users list.
 *
 * This screen asks a different question of the summary than the rest of the
 * network UI does. It is keyed by user rather than by site, and the answer for
 * one row has to hold for every other row on the page without rereading the
 * network, so most of what is asserted here is that inversion.
 *
 * @package Presence_API
 *
 * @group presence
 * @group ms-required
 */
class WP_Test_Network_Users_List extends WP_Presence_Network_UnitTestCase {

	private static $editor_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id = $factory->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * One person signed in on two sites is one person online, which is the
	 * distinction the network user list filters on.
	 *
	 * @covers ::wp_presence_get_network_online_user_ids
	 */
	public function test_online_user_ids_are_deduplicated_across_sites() {
		$first  = $this->create_blog();
		$second = $this->create_blog();
		$other  = self::factory()->user->create();

		$this->set_network_summary_row( $first, array( self::$editor_id ) );
		$this->set_network_summary_row( $second, array( self::$editor_id, $other ) );

		$ids = wp_presence_get_network_online_user_ids();
		sort( $ids );

		$expected = array( self::$editor_id, $other );
		sort( $expected );

		$this->assertSame( $expected, $ids );
	}

	/**
	 * @covers ::wp_presence_network_users_views
	 */
	public function test_users_view_requires_capability() {
		$views = wp_presence_network_users_views( array() );

		$this->assertArrayNotHasKey( 'presence_online', $views );
	}

	/**
	 * @covers ::wp_presence_network_users_views
	 */
	public function test_users_view_reports_the_network_wide_count() {
		$this->become_network_admin();

		$blog_ids = array( $this->create_blog(), $this->create_blog() );
		$this->set_presence_on_site( $blog_ids[0], self::$editor_id );
		$this->set_presence_on_site( $blog_ids[1], self::$editor_id );

		$views = wp_presence_network_users_views( array() );

		$this->assertArrayHasKey( 'presence_online', $views );
		// One distinct user online across both sites, not two.
		$this->assertStringContainsString( '(1)', $views['presence_online'] );
	}

	/**
	 * @covers ::wp_presence_filter_network_online_users
	 */
	public function test_users_query_filter_requires_network_admin_context() {
		$args = wp_presence_filter_network_online_users( array( 'number' => 10 ) );

		$this->assertArrayNotHasKey( 'include', $args, 'Outside is_network_admin(), the query should be left alone.' );
	}

	/**
	 * @covers ::wp_presence_filter_network_online_users
	 */
	public function test_users_query_filter_restricts_to_online_users() {
		$this->become_network_admin();
		set_current_screen( 'users-network' );

		$blog_id = $this->create_blog();
		$this->set_presence_on_site( $blog_id, self::$editor_id );

		$_GET['presence_status'] = 'online';
		$_GET['_wpnonce']        = wp_create_nonce( 'presence_online_filter' );

		$args = wp_presence_filter_network_online_users( array( 'number' => 10 ) );

		$this->assertSame( array( self::$editor_id ), $args['include'] );
	}

	/**
	 * WP_User_Query treats an empty "include" as no restriction at all, which
	 * would silently fall through to every network user instead of none.
	 *
	 * @covers ::wp_presence_filter_network_online_users
	 */
	public function test_users_query_filter_matches_nobody_when_nobody_is_online() {
		$this->become_network_admin();
		set_current_screen( 'users-network' );

		$_GET['presence_status'] = 'online';
		$_GET['_wpnonce']        = wp_create_nonce( 'presence_online_filter' );

		$args = wp_presence_filter_network_online_users( array( 'number' => 10 ) );

		$this->assertSame( array( 0 ), $args['include'] );
	}

	/**
	 * The single-site filter runs on this screen too, since is_admin() is true
	 * in Network Admin, and the list table's own WP_User_Query fires
	 * pre_get_users after users_list_table_query_args has already set the
	 * network-wide IDs. Asserted through a real query rather than the filter's
	 * return value, because the collision only shows up downstream of it.
	 *
	 * @covers ::wp_presence_filter_network_online_users
	 * @covers ::wp_presence_filter_online_users
	 */
	public function test_the_network_online_view_is_not_narrowed_to_the_current_site() {
		$this->become_network_admin();
		set_current_screen( 'users-network' );

		// Online on another site, and deliberately not on the one this request
		// resolved to.
		$blog_id = $this->create_blog();
		$this->set_presence_on_site( $blog_id, self::$editor_id );

		$_GET['presence_status'] = 'online';
		$_GET['_wpnonce']        = wp_create_nonce( 'presence_online_filter' );

		$args = wp_presence_filter_network_online_users(
			array(
				'number' => 10,
				'fields' => 'ID',
			)
		);

		$query = new WP_User_Query( $args );

		$this->assertSame(
			array( self::$editor_id ),
			array_map( 'intval', $query->get_results() ),
			'pre_get_users narrowed the network view to the current site.'
		);
	}

	/**
	 * @covers ::wp_presence_render_network_users_column
	 * @covers ::wp_presence_get_network_sites_for_user
	 */
	public function test_users_column_lists_the_sites_a_user_is_online_on() {
		$this->become_network_admin();

		$blog_id = $this->create_blog();
		$this->set_presence_on_site( $blog_id, self::$editor_id );

		$output = wp_presence_render_network_users_column( '', 'presence_online', self::$editor_id );

		$this->assertNotSame( '&#8212;', $output );
	}

	/**
	 * @covers ::wp_presence_render_network_users_column
	 * @covers ::wp_presence_get_network_sites_for_user
	 */
	public function test_users_column_shows_a_dash_for_an_offline_user() {
		$output = wp_presence_render_network_users_column( '', 'presence_online', self::$editor_id );

		$this->assertSame( '&#8212;', $output );
	}

	/**
	 * @covers ::wp_presence_register_network_users_column
	 */
	public function test_users_column_requires_capability() {
		$columns = wp_presence_register_network_users_column( array( 'username' => 'Username' ) );

		$this->assertArrayNotHasKey( 'presence_online', $columns );
	}

	/**
	 * @covers ::wp_presence_register_network_users_column
	 */
	public function test_users_column_is_registered_for_a_network_admin() {
		$this->become_network_admin();

		$columns = wp_presence_register_network_users_column( array( 'username' => 'Username' ) );

		$this->assertArrayHasKey( 'presence_online', $columns );
	}

	/**
	 * The Network Users "Online" column lists the sites a user is active on.
	 * If the user's only active site is archived, the column must show a dash
	 * rather than naming a site that is no longer live.
	 *
	 * @covers ::wp_presence_render_network_users_column
	 * @covers ::wp_presence_get_network_sites_for_user
	 */
	public function test_users_column_omits_archived_site() {
		$this->become_network_admin();

		$blog_id = $this->create_blog();
		$this->set_presence_on_site( $blog_id, self::$editor_id );

		update_blog_status( $blog_id, 'archived', 1 );
		wp_presence_flush_network_summary_cache();

		$output = wp_presence_render_network_users_column( '', 'presence_online', self::$editor_id );

		$this->assertSame( '&#8212;', $output, 'An archived site must not appear in the Online column for a user.' );
	}
}
