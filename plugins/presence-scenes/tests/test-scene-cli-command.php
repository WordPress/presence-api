<?php
/**
 * Tests for the scene WP-CLI command (class-wp-presence-scene-cli-command.php).
 *
 * @package Presence_Scenes
 *
 * @group presence
 * @group cli
 */

require_once WP_PRESENCE_PLUGIN_DIR . 'tests/stubs/wp-cli.php';
require_once dirname( __DIR__ ) . '/class-wp-presence-scene-cli-command.php';

/**
 * Plays on a clock that moves a second per tick, without sleeping.
 */
class WP_Presence_Test_Scene_CLI_Command extends WP_Presence_Scene_CLI_Command {

	public $elapsed = 0;

	public $on_wait;

	protected function trap_interrupt() {}

	protected function now() {
		return time() + $this->elapsed;
	}

	protected function wait() {
		++$this->elapsed;
		if ( $this->on_wait ) {
			call_user_func( $this->on_wait );
		}
	}
}

class WP_Test_Presence_Scene_CLI_Command extends WP_Presence_UnitTestCase {

	private $command;

	public function set_up() {
		parent::set_up();
		WP_CLI::reset();
		$this->command = new WP_Presence_Test_Scene_CLI_Command();
	}

	public function tear_down() {
		// The parent's TRUNCATE commits, so actors would otherwise outlive the rollback.
		wp_presence_scene_sweep( true );
		delete_option( 'wp_presence_scene' );
		delete_option( 'wp_presence_scene.lock' );
		delete_option( 'wp_presence_scene_runs' );
		parent::tear_down();
	}

	private function run_and_halt( $name, $expected ) {
		try {
			$this->command->run( array( $name ), array() );
		} catch ( WP_Presence_CLI_Halt $e ) {
			$this->assertSame( $expected, $e->getMessage() );
			return;
		}

		$this->fail( 'Expected WP_CLI::error() to halt the command.' );
	}

	/**
	 * @covers WP_Presence_Scene_CLI_Command::list_
	 */
	public function test_list_shows_every_scene() {
		$this->command->list_( array(), array() );

		$items = WP_CLI::$formatted[0]['items'];
		$this->assertSame( array_keys( wp_presence_get_scenes() ), wp_list_pluck( $items, 'name' ) );
		$this->assertSame( array( 'name', 'title', 'cast', 'steps', 'duration' ), WP_CLI::$formatted[0]['fields'] );
	}

	/**
	 * @covers WP_Presence_Scene_CLI_Command::run
	 */
	public function test_run_refuses_an_unknown_scene() {
		$this->run_and_halt( 'nope', 'There is no scene called nope.' );
	}

	/**
	 * @covers WP_Presence_Scene_CLI_Command::run
	 * @covers WP_Presence_Scene_CLI_Command::print_notes
	 * @covers ::wp_presence_scene_lock
	 * @covers ::wp_presence_scene_locked
	 */
	public function test_run_plays_a_scene_to_the_end_and_cleans_up() {
		$this->command->run( array( 'editing-together' ), array() );

		$this->assertSame( array( 'Finished with no problems.' ), WP_CLI::messages( 'success' ) );
		$this->assertContains( '✓ Actor 1 writes “Launch checklist”', WP_CLI::messages( 'log' ) );
		$this->assertFalse( get_option( 'wp_presence_scene' ) );
		$this->assertSame( array(), get_users( array( 'meta_key' => '_wp_presence_scene' ) ) );
	}

	/**
	 * @covers WP_Presence_Scene_CLI_Command::run
	 */
	public function test_run_fails_when_heartbeat_refuses_the_cast() {
		remove_filter( 'heartbeat_received', 'wp_presence_admin_heartbeat_received', 9 );

		try {
			$this->command->run( array( 'editing-together' ), array() );
			$this->fail( 'Expected WP_CLI::error() to halt the command.' );
		} catch ( WP_Presence_CLI_Halt $e ) {
			$this->assertStringStartsWith( 'Finished with', $e->getMessage() );
		} finally {
			add_filter( 'heartbeat_received', 'wp_presence_admin_heartbeat_received', 9, 3 );
		}
	}

	/**
	 * @covers WP_Presence_Scene_CLI_Command::run
	 */
	public function test_run_says_why_the_cast_could_not_be_created() {
		add_filter( 'pre_user_login', '__return_empty_string' );

		$this->run_and_halt( 'editing-together', wp_insert_user( array() )->get_error_message() );
	}

	/**
	 * @covers WP_Presence_Scene_CLI_Command::run
	 */
	public function test_run_refuses_while_another_scene_runs() {
		wp_presence_scene_start( 'connection-lost' );

		$this->run_and_halt( 'editing-together', 'Another scene is running. Stop it with wp presence scene stop.' );
	}

	/**
	 * @covers WP_Presence_Scene_CLI_Command::run
	 * @covers ::wp_presence_scene_sweep
	 */
	public function test_run_replaces_a_scene_whose_command_died() {
		$run           = wp_presence_scene_start( 'connection-lost' );
		$run['ticked'] = time() - 31;
		update_option( 'wp_presence_scene', $run, false );

		$this->command->run( array( 'editing-together' ), array() );

		$this->assertSame( array( 'Finished with no problems.' ), WP_CLI::messages( 'success' ) );
		$this->assertFalse( get_userdata( $run['cast'][0] ) );
	}

	/**
	 * @covers WP_Presence_Scene_CLI_Command::run
	 * @covers WP_Presence_Scene_CLI_Command::stop
	 */
	public function test_stop_from_another_command_ends_the_run() {
		$this->command->on_wait = function () {
			( new WP_Presence_Scene_CLI_Command() )->stop( array(), array() );
		};

		$this->run_and_halt( 'editing-together', 'The scene was stopped.' );
		$this->assertSame( 1, $this->command->elapsed, 'The run should end on the tick after the stop.' );
		$this->assertSame( array(), get_users( array( 'meta_key' => '_wp_presence_scene' ) ) );
	}
}
