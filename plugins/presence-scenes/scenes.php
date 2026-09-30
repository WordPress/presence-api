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
