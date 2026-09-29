<?php
/**
 * Scenes: bundled scripts of marked users working in the admin, played by WP-CLI through the same Heartbeat filters a browser reaches.
 *
 * @package Presence_Scenes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns the most a scene may hold.
 *
 * @since 0.1.0
 *
 * @access private
 *
 * @return int[] {
 *     @type int $cast  Parts in the cast.
 *     @type int $steps Steps.
 *     @type int $at    Latest second a step can start at.
 *     @type int $title Characters in the scene's title.
 *     @type int $text  Characters in a step's title or text.
 * }
 */
function wp_presence_scene_limits() {
	return array(
		'cast'  => 7,
		'steps' => 30,
		'at'    => 15 * MINUTE_IN_SECONDS,
		'title' => 60,
		'text'  => 100,
	);
}

/**
 * Places an actor can visit, each with its screen ID, title and the capability it needs.
 *
 * @since 0.1.0
 *
 * @access private
 *
 * @return array[] Places keyed by the name a scene uses.
 */
function wp_presence_scene_places() {
	return array(
		'dashboard' => array(
			'screen' => 'dashboard',
			'title'  => __( 'Dashboard', 'presence-scenes' ),
			'cap'    => 'read',
		),
		'posts'     => array(
			'screen' => 'edit-post',
			'title'  => __( 'Posts', 'presence-scenes' ),
			'cap'    => 'edit_posts',
		),
		'pages'     => array(
			'screen' => 'edit-page',
			'title'  => __( 'Pages', 'presence-scenes' ),
			'cap'    => 'edit_pages',
		),
		'media'     => array(
			'screen' => 'upload',
			'title'  => __( 'Media', 'presence-scenes' ),
			'cap'    => 'upload_files',
		),
		'comments'  => array(
			'screen' => 'edit-comments',
			'title'  => __( 'Comments', 'presence-scenes' ),
			'cap'    => 'edit_posts',
		),
		'profile'   => array(
			'screen' => 'profile',
			'title'  => __( 'Profile', 'presence-scenes' ),
			'cap'    => 'read',
		),
	);
}

/**
 * Returns the bundled scenes.
 *
 * @since 0.1.0
 *
 * @access private
 *
 * @return array Scenes keyed by name.
 */
function wp_presence_get_scenes() {
	static $scenes = null;

	if ( null === $scenes ) {
		$scenes = array();
		foreach ( (array) glob( __DIR__ . '/library/*.json' ) as $file ) {
			$scene = wp_presence_scene_prepare( wp_json_file_decode( $file, array( 'associative' => true ) ) );
			if ( is_wp_error( $scene ) ) {
				_doing_it_wrong( __FUNCTION__, esc_html( wp_basename( $file ) . ': ' . $scene->get_error_message() ), '0.1.0' );
				continue;
			}
			$scenes[ $scene['name'] ] = $scene;
		}
	}

	return $scenes;
}

/**
 * Steps a scene can take, each with the WP_Presence_Scene_Actor method that plays it, the fields it needs, whether the whole cast can take it and how it is narrated.
 *
 * @since 0.1.0
 *
 * @access private
 *
 * @return array[] Steps keyed by name.
 */
