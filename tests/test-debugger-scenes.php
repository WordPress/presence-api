<?php
/**
 * Tests for debugger scenes.
 *
 * @package Presence_API
 *
 * @group presence
 */

class WP_Test_Presence_Debugger_Scenes extends WP_Presence_UnitTestCase {

	public function set_up() {
		global $wp_actions;

		parent::set_up();

		// The plugin registers the bundled scenes only under WP_DEBUG, which the suite does not set.
		unset( $wp_actions['wp_presence_scenes_init'] );
		add_action( 'wp_presence_scenes_init', 'wp_presence_register_default_scenes' );
	}

	public function tear_down() {
		delete_option( 'wp_presence_scene' );
		delete_option( 'wp_presence_scene.lock' );
		delete_option( 'wp_presence_scene_runs' );
		delete_option( 'wp_presence_scene_reports' );
		parent::tear_down();
	}

	public function data_bundled_scenes() {
		$scenes = array();
		foreach ( glob( dirname( __DIR__ ) . '/scenes/*.json' ) as $file ) {
			$scenes[ basename( $file ) ] = array( $file );
		}
		return $scenes;
	}

	/**
	 * @dataProvider data_bundled_scenes
	 *
	 * @covers ::wp_presence_scene_prepare
	 * @covers ::wp_presence_scene_text
	 */
	public function test_bundled_scene_is_valid( $file ) {
		$prepared = wp_presence_scene_prepare( wp_json_file_decode( $file, array( 'associative' => true ) ) );

		$this->assertNotWPError( $prepared );
	}

	public function data_invalid_cues() {
		return array(
			'no apiVersion'       => array( array( 'apiVersion' => null ), array() ),
			'unknown action'      => array( array(), array( 'action' => 'deleteUser' ) ),
			'post never written'  => array( array(), array( 'action' => 'open', 'post' => 1 ) ),
			'markup in the title' => array( array(), array( 'action' => 'write', 'title' => '<b>Hi</b>' ) ),
		);
	}

	/**
	 * @dataProvider data_invalid_cues
	 *
	 * @covers ::wp_presence_scene_prepare
	 * @covers ::wp_presence_scene_text
	 */
	public function test_refuses_an_invalid_scene( $scene, $cue ) {
		$scene = array_filter(
			$scene + array(
				'apiVersion' => 1,
				'name'       => 'tests/invalid',
				'title'      => 'Invalid',
				'cast'       => array( array( 'role' => 'author' ) ),
				'cues'       => array(
					$cue + array(
						'at'     => 0,
						'actor'  => 1,
						'action' => 'visit',
						'place'  => 'posts',
					),
				),
			),
			function ( $value ) {
				return null !== $value;
			}
		);

		if ( isset( $cue['action'] ) && 'visit' !== $cue['action'] ) {
			unset( $scene['cues'][0]['place'] );
		}

		$this->assertWPError( wp_presence_scene_prepare( $scene ) );
	}

	/**
	 * @covers WP_Presence_Scene_Actor::open
	 */
	public function test_actor_refuses_a_post_the_scene_did_not_write() {
		$run   = array( 'posts' => array() );
		$actor = new WP_Presence_Scene_Actor( self::factory()->user->create( array( 'role' => 'editor' ) ), $run );

		$this->expectException( RuntimeException::class );

		$actor->open( self::factory()->post->create() );
	}

	/**
	 * @covers ::wp_presence_scene_start
	 * @covers ::wp_presence_scene_lock
	 * @covers ::wp_presence_scene_locked
	 */
	public function test_start_waits_for_the_lock() {
		$this->direct();
		wp_presence_scene_lock();

		$this->assertFalse( wp_presence_scene_start( 'presence-api/editing-together' ) );
		$this->assertFalse( get_option( 'wp_presence_scene' ) );
	}

	/**
	 * @covers ::wp_presence_scene_locked
	 */
	public function test_lock_is_released_when_a_step_throws() {
		try {
			wp_presence_scene_locked(
				function () {
					throw new RuntimeException();
				}
			);
		} catch ( RuntimeException $e ) {
			$this->assertTrue( wp_presence_scene_lock() );
		}
	}

	/**
	 * @covers ::wp_presence_scene_start
	 * @covers ::wp_presence_scene_cast
	 * @covers ::wp_presence_scene_direct
	 * @covers ::wp_presence_scene_play
	 * @covers ::wp_presence_scene_strike
	 * @covers ::wp_presence_scene_problems
	 * @covers ::wp_presence_scene_delete_users
	 */
	public function test_stopping_a_scene_leaves_nothing_behind() {
		$this->direct();

		$this->assertTrue( wp_presence_scene_start( 'presence-api/editing-together' ) );
		$run = get_option( 'wp_presence_scene' );
		$this->assertNotEmpty( $run['cast'] );

		// Plays the draft the opening cues would write a few beats later.
		$run['started'] -= 5;
		update_option( 'wp_presence_scene', $run, false );
		$run = wp_presence_scene_direct();
		$this->assertNotEmpty( $run['posts'] );

		wp_presence_scene_strike( $run );

		$this->assertFalse( get_option( 'wp_presence_scene' ) );
		$this->assertSame( array(), get_users( array( 'include' => $run['cast'], 'blog_id' => 0 ) ) );
		$this->assertNull( get_post( $run['posts'][0] ), 'The draft should be deleted, not trashed.' );
		$this->assertSame( array(), $this->presence_for_user( $run['cast'][0] ) );

		$notes = get_option( 'wp_presence_scene_reports' )['presence-api/editing-together']['notes'];
		$this->assertStringStartsWith( 'Stopped after', end( $notes )['message'] );
	}

	/**
	 * @covers ::wp_presence_scene_sweep
	 * @covers ::wp_presence_scene_delete_users
	 */
	public function test_sweep_deletes_an_expired_users_posts() {
		$user_id = self::factory()->user->create(
			array(
				'user_login' => 'actor1run5',
				'meta_input' => array( '_wp_presence_scene' => time() - 1 ),
			)
		);
		$post_id = self::factory()->post->create( array( 'post_author' => $user_id ) );

		wp_presence_scene_sweep();

		$this->assertFalse( get_userdata( $user_id ) );
		$this->assertNull( get_post( $post_id ), 'The post should be deleted, not trashed.' );
	}

	private function direct() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin );
		}
		wp_set_current_user( $admin );
	}
}
