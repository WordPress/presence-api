<?php
/**
 * Tests for stale-screen detection on Network Settings and the Edit Site tabs.
 *
 * Several of these screens save while switched to the site being edited, so
 * what matters most here is that a revision written from inside that switch
 * is the one Network Admin reads back from outside it.
 *
 * @package Presence_API
 *
 * @group presence
 * @group ms-required
 *
 * @covers ::wp_presence_get_network_screen_revisions
 * @covers ::wp_presence_advance_screen_revision_map
 * @covers ::wp_presence_parse_screen_key_target
 * @covers ::wp_presence_get_screen_revision
 * @covers ::wp_presence_bump_screen_revision
 */
class WP_Test_Network_Screen_Revisions extends WP_Presence_Network_UnitTestCase {

	public function tear_down() {
		delete_site_option( 'wp_presence_network_screen_revisions' );
		unset( $_GET['id'] );
		set_current_screen( 'dashboard' );
		parent::tear_down();
	}

	/**
	 * @covers ::wp_presence_current_screen_key
	 */
	public function test_network_settings_has_a_screen_key() {
		$this->become_network_admin();
		set_current_screen( 'settings-network' );

		$this->assertSame( 'network/settings', wp_presence_current_screen_key() );
	}

	/**
	 * @covers ::wp_presence_current_screen_key
	 */
	public function test_each_edit_site_tab_keys_to_the_site_being_edited() {
		$this->become_network_admin();
		$blog_id   = $this->create_blog();
		$_GET['id'] = (string) $blog_id;

		$keys = array();
		foreach ( array( 'site-info', 'site-users', 'site-themes', 'site-settings' ) as $tab ) {
			set_current_screen( $tab . '-network' );
			$keys[] = wp_presence_current_screen_key();
		}

		$this->assertSame(
			array(
				'network/site-info/' . $blog_id,
				'network/site-users/' . $blog_id,
				'network/site-themes/' . $blog_id,
				'network/site-settings/' . $blog_id,
			),
			$keys
		);
	}

	/**
	 * @covers ::wp_presence_current_screen_key
	 */
	public function test_an_edit_site_tab_without_a_site_has_no_key() {
		$this->become_network_admin();
		set_current_screen( 'site-info-network' );

		$this->assertSame( '', wp_presence_current_screen_key() );
	}

	/**
	 * @covers ::wp_presence_on_update_network_options
	 */
	public function test_saving_network_settings_bumps_its_screen() {
		$admin_id = $this->become_network_admin();
		set_current_screen( 'settings-network' );

		do_action( 'update_wpmu_options' );

		$this->assertSame( $admin_id, wp_presence_get_screen_revision( 'network/settings' )['actor_id'] );
	}

	/**
	 * Edit Site → Settings fires its hook while still switched to the site.
	 *
	 * @covers ::wp_presence_on_update_site_options
	 */
	public function test_site_settings_saved_inside_the_switch_are_read_from_outside_it() {
		$this->become_network_admin();
		set_current_screen( 'site-settings-network' );
		$blog_id = $this->create_blog();

		switch_to_blog( $blog_id );
		do_action( 'wpmu_update_blog_options', $blog_id );
		restore_current_blog();

		$this->assertNotNull( wp_presence_get_screen_revision( 'network/site-settings/' . $blog_id ) );
	}

	/**
	 * @covers ::wp_presence_on_update_site
	 */
	public function test_updating_a_site_bumps_its_info_screen() {
		$this->become_network_admin();
		set_current_screen( 'site-info-network' );
		$blog_id = $this->create_blog();

		wp_update_site( $blog_id, array( 'public' => 0 ) );

		$this->assertNotNull( wp_presence_get_screen_revision( 'network/site-info/' . $blog_id ) );
	}

	/**
	 * @covers ::wp_presence_on_site_allowed_themes_updated
	 */
	public function test_changing_a_sites_themes_bumps_its_themes_screen() {
		$this->become_network_admin();
		set_current_screen( 'site-themes-network' );
		$blog_id = $this->create_blog();

		switch_to_blog( $blog_id );
		update_option( 'allowedthemes', array( 'twentytwentyfour' => true ) );
		restore_current_blog();

		$this->assertNotNull( wp_presence_get_screen_revision( 'network/site-themes/' . $blog_id ) );
	}

	/**
	 * @covers ::wp_presence_on_site_users_changed
	 */
	public function test_adding_and_removing_a_user_bumps_the_sites_users_screen() {
		$this->become_network_admin();
		set_current_screen( 'site-users-network' );
		$blog_id = $this->create_blog();
		$user_id = self::factory()->user->create();
		$key     = 'network/site-users/' . $blog_id;

		add_user_to_blog( $blog_id, $user_id, 'editor' );
		$added = wp_presence_get_screen_revision( $key );

		remove_user_from_blog( $user_id, $blog_id );
		$removed = wp_presence_get_screen_revision( $key );

		$this->assertNotNull( $added );
		$this->assertGreaterThan( $added['rev'], $removed['rev'] );
	}

	/**
	 * @covers ::wp_presence_on_site_users_changed
	 */
	public function test_changing_a_role_on_a_site_bumps_that_sites_users_screen() {
		$this->become_network_admin();
		set_current_screen( 'site-users-network' );
		$blog_id = $this->create_blog();
		$user_id = self::factory()->user->create();
		add_user_to_blog( $blog_id, $user_id, 'editor' );
		delete_site_option( 'wp_presence_network_screen_revisions' );

		switch_to_blog( $blog_id );
		( new WP_User( $user_id ) )->set_role( 'author' );
		restore_current_blog();

		$this->assertNotNull( wp_presence_get_screen_revision( 'network/site-users/' . $blog_id ) );
	}

	/**
	 * @covers ::wp_presence_current_user_can_access_screen
	 */
	public function test_only_network_admins_can_read_network_screens() {
		$site_admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $site_admin );
		$site_admin_can = wp_presence_current_user_can_access_screen( 'network/settings' );

		$this->become_network_admin();

		$this->assertFalse( $site_admin_can );
		$this->assertTrue( wp_presence_current_user_can_access_screen( 'network/settings' ) );
		$this->assertTrue( wp_presence_current_user_can_access_screen( 'network/site-info/2' ) );
	}

	/**
	 * @covers ::wp_presence_screen_heartbeat_received
	 */
	public function test_heartbeat_reports_a_network_screen_revision() {
		$admin_id = $this->become_network_admin();
		wp_presence_bump_screen_revision( 'network/settings', $admin_id );

		$response = wp_presence_screen_heartbeat_received(
			array(),
			array( 'presence-screen-ping' => array( 'key' => 'network/settings' ) ),
			'settings-network'
		);

		$this->assertSame( 1, $response['presence-screen-rev']['rev'] );
		$this->assertTrue( $response['presence-screen-rev']['actor_is_me'] );
	}
}