function wp_presence_scene_actions() {
	return array(
		'visit'         => array(
			'method' => 'visit',
			'fields' => array( 'place' ),
			'cast'   => true,
			/* translators: 1: Actor, 2: Screen title. */
			'label'  => __( '%1$s arrives on %2$s', 'presence-scenes' ),
			/* translators: 1: Actor, 2: Screen title. */
			'again'  => __( '%1$s goes to %2$s', 'presence-scenes' ),
		),
		'write'         => array(
			'method' => 'write',
			'fields' => array( 'title' ),
			'cast'   => false,
			/* translators: 1: Actor, 2: Post title. */
			'label'  => __( '%1$s writes “%2$s”', 'presence-scenes' ),
		),
		'open'          => array(
			'method' => 'open',
			'fields' => array( 'post' ),
			'cast'   => false,
			/* translators: 1: Actor, 2: Post title. */
			'label'  => __( '%1$s opens “%2$s”', 'presence-scenes' ),
		),
		'takeOver'      => array(
			'method' => 'take_over',
			'fields' => array( 'post' ),
			'cast'   => false,
			/* translators: 1: Actor, 2: Post title. */
			'label'  => __( '%1$s takes over “%2$s”', 'presence-scenes' ),
		),
		'type'          => array(
			'method' => 'type',
			'fields' => array( 'post', 'text' ),
			'cast'   => false,
			/* translators: 1: Actor, 2: Post title. */
			'label'  => __( '%1$s edits “%2$s”', 'presence-scenes' ),
		),
		'close'         => array(
			'method' => 'close',
			'fields' => array( 'post' ),
			'cast'   => false,
			/* translators: 1: Actor, 2: Post title. */
			'label'  => __( '%1$s closes “%2$s”', 'presence-scenes' ),
		),
		'drop'          => array(
			'method' => 'drop',
			'fields' => array(),
			'cast'   => true,
			/* translators: %s: Actor. */
			'label'  => __( '%s loses connection', 'presence-scenes' ),
		),
		'leave'         => array(
			'method' => 'leave',
			'fields' => array(),
			'cast'   => true,
			/* translators: %s: Actor. */
			'label'  => __( '%s logs out', 'presence-scenes' ),
		),
		'checkOnline'   => array(
			'method' => 'check_online',
			'fields' => array(),
			'cast'   => true,
			/* translators: %s: Actor. */
			'label'  => __( '%s is online', 'presence-scenes' ),
		),
		'checkOffline'  => array(
			'method' => 'check_offline',
			'fields' => array(),
			'cast'   => true,
			/* translators: %s: Actor. */
			'label'  => __( '%s is offline', 'presence-scenes' ),
		),
		'checkLocked'   => array(
			'method' => 'check_locked',
			'fields' => array( 'post' ),
			'cast'   => false,
			/* translators: 1: Actor, 2: Post title. */
			'label'  => __( '%1$s finds “%2$s” locked', 'presence-scenes' ),
		),
		'checkUnlocked' => array(
			'method' => 'check_unlocked',
			'fields' => array( 'post' ),
			'cast'   => false,
			/* translators: 1: Actor, 2: Post title. */
			'label'  => __( '%1$s finds “%2$s” free', 'presence-scenes' ),
		),
	);
}

/**
 * Checks a scene against the allowed steps and narrates each one.
 *
 * @since 0.1.0
 *
 * @access private
 *
 * @param mixed $scene The decoded scene.
 * @return array|WP_Error The scene ready to play, or why it cannot be.
 */
