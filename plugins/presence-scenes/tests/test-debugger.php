<?php
/**
 * Tests for the scene rows in the Presence API debugger.
 *
 * @package Presence_Scenes
 *
 * @group presence
 */

class WP_Test_Presence_Scene_Debugger extends WP_Presence_UnitTestCase {

	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once __DIR__ . '/../../presence-debugger/debugger-admin-bar.php';
	}

	/**
	 * @covers ::wp_presence_scene_debugger_indicators
	 * @covers ::wp_presence_scene_debugger_menu
	 */
	public function test_shows_progress_and_the_latest_step_while_a_scene_plays() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertStringNotContainsString( 'Scene playing', wp_presence_debugger_admin_bar_markup() );

		update_option(
			'wp_presence_scene',
			array(
				'title' => 'Editing together',
				'scene' => array( 'steps' => array( array( 'label' => 'Actor 1 writes' ), array( 'label' => 'Actor 2 opens' ), array( 'label' => 'Actor 1 leaves' ) ) ),
				'done'  => array( 0 => 'pass', 1 => 'pass' ),
			)
		);
		$markup = wp_presence_debugger_admin_bar_markup();
		delete_option( 'wp_presence_scene' );

		$this->assertStringContainsString( 'Scene playing', $markup );
		$this->assertStringContainsString( '2 / 3', $markup );
		$this->assertStringContainsString( 'Actor 2 opens', $markup );
	}
}
