<?php
/**
 * Tests for steps and scenes other plugins add.
 *
 * @package Presence_Scenes
 *
 * @group presence
 */

class WP_Test_Presence_Scene_Plugins extends WP_Presence_UnitTestCase {

	/**
	 * A folder of scenes, as a plugin would add.
	 *
	 * @var string
	 */
	private $folder;

	/**
	 * What each played step saw.
	 *
	 * @var array[]
	 */
	private $played = array();

	public function set_up() {
		parent::set_up();

		$this->folder = trailingslashit( get_temp_dir() ) . 'presence-scenes-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->folder );
		$this->played = array();

		add_filter(
			'wp_presence_scene_libraries',
			function ( $folders ) {
				$folders[] = $this->folder;
				return $folders;
			}
		);
		add_filter(
			'wp_presence_scene_steps',
			function ( $steps ) {
				$steps['startListening'] = array(
					'callback' => function ( $actor, $fields ) {
						$this->played[] = array( get_current_user_id(), $actor->ID, $fields );
					},
					'fields'   => array( 'text' ),
					'label'    => '%1$s starts listening to %2$s',
				);
				$steps['stopListening']  = array(
					'callback' => function () {
						throw new RuntimeException( 'Stopped too soon.' );
					},
					'cast'     => true,
					'label'    => '%s stops listening',
				);
				return $steps;
			}
		);
	}

