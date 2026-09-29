<?php
/**
 * Tests for the admin bar debugger.
 *
 * @package Presence_API
 *
 * @group presence
 */

// The plugin loads this only under WP_DEBUG, which the suite does not set.
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/debugger-admin-bar.php';

class WP_Test_Presence_Debugger_Admin_Bar extends WP_Presence_UnitTestCase {

	/**
	 * @covers ::wp_presence_debugger_heartbeat_received
	 */
	public function test_heartbeat_says_nothing_to_a_subscriber() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$response = wp_presence_debugger_heartbeat_received( array(), array( 'presence-fragments' => array( 'debugger' => true ) ) );

		$this->assertSame( array(), $response );
	}

	/**
	 * @covers ::wp_presence_debugger_heartbeat_received
	 * @covers ::wp_presence_debugger_admin_bar_node
	 */
	public function test_lists_every_client_in_the_rooms_the_user_is_in() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$other = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $admin );

		wp_set_presence( 'postType/post:1', 'editor-1', array(), $admin );
		wp_set_presence( 'postType/post:1', 'gse-42', array(), $other );
		wp_set_presence( 'postType/post:2', 'gse-43', array(), $other );

		$response = wp_presence_debugger_heartbeat_received( array(), array( 'presence-fragments' => array( 'debugger' => true ) ) );
		$markup   = $response['presence-fragments']['debugger'];

		$this->assertStringContainsString( 'gse-42', $markup, 'Another client in a room the user is in should be listed.' );
		$this->assertStringNotContainsString( 'postType/post:2', $markup, 'A room the user is not in should be left out.' );
	}

	/**
	 * @covers ::wp_presence_debugger_admin_bar_node
	 */
	public function test_lists_people_on_your_page_before_the_row_limit() {
		global $wpdb;

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$room = wp_presence_admin_room();

		wp_set_presence( $room, 'mine', array( 'screen' => 'dashboard' ), $admin );
		wp_set_presence( $room, 'beside-me', array( 'screen' => 'dashboard' ), self::factory()->user->create() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $wpdb->presence, array( 'date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'client_id' => 'beside-me' ) );
		foreach ( self::factory()->user->create_many( 25 ) as $i => $user_id ) {
			wp_set_presence( $room, 'elsewhere-' . $i, array( 'screen' => 'edit-post' ), $user_id );
		}

		$markup = wp_presence_debugger_admin_bar_markup();

		$this->assertStringContainsString( 'beside-me', $markup );
	}

	/**
	 * @covers ::wp_presence_debugger_admin_bar_node
	 */
	public function test_renders_more_link_with_pluralization() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$room = 'postType/post:999';

		wp_set_presence( $room, 'client-0', array(), $admin );
		foreach ( self::factory()->user->create_many( 20 ) as $i => $user_id ) {
			wp_set_presence( $room, 'client-' . ( $i + 1 ), array(), $user_id );
		}

		// 21 clients total in room -> 1 beyond 20.
		$markup = wp_presence_debugger_admin_bar_markup();
		$this->assertStringContainsString( '+1 more', $markup, 'Singular overflow (+1 more) should render.' );

		// Add 4 more -> 25 clients total -> 5 beyond 20.
		foreach ( self::factory()->user->create_many( 4 ) as $i => $user_id ) {
			wp_set_presence( $room, 'client-extra-' . $i, array(), $user_id );
		}
		$markup = wp_presence_debugger_admin_bar_markup();
		$this->assertStringContainsString( '+5 more', $markup, 'Plural overflow (+5 more) should render.' );
	}
}
