<?php
/**
 * Scenes: registered scenes create marked users, play their cues on the debugging tab's Heartbeat, then delete them.
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Places an actor can visit, each with its screen ID, title and the capability it needs.
 *
 * @since 0.12.0
 *
 * @access private
 *
 * @return array[] Places keyed by the name a scene uses.
 */
function wp_presence_scene_places() {
	return array(
		'dashboard' => array( 'dashboard', __( 'Dashboard', 'presence-api' ), 'read' ),
		'posts'     => array( 'edit-post', __( 'Posts', 'presence-api' ), 'edit_posts' ),
		'pages'     => array( 'edit-page', __( 'Pages', 'presence-api' ), 'edit_pages' ),
		'media'     => array( 'upload', __( 'Media', 'presence-api' ), 'upload_files' ),
		'comments'  => array( 'edit-comments', __( 'Comments', 'presence-api' ), 'edit_posts' ),
		'profile'   => array( 'profile', __( 'Profile', 'presence-api' ), 'read' ),
	);
}

/**
 * Registers a scene for the debugger to direct.
 *
 * A scene is data only: every cue is an action from a fixed list, acted
 * through WP_Presence_Scene_Actor on posts the scene writes itself.
 *
 * Experimental: this exists only in development checkouts with WP_DEBUG on,
 * never in the plugin's release zip, and may change without notice.
 *
 * @since 0.12.0
 *
 * @param string|array $scene Path to a scene.json file, or the same shape as an array. See schemas/scene.json.
 * @return array|false The registered scene, or false when it was refused.
 */
function wp_register_presence_scene( $scene ) {
	global $wp_presence_scenes;

	if ( ! doing_action( 'wp_presence_scenes_init' ) ) {
		/* translators: %s: Action name. */
		_doing_it_wrong( __FUNCTION__, sprintf( esc_html__( 'Scenes must be registered on the %s action.', 'presence-api' ), '<code>wp_presence_scenes_init</code>' ), '0.12.0' );
		return false;
	}

	$source = is_string( $scene ) ? wp_basename( $scene ) : '';
	if ( is_string( $scene ) ) {
		$scene = '.json' === substr( $scene, -5 ) && is_file( $scene ) && filesize( $scene ) <= 65536
			? wp_json_file_decode( $scene, array( 'associative' => true ) )
			: null;
	}

	$prepared = wp_presence_scene_prepare( $scene );

	if ( is_wp_error( $prepared ) ) {
		_doing_it_wrong( __FUNCTION__, esc_html( ( $source ? $source . ': ' : '' ) . $prepared->get_error_message() ), '0.12.0' );
		return false;
	}

	if ( isset( $wp_presence_scenes[ $prepared['name'] ] ) ) {
		/* translators: %s: Scene name. */
		_doing_it_wrong( __FUNCTION__, esc_html( sprintf( __( 'Scene “%s” is already registered.', 'presence-api' ), $prepared['name'] ) ), '0.12.0' );
		return false;
	}

	$wp_presence_scenes[ $prepared['name'] ] = $prepared;

	return $prepared;
}

/**
 * Actions a cue can take, each with the WP_Presence_Scene_Actor method that plays it, the fields it needs and how its step is narrated.
 *
 * @since 0.12.0
 *
 * @access private
 *
 * @return array[] Actions keyed by name.
 */
function wp_presence_scene_actions() {
	return array(
		'visit'         => array(
			'method' => 'visit',
			'fields' => array( 'place' ),
			'cast'   => true,
			/* translators: 1: Actor, 2: Screen title. */
			'label'  => __( '%1$s arrives on %2$s', 'presence-api' ),
			/* translators: 1: Actor, 2: Screen title. */
			'again'  => __( '%1$s goes to %2$s', 'presence-api' ),
		),
		'write'         => array(
			'method' => 'write',
			'fields' => array( 'title' ),
			'cast'   => false,
			/* translators: 1: Actor, 2: Post title. */
			'label'  => __( '%1$s writes “%2$s”', 'presence-api' ),
		),
		'open'          => array(
			'method' => 'open',
			'fields' => array( 'post' ),
			'cast'   => false,
			/* translators: 1: Actor, 2: Post title. */
			'label'  => __( '%1$s opens “%2$s”', 'presence-api' ),
		),
		'takeOver'      => array(
			'method' => 'take_over',
			'fields' => array( 'post' ),
			'cast'   => false,
			/* translators: 1: Actor, 2: Post title. */
			'label'  => __( '%1$s takes over “%2$s”', 'presence-api' ),
		),
		'type'          => array(
			'method' => 'type',
			'fields' => array( 'post', 'text' ),
			'cast'   => false,
			/* translators: 1: Actor, 2: Post title. */
			'label'  => __( '%1$s edits “%2$s”', 'presence-api' ),
		),
		'close'         => array(
			'method' => 'close',
			'fields' => array( 'post' ),
			'cast'   => false,
			/* translators: 1: Actor, 2: Post title. */
			'label'  => __( '%1$s closes “%2$s”', 'presence-api' ),
		),
		'drop'          => array(
			'method' => 'drop',
			'fields' => array(),
			'cast'   => true,
			/* translators: %s: Actor. */
			'label'  => __( '%s loses connection', 'presence-api' ),
		),
		'leave'         => array(
			'method' => 'leave',
			'fields' => array(),
			'cast'   => true,
			/* translators: %s: Actor. */
			'label'  => __( '%s logs out', 'presence-api' ),
		),
		'checkOnline'   => array(
			'method' => 'check_online',
			'fields' => array(),
			'cast'   => true,
			/* translators: %s: Actor. */
			'label'  => __( '%s is online', 'presence-api' ),
		),
		'checkOffline'  => array(
			'method' => 'check_offline',
			'fields' => array(),
			'cast'   => true,
			/* translators: %s: Actor. */
			'label'  => __( '%s is offline', 'presence-api' ),
		),
		'checkLocked'   => array(
			'method' => 'check_locked',
			'fields' => array( 'post' ),
			'cast'   => false,
			/* translators: 1: Actor, 2: Post title. */
			'label'  => __( '%1$s finds “%2$s” locked', 'presence-api' ),
		),
		'checkUnlocked' => array(
			'method' => 'check_unlocked',
			'fields' => array( 'post' ),
			'cast'   => false,
			/* translators: 1: Actor, 2: Post title. */
			'label'  => __( '%1$s finds “%2$s” free', 'presence-api' ),
		),
	);
}

/**
 * Checks a scene against the allowed actions and narrates each cue.
 *
 * @since 0.12.0
 *
 * @access private
 *
 * @param mixed $scene The decoded scene.
 * @return array|WP_Error The scene ready to direct, or why it cannot be.
 */
