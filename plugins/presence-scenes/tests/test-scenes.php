<?php
/**
 * Tests for scenes.
 *
 * @package Presence_Scenes
 *
 * @group presence
 */

class WP_Test_Presence_Scenes extends WP_Presence_UnitTestCase {

	public function tear_down() {
		// The parent's TRUNCATE commits, so actors would otherwise outlive the rollback.
		wp_presence_scene_sweep( true );
		delete_option( 'wp_presence_scene' );
		delete_option( 'wp_presence_scene.lock' );
		delete_option( 'wp_presence_scene_runs' );
		parent::tear_down();
	}

	public function data_bundled_scenes() {
		$scenes = array();
		foreach ( glob( dirname( __DIR__ ) . '/library/*.json' ) as $file ) {
			$scenes[ basename( $file, '.json' ) ] = array( basename( $file, '.json' ) );
		}
		return $scenes;
	}

	/**
	 * @dataProvider data_bundled_scenes
	 *
	 * @covers ::wp_presence_get_scenes
	 * @covers ::wp_presence_scene_places
	 * @covers ::wp_presence_scene_actions
	 * @covers ::wp_presence_scene_prepare
	 * @covers ::wp_presence_scene_text
	 * @covers ::wp_presence_scene_start
	 * @covers ::wp_presence_scene_cast
	 * @covers ::wp_presence_scene_actor_name
	 * @covers ::wp_presence_scene_authenticate
	 * @covers ::wp_presence_scene_play
	 * @covers ::wp_presence_scene_perform
	 * @covers ::wp_presence_scene_note
	 * @covers ::wp_presence_scene_problems
	 * @covers ::wp_presence_scene_strike
	 * @covers ::wp_presence_scene_delete_users
	 * @covers WP_Presence_Scene_Actor
	 */
	public function test_bundled_scene_plays_to_the_end_through_heartbeat( $name ) {
		global $wpdb;

		$run   = wp_presence_scene_start( $name );
		$steps = count( $run['scene']['steps'] );
		$now   = $run['started'];

		while ( count( $run['done'] ) < $steps ) {
			$run = wp_presence_scene_play( $run, $now );

			$next = $now + wp_presence_scene_timings()['beat'];
			foreach ( $run['scene']['steps'] as $i => $step ) {
				if ( ! isset( $run['done'][ $i ] ) ) {
					$next = min( $next, $run['started'] + $step['at'] );
					break;
				}
			}
			$jump = max( 1, $next - $now );
			$now += $jump;

			// Ages every row as far as the fake clock moved, in PHP since SQLite has no INTERVAL.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $wpdb->get_results( "SELECT id, date_gmt, expires_gmt FROM {$wpdb->presence}" ) as $row ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update(
					$wpdb->presence,
					array(
						'date_gmt'    => gmdate( 'Y-m-d H:i:s', strtotime( $row->date_gmt . ' UTC' ) - $jump ),
						'expires_gmt' => gmdate( 'Y-m-d H:i:s', strtotime( $row->expires_gmt . ' UTC' ) - $jump ),
					),
					array( 'id' => $row->id )
				);
			}
			wp_cache_set_last_changed( 'presence' );
		}

		$run = wp_presence_scene_strike( $run );

		$this->assertSame( array(), array_values( wp_presence_scene_problems( $run['notes'] ) ) );
		$this->assertSame( array(), get_users( array( 'include' => $run['cast'], 'blog_id' => 0 ) ) );
		foreach ( $run['posts'] as $post_id ) {
			$this->assertNull( get_post( $post_id ), 'Scene posts should be deleted, not trashed.' );
		}
	}

	public function data_bundled_files() {
		$files = array();
		foreach ( glob( dirname( __DIR__ ) . '/library/*.json' ) as $file ) {
			$files[ basename( $file, '.json' ) ] = array( $file );
		}
		return $files;
	}

	/**
	 * @dataProvider data_bundled_files
	 *
	 * @covers ::wp_presence_scene_places
	 * @covers ::wp_presence_scene_actions
	 * @covers ::wp_presence_scene_prepare
	 * @covers ::wp_presence_scene_text
	 * @covers ::wp_presence_scene_actor_name
	 */
	public function test_bundled_scene_is_valid( $file ) {
		$scene = wp_presence_scene_prepare( wp_json_file_decode( $file, array( 'associative' => true ) ) );

		$this->assertNotWPError( $scene );
		$this->assertSame( basename( $file, '.json' ), $scene['name'] );
	}

	public function data_invalid_steps() {
		$limits = wp_presence_scene_limits();

		return array(
			'unknown key'         => array( array( 'author' => 'admin' ), array() ),
			'no apiVersion'       => array( array( 'apiVersion' => null ), array() ),
			'uppercase name'      => array( array( 'name' => 'Invalid' ), array() ),
			'no title'            => array( array( 'title' => null ), array() ),
			'no steps'            => array( array( 'steps' => array() ), array() ),
			'unknown field'       => array( array(), array( 'post' => 'Draft' ) ),
			'too late'            => array( array(), array( 'at' => $limits['at'] + 1 ) ),
			'actor not cast'      => array( array(), array( 'actor' => 2 ) ),
			'unknown place'       => array( array(), array( 'place' => 'plugins' ) ),
			'unknown step'        => array( array(), array( 'step' => 'deleteUser' ) ),
			'post never written'  => array( array(), array( 'step' => 'open', 'post' => 'Draft' ) ),
			'markup in the title' => array( array(), array( 'step' => 'write', 'title' => '<b>Hi</b>' ) ),
			'administrator cast'  => array( array( 'cast' => array( 'administrator' ) ), array() ),
			'repeated title'      => array( array( 'steps' => array_fill( 0, 2, array( 'at' => 0, 'actor' => 1, 'step' => 'write', 'title' => 'Draft' ) ) ), array() ),
			'too many roles'      => array( array( 'cast' => array_fill( 0, $limits['cast'] + 1, 'author' ) ), array() ),
			'back in time'        => array( array( 'steps' => array( array( 'at' => 1, 'actor' => 1, 'step' => 'drop' ), array( 'at' => 0, 'actor' => 1, 'step' => 'drop' ) ) ), array() ),
			'whole cast writing'  => array( array(), array( 'actor' => 'cast', 'step' => 'write', 'title' => 'Draft' ) ),
			'long scene title'    => array( array( 'title' => str_repeat( 'a', $limits['title'] + 1 ) ), array() ),
			'long step title'     => array( array(), array( 'step' => 'write', 'title' => str_repeat( 'a', $limits['text'] + 1 ) ) ),
		);
	}

	/**
	 * @dataProvider data_invalid_steps
	 *
	 * @covers ::wp_presence_scene_places
	 * @covers ::wp_presence_scene_actions
	 * @covers ::wp_presence_scene_prepare
	 * @covers ::wp_presence_scene_text
	 */
	public function test_refuses_an_invalid_scene( $scene, $step ) {
		$scene = array_filter(
			$scene + array(
				'apiVersion' => 1,
				'name'       => 'invalid',
				'title'      => 'Invalid',
				'cast'       => array( 'author' ),
				'steps'      => array(
					$step + array(
						'at'    => 0,
						'actor' => 1,
						'step'  => 'visit',
						'place' => 'posts',
					),
				),
			),
			function ( $value ) {
				return null !== $value;
			}
		);

		if ( isset( $step['step'] ) && 'visit' !== $step['step'] ) {
			unset( $scene['steps'][0]['place'] );
		}

		$this->assertWPError( wp_presence_scene_prepare( $scene ) );
	}

	/**
	 * @covers ::wp_presence_scene_start
	 */
	public function test_start_refuses_while_another_scene_runs() {
		$this->assertNotFalse( wp_presence_scene_start( 'editing-together' ) );

		$this->assertFalse( wp_presence_scene_start( 'connection-lost' ) );
	}

	/**
	 * @covers ::wp_presence_scene_start
	 * @covers ::wp_presence_scene_cast
	 */
	public function test_start_returns_why_the_cast_could_not_be_created() {
		add_filter( 'pre_user_login', '__return_empty_string' );

		$this->assertWPError( wp_presence_scene_start( 'editing-together' ) );
		$this->assertSame( array(), wp_presence_scene_actors( true ) );
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
	 * @covers ::wp_presence_scene_sweep
	 * @covers ::wp_presence_scene_strike
	 * @covers ::wp_presence_scene_delete_users
	 */
	public function test_stopping_a_scene_leaves_nothing_behind() {
		$run = wp_presence_scene_start( 'editing-together' );
		$this->assertSame( $run['expires'], wp_next_scheduled( 'wp_presence_scene_sweep' ), 'Cron should strike the scene if its command dies.' );
		$run = wp_presence_scene_play( $run, $run['started'] + 5 );
		$this->assertNotEmpty( $run['posts'] );

		wp_presence_scene_sweep( true );

		$this->assertFalse( get_option( 'wp_presence_scene' ) );
		$this->assertFalse( wp_next_scheduled( 'wp_presence_scene_sweep' ) );
		$this->assertSame( array(), get_users( array( 'include' => $run['cast'], 'blog_id' => 0 ) ) );
		$this->assertNull( get_post( $run['posts'][0] ), 'The draft should be deleted, not trashed.' );
		$this->assertSame( array(), $this->presence_for_user( $run['cast'][0] ) );
	}

	public function data_run_past_its_time() {
		return array(
			'command died' => array( 'ticked', -wp_presence_scene_timings()['abandoned'] - 1 ),
			'run expired'  => array( 'expires', 0 ),
		);
	}

	/**
	 * @dataProvider data_run_past_its_time
	 *
	 * @covers ::wp_presence_scene_sweep
	 */
	public function test_sweep_strikes_a_run_past_its_time( $key, $offset ) {
		$run         = wp_presence_scene_start( 'editing-together' );
		$run[ $key ] = time() + $offset;
		update_option( 'wp_presence_scene', $run, false );

		wp_presence_scene_sweep();

		$this->assertFalse( get_option( 'wp_presence_scene' ) );
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

	/**
	 * @covers ::wp_presence_scene_play
	 * @covers ::wp_presence_scene_strike
	 */
	public function test_failed_step_is_reported() {
		$run                   = wp_presence_scene_start( 'editing-together' );
		$run['scene']['steps'] = array(
			array(
				'at'    => 0,
				'actor' => 1,
				'step'  => 'checkOnline',
				'label' => 'Actor 1 is online',
			),
		);

		$run = wp_presence_scene_play( $run, $run['started'] );
		$this->assertSame( 'fail', $run['done'][0] );

		$run = wp_presence_scene_strike( $run );
		$this->assertSame( 'fail', end( $run['notes'] )['level'] );
	}

	/**
	 * @covers ::wp_presence_scene_play
	 */
	public function test_warning_during_a_step_fails_it() {
		$run                   = wp_presence_scene_start( 'editing-together' );
		$run['scene']['steps'] = array_slice( $run['scene']['steps'], 0, 1 );

		add_filter(
			'heartbeat_received',
			function ( $response ) {
				trigger_error( 'Deprecated in a plugin', E_USER_WARNING ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
				return $response;
			}
		);
		$run = wp_presence_scene_play( $run, $run['started'] );

		$this->assertSame( 'fail', $run['done'][0] );
		$this->assertContains( 'warning', array_column( $run['notes'], 'level' ) );
	}

	/**
	 * @covers ::wp_presence_scene_authenticate
	 */
	public function test_actor_cannot_log_in() {
		$run = wp_presence_scene_start( 'editing-together' );

		$this->assertWPError( wp_presence_scene_authenticate( get_userdata( $run['cast'][0] ) ) );
		$this->assertInstanceOf( WP_User::class, wp_presence_scene_authenticate( get_userdata( self::factory()->user->create() ) ) );
	}

	/**
	 * @covers ::wp_presence_scene_casting
	 */
	public function test_casting_names_each_role_once() {
		$this->assertSame( 'Author and Editor', wp_presence_scene_casting( array( 'cast' => array( 'author', 'editor', 'author' ) ) ) );
	}
}