function wp_presence_scene_prepare( $scene ) {
	$actions = wp_presence_scene_actions();
	$places  = wp_presence_scene_places();
	$limits  = wp_presence_scene_limits();
	$roles   = array( 'contributor', 'author', 'editor' );
	$invalid = function ( $message, $n = null ) {
		/* translators: 1: Step number, 2: What is wrong with it. */
		return new WP_Error( 'presence_scene_invalid', null === $n ? $message : sprintf( __( 'Step %1$d: %2$s', 'presence-scenes' ), $n + 1, $message ) );
	};

	if ( ! is_array( $scene ) || array_diff( array_keys( $scene ), array( 'apiVersion', 'name', 'title', 'cast', 'steps' ) ) ) {
		return $invalid( __( 'A scene has only apiVersion, name, title, cast and steps.', 'presence-scenes' ) );
	}

	if ( 1 !== ( $scene['apiVersion'] ?? null ) ) {
		return $invalid( __( 'This version of the plugin reads apiVersion 1 only.', 'presence-scenes' ) );
	}

	if ( ! is_string( $scene['name'] ?? null ) || ! preg_match( '#^[a-z0-9-]+$#', $scene['name'] ) ) {
		return $invalid( __( 'The name must be lowercase letters, numbers and dashes, such as editing-together.', 'presence-scenes' ) );
	}

	$title = wp_presence_scene_text( $scene['title'] ?? null, $limits['title'] );
	if ( null === $title ) {
		/* translators: %s: Maximum number of characters. */
		return $invalid( sprintf( __( 'The title must be text of up to %s characters.', 'presence-scenes' ), number_format_i18n( $limits['title'] ) ) );
	}

	$cast    = $scene['cast'] ?? null;
	$allowed = function ( $role ) use ( $roles ) {
		return in_array( $role, $roles, true );
	};
	if ( ! is_array( $cast ) || ! wp_is_numeric_array( $cast ) || count( $cast ) < 1 || count( $cast ) > $limits['cast'] || array_filter( $cast, $allowed ) !== $cast ) {
		/* translators: 1: Maximum number of roles, 2: Allowed roles. */
		return $invalid( sprintf( __( 'The cast must list 1 to %1$s roles, each one of %2$s.', 'presence-scenes' ), number_format_i18n( $limits['cast'] ), implode( ', ', $roles ) ) );
	}

	$steps = $scene['steps'] ?? null;
	if ( ! is_array( $steps ) || ! wp_is_numeric_array( $steps ) || count( $steps ) < 1 || count( $steps ) > $limits['steps'] ) {
		/* translators: %s: Maximum number of steps. */
		return $invalid( sprintf( __( 'The steps must list 1 to %s steps.', 'presence-scenes' ), number_format_i18n( $limits['steps'] ) ) );
	}

	$prepared = array();
	$titles   = array();
	$present  = array_fill( 0, count( $cast ), false );
	$last     = 0;

	foreach ( $steps as $n => $step ) {
		$action = is_array( $step ) && is_string( $step['step'] ?? null ) ? $step['step'] : '';

		if ( ! isset( $actions[ $action ] ) ) {
			/* translators: %s: Allowed steps. */
			return $invalid( sprintf( __( 'step must be one of %s.', 'presence-scenes' ), implode( ', ', array_keys( $actions ) ) ), $n );
		}

		$fields = $actions[ $action ]['fields'];
		$keys   = array_merge( array( 'at', 'actor', 'step' ), $fields );
		if ( array_diff( array_keys( $step ), $keys ) || array_diff( $keys, array_keys( $step ) ) ) {
			/* translators: 1: Step, 2: Its fields. */
			return $invalid( sprintf( __( '%1$s takes %2$s.', 'presence-scenes' ), $action, implode( ', ', $keys ) ), $n );
		}

		$at = $step['at'];
		if ( ! is_int( $at ) || $at < $last || $at > $limits['at'] ) {
			/* translators: %s: Latest second a step can start at. */
			return $invalid( sprintf( __( 'at must be seconds from 0 to %s, never earlier than the step before.', 'presence-scenes' ), number_format_i18n( $limits['at'] ) ), $n );
		}
		$last = $at;

		$actor = $step['actor'];
		$whole = 'cast' === $actor && $actions[ $action ]['cast'];
		if ( ! $whole && ! ( is_int( $actor ) && $actor >= 1 && $actor <= count( $cast ) ) ) {
			/* translators: %d: Number of actors. */
			return $invalid( sprintf( __( 'actor must be a number from 1 to %d, or "cast" for steps the whole cast can take.', 'presence-scenes' ), count( $cast ) ), $n );
		}

		$clean  = array(
			'at'    => $at,
			'actor' => $actor,
			'step'  => $action,
		);
		$object = '';

		foreach ( $fields as $field ) {
			$value = $step[ $field ];

			if ( 'place' === $field ) {
				if ( ! is_string( $value ) || ! isset( $places[ $value ] ) ) {
					/* translators: %s: Allowed places. */
					return $invalid( sprintf( __( 'place must be one of %s.', 'presence-scenes' ), implode( ', ', array_keys( $places ) ) ), $n );
				}
				$clean['place'] = $value;
				$object         = $places[ $value ]['title'];
			} elseif ( 'post' === $field ) {
				// Posts are named by title, and stored as their place in the order they were written.
				$clean['post'] = is_string( $value ) ? array_search( $value, $titles, true ) : false;
				if ( false === $clean['post'] ) {
					return $invalid( __( 'post must be the title of a post an earlier write step created.', 'presence-scenes' ), $n );
				}
				$object = $value;
			} else {
				$clean[ $field ] = wp_presence_scene_text( $value, $limits['text'] );
				if ( null === $clean[ $field ] ) {
					/* translators: 1: Field name, 2: Maximum number of characters. */
					return $invalid( sprintf( __( '%1$s must be text of up to %2$s characters.', 'presence-scenes' ), $field, number_format_i18n( $limits['text'] ) ), $n );
				}
			}
		}

		if ( 'write' === $action ) {
			if ( in_array( $clean['title'], $titles, true ) ) {
				return $invalid( __( 'Each write step needs a title of its own.', 'presence-scenes' ), $n );
			}
			$titles[] = $clean['title'];
			$object   = $clean['title'];
		}

		$members = $whole ? array_keys( $present ) : array( $actor - 1 );
		$format  = 'visit' === $action && ! in_array( false, array_intersect_key( $present, array_flip( $members ) ), true ) ? $actions[ $action ]['again'] : $actions[ $action ]['label'];

		$clean['label'] = sprintf( $format, $whole ? __( 'The cast', 'presence-scenes' ) : wp_presence_scene_actor_name( $actor - 1 ), $object );
		$prepared[]     = $clean;

		if ( 0 !== strpos( $action, 'check' ) ) {
			foreach ( $members as $i ) {
				$present[ $i ] = ! in_array( $action, array( 'drop', 'leave' ), true );
			}
		}
	}

	return array(
		'name'     => $scene['name'],
		'title'    => $title,
		'cast'     => $cast,
		'steps'    => $prepared,
		'duration' => $last,
	);
}