	public function tear_down() {
		// The parent's TRUNCATE commits, so actors would otherwise outlive the rollback.
		wp_presence_scene_sweep( true );
		delete_option( 'wp_presence_scene' );
		delete_option( 'wp_presence_scene.lock' );
		delete_option( 'wp_presence_scene_runs' );
		foreach ( (array) glob( $this->folder . '/*.json' ) as $file ) {
			wp_delete_file( $file );
		}
		rmdir( $this->folder ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		parent::tear_down();
	}

	/**
	 * Writes a scene into the plugin's folder.
	 *
	 * @param string $file  File name, without .json.
	 * @param array  $steps The scene's steps.
	 * @param array  $more  Optional. Other keys to set or replace.
	 */
	private function scene( $file, array $steps, array $more = array() ) {
		$scene = $more + array(
			'apiVersion' => 1,
			'name'       => $file,
			'title'      => 'Listening',
			'cast'       => array( 'subscriber' ),
			'steps'      => $steps,
		);
		file_put_contents( "{$this->folder}/{$file}.json", wp_json_encode( $scene ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * @covers ::wp_presence_get_scenes
	 * @covers ::wp_presence_scene_actions
	 * @covers ::wp_presence_scene_prepare
	 * @covers ::wp_presence_scene_start
	 * @covers ::wp_presence_scene_play
	 * @covers ::wp_presence_scene_perform
	 * @covers ::wp_presence_scene_strike
	 * @covers WP_Presence_Scene_Actor::perform
	 */
	public function test_plugin_step_plays_as_a_subscriber_with_its_fields() {
		$this->scene(
			'listening-alone',
			array(
				array(
					'at'    => 0,
					'actor' => 1,
					'step'  => 'startListening',
					'text'  => 'Underground',
				),
			)
		);

		$run = wp_presence_scene_start( 'listening-alone' );
		$this->assertIsArray( $run );
		$this->assertSame( 'Actor 1 starts listening to Underground', $run['scene']['steps'][0]['label'] );

		$before = get_current_user_id();
		$run    = wp_presence_scene_play( $run, $run['started'] );

		$this->assertSame( 'pass', $run['done'][0] );
		$this->assertSame( array( array( $run['cast'][0], $run['cast'][0], array( 'text' => 'Underground' ) ) ), $this->played );
		$this->assertSame( $before, get_current_user_id(), 'The actor should stop being the current user after the step.' );
		$this->assertSame( array( 'subscriber' ), get_userdata( $run['cast'][0] )->roles );

		$run = wp_presence_scene_strike( $run );
		$this->assertSame( array(), array_values( wp_presence_scene_problems( $run['notes'] ) ) );
	}

	/**
	 * @covers ::wp_presence_scene_play
	 * @covers ::wp_presence_scene_perform
	 * @covers WP_Presence_Scene_Actor::perform
	 */
	public function test_plugin_step_that_throws_fails_for_the_whole_cast() {
		$this->scene(
			'stopping',
			array(
				array(
					'at'    => 0,
					'actor' => 'cast',
					'step'  => 'stopListening',
				),
			),
			array( 'cast' => array( 'subscriber', 'subscriber' ) )
		);

		$run = wp_presence_scene_start( 'stopping' );
		$run = wp_presence_scene_play( $run, $run['started'] );

		$this->assertSame( 'fail', $run['done'][0] );
		$this->assertStringContainsString( 'Stopped too soon.', end( $run['notes'] )['message'] );
	}

	/**
	 * @covers ::wp_presence_scene_perform
	 */
	public function test_step_whose_plugin_is_gone_fails() {
		$run                   = wp_presence_scene_start( 'editing-together' );
		$run['scene']['steps'] = array(
			array(
				'at'    => 0,
				'actor' => 1,
				'step'  => 'goneAway',
				'label' => 'Actor 1 goes away',
			),
		);

		$run = wp_presence_scene_play( $run, $run['started'] );

		$this->assertSame( 'fail', $run['done'][0] );
	}

	public function data_invalid_plugin_steps() {
		$callback = '__return_null';

		return array(
			'replaces a bundled step' => array( 'visit', array( 'callback' => $callback, 'label' => '%s' ) ),
			'not camelCase'           => array( 'start-listening', array( 'callback' => $callback, 'label' => '%s' ) ),
			'no callback'             => array( 'startPlaying', array( 'label' => '%s' ) ),
			'no label'                => array( 'startPlaying', array( 'callback' => $callback ) ),
			'a reserved field'        => array( 'startPlaying', array( 'callback' => $callback, 'label' => '%s', 'fields' => array( 'actor' ) ) ),
			'the label as a field'    => array( 'startPlaying', array( 'callback' => $callback, 'label' => '%s', 'fields' => array( 'label' ) ) ),
			'fields not a list'       => array( 'startPlaying', array( 'callback' => $callback, 'label' => '%s', 'fields' => array( 'text' => 'text' ) ) ),
		);
	}

	/**
	 * @dataProvider data_invalid_plugin_steps
	 *
	 * @covers ::wp_presence_scene_actions
	 */
	public function test_refuses_an_invalid_plugin_step( $name, $step ) {
		$this->setExpectedIncorrectUsage( 'wp_presence_scene_actions' );
		add_filter(
			'wp_presence_scene_steps',
			function ( $steps ) use ( $name, $step ) {
				$steps[ $name ] = $step;
				return $steps;
			},
			20
		);

		$actions = wp_presence_scene_actions();

		$this->assertSame( 'visit', $actions['visit']['method'] );
		if ( 'visit' !== $name ) {
			$this->assertArrayNotHasKey( $name, $actions );
		}
	}

	/**
	 * @covers ::wp_presence_scene_prepare
	 * @covers ::wp_presence_scene_text
	 */
	public function test_plugin_step_fields_are_plain_text() {
		$scene = array(
			'apiVersion' => 1,
			'name'       => 'markup',
			'title'      => 'Markup',
			'cast'       => array( 'subscriber' ),
			'steps'      => array(
				array(
					'at'    => 0,
					'actor' => 1,
					'step'  => 'startListening',
					'text'  => '<b>Underground</b>',
				),
			),
		);

		$this->assertWPError( wp_presence_scene_prepare( $scene ) );
	}

	public function data_skipped_files() {
		return array(
			'named after another scene' => array( 'listening', 'something-else' ),
			'a bundled scene\'s name'   => array( 'editing-together', 'editing-together' ),
		);
	}

	/**
	 * @dataProvider data_skipped_files
	 *
	 * @covers ::wp_presence_get_scenes
	 */
	public function test_skips_a_scene_file_it_cannot_tell_apart( $file, $name ) {
		$this->setExpectedIncorrectUsage( 'wp_presence_get_scenes' );
		$this->scene( $file, array( array( 'at' => 0, 'actor' => 1, 'step' => 'startListening', 'text' => 'Underground' ) ), array( 'name' => $name ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$scenes = wp_presence_get_scenes();

		$this->assertSame( array( 'author', 'editor' ), $scenes['editing-together']['cast'], 'The bundled scene should keep its name.' );
		$this->assertArrayNotHasKey( 'listening', $scenes );
		$this->assertArrayNotHasKey( 'something-else', $scenes );
	}

	/**
	 * @covers WP_Presence_Scene_Actor::write
	 */
	public function test_subscriber_cannot_write() {
		$run   = array(
			'posts'   => array(),
			'clients' => array(),
		);
		$actor = new WP_Presence_Scene_Actor( self::factory()->user->create( array( 'role' => 'subscriber' ) ), $run );

		$this->expectException( RuntimeException::class );

		try {
			$actor->write( 'Draft' );
		} finally {
			$this->assertSame( array(), $run['posts'] );
		}
	}
}
