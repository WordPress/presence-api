<?php
/**
 * Tests for the Presence API Debugger dashboard widget's capability bar.
 *
 * @package Presence_API
 *
 * @group presence
 */

// The plugin loads this only under WP_DEBUG, which the suite does not set.
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/debugger-widget.php';

class WP_Test_Presence_Debugger_Widget extends WP_Presence_UnitTestCase {

	/**
	 * @covers ::wp_presence_heartbeat_widget_received
	 */
	public function test_heartbeat_says_nothing_to_a_subscriber() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$response = wp_presence_heartbeat_widget_received( array(), array( 'presence-ping' => 1 ), 'dashboard' );

		$this->assertSame( array(), $response, 'A subscriber sending the ping key should learn nothing about the site.' );
	}

	/**
	 * @covers ::wp_presence_heartbeat_widget_received
	 */
	public function test_heartbeat_answers_an_administrator() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$response = wp_presence_heartbeat_widget_received( array(), array( 'presence-ping' => 1 ), 'dashboard' );

		$this->assertArrayHasKey( 'presence-heartbeat-users', $response );
	}

	/**
	 * @covers ::wp_presence_heartbeat_widget_register
	 */
	public function test_the_widget_is_registered_for_administrators_only() {
		global $wp_meta_boxes;

		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		set_current_screen( 'dashboard' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$wp_meta_boxes = array();
		wp_presence_heartbeat_widget_register();

		$this->assertSame( array(), $wp_meta_boxes, 'A subscriber should get no widget.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$wp_meta_boxes = array();
		wp_presence_heartbeat_widget_register();

		// Across the whole screen: core is free to normalise context and priority.
		$registered = array();
		foreach ( $wp_meta_boxes['dashboard'] as $context ) {
			foreach ( $context as $priority ) {
				$registered = array_merge( $registered, array_keys( $priority ) );
			}
		}

		$this->assertContains( 'presence_heartbeat', $registered );
	}
}