/**
 * Accepts a scene string only if it is already short plain text.
 *
 * @since 0.1.0
 *
 * @access private
 *
 * @param mixed $value The value from the scene.
 * @param int   $max   Maximum length in characters.
 * @return string|null The text, or null when it is not allowed.
 */
function wp_presence_scene_text( $value, $max ) {
	if ( ! is_string( $value ) ) {
		return null;
	}

	$text = sanitize_text_field( $value );

	return '' !== $text && $text === $value && mb_strlen( $text ) <= $max ? $text : null;
}

/**
 * Names the actor cast in a scene's part, such as "Actor 1".
 *
 * @since 0.1.0
 *
 * @access private
 *
 * @param int $i Zero-based index of the part.
 * @return string
 */
function wp_presence_scene_actor_name( $i ) {
	/* translators: %d: Actor number. */
	return sprintf( __( 'Actor %d', 'presence-scenes' ), $i + 1 );
}

/**
 * Adds a note to the running scene's report, once per distinct problem.
 *
 * @since 0.1.0
 *
 * @access private
 *
 * @param array  $run     The running scene.
 * @param string $level   One of pass, fail, warning or info.
 * @param string $message The note.
 */
function wp_presence_scene_note( array &$run, $level, $message ) {
	if ( 'fail' === $level || 'warning' === $level ) {
		foreach ( $run['notes'] as $note ) {
			if ( $note['level'] === $level && $note['message'] === $message ) {
				return;
			}
		}
	}

	$run['notes'][] = array(
		't'       => max( 0, time() - $run['started'] ),
		'level'   => $level,
		'message' => $message,
	);
}

/**
 * Plays one step through the actors it names.
 *
 * @since 0.1.0
 *
 * @access private
 *
 * @param array                     $step A prepared step.
 * @param WP_Presence_Scene_Actor[] $cast The cast.
 * @param array                     $run  The running scene.
 */
function wp_presence_scene_perform( array $step, array $cast, array $run ) {
	$action = wp_presence_scene_actions()[ $step['step'] ];
	$method = $action['method'];
	$args   = array();

	foreach ( $action['fields'] as $field ) {
		$args[] = 'post' === $field ? (int) ( $run['posts'][ $step['post'] ] ?? 0 ) : $step[ $field ];
	}

	foreach ( 'cast' === $step['actor'] ? $cast : array( $cast[ $step['actor'] - 1 ] ) as $actor ) {
		$actor->$method( ...$args );
	}
}

/**
 * Picks the failures and warnings out of a scene's notes.
 *
 * @since 0.1.0
 *
 * @access private
 *
 * @param array[] $notes Notes from wp_presence_scene_note().
 * @return array[] The notes that are problems.
 */
function wp_presence_scene_problems( array $notes ) {
	return array_filter(
		$notes,
		function ( $note ) {
			return 'fail' === $note['level'] || 'warning' === $note['level'];
		}
	);
}

/**
 * Returns how long the parts of a run take.
 *
 * @since 0.1.0
 *
 * @access private
 *
 * @return int[] {
 *     @type int $beat      Seconds between each actor's Heartbeats.
 *     @type int $abandoned Seconds a run can go unplayed, or its lock held, before its command is taken to have died.
 *     @type int $wait      Seconds a command waits for another to release the lock.
 *     @type int $grace     Seconds past its last step before a run expires.
 * }
 */
function wp_presence_scene_timings() {
	return array(
		'beat'      => 15,
		'abandoned' => 30,
		'wait'      => 5,
		'grace'     => 10 * MINUTE_IN_SECONDS,
	);
}

