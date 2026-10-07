<?php
/**
 * Tests for the block editor Editors panel.
 *
 * @package Presence_API
 *
 * @group presence
 */

class WP_Test_Presence_Editors_Panel extends WP_Presence_UnitTestCase {

	public function tear_down() {
		wp_dequeue_script( 'wp-presence-editors-panel' );
		wp_deregister_script( 'wp-presence-editors-panel' );
		unset( $GLOBALS['post'] );
		parent::tear_down();
	}

	/**
	 * @covers ::wp_presence_enqueue_editors_panel
	 */
	public function test_enqueues_with_the_post_room_only_for_someone_who_can_edit_the_post() {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$post   = self::factory()->post->create_and_get( array( 'post_author' => self::factory()->user->create( array( 'role' => 'editor' ) ) ) );

		$GLOBALS['post'] = $post;

		wp_set_current_user( $author );
		wp_presence_enqueue_editors_panel();
		$this->assertFalse( wp_script_is( 'wp-presence-editors-panel' ), 'An author cannot edit another person\'s post.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		wp_presence_enqueue_editors_panel();
		$this->assertTrue( wp_script_is( 'wp-presence-editors-panel' ) );
		$this->assertContains( 'wp-presence', wp_scripts()->registered['wp-presence-editors-panel']->deps );
		$this->assertStringContainsString( '"room":"postType/post:' . $post->ID . '"', implode( '', wp_scripts()->get_data( 'wp-presence-editors-panel', 'before' ) ) );
	}
}