function wp_presence_scene_prepare( $scene ) {
	$actions = wp_presence_scene_actions();
	$places  = wp_presence_scene_places();
	$roles   = array( 'subscriber', 'contributor', 'author', 'editor' );
	$invalid = function ( $message, $n = null ) {
		/* translators: 1: Cue number, 2: What is wrong with it. */
		return new WP_Error( 'presence_scene_invalid', null === $n ? $message : sprintf( __( 'Cue %1$d: %2$s', 'presence-api' ), $n + 1, $message ) );
	};

	if ( ! is_array( $scene ) || array_diff( array_keys( $scene ), array( '$schema', 'apiVersion', 'name', 'title', 'cast', 'cues' ) ) ) {
		return $invalid( __( 'A scene has only $schema, apiVersion, name, title, cast and cues.', 'presence-api' ) );
	}

	if ( 1 !== ( $scene['apiVersion'] ?? null ) ) {
		return $invalid( __( 'This version of the plugin reads apiVersion 1 only.', 'presence-api' ) );
	}

	if ( ! is_string( $scene['name'] ?? null ) || ! preg_match( '#^[a-z0-9-]+/[a-z0-9-]+$#', $scene['name'] ) ) {
		return $invalid( __( 'The name must be a namespace and a name, lowercase with dashes, such as my-plugin/review-queue.', 'presence-api' ) );
	}

	$title = wp_presence_scene_text( $scene['title'] ?? null, 60 );
	if ( null === $title ) {
		return $invalid( __( 'The title must be text of up to 60 characters.', 'presence-api' ) );
	}

	$cast = $scene['cast'] ?? null;
	if ( ! is_array( $cast ) || ! wp_is_numeric_array( $cast ) || count( $cast ) < 1 || count( $cast ) > 7 ) {
		return $invalid( __( 'The cast must list one to seven actors.', 'presence-api' ) );
	}
	foreach ( $cast as $part ) {
		if ( ! is_array( $part ) || array_keys( $part ) !== array( 'role' ) || ! in_array( $part['role'], $roles, true ) ) {
			/* translators: %s: Allowed roles. */
			return $invalid( sprintf( __( 'Each actor needs only a role, one of %s.', 'presence-api' ), implode( ', ', $roles ) ) );
		}
	}

	$cues = $scene['cues'] ?? null;
	if ( ! is_array( $cues ) || ! wp_is_numeric_array( $cues ) || count( $cues ) < 1 || count( $cues ) > 30 ) {
		return $invalid( __( 'The cues must list one to thirty actions.', 'presence-api' ) );
	}

	$prepared = array();
	$titles   = array();
	$present  = array_fill( 0, count( $cast ), false );
	$last     = 0;

	foreach ( $cues as $n => $cue ) {
		$action = is_array( $cue ) && is_string( $cue['action'] ?? null ) ? $cue['action'] : '';

		if ( ! isset( $actions[ $action ] ) ) {
			/* translators: %s: Allowed actions. */
			return $invalid( sprintf( __( 'The action must be one of %s.', 'presence-api' ), implode( ', ', array_keys( $actions ) ) ), $n );
		}

		$fields  = $actions[ $action ]['fields'];
		$allowed = array_merge( array( 'at', 'actor', 'action' ), $fields );
		if ( array_diff( array_keys( $cue ), $allowed ) || array_diff( array_diff( $allowed, array( 'text' ) ), array_keys( $cue ) ) ) {
			$message = in_array( 'text', $fields, true )
				/* translators: 1: Action, 2: Its fields. */
				? __( '%1$s takes %2$s, and text is optional.', 'presence-api' )
				/* translators: 1: Action, 2: Its fields. */
				: __( '%1$s takes %2$s.', 'presence-api' );
			return $invalid( sprintf( $message, $action, implode( ', ', $allowed ) ), $n );
		}

		$at = $cue['at'];
		if ( is_string( $at ) && preg_match( '/^(?:(\d{1,3})\+)?ttl(?:\+(\d{1,3}))?$/', $at, $m ) ) {
			$at = (int) ( $m[1] ?? 0 ) + wp_presence_get_timeout() + (int) ( $m[2] ?? 0 );
		}
		if ( ! is_int( $at ) || $at < $last || $at > 900 ) {
			return $invalid( __( 'at must be seconds from 0 to 900, or a TTL offset such as "25+ttl+5", and never earlier than the cue before.', 'presence-api' ), $n );
		}
		$last = $at;

		$actor = $cue['actor'];
		$whole = 'cast' === $actor && $actions[ $action ]['cast'];
		if ( ! $whole && ! ( is_int( $actor ) && $actor >= 1 && $actor <= count( $cast ) ) ) {
			/* translators: %d: Number of actors. */
			return $invalid( sprintf( __( 'actor must be a number from 1 to %d, or "cast" for actions the whole cast can take.', 'presence-api' ), count( $cast ) ), $n );
		}

		$clean  = array(
			'after'  => $at,
			'actor'  => $actor,
			'action' => $action,
		);
		$object = '';

		if ( isset( $cue['place'] ) ) {
			if ( ! is_string( $cue['place'] ) || ! isset( $places[ $cue['place'] ] ) ) {
				/* translators: %s: Allowed places. */
				return $invalid( sprintf( __( 'place must be one of %s.', 'presence-api' ), implode( ', ', array_keys( $places ) ) ), $n );
			}
			$clean['place'] = $cue['place'];
			$object         = $places[ $cue['place'] ][1];
		}

		if ( isset( $cue['post'] ) ) {
			if ( ! is_int( $cue['post'] ) || $cue['post'] < 1 || $cue['post'] > count( $titles ) ) {
				return $invalid( __( 'post must number a post an earlier write cue created, starting at 1.', 'presence-api' ), $n );
			}
			$clean['post'] = $cue['post'];
			$object        = $titles[ $cue['post'] - 1 ];
		}

		foreach ( array( 'title', 'text' ) as $key ) {
			if ( ! isset( $cue[ $key ] ) ) {
				continue;
			}
			$clean[ $key ] = wp_presence_scene_text( $cue[ $key ], 100 );
			if ( null === $clean[ $key ] ) {
				/* translators: %s: Field name. */
				return $invalid( sprintf( __( '%s must be text of up to 100 characters.', 'presence-api' ), $key ), $n );
			}
		}

		if ( 'write' === $action ) {
			$titles[] = $clean['title'];
			$object   = $clean['title'];
		}

		$members = $whole ? array_keys( $present ) : array( $actor - 1 );
		$format  = 'visit' === $action && $present[ $members[0] ] ? $actions[ $action ]['again'] : $actions[ $action ]['label'];

		$clean['label'] = sprintf( $format, $whole ? __( 'The cast', 'presence-api' ) : wp_presence_scene_actor_name( $actor - 1 ), $object );
		$prepared[]     = $clean;

		if ( 0 !== strpos( $action, 'check' ) ) {
			foreach ( $members as $i ) {
				$present[ $i ] = ! in_array( $action, array( 'drop', 'leave' ), true );
			}
		}
	}

	return array(
		'name'     => $scene['name'],
		'label'    => $title,
		'cast'     => $cast,
		'cues'     => $prepared,
		'duration' => $last,
	);
}