/**
 * Takes the lock that casting or playing a scene needs, so two commands cannot do either twice.
 *
 * @since 0.1.0
 *
 * @access private
 *
 * @param int $wait Optional. Seconds to wait for a busy lock. Default 0.
 * @return bool Whether the lock was taken.
 */
function wp_presence_scene_lock( $wait = 0 ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

	$until = microtime( true ) + $wait;
	while ( ! WP_Upgrader::create_lock( 'wp_presence_scene', wp_presence_scene_timings()['abandoned'] ) ) {
		if ( microtime( true ) >= $until ) {
			return false;
		}
		sleep( 1 );
	}

	return true;
}

/**
 * Runs a callback on the running scene while holding its lock, releasing it even when the callback throws.
 *
 * @since 0.1.0
 *
 * @access private
 *
 * @param callable $callback Receives the running scene, read after the lock is taken, or false when none runs.
 * @param int      $wait     Optional. Seconds to wait for a busy lock. Default 0.
 * @return mixed The callback's return value, or false when the lock was busy.
 */
function wp_presence_scene_locked( callable $callback, $wait = 0 ) {
	if ( ! wp_presence_scene_lock( $wait ) ) {
		return false;
	}

	try {
		// Another command may have changed the scene before the lock was free.
		wp_cache_delete( 'wp_presence_scene', 'options' );
		return $callback( get_option( 'wp_presence_scene' ) );
	} finally {
		WP_Upgrader::release_lock( 'wp_presence_scene' );
	}
}

/**
 * Creates a scene's cast and saves it as the running scene.
 *
 * @since 0.1.0
 *
 * @access private
 *
 * @param array $scene A prepared scene.
 * @return array|WP_Error The running scene, or the error that stopped a user being created.
 */
function wp_presence_scene_cast( array $scene ) {
	$number = (int) get_option( 'wp_presence_scene_runs', 0 ) + 1;
	update_option( 'wp_presence_scene_runs', $number, false );

	$run = array(
		'name'    => $scene['name'],
		'title'   => $scene['title'],
		// A copy, so a scene edited or updated mid-run cannot change the steps being played.
		'scene'   => $scene,
		'run'     => $number,
		'started' => time(),
		'expires' => time() + $scene['duration'] + wp_presence_scene_timings()['grace'],
		'cast'    => array(),
		'posts'   => array(),
		'done'    => array(),
		'clients' => array(),
		'beat'    => 0,
		'ticked'  => time(),
		'notes'   => array(),
	);

	foreach ( $scene['cast'] as $i => $role ) {
		$login   = 'actor' . ( $i + 1 ) . 'run' . $number;
		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $login . '@example.com',
				'user_pass'    => wp_generate_password(),
				'display_name' => wp_presence_scene_actor_name( $i ),
				'first_name'   => wp_presence_scene_actor_name( $i ),
				'role'         => $role,
				'meta_input'   => array( '_wp_presence_scene' => $run['expires'] ),
			)
		);

		if ( is_wp_error( $user_id ) ) {
			wp_presence_scene_strike( $run );
			return $user_id;
		}

		$run['cast'][] = $user_id;
	}

	update_option( 'wp_presence_scene', $run, false );

	return $run;
}

/**
 * Casts a scene, unless another is running.
 *
 * @since 0.1.0
 *
 * @access private
 *
 * @param string $name Scene name.
 * @return array|WP_Error|false The running scene, the error that stopped its cast, or false when it could not start.
 */
function wp_presence_scene_start( $name ) {
	$scenes = wp_presence_get_scenes();

	if ( ! isset( $scenes[ $name ] ) ) {
		return false;
	}

	// One scene runs at a time, so a second waits until the first finishes or expires.
	wp_presence_scene_sweep();
	$run = wp_presence_scene_locked(
		function ( $running ) use ( $name, $scenes ) {
			return $running ? false : wp_presence_scene_cast( $scenes[ $name ] );
		}
	);
	if ( ! $run || is_wp_error( $run ) ) {
		return $run;
	}

	// Strikes the scene if the command dies before the last step.
	wp_clear_scheduled_hook( 'wp_presence_scene_sweep' );
	wp_schedule_single_event( $run['expires'], 'wp_presence_scene_sweep' );

	return $run;
}

