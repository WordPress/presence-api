<?php
/**
 * WP-CLI commands for scenes.
 *
 * @package Presence_Scenes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plays scenes: marked users who work in the admin at real speed, then are deleted.
 *
 * @since 0.1.0
 */
class WP_Presence_Scene_CLI_Command extends WP_CLI_Command {

	/**
	 * Lists the scenes.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp presence scene list
	 *
	 * @since 0.1.0
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function list_( $args, $assoc_args ) {
		$items = array();
		foreach ( wp_presence_get_scenes() as $scene ) {
			$items[] = array(
				'name'     => $scene['name'],
				'title'    => $scene['title'],
				'cast'     => wp_presence_scene_casting( $scene ),
				'steps'    => count( $scene['steps'] ),
				'duration' => human_time_diff( 0, $scene['duration'] ),
			);
		}

		WP_CLI\Utils\format_items( WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' ), $items, array( 'name', 'title', 'cast', 'steps', 'duration' ) );
	}

	/**
	 * Plays a scene to the end, then deletes its users and everything they wrote.
	 *
	 * Creates real users, so other plugins see them come and go. Exits non-zero
	 * when a step fails.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The scene name, from `wp presence scene list`.
	 *
	 * [--yes]
	 * : Skip the confirmation on a production site.
	 *
	 * ## EXAMPLES
	 *
	 *     wp presence scene run editing-together
	 *
	 * @since 0.1.0
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function run( $args, $assoc_args ) {
		$scenes = wp_presence_get_scenes();
		if ( ! isset( $scenes[ $args[0] ] ) ) {
			/* translators: %s: Scene name. */
			WP_CLI::error( sprintf( __( 'There is no scene called %s.', 'presence-scenes' ), $args[0] ) );
		}
		$scene = $scenes[ $args[0] ];

		/* translators: 1: Scene title, 2: Roles, 3: Number of steps, 4: Duration. */
		WP_CLI::log( sprintf( __( '%1$s: creates %2$s users for %3$s steps over %4$s.', 'presence-scenes' ), $scene['title'], wp_presence_scene_casting( $scene ), number_format_i18n( count( $scene['steps'] ) ), human_time_diff( 0, $scene['duration'] ) ) );

		if ( 'production' === wp_get_environment_type() ) {
			WP_CLI::confirm( __( 'This is a production site. Create these users?', 'presence-scenes' ), $assoc_args );
		}

		$run = wp_presence_scene_start( $scene['name'] );
		if ( is_wp_error( $run ) ) {
			WP_CLI::error( $run->get_error_message() );
		}
		if ( ! $run ) {
			wp_cache_delete( 'wp_presence_scene', 'options' );
			WP_CLI::error(
				is_array( get_option( 'wp_presence_scene' ) )
					? __( 'Another scene is running. Stop it with wp presence scene stop.', 'presence-scenes' )
					: __( 'Another command is starting or stopping a scene. Try again.', 'presence-scenes' )
			);
		}

		$this->trap_interrupt();

		$printed = 0;
		$steps   = count( $run['scene']['steps'] );
		while ( count( $run['done'] ) < $steps ) { // phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found -- Grows as steps play.
			$playing = wp_presence_scene_locked(
				function ( $running ) use ( &$run ) {
					// Stopped from another command, which already struck it.
					if ( ! is_array( $running ) || $running['run'] !== $run['run'] ) {
						return false;
					}
					$run = wp_presence_scene_play( $run, $this->now() );
					return true;
				},
				wp_presence_scene_timings()['wait']
			);
			$this->print_notes( $run['notes'], $printed );
			if ( ! $playing ) {
				WP_CLI::error( __( 'The scene was stopped.', 'presence-scenes' ) );
			}
			$this->wait();
		}

		$run = wp_presence_scene_locked(
			function ( $running ) {
				return is_array( $running ) ? wp_presence_scene_strike( $running ) : false;
			},
			wp_presence_scene_timings()['wait']
		);
		if ( ! $run ) {
			WP_CLI::error( __( 'The scene was stopped.', 'presence-scenes' ) );
		}
		$summary = array_pop( $run['notes'] );
		$this->print_notes( $run['notes'], $printed );

		if ( 'fail' === $summary['level'] ) {
			WP_CLI::error( $summary['message'] );
		}
		WP_CLI::success( $summary['message'] );
	}

	/**
	 * Stops the running scene and deletes every scene user.
	 *
	 * ## EXAMPLES
	 *
	 *     wp presence scene stop
	 *
	 * @since 0.1.0
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function stop( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by WP-CLI.
		wp_presence_scene_sweep( true );

		WP_CLI::success( __( 'No scene is running, and no scene users are left.', 'presence-scenes' ) );
	}

	/**
	 * Strikes the scene on Ctrl+C, where PHP can catch it.
	 *
	 * @codeCoverageIgnore
	 */
	protected function trap_interrupt() {
		if ( ! function_exists( 'pcntl_async_signals' ) ) {
			return;
		}

		pcntl_async_signals( true );
		pcntl_signal(
			SIGINT,
			function () {
				wp_presence_scene_sweep( true );
				// Shells report a command killed by a signal as 128 plus its number.
				WP_CLI::halt( 128 + SIGINT );
			}
		);
	}

	/**
	 * Returns the time to play the scene at.
	 *
	 * @return int Timestamp.
	 *
	 * @codeCoverageIgnore
	 */
	protected function now() {
		return time();
	}

	/**
	 * Waits before the next tick.
	 *
	 * @codeCoverageIgnore
	 */
	protected function wait() {
		sleep( 1 );
	}

	/**
	 * Prints the notes added since the last call.
	 *
	 * @param array $notes   The scene's notes.
	 * @param int   $printed How many are already printed.
	 */
	private function print_notes( array $notes, &$printed ) {
		$marks = array(
			'pass'    => '✓',
			'fail'    => '✗',
			'warning' => '!',
			'info'    => '·',
		);

		foreach ( array_slice( $notes, $printed ) as $note ) {
			WP_CLI::log( $marks[ $note['level'] ] . ' ' . $note['message'] );
		}
		$printed = count( $notes );
	}
}