/**
 * Sanitizes a scene string, refusing anything that is not short plain text.
 *
 * @since 0.12.0
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
 * Plays one cue through the actors it names.
 *
 * @since 0.12.0
 *
 * @access private
 *
 * @param array                     $cue  A prepared cue.
 * @param WP_Presence_Scene_Actor[] $cast The cast.
 * @param array                     $run  The running scene.
 */
function wp_presence_scene_perform( array $cue, array $cast, array $run ) {
	$action = wp_presence_scene_actions()[ $cue['action'] ];
	$method = $action['method'];
	$args   = array();

	foreach ( $action['fields'] as $field ) {
		$args[] = 'post' === $field ? (int) ( $run['posts'][ $cue['post'] - 1 ] ?? 0 ) : ( $cue[ $field ] ?? null );
	}

	foreach ( 'cast' === $cue['actor'] ? $cast : array( $cast[ $cue['actor'] - 1 ] ) as $actor ) {
		$actor->$method( ...$args );
	}
}

/**
 * Returns every registered scene, firing the registration action on first use.
 *
 * @since 0.12.0
 *
 * @return array Scenes keyed by name.
 */
function wp_get_presence_scenes() {
	global $wp_presence_scenes;

	if ( ! did_action( 'wp_presence_scenes_init' ) ) {
		$wp_presence_scenes = array();

		/**
		 * Fires when the debugger first needs its scenes, for wp_register_presence_scene() calls.
		 *
		 * Experimental, and missing from the plugin's release zip, like the function.
		 *
		 * @since 0.12.0
		 */
		do_action( 'wp_presence_scenes_init' );
	}

	return (array) $wp_presence_scenes;
}

/**
 * Adds a note to the running scene's report, once per distinct problem.
 *
 * @since 0.12.0
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
 * Picks the failures and warnings out of a scene's notes.
 *
 * @since 0.12.0
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
 * Returns how long a scene's cast may exist, leaving time for a closed tab's run to be swept.
 *
 * @since 0.12.0
 *
 * @access private
 *
 * @param array $scene A registered scene.
 * @return int Seconds.
 */
function wp_presence_scene_lifetime( array $scene ) {
	return $scene['duration'] + 10 * MINUTE_IN_SECONDS;
}

/**
 * Moves the sweep and every cast member's expiry to when the scene expires.
 *
 * @since 0.12.0
 *
 * @access private
 *
 * @param array $run The running scene.
 */
function wp_presence_scene_schedule_sweep( array $run ) {
	foreach ( $run['cast'] as $user_id ) {
		update_user_meta( $user_id, '_wp_presence_scene', $run['expires'] );
	}

	wp_clear_scheduled_hook( 'wp_presence_scene_sweep' );
	wp_schedule_single_event( $run['expires'], 'wp_presence_scene_sweep' );
}

/**
 * Takes the lock that casting or playing a scene needs, so two requests cannot do either twice.
 *
 * @since 0.12.0
 *
 * @access private
 *
 * @param int $wait Optional. Seconds to wait for a busy lock. Default 0.
 * @return bool Whether the lock was taken.
 */
function wp_presence_scene_lock( $wait = 0 ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

	$until = microtime( true ) + $wait;
	while ( ! WP_Upgrader::create_lock( 'wp_presence_scene', 30 ) ) {
		if ( microtime( true ) >= $until ) {
			return false;
		}
		usleep( 250000 );
	}

	return true;
}

/**
 * Runs a callback on the running scene while holding its lock, releasing it even when the callback throws.
 *
 * @since 0.12.0
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
		// Another request may have changed the scene before the lock was free.
		wp_cache_delete( 'wp_presence_scene', 'options' );
		return $callback( get_option( 'wp_presence_scene' ) );
	} finally {
		WP_Upgrader::release_lock( 'wp_presence_scene' );
	}
}

/**
 * Creates a scene's cast and saves it as the running scene.
 *
 * @since 0.12.0
 *
 * @access private
 *
 * @param array $scene A registered scene.
 * @return array|false The running scene, or false when a user could not be created.
 */
function wp_presence_scene_cast( array $scene ) {
	$number = (int) get_option( 'wp_presence_scene_runs', 1000 ) + 1;
	update_option( 'wp_presence_scene_runs', $number, false );

	$run = array(
		'name'    => $scene['name'],
		'label'   => $scene['label'],
		// A copy, so a scene edited or updated mid-run cannot change the steps being played.
		'scene'   => $scene,
		'run'     => $number,
		'started' => time(),
		'expires' => time() + wp_presence_scene_lifetime( $scene ),
		'cast'    => array(),
		'posts'   => array(),
		'done'    => array(),
		'beats'   => array(),
		'notes'   => array(),
	);

	foreach ( array_values( $scene['cast'] ) as $i => $part ) {
		$login   = 'actor' . ( $i + 1 ) . 'run' . $number;
		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $login . '@example.com',
				'user_pass'    => wp_generate_password( 24 ),
				'display_name' => wp_presence_scene_actor_name( $i ),
				'first_name'   => wp_presence_scene_actor_name( $i ),
				'role'         => $part['role'],
				'meta_input'   => array( '_wp_presence_scene' => $run['expires'] ),
			)
		);

		if ( is_wp_error( $user_id ) ) {
			wp_presence_scene_note( $run, 'fail', $user_id->get_error_message() );
			wp_presence_scene_strike( $run );
			return false;
		}

		$run['cast'][] = $user_id;
	}

	update_option( 'wp_presence_scene', $run, false );

	return $run;
}

/**
 * Casts a scene and plays its opening cues.
 *
 * @since 0.12.0
 *
 * @access private
 *
 * @param string $name Scene name.
 * @return bool Whether the scene started.
 */
function wp_presence_scene_start( $name ) {
	$scenes = wp_get_presence_scenes();

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
	if ( ! $run ) {
		return false;
	}

	// Strikes the scene if its tab closes before the last cue.
	wp_presence_scene_schedule_sweep( $run );

	wp_presence_scene_direct();

	return true;
}