/**
 * Plays every step that is due, then sends the cast's Heartbeats when they are due.
 *
 * @since 0.1.0
 *
 * @access private
 *
 * @param array    $run The running scene.
 * @param int|null $now Optional. Timestamp to play at. Default the current time.
 * @return array The running scene.
 */
function wp_presence_scene_play( array $run, $now = null ) {
	// Loads core's own Heartbeat filters, such as wp_refresh_post_lock().
	require_once ABSPATH . 'wp-admin/includes/admin.php';

	$now     = null === $now ? time() : (int) $now;
	$elapsed = $now - $run['started'];
	$cast    = array();
	foreach ( $run['cast'] as $user_id ) {
		$cast[] = new WP_Presence_Scene_Actor( $user_id, $run );
	}

	foreach ( $run['scene']['steps'] as $i => $step ) {
		if ( isset( $run['done'][ $i ] ) || $step['at'] > $elapsed ) {
			continue;
		}
		// Saved as failed first, so a fatal error cannot replay the step on the next beat.
		$run['done'][ $i ] = 'fail';
		update_option( 'wp_presence_scene', $run, false );

		$warnings = array();
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Collects the step's warnings for the report.
		set_error_handler(
			function ( $errno, $errstr, $file, $line ) use ( &$warnings ) {
				// Respects @ and error_reporting(), as PHP's own handler would.
				if ( ! ( error_reporting() & $errno ) ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- Only reads the level.
					return false;
				}
				$warnings[] = sprintf( '%s (%s:%d)', $errstr, wp_basename( $file ), $line );
				return true;
			}
		);

		try {
			wp_presence_scene_perform( $step, $cast, $run );
			$run['done'][ $i ] = 'pass';
			wp_presence_scene_note( $run, 'pass', $step['label'] );
		} catch ( Throwable $e ) {
			wp_presence_scene_note( $run, 'fail', $step['label'] . ': ' . $e->getMessage() );
		} finally {
			restore_error_handler();
		}

		if ( $warnings ) {
			$run['done'][ $i ] = 'fail';
		}
		foreach ( $warnings as $warning ) {
			wp_presence_scene_note( $run, 'warning', $warning );
		}
	}

	if ( $now - $run['beat'] >= wp_presence_scene_timings()['beat'] ) {
		$run['beat'] = $now;
		foreach ( $cast as $actor ) {
			try {
				$actor->beat();
			} catch ( RuntimeException $e ) {
				wp_presence_scene_note( $run, 'fail', $e->getMessage() );
			}
		}
	}

	$run['ticked'] = time();
	update_option( 'wp_presence_scene', $run, false );

	return $run;
}

/**
 * Deletes the cast and everything they made, then checks nothing is left behind.
 *
 * @since 0.1.0
 *
 * @access private
 *
 * @param array $run The running scene.
 * @return array The struck scene, with its closing note.
 */
function wp_presence_scene_strike( array $run ) {
	wp_clear_scheduled_hook( 'wp_presence_scene_sweep' );

	// Rooms are named before the posts that name them are deleted.
	$rooms = array_filter( array_merge( array( wp_presence_admin_room() ), array_map( 'wp_presence_post_room', $run['posts'] ) ) );

	foreach ( wp_presence_scene_delete_users( $run['cast'] ) as $user_id ) {
		/* translators: %d: User ID. */
		wp_presence_scene_note( $run, 'fail', sprintf( __( 'Could not delete user %d.', 'presence-scenes' ), $user_id ) );
	}

	foreach ( $rooms as $room ) {
		foreach ( wp_get_presence( $room ) as $entry ) {
			if ( in_array( (int) $entry->user_id, $run['cast'], true ) ) {
				/* translators: 1: User ID, 2: Room. */
				wp_presence_scene_note( $run, 'fail', sprintf( __( 'User %1$d is still present in %2$s.', 'presence-scenes' ), $entry->user_id, $room ) );
			}
		}
	}

	$problems = count( wp_presence_scene_problems( $run['notes'] ) );

	$played = number_format_i18n( count( $run['done'] ) );
	$total  = number_format_i18n( count( $run['scene']['steps'] ) );
	if ( count( $run['done'] ) < count( $run['scene']['steps'] ) ) {
		$summary = $problems
			/* translators: 1: Steps played, 2: Total steps, 3: Number of problems. */
			? sprintf( _n( 'Stopped after %1$s of %2$s steps, with %3$s problem.', 'Stopped after %1$s of %2$s steps, with %3$s problems.', $problems, 'presence-scenes' ), $played, $total, number_format_i18n( $problems ) )
			/* translators: 1: Steps played, 2: Total steps. */
			: sprintf( __( 'Stopped after %1$s of %2$s steps, with no problems.', 'presence-scenes' ), $played, $total );
	} else {
		$summary = $problems
			/* translators: %s: Number of problems. */
			? sprintf( _n( 'Finished with %s problem.', 'Finished with %s problems.', $problems, 'presence-scenes' ), number_format_i18n( $problems ) )
			: __( 'Finished with no problems.', 'presence-scenes' );
	}

	wp_presence_scene_note( $run, $problems ? 'fail' : 'pass', $summary );

	delete_option( 'wp_presence_scene' );

	return $run;
}

