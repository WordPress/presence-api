<?php
/**
 * Tests for scene actors.
 *
 * @package Presence_Scenes
 *
 * @group presence
 */

class WP_Test_Presence_Scene_Actor extends WP_Presence_UnitTestCase {

	/**
	 * @covers WP_Presence_Scene_Actor
	 */
	public function test_actor_refuses_a_post_the_scene_did_not_write() {
		$run   = array( 'posts' => array() );
		$actor = new WP_Presence_Scene_Actor( self::factory()->user->create( array( 'role' => 'editor' ) ), $run );

		$this->expectException( RuntimeException::class );

		$actor->open( self::factory()->post->create() );
	}

	/**
	 * @covers WP_Presence_Scene_Actor
	 * @covers ::wp_presence_scene_note
	 */
	public function test_actor_stops_holding_a_lock_taken_over_by_another() {
		require_once ABSPATH . 'wp-admin/includes/admin.php';

		$run = array(
			'started' => time(),
			'posts'   => array(),
			'clients' => array(),
			'notes'   => array(),
		);
		$first  = new WP_Presence_Scene_Actor( self::factory()->user->create( array( 'role' => 'editor' ) ), $run );
		$second = new WP_Presence_Scene_Actor( self::factory()->user->create( array( 'role' => 'editor' ) ), $run );

		$first->write( 'Draft' );
		$second->take_over( $run['posts'][0] );
		$first->beat();

		$this->assertFalse( $run['clients'][ $first->ID ]['lock'] );
		$this->assertSame( 'info', end( $run['notes'] )['level'] );
	}

	/**
	 * @covers WP_Presence_Scene_Actor
	 */
	public function test_actor_edits_a_draft_and_logs_out() {
		require_once ABSPATH . 'wp-admin/includes/admin.php';

		$run   = array(
			'posts'   => array(),
			'clients' => array(),
		);
		$actor = new WP_Presence_Scene_Actor( self::factory()->user->create( array( 'role' => 'author' ) ), $run );

		$actor->visit( 'posts' );
		$actor->check_online();
		$actor->write( 'Draft' );
		$actor->type( $run['posts'][0], 'A second paragraph.' );
		$actor->close( $run['posts'][0] );
		$actor->leave();
		$actor->check_offline();

		$this->assertStringContainsString( 'A second paragraph.', get_post( $run['posts'][0] )->post_content );
		$this->assertSame( '', get_post_meta( $run['posts'][0], '_edit_lock', true ) );
	}

	/**
	 * @covers WP_Presence_Scene_Actor
	 */
	public function test_actor_cannot_type_in_a_post_another_has_locked() {
		require_once ABSPATH . 'wp-admin/includes/admin.php';

		$run    = array(
			'posts'   => array(),
			'clients' => array(),
		);
		$first  = new WP_Presence_Scene_Actor( self::factory()->user->create( array( 'role' => 'editor' ) ), $run );
		$second = new WP_Presence_Scene_Actor( self::factory()->user->create( array( 'role' => 'editor' ) ), $run );

		$first->write( 'Draft' );
		$second->check_locked( $run['posts'][0] );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'holds the lock' );

		$second->type( $run['posts'][0], 'A second paragraph.' );
	}

	/**
	 * @covers WP_Presence_Scene_Actor
	 */
	public function test_actor_cannot_visit_a_screen_their_role_cannot_open() {
		$run   = array( 'clients' => array() );
		$actor = new WP_Presence_Scene_Actor( self::factory()->user->create( array( 'role' => 'contributor' ) ), $run );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'cannot open' );

		$actor->visit( 'media' );
	}

	/**
	 * @covers ::wp_presence_scene_note
	 */
	public function test_repeated_problem_is_noted_once() {
		$run = array(
			'started' => time(),
			'notes'   => array(),
		);

		wp_presence_scene_note( $run, 'fail', 'Not in the online list.' );
		wp_presence_scene_note( $run, 'fail', 'Not in the online list.' );

		$this->assertCount( 1, $run['notes'] );
	}
}