/**
 * Plays every cue that is due, keeps the cast beating, and strikes a finished scene.
 *
 * @since 0.12.0
 *
 * @access private
 *
 * @return array|null The running scene, or null when none is running.
 */
function wp_presence_scene_direct() {
	$run = get_option( 'wp_presence_scene' );

	if ( ! is_array( $run ) ) {
		return null;
	}

	// Paused, so the cast's rows age like anyone else's.
	if ( ! empty( $run['paused'] ) ) {
		return $run;
	}

	// Two tabs beating at once would otherwise play a cue twice.
	$played = wp_presence_scene_locked(
		function ( $run ) {
			if ( ! is_array( $run ) ) {
				return null;
			}
			return empty( $run['paused'] ) ? wp_presence_scene_play( $run ) : $run;
		}
	);

	return false === $played ? $run : $played;
}

/**
 * Plays every cue that is due and keeps the cast beating, for a caller holding the scene lock.
 *
 * @since 0.12.0
 *
 * @access private
 *
 * @param array $run The running scene.
 * @return array|null The running scene, or null once it is struck.
 */
function wp_presence_scene_play( array $run ) {
	require_once ABSPATH . 'wp-admin/includes/post.php';

	$scene   = $run['scene'];
	$elapsed = time() - $run['started'];
	$cast    = array();
	foreach ( $run['cast'] as $user_id ) {
		$cast[] = new WP_Presence_Scene_Actor( $user_id, $run );
	}

	foreach ( $scene['cues'] as $i => $cue ) {
		if ( isset( $run['done'][ $i ] ) || (int) $cue['after'] > $elapsed ) {
			continue;
		}
		// Saved as failed first, so a fatal error cannot replay the cue on the next beat.
		$run['done'][ $i ] = 'fail';
		update_option( 'wp_presence_scene', $run, false );

		$warnings = array();
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Collects the cue's warnings for the report.
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
			wp_presence_scene_perform( $cue, $cast, $run );
			$run['done'][ $i ] = 'pass';
			wp_presence_scene_note( $run, 'pass', $cue['label'] );
		} catch ( Throwable $e ) {
			wp_presence_scene_note( $run, 'fail', $cue['label'] . ': ' . $e->getMessage() );
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

	foreach ( $cast as $actor ) {
		foreach ( $actor->beat_again() as $problem ) {
			wp_presence_scene_note( $run, $problem[0], $problem[1] );
		}
	}

	if ( count( $run['done'] ) === count( $scene['cues'] ) ) {
		wp_presence_scene_strike( $run );
		return null;
	}

	update_option( 'wp_presence_scene', $run, false );

	return $run;
}

/**
 * Deletes the cast and everything they made, then checks nothing is left behind.
 *
 * @since 0.12.0
 *
 * @access private
 *
 * @param array $run The running scene.
 */
function wp_presence_scene_strike( array $run ) {
	global $wpdb;

	wp_clear_scheduled_hook( 'wp_presence_scene_sweep' );

	foreach ( wp_presence_scene_delete_users( $run['cast'] ) as $user_id ) {
		/* translators: %d: User ID. */
		wp_presence_scene_note( $run, 'fail', sprintf( __( 'Could not delete user %d.', 'presence-api' ), $user_id ) );
	}

	foreach ( $run['cast'] as $user_id ) {
		if ( wp_presence_has_table() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->presence} WHERE user_id = %d", $user_id ) );
			if ( $left ) {
				/* translators: 1: Number of rows, 2: User ID. */
				wp_presence_scene_note( $run, 'fail', sprintf( _n( '%1$s presence row is left for user %2$d.', '%1$s presence rows are left for user %2$d.', $left, 'presence-api' ), number_format_i18n( $left ), $user_id ) );
			}
		}
	}

	$problems = count( wp_presence_scene_problems( $run['notes'] ) );

	$played = number_format_i18n( count( $run['done'] ) );
	$total  = number_format_i18n( count( $run['scene']['cues'] ) );
	if ( count( $run['done'] ) < count( $run['scene']['cues'] ) ) {
		$summary = $problems
			/* translators: 1: Steps played, 2: Total steps, 3: Number of problems. */
			? sprintf( _n( 'Stopped after %1$s of %2$s steps, with %3$s problem.', 'Stopped after %1$s of %2$s steps, with %3$s problems.', $problems, 'presence-api' ), $played, $total, number_format_i18n( $problems ) )
			/* translators: 1: Steps played, 2: Total steps. */
			: sprintf( __( 'Stopped after %1$s of %2$s steps, with no problems.', 'presence-api' ), $played, $total );
	} else {
		$summary = $problems
			/* translators: %s: Number of problems. */
			? sprintf( _n( 'Finished with %s problem.', 'Finished with %s problems.', $problems, 'presence-api' ), number_format_i18n( $problems ) )
			: __( 'Finished with no problems.', 'presence-api' );
	}

	wp_presence_scene_note( $run, $problems ? 'fail' : 'pass', $summary );

	delete_option( 'wp_presence_scene' );
	$reports = (array) get_option( 'wp_presence_scene_reports', array() );
	// The newest report goes last, which is the one the console prints.
	unset( $reports[ $run['name'] ] );
	$reports[ $run['name'] ] = array(
		'run'   => $run['run'],
		'name'  => $run['name'],
		'label' => $run['label'],
		'done'  => $run['done'],
		'notes' => $run['notes'],
	);
	update_option( 'wp_presence_scene_reports', $reports, false );
}

/**
 * Strikes an abandoned scene and deletes any cast member past their expiry.
 *
 * @since 0.12.0
 *
 * @access private
 *
 * @param bool $all Optional. Strike everything regardless of expiry. Default false.
 */
function wp_presence_scene_sweep( $all = false ) {
	$due = function ( $run ) use ( $all ) {
		return is_array( $run ) && ( $all || $run['expires'] <= time() );
	};

	if ( $due( get_option( 'wp_presence_scene' ) ) ) {
		wp_presence_scene_locked(
			function ( $run ) use ( $due ) {
				if ( $due( $run ) ) {
					wp_presence_scene_note( $run, 'info', __( 'Cleaned up before the scene finished.', 'presence-api' ) );
					wp_presence_scene_strike( $run );
				}
			},
			5
		);
	}

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
		if ( preg_match( '/^actor[1-7]run\d+$/', $user->user_login ) ) {
			$user_ids[] = (int) $user->ID;
		}
	}
	wp_presence_scene_delete_users( $user_ids );
}

/**
 * Deletes scene users and everything they wrote.
 *
 * @since 0.12.0
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
			'author__in'       => $user_ids,
			'post_type'        => 'any',
			'post_status'      => 'any',
			'fields'           => 'ids',
			'numberposts'      => -1,
			'suppress_filters' => true,
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
 * Whether the current user may create and delete the users a scene casts.
 *
 * @since 0.12.0
 *
 * @access private
 *
 * @return bool Whether the current user can direct scenes.
 */
function wp_presence_scene_user_can_direct() {
	return current_user_can( 'manage_options' ) && current_user_can( 'create_users' ) && current_user_can( 'delete_users' );
}

/**
 * Refuses to log in as an actor.
 *
 * @since 0.12.0
 *
 * @access private
 *
 * @param WP_User|WP_Error $user The user logging in.
 * @return WP_User|WP_Error The user, or an error for an actor.
 */
function wp_presence_scene_authenticate( $user ) {
	if ( $user instanceof WP_User && get_user_meta( $user->ID, '_wp_presence_scene', true ) ) {
		return new WP_Error( 'presence_scene_actor', __( 'Scene actors cannot log in.', 'presence-api' ) );
	}

	return $user;
}

/**
 * Handles the debugger's start, pause, resume and cut links.
 *
 * @since 0.12.0
 *
 * @access private
 */
function wp_presence_scene_admin_post() {
	if ( ! wp_presence_scene_user_can_direct() ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to direct scenes.', 'presence-api' ), 403 );
	}

	check_admin_referer( 'wp_presence_scene' );

	$do = isset( $_GET['do'] ) ? sanitize_key( $_GET['do'] ) : '';

	if ( 'start' === $do && isset( $_GET['scene'] ) ) {
		wp_presence_scene_start( sanitize_text_field( wp_unslash( $_GET['scene'] ) ) );
	} elseif ( in_array( $do, array( 'cut', 'pause', 'resume' ), true ) ) {
		wp_presence_scene_locked(
			function ( $run ) use ( $do ) {
				if ( is_array( $run ) && 'cut' === $do ) {
					wp_presence_scene_note( $run, 'info', __( 'Stopped.', 'presence-api' ) );
					wp_presence_scene_strike( $run );
				} elseif ( is_array( $run ) && empty( $run['paused'] ) === ( 'pause' === $do ) ) {
					// A pause holds the scene for up to an hour, then the sweep strikes it.
					if ( 'pause' === $do ) {
						$run['paused']   = time();
						$run['expires'] += HOUR_IN_SECONDS;
					} else {
						$paused          = time() - $run['paused'];
						$run['started'] += $paused;
						$run['expires'] += $paused - HOUR_IN_SECONDS;
						unset( $run['paused'] );
					}
					wp_presence_scene_schedule_sweep( $run );
					update_option( 'wp_presence_scene', $run, false );
				}
			},
			5
		);
	}

	wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
	exit;
}

/**
 * Directs the running scene on each beat of the debugging tab, and returns its notes.
 *
 * @since 0.12.0
 *
 * @access private
 *
 * @param array $response Heartbeat response data.
 * @param array $data     Data received from the client.
 * @return array The Heartbeat response.
 */
function wp_presence_scene_heartbeat_received( $response, $data ) {
	if ( empty( $data['presence-scene'] ) || ! wp_presence_scene_user_can_direct() ) {
		return $response;
	}

	$run    = wp_presence_scene_direct();
	$report = $run;
	if ( ! $report ) {
		$reports = (array) get_option( 'wp_presence_scene_reports', array() );
		$report  = $reports ? end( $reports ) : null;
	}

	if ( is_array( $report ) ) {
		$response['presence-scene'] = array(
			'run'     => (int) $report['run'],
			'label'   => $report['label'],
			'running' => null !== $run,
			'notes'   => $report['notes'],
		);

		if ( $run && $run['done'] && ! empty( $data['presence-scene']['follow'] ) ) {
			// Lists rather than editors, since opening a scene post would take its lock.
			$cue = $run['scene']['cues'][ max( array_keys( $run['done'] ) ) ];

			$response['presence-scene']['watch'] = isset( $cue['post'] ) || 'write' === $cue['action'] ? admin_url( 'edit.php' ) : admin_url();
		}
	}

	return $response;
}

/**
 * Names the actor cast in a scene's part, such as "Actor 1".
 *
 * @since 0.12.0
 *
 * @access private
 *
 * @param int $i Zero-based index of the part.
 * @return string
 */
function wp_presence_scene_actor_name( $i ) {
	/* translators: %d: Actor number. */
	return sprintf( __( 'Actor %d', 'presence-api' ), $i + 1 );
}

/**
 * Lists the roles a scene casts, such as "Editor, Author and Subscriber".
 *
 * @since 0.12.0
 *
 * @access private
 *
 * @param array $scene A registered scene.
 * @return string
 */
function wp_presence_scene_casting( array $scene ) {
	$role_names = wp_roles()->role_names;
	$roles      = array();
	foreach ( array_unique( wp_list_pluck( $scene['cast'], 'role' ) ) as $role ) {
		$roles[] = translate_user_role( $role_names[ $role ] ?? $role );
	}

	return wp_sprintf( '%l', $roles );
}

/**
 * Adds a Scenes section to the debugger menu, listing every step a scene will take before it can run.
 *
 * @since 0.12.0
 *
 * @access private
 *
 * @param WP_Admin_Bar $wp_admin_bar The admin bar instance.
 */
function wp_presence_scene_admin_bar_nodes( $wp_admin_bar ) {
	$scenes = wp_presence_scene_user_can_direct() ? wp_get_presence_scenes() : array();

	if ( ! $scenes ) {
		return;
	}

	$run     = get_option( 'wp_presence_scene' );
	$reports = (array) get_option( 'wp_presence_scene_reports', array() );
	$open    = get_user_setting( 'presence_scene', '' );
	$marks   = array(
		'pending' => __( 'Not played yet:', 'presence-api' ),
		'current' => __( 'Playing:', 'presence-api' ),
		'pass'    => __( 'Passed:', 'presence-api' ),
		'fail'    => __( 'Failed:', 'presence-api' ),
	);

	$wp_admin_bar->add_group(
		array(
			'parent' => 'presence-debug',
			'id'     => 'presence-debug-scenes',
			'meta'   => array( 'class' => 'ab-sub-secondary' . ( get_user_setting( 'presence_scenes' ) ? ' is-shown' : '' ) ),
		)
	);
	$wp_admin_bar->add_node(
		array(
			'parent' => 'presence-debug-scenes',
			'id'     => 'presence-debug-scenes-heading',
			'title'  => '<span class="presence-debug-scenes-mark" aria-hidden="true"></span>' . esc_html__( 'Scenes', 'presence-api' ),
			'href'   => '#',
			'meta'   => array(
				'class' => 'presence-debug-scenes-heading',
				'html'  => ( is_array( $run ) ? '<a class="presence-debug-scene-button is-follow" href="#" role="button" aria-pressed="false" title="' . esc_attr__( 'Follow along', 'presence-api' ) . '"><span class="screen-reader-text">' . esc_html__( 'Follow along', 'presence-api' ) . '</span></a>' : '<span class="presence-debug-scene-button" aria-hidden="true"></span>' ) . '<span class="presence-debug-scene-icon" aria-hidden="true"></span>',
			),
		)
	);

	foreach ( $scenes as $name => $scene ) {
		// Core strips dashes from user settings, and names could collide once slashes go.
		$slug     = md5( $name );
		$running  = is_array( $run ) && $run['name'] === $name;
		$paused   = $running && ! empty( $run['paused'] );
		$scene    = $running ? $run['scene'] : $scene;
		$result   = $running ? $run : ( $reports[ $name ] ?? null );
		$shown    = $slug === $open;
		$value    = '';
		$problems = array();

		if ( $running ) {
			/* translators: 1: Steps played, 2: Total steps. */
			$value = ( $paused ? '<span class="screen-reader-text">' . esc_html__( 'Paused:', 'presence-api' ) . ' </span>' : '<span class="presence-debug-scene-busy" aria-hidden="true"></span>' ) . esc_html( sprintf( __( '%1$s / %2$s', 'presence-api' ), number_format_i18n( count( $run['done'] ) ), number_format_i18n( count( $scene['cues'] ) ) ) );
		} elseif ( $result ) {
			// The last note is the summary, which only counts the others.
			$problems = wp_presence_scene_problems( array_slice( $result['notes'], 0, -1 ) );
			if ( $problems ) {
				/* translators: %s: Number of problems. */
				$value = '<span class="presence-debug-scene-result" aria-hidden="true"></span>' . esc_html( number_format_i18n( count( $problems ) ) ) . '<span class="screen-reader-text">' . esc_html( sprintf( _n( '%s problem', '%s problems', count( $problems ), 'presence-api' ), number_format_i18n( count( $problems ) ) ) ) . '</span>';
			}
		}

		/* translators: %s: Scene label. */
		$plan = sprintf( __( 'Run “%s”?', 'presence-api' ), $scene['label'] ) . "\n\n"
			/* translators: 1: Number of users, 2: Their roles. */
			. sprintf( _n( 'Creates %1$s user (%2$s), then deletes the user and their posts when the scene ends.', 'Creates %1$s users (%2$s), then deletes them and their posts when the scene ends.', count( $scene['cast'] ), 'presence-api' ), number_format_i18n( count( $scene['cast'] ) ), wp_presence_scene_casting( $scene ) ) . ' '
			. __( 'Plugins that act on new users or posts act on these too.', 'presence-api' );

		$url     = function ( $args ) {
			return esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'presence_scene' ) + $args, admin_url( 'admin-post.php' ) ), 'wp_presence_scene' ) );
		};
		$buttons = '';
		if ( $running ) {
			$buttons .= $paused
				? '<a class="presence-debug-scene-button is-resume" href="' . $url( array( 'do' => 'resume' ) ) . '"><span class="screen-reader-text">' . esc_html__( 'Resume', 'presence-api' ) . '</span></a>'
				: '<a class="presence-debug-scene-button is-pause" href="' . $url( array( 'do' => 'pause' ) ) . '"><span class="screen-reader-text">' . esc_html__( 'Pause', 'presence-api' ) . '</span></a>';
			/* translators: %s: Scene label. */
			$buttons .= '<a class="presence-debug-scene-button is-stop" href="' . $url( array( 'do' => 'cut' ) ) . '"><span class="screen-reader-text">' . esc_html( sprintf( __( 'Stop “%s”', 'presence-api' ), $scene['label'] ) ) . '</span></a>';
		} elseif ( ! is_array( $run ) ) {
			$buttons .= '<a class="presence-debug-scene-button is-start" href="' . $url(
				array(
					'do'    => 'start',
					'scene' => $name,
				)
			) . '"><span class="screen-reader-text">' . esc_html( sprintf( /* translators: %s: Scene label. */ __( 'Run “%s”', 'presence-api' ), $scene['label'] ) ) . '</span><span class="presence-debug-scene-plan" hidden>' . esc_html( $plan ) . '</span></a>';
		}

		$wp_admin_bar->add_node(
			array(
				'parent' => 'presence-debug-scenes',
				'id'     => 'presence-debug-scene-' . $slug,
				'title'  => '<span class="presence-debug-scene-icon" aria-hidden="true"></span><span>' . esc_html( $scene['label'] ) . '</span>',
				'href'   => '#',
				'meta'   => array(
					'class' => 'presence-debug-scene' . ( $shown ? ' is-open' : '' ),
					// Controls take the status's place on hover, so Run or Stop always sits at the edge.
					'html'  => '<span class="presence-debug-scene-controls">' . $buttons . '</span><span class="presence-debug-value">' . $value . '</span>',
				),
			)
		);

		$hidden = 'presence-debug-for-' . $slug . ( $shown ? '' : ' is-hidden' );

		foreach ( $scene['cues'] as $i => $cue ) {
			$state = isset( $result['done'][ $i ] ) ? ( 'fail' === $result['done'][ $i ] ? 'fail' : 'pass' ) : ( $running && ! $paused && count( $run['done'] ) === $i ? 'current' : 'pending' );
			$wp_admin_bar->add_node(
				array(
					'parent' => 'presence-debug-scenes',
					'id'     => 'presence-debug-scene-' . $slug . '-step-' . $i,
					'title'  => '<span class="presence-debug-step-number" aria-hidden="true">' . esc_html( number_format_i18n( $i + 1 ) ) . '</span><span><span class="screen-reader-text">' . esc_html( $marks[ $state ] ) . ' </span>' . esc_html( $cue['label'] ) . '</span><span class="presence-debug-step-mark" aria-hidden="true"></span>',
					'meta'   => array( 'class' => 'presence-debug-step is-' . $state . ' ' . $hidden ),
				)
			);
		}

		foreach ( $problems as $i => $note ) {
			$wp_admin_bar->add_node(
				array(
					'parent' => 'presence-debug-scenes',
					'id'     => 'presence-debug-scene-' . $slug . '-note-' . $i,
					'title'  => '<span>' . esc_html( $note['message'] ) . '</span><span class="presence-debug-value" data-presence-debug="note" data-presence-debug-seconds="' . esc_attr( $note['t'] ) . '"></span>',
					'meta'   => array( 'class' => 'presence-debug-note ' . $hidden ),
				)
			);
		}
	}
}

/**
 * Adds the scene styles, controls and console report to the debugger's assets.
 *
 * @since 0.12.0
 *
 * @access private
 */
function wp_presence_scene_assets() {
	if ( ! wp_script_is( 'presence-debugger-admin-bar' ) ) {
		return;
	}

	wp_add_inline_style(
		'presence-debugger-admin-bar',
		'#wpadminbar #wp-admin-bar-presence-debug > .ab-sub-wrapper { color-scheme: dark; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes { padding-block: 6px; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .is-hidden { display: none; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-scenes-heading > .ab-item { display: flex; align-items: center; font-weight: 600; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes:not(.is-shown) > :not(.presence-debug-scenes-heading) { display: none; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes :is(.presence-debug-scene-icon, .presence-debug-scenes-mark, .presence-debug-step-mark)::before { display: block; width: 16px; margin-inline-end: 8px; font: 16px/1 dashicons; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-scene-icon::before { content: "\\f345"; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-scene.is-open .presence-debug-scene-icon::before, #wpadminbar #wp-admin-bar-presence-debug-scenes.is-shown .presence-debug-scenes-heading .presence-debug-scene-icon::before { content: "\\f347"; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes :is(.presence-debug-scene, .presence-debug-scenes-heading) { display: flex; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-scenes-heading > .ab-item { flex: 1; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-scenes-heading > .presence-debug-scene-icon { display: flex; align-items: center; padding-inline: 6px 10px; cursor: pointer; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-scenes-heading .presence-debug-scene-icon::before { margin: 0; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-scenes-mark::before { content: "\\f204"; }
		#wpadminbar #wp-admin-bar-presence-debug.is-playing > .ab-item::after { content: "\\f522"; font: 16px/1 dashicons; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-scene > .ab-item { flex: 1; min-width: 0; padding-inline-start: calc(10px + var(--presence-debug-indent)); }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-scene > .presence-debug-value { display: flex; flex: none; align-items: center; justify-content: flex-end; min-width: 4.5em; padding-inline: 4px 10px; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-scene.is-open .presence-debug-scene-busy { display: none; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-scene > .ab-item:not(:last-child) { padding-inline-end: 4px; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-scene-button { display: flex; flex: none; align-items: center; justify-content: center; width: 28px; padding: 0; color: inherit; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-scene { position: relative; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-scene-controls { display: flex; position: absolute; inset-block: 0; inset-inline-end: 0; padding-inline-end: 4px; opacity: 0; pointer-events: none; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-scene:is(:hover, :focus-within, :has(.is-resume)) .presence-debug-scene-controls { opacity: 1; pointer-events: auto; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-scene:is(:hover, :focus-within, :has(.is-resume)) > .presence-debug-value { visibility: hidden; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-scene-button:is(:hover, :focus, [aria-pressed="true"]):not([aria-pressed="false"]) { color: var(--wp-admin-theme-color, #72aee6); }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-scene-button::before { font: 16px/1 dashicons; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-scene-button:is(.is-start, .is-resume)::before { content: "\\f522"; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-scene-button.is-pause::before { content: "\\f523"; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-scene-button.is-follow::before { content: "\\f530"; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-scene-button.is-follow[aria-pressed="true"]::before { content: "\\f177"; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-scene-button.is-stop::before { content: ""; width: 10px; height: 10px; background: currentColor; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-scene-busy::before { content: "\\f463"; display: inline-block; margin-inline-end: 6px; font: 16px/1 dashicons; vertical-align: text-bottom; animation: presence-debug-spin 2s infinite linear; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-scene-result::before { content: "\\f534"; display: inline-block; margin-inline-end: 4px; font: 16px/1 dashicons; vertical-align: text-bottom; }
		@keyframes presence-debug-spin { to { transform: rotate(360deg); } }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .is-current .presence-debug-step-mark::before { content: "\\f463"; animation: presence-debug-spin 2s infinite linear; }
		@media (prefers-reduced-motion: reduce) { #wpadminbar #wp-admin-bar-presence-debug-scenes :is(.presence-debug-scene-busy, .is-current .presence-debug-step-mark)::before { animation: none; } }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-step > .ab-item { min-height: 22px; padding-inline-start: calc(10px + 2 * var(--presence-debug-indent)); }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-step-number { min-width: 2ch; margin-inline-end: 6px; text-align: end; font-variant-numeric: tabular-nums; opacity: 0.75; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-step.is-current span { font-weight: 600; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-step-mark { margin-inline-start: auto; padding-inline-start: 16px; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-step-mark::before { content: ""; margin-inline-end: 0; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-step > .ab-item, #wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-step > .ab-item * { white-space: nowrap; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .is-pass .presence-debug-step-mark::before { content: "\\f147"; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .is-fail .presence-debug-step-mark::before { content: "\\f158"; }
		#wpadminbar #wp-admin-bar-presence-debug-scenes .presence-debug-note > .ab-item { max-width: 420px; padding-inline-start: calc(10px + 3 * var(--presence-debug-indent)); white-space: normal; }'
	);

	wp_register_script( 'presence-debugger-scenes', false, array( 'presence-debugger-admin-bar', 'utils' ), WP_PRESENCE_VERSION, true );
	wp_enqueue_script( 'presence-debugger-scenes' );
	wp_add_inline_script(
		'presence-debugger-scenes',
		<<<'JS'
( function ( $ ) {
	if ( ! document.getElementById( "wp-admin-bar-presence-debug-scenes" ) || ! window.wp || ! wp.heartbeat ) {
		return;
	}
	const marks = { pass: "✓", fail: "✗", warning: "!", info: "•" };
	const methods = { fail: "error", warning: "warn" };
	const prefix = "wp-admin-bar-presence-debug-scene-";

	// Follow and the fast beat belong to one tab, so other tabs neither move nor beat faster.
	function stored( key ) {
		try {
			return "1" === window.sessionStorage.getItem( key );
		} catch ( e ) {
			return false;
		}
	}
	function store( key, on ) {
		try {
			window.sessionStorage.setItem( key, on ? "1" : "" );
		} catch ( e ) {}
	}

	// Core cannot print these attributes on a menu item, and live updates replace the markup.
	function sync() {
		const group = document.getElementById( "wp-admin-bar-presence-debug-scenes" );
		if ( ! group ) {
			return;
		}
		group.querySelector( ".presence-debug-scenes-heading > .ab-item" ).setAttribute( "aria-expanded", group.classList.contains( "is-shown" ) );
		group.querySelectorAll( ".presence-debug-scene > .ab-item" ).forEach( function ( item ) {
			item.setAttribute( "aria-expanded", item.parentNode.classList.contains( "is-open" ) );
		} );
		const follow = group.querySelector( ".is-follow" );
		if ( follow ) {
			follow.setAttribute( "aria-pressed", stored( "presence-scene-follow" ) );
		}
	}
	sync();
	new MutationObserver( function ( records ) {
		if ( records.some( function ( record ) {
			return Array.prototype.some.call( record.addedNodes, function ( added ) {
				return 1 === added.nodeType;
			} );
		} ) ) {
			sync();
		}
	} ).observe( document.getElementById( "wp-admin-bar-presence-debug" ), { childList: true, subtree: true } );

	$( document ).on( "keydown", "#wp-admin-bar-presence-debug-scenes .presence-debug-scene > .ab-item, #wp-admin-bar-presence-debug-scenes .presence-debug-scenes-heading > .ab-item, #wp-admin-bar-presence-debug-scenes .is-follow", function ( event ) {
		if ( " " === event.key ) {
			event.preventDefault();
			this.click();
		}
	} );

	// One scene opens at a time, and a user setting keeps it open through the next beat's swap.
	$( document ).on( "click", "#wp-admin-bar-presence-debug-scenes .presence-debug-scene > .ab-item", function ( event ) {
		event.preventDefault();
		const scene = this.parentNode;
		const slug = scene.id.slice( prefix.length );
		const open = ! scene.classList.contains( "is-open" );
		if ( open ) {
			window.setUserSetting( "presence_scene", slug );
		} else {
			window.deleteUserSetting( "presence_scene" );
		}
		scene.parentNode.querySelectorAll( ".presence-debug-scene" ).forEach( function ( other ) {
			const otherSlug = other.id.slice( prefix.length );
			const shown = open && other === scene;
			other.classList.toggle( "is-open", shown );
			scene.parentNode.querySelectorAll( ".presence-debug-for-" + otherSlug ).forEach( function ( row ) {
				row.classList.toggle( "is-hidden", ! shown );
			} );
		} );
		sync();
		// Live updates skip a focused menu, so a click must not leave focus behind.
		if ( event.detail ) {
			this.blur();
		}
	} );

	$( document ).on( "click", "#wp-admin-bar-presence-debug-scenes .presence-debug-scenes-heading > :not(.is-follow)", function ( event ) {
		event.preventDefault();
		const shown = document.getElementById( "wp-admin-bar-presence-debug-scenes" ).classList.toggle( "is-shown" );
		window.setUserSetting( "presence_scenes", shown ? 1 : 0 );
		sync();
		if ( event.detail ) {
			this.blur();
		}
	} );

	$( document ).on( "click", "#wp-admin-bar-presence-debug-scenes .presence-debug-scene-button.is-start", function ( event ) {
		if ( ! window.confirm( this.querySelector( ".presence-debug-scene-plan" ).textContent ) ) {
			event.preventDefault();
			return;
		}
		window.setUserSetting( "presence_scene", this.closest( ".presence-debug-scene" ).id.slice( prefix.length ) );
		store( "presence-scene-directing", true );
	} );

	$( document ).on( "click", "#wp-admin-bar-presence-debug-scenes .presence-debug-scene-button.is-resume", function () {
		store( "presence-scene-directing", true );
	} );

	$( document ).on( "click", "#wp-admin-bar-presence-debug-scenes .presence-debug-scene-button.is-follow", function ( event ) {
		event.preventDefault();
		store( "presence-scene-follow", ! stored( "presence-scene-follow" ) );
		sync();
		if ( event.detail ) {
			this.blur();
		}
		wp.heartbeat.connectNow();
	} );

	$( document ).on( "heartbeat-send", function ( event, data ) {
		data[ "presence-scene" ] = { follow: stored( "presence-scene-follow" ) ? 1 : 0 };
	} );

	// Printed once per tab, so a reload does not repeat the report.
	$( document ).on( "heartbeat-tick", function ( event, data ) {
		const scene = data[ "presence-scene" ];
		document.getElementById( "wp-admin-bar-presence-debug" ).classList.toggle( "is-playing", !! ( scene && scene.running ) );
		if ( ! scene || ! scene.running ) {
			store( "presence-scene-directing", false );
		}
		if ( ! scene ) {
			return;
		}
		let printed = 0;
		try {
			const seen = ( window.sessionStorage.getItem( "presence-scene-printed" ) || "" ).split( ":" );
			printed = +seen[ 0 ] === scene.run ? +seen[ 1 ] : 0;
		} catch ( e ) {}
		scene.notes.slice( printed ).forEach( function ( note ) {
			console[ methods[ note.level ] || "log" ]( scene.label + " " + marks[ note.level ] + " " + note.t + "s " + note.message );
		} );
		try {
			window.sessionStorage.setItem( "presence-scene-printed", scene.run + ":" + scene.notes.length );
		} catch ( e ) {}
		if ( scene.watch && new URL( scene.watch ).pathname !== window.location.pathname ) {
			window.location.assign( scene.watch );
		}
		// Cues play on this tab's beats, which core slows to two minutes while the window is out of focus.
		if ( scene.running && stored( "presence-scene-directing" ) ) {
			window.setTimeout( function () {
				wp.heartbeat.interval( "fast" );
				if ( ! document.hasFocus() ) {
					window.setTimeout( wp.heartbeat.connectNow, 5000 );
				}
			} );
		}
	} );

	if ( document.querySelector( "#wp-admin-bar-presence-debug-scenes .presence-debug-scene-busy" ) ) {
		document.getElementById( "wp-admin-bar-presence-debug" ).classList.add( "is-playing" );
		if ( stored( "presence-scene-directing" ) ) {
			wp.heartbeat.interval( "fast" );
			wp.heartbeat.connectNow();
		}
	}
} )( jQuery );
JS
	);
}

/**
 * Registers the built-in scenes.
 *
 * @since 0.12.0
 *
 * @access private
 */
function wp_presence_register_default_scenes() {
	foreach ( (array) glob( dirname( __DIR__ ) . '/scenes/*.json' ) as $file ) {
		wp_register_presence_scene( $file );
	}
}

/**
 * Strikes every scene and cast member, for plugin deactivation.
 *
 * @since 0.12.0
 *
 * @access private
 */
function wp_presence_scene_sweep_all() {
	wp_presence_scene_sweep( true );
}