/**
 * Strikes an abandoned scene and deletes any cast member past their expiry.
 *
 * @since 0.1.0
 *
 * @access private
 *
 * @param bool $all Optional. Strike everything regardless of expiry. Default false.
 */
function wp_presence_scene_sweep( $all = false ) {
	$timings = wp_presence_scene_timings();
	$due     = function ( $run ) use ( $all, $timings ) {
		// A run nobody is playing lost its command, such as to Ctrl+C.
		return is_array( $run ) && ( $all || $run['expires'] <= time() || $run['ticked'] < time() - $timings['abandoned'] );
	};

	if ( $due( get_option( 'wp_presence_scene' ) ) ) {
		wp_presence_scene_locked(
			function ( $run ) use ( $due ) {
				if ( $due( $run ) ) {
					wp_presence_scene_note( $run, 'info', __( 'Cleaned up before the scene finished.', 'presence-scenes' ) );
					wp_presence_scene_strike( $run );
				}
			},
			$timings['wait']
		);
	}

	wp_presence_scene_delete_users( wp_presence_scene_actors( $all ) );
}

/**
 * Returns the site's actors.
 *
 * @since 0.1.0
 *
 * @access private
 *
 * @param bool $all Optional. Include actors not yet past their expiry. Default false.
 * @return int[] Actor user IDs.
 */
function wp_presence_scene_actors( $all = false ) {
	$query = array(
		'meta_key' => '_wp_presence_scene', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
	);
	if ( ! $all ) {
		$query['meta_value']   = time(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		$query['meta_compare'] = '<=';
		$query['meta_type']    = 'NUMERIC';
	}

	$query['fields'] = array( 'ID', 'user_login' );
	$user_ids        = array();
	foreach ( get_users( $query ) as $user ) {
		if ( preg_match( '/^actor\d+run\d+$/', $user->user_login ) ) {
			$user_ids[] = (int) $user->ID;
		}
	}

	return $user_ids;
}

/**
 * Deletes scene users and everything they wrote.
 *
 * @since 0.1.0
 *
 * @access private
 *
 * @param int[] $user_ids Scene user IDs.
 * @return int[] The users that could not be deleted.
 */
function wp_presence_scene_delete_users( array $user_ids ) {
	if ( ! $user_ids ) {
		return array();
	}

	require_once ABSPATH . 'wp-admin/includes/user.php';
	if ( is_multisite() ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
	}

	// Deleting a user only trashes their posts, so theirs go first.
	$posts = get_posts(
		array(
			'author__in'  => $user_ids,
			'post_type'   => 'any',
			'post_status' => 'any',
			'fields'      => 'ids',
			'numberposts' => -1,
		)
	);
	foreach ( $posts as $post_id ) {
		wp_delete_post( $post_id, true );
	}

	$failed = array();
	foreach ( $user_ids as $user_id ) {
		if ( ! ( is_multisite() ? wpmu_delete_user( $user_id ) : wp_delete_user( $user_id ) ) ) {
			$failed[] = $user_id;
		}
	}

	return $failed;
}

/**
 * Refuses to log in as an actor.
 *
 * @since 0.1.0
 *
 * @access private
 *
 * @param WP_User|WP_Error $user The user logging in.
 * @return WP_User|WP_Error The user, or an error for an actor.
 */
function wp_presence_scene_authenticate( $user ) {
	if ( $user instanceof WP_User && get_user_meta( $user->ID, '_wp_presence_scene', true ) ) {
		return new WP_Error( 'presence_scene_actor', __( 'Scene actors cannot log in.', 'presence-scenes' ) );
	}

	return $user;
}
