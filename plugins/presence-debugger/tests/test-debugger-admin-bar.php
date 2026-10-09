<?php
/**
 * Tests for the admin bar debugger.
 *
 * @package Presence_API
 *
 * @group presence
 */

// The plugin loads this only under WP_DEBUG, which the suite does not set.
require_once __DIR__ . '/../debugger-admin-bar.php';

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

		wp_set_presence( 'postType/post:1', 'editor-1', array(), array( 'user_id' => $admin ) );
		wp_set_presence( 'postType/post:1', 'gse-42', array(), array( 'user_id' => $other ) );
		wp_set_presence( 'postType/post:2', 'gse-43', array(), array( 'user_id' => $other ) );

		$response = wp_presence_debugger_heartbeat_received( array(), array( 'presence-fragments' => array( 'debugger' => true ) ) );
		$markup   = $response['presence-fragments']['debugger'];

		$this->assertStringContainsString( 'gse-42', $markup, 'Another client in a room the user is in should be listed.' );
		$this->assertStringNotContainsString( 'postType/post:2', $markup, 'A room the user is not in should be left out.' );
	}

	/**
	 * @covers ::wp_presence_debugger_admin_bar_node
	 */
	public function test_shows_each_rows_data_without_a_location_the_viewer_cannot_see() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$other = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $admin );
		$room = 'postType/post:1';

		wp_set_presence( $room, 'editor-1', array(), array( 'user_id' => $admin ) );
		wp_set_presence(
			$room,
			'gse-42',
			array(
				'screen' => 'post',
				'cursor' => 7,
			),
			array( 'user_id' => $other )
		);

		$this->assertStringContainsString( '{&quot;screen&quot;:&quot;post&quot;,&quot;cursor&quot;:7}', wp_presence_debugger_admin_bar_markup() );

		wp_get_current_user()->add_cap( 'list_users', false );
		$markup = wp_presence_debugger_admin_bar_markup();

		$this->assertStringContainsString( '{&quot;cursor&quot;:7}', $markup, 'Data that names no location should still show.' );
		$this->assertStringNotContainsString( '&quot;screen&quot;', $markup, 'A location the viewer may not see should be left out.' );
	}

	/**
	 * @covers ::wp_presence_debugger_admin_bar_node
	 */
	public function test_lists_you_first_then_by_name() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$room = 'postType/post:1';

		// Freshest first is the reverse of the order expected.
		wp_set_presence(
			$room,
			'editor-me',
			array(),
			array(
				'user_id'  => $admin,
				'date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 30 ),
			)
		);
		wp_set_presence(
			$room,
			'editor-ana',
			array(),
			array(
				'user_id'  => self::factory()->user->create( array( 'display_name' => 'Ana' ) ),
				'date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 20 ),
			)
		);
		wp_set_presence(
			$room,
			'editor-bea',
			array(),
			array(
				'user_id'  => self::factory()->user->create( array( 'display_name' => 'Bea' ) ),
				'date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 10 ),
			)
		);

		$markup = wp_presence_debugger_admin_bar_markup();

		$this->assertLessThan( strpos( $markup, 'editor-ana' ), strpos( $markup, 'editor-me' ) );
		$this->assertLessThan( strpos( $markup, 'editor-bea' ), strpos( $markup, 'editor-ana' ) );
	}

	/**
	 * @covers ::wp_presence_debugger_admin_bar_node
	 */
	public function test_lists_everyone_without_a_limit() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$room = 'postType/post:999';

		wp_set_presence( $room, 'client-0', array(), array( 'user_id' => $admin ) );
		foreach ( self::factory()->user->create_many( 25 ) as $i => $user_id ) {
			wp_set_presence( $room, 'client-' . ( $i + 1 ), array(), array( 'user_id' => $user_id ) );
		}

		$this->assertStringContainsString( 'wp-admin-bar-presence-debug-room-0-25', wp_presence_debugger_admin_bar_markup() );
	}

	/**
	 * @covers ::wp_presence_debugger_admin_bar_node
	 */
	public function test_other_plugins_add_rows_and_indicators() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$row       = function ( $wp_admin_bar ) {
			$wp_admin_bar->add_node(
				array(
					'parent' => 'presence-debug',
					'id'     => 'presence-debug-extra',
					'title'  => 'Extra row',
				)
			);
		};
		$indicator = function ( $indicators ) {
			$indicators[] = array(
				'icon'  => 'dashicons-controls-play',
				'label' => 'Playing',
			);
			return $indicators;
		};
		add_action( 'wp_presence_debugger_menu', $row );
		add_filter( 'wp_presence_debugger_indicators', $indicator );

		$markup = wp_presence_debugger_admin_bar_markup();

		$this->assertStringContainsString( 'Extra row', $markup, 'Rows added through the action should refresh with the menu.' );
		$this->assertStringContainsString( 'dashicons-controls-play" title="Playing"', $markup );
	}
}