<?php
/**
 * Scene actor: one cast member of a scene.
 *
 * @package Presence_Scenes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One cast member, acting as their own user through Heartbeat and core APIs.
 *
 * @since 0.1.0
 *
 * @access private
 */
final class WP_Presence_Scene_Actor {

	/**
	 * User ID.
	 *
	 * @var int
	 */
	public $ID;

	/**
	 * Display name.
	 *
	 * @var string
	 */
	public $name;

	/**
	 * The running scene, shared by the whole cast.
	 *
	 * @var array
	 */
	private $run;

	/**
	 * Constructor.
	 *
	 * @param int   $user_id User ID.
	 * @param array $run     The running scene.
	 */
	public function __construct( $user_id, array &$run ) {
		$this->ID   = (int) $user_id;
		$this->name = self::name_of( $user_id );
		$this->run  = &$run;
	}

	/**
	 * Crosses to another admin screen, leaving any editor they had open.
	 *
	 * @param string $place A place from wp_presence_scene_places().
	 *
	 * @throws RuntimeException When their role cannot open that screen.
	 */
	public function visit( $place ) {
		$where = wp_presence_scene_places()[ $place ];

		if ( ! user_can( $this->ID, $where['cap'] ) ) {
			/* translators: %s: Screen title. */
			throw new RuntimeException( sprintf( __( 'Their role cannot open %s.', 'presence-scenes' ), $where['title'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Printed as text.
		}

		$this->leave_editor();
		$this->be_on( $where['screen'], $where['title'] );
	}

	/**
	 * Writes a draft of their own and opens it in the editor.
	 *
	 * @param string $title Post title.
	 *
	 * @throws RuntimeException When the post cannot be created.
	 */
	public function write( $title ) {
		$post_id = $this->act(
			function () use ( $title ) {
				return wp_insert_post(
					wp_slash(
						array(
							'post_title'   => $title,
							'post_content' => self::paragraph( $title ),
							'post_status'  => 'draft',
							'post_author'  => $this->ID,
						)
					),
					true
				);
			}
		);

		if ( is_wp_error( $post_id ) ) {
			throw new RuntimeException( $post_id->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Printed as text.
		}

		$this->run['posts'][] = (int) $post_id;

		$this->open( (int) $post_id );
	}

	/**
	 * Opens a scene post in the editor, taking the lock when nobody holds it.
	 *
	 * @param int $post_id Scene post ID.
	 */
	public function open( $post_id ) {
		$post = $this->scene_post( $post_id );

		$this->edit( $post, ! $this->sees_lock( $post->ID ) );
	}

	/**
	 * Takes over a scene post someone else has locked, as the editor's Take over button does.
	 *
	 * @param int $post_id Scene post ID.
	 */
	public function take_over( $post_id ) {
		$this->edit( $this->scene_post( $post_id ), true );
	}

	/**
	 * Adds a paragraph to a scene post and saves it.
	 *
	 * @param int    $post_id Scene post ID.
	 * @param string $text    Paragraph text.
	 *
	 * @throws RuntimeException When someone else holds the lock or the update fails.
	 */
	public function type( $post_id, $text ) {
		$post   = $this->scene_post( $post_id );
		$holder = $this->sees_lock( $post->ID );
		if ( $holder ) {
			/* translators: %s: Who holds the lock. */
			throw new RuntimeException( sprintf( __( '%s holds the lock.', 'presence-scenes' ), self::name_of( $holder ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Printed as text.
		}

		$result = $this->act(
			function () use ( $post, $text ) {
				return wp_update_post(
					wp_slash(
						array(
							'ID'           => $post->ID,
							'post_content' => $post->post_content . "\n\n" . self::paragraph( $text ),
						)
					),
					true
				);
			}
		);

		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( $result->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Printed as text.
		}
	}

	/**
	 * Leaves the editor for the post type's list, releasing their lock.
	 *
	 * @param int $post_id Scene post ID.
	 */
	public function close( $post_id ) {
		$post = $this->scene_post( $post_id );

		$this->leave_editor();
		$this->be_on( 'edit-' . $post->post_type, get_post_type_object( $post->post_type )->labels->name );
	}

	/**
	 * Stops beating without saying goodbye, like a closed laptop.
	 */
	public function drop() {
		unset( $this->run['clients'][ $this->ID ] );
	}

	/**
	 * Logs out.
	 */
	public function leave() {
		$this->drop();
		wp_remove_user_presence( $this->ID );
	}

	/**
	 * Fails unless the online list shows them.
	 *
	 * @throws UnexpectedValueException When it does not.
	 */
	public function check_online() {
		if ( ! $this->in_room( wp_presence_admin_room() ) ) {
			throw new UnexpectedValueException( __( 'Not in the online list.', 'presence-scenes' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Printed as text.
		}
	}

	/**
	 * Fails while the online list still shows them.
	 *
	 * @throws UnexpectedValueException When it does.
	 */
	public function check_offline() {
		if ( $this->in_room( wp_presence_admin_room() ) ) {
			throw new UnexpectedValueException( __( 'Still in the online list.', 'presence-scenes' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Printed as text.
		}
	}

	/**
	 * Fails unless someone else holds a scene post's lock.
	 *
	 * @param int $post_id Scene post ID.
	 *
	 * @throws UnexpectedValueException When nobody else holds it.
	 */
	public function check_locked( $post_id ) {
		if ( ! $this->sees_lock( $this->scene_post( $post_id )->ID ) ) {
			throw new UnexpectedValueException( __( 'Nobody else holds it.', 'presence-scenes' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Printed as text.
		}
	}

	/**
	 * Fails while someone else holds a scene post's lock.
	 *
	 * @param int $post_id Scene post ID.
	 *
	 * @throws UnexpectedValueException When someone else holds it.
	 */
	public function check_unlocked( $post_id ) {
		$holder = $this->sees_lock( $this->scene_post( $post_id )->ID );
		if ( $holder ) {
			/* translators: %s: Who holds the lock. */
			throw new UnexpectedValueException( sprintf( __( '%s holds it.', 'presence-scenes' ), self::name_of( $holder ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Printed as text.
		}
	}

	/**
	 * Sends the Heartbeat their open screen would send, through the same filters a browser reaches.
	 *
	 * @throws RuntimeException When the online list or the editor does not show them afterwards.
	 */
	public function beat() {
		$client = $this->run['clients'][ $this->ID ] ?? null;
		if ( ! $client ) {
			return;
		}

		$data = array(
			'presence-ping' => array(
				'screen' => $client['screen'],
				'title'  => $client['title'],
			),
		);
		if ( $client['post'] ) {
			$data['presence-editor-ping'] = array( 'post_id' => $client['post'] );
			if ( $client['lock'] ) {
				$data['wp-refresh-post-lock'] = array( 'post_id' => $client['post'] );
			}
		}

		$response = $this->act(
			function () use ( $data, $client ) {
				/** This filter is documented in wp-admin/includes/ajax-actions.php */
				return apply_filters( 'heartbeat_received', array(), $data, $client['screen'] );
			}
		);

		if ( isset( $response['wp-refresh-post-lock']['lock_error'] ) ) {
			$this->run['clients'][ $this->ID ]['lock'] = false;
			/* translators: 1: Actor name, 2: Post ID, 3: The user who took it over. */
			wp_presence_scene_note( $this->run, 'info', sprintf( __( '%1$s lost the lock on post %2$d to %3$s.', 'presence-scenes' ), $this->name, $client['post'], $response['wp-refresh-post-lock']['lock_error']['name'] ) );
		}

		if ( ! $this->in_room( wp_presence_admin_room() ) ) {
			/* translators: %s: Actor name. */
			throw new RuntimeException( sprintf( __( 'Heartbeat did not put %s in the online list.', 'presence-scenes' ), $this->name ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Printed as text.
		}
		if ( $client['post'] && ! $this->in_room( wp_presence_post_room( $client['post'] ), 'editor-' . $this->ID ) ) {
			/* translators: 1: Actor name, 2: Post ID. */
			throw new RuntimeException( sprintf( __( 'Heartbeat did not put %1$s in the editor for post %2$d.', 'presence-scenes' ), $this->name, $client['post'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Printed as text.
		}
	}

	/**
	 * Opens a post in the editor, taking its lock first when asked.
	 *
	 * @param WP_Post $post A scene post.
	 * @param bool    $lock Whether to take its lock.
	 */
	private function edit( WP_Post $post, $lock ) {
		if ( (int) ( $this->run['clients'][ $this->ID ]['post'] ?? 0 ) !== $post->ID ) {
			$this->leave_editor();
		}

		if ( $lock ) {
			$this->lock( $post->ID );
		}

		$this->be_on( $post->post_type, get_post_type_object( $post->post_type )->labels->edit_item, $post->ID, $lock );
	}

	/**
	 * Moves to a screen and beats at once, as a page load would.
	 *
	 * @param string $screen  Screen ID, or the post type on the post editor.
	 * @param string $title   Screen title.
	 * @param int    $post_id Optional. The scene post open in the editor.
	 * @param bool   $lock    Optional. Whether they hold its lock.
	 */
	private function be_on( $screen, $title, $post_id = 0, $lock = false ) {
		$this->run['clients'][ $this->ID ] = array(
			'screen' => $screen,
			'title'  => $title,
			'post'   => (int) $post_id,
			'lock'   => (bool) $lock,
		);

		$this->beat();
	}

	/**
	 * Closes the editor they have open, releasing their lock.
	 */
	private function leave_editor() {
		$post_id = (int) ( $this->run['clients'][ $this->ID ]['post'] ?? 0 );
		if ( ! $post_id ) {
			return;
		}

		$this->run['clients'][ $this->ID ]['post'] = 0;
		wp_remove_presence( wp_presence_post_room( $post_id ), 'editor-' . $this->ID );

		$lock = explode( ':', (string) get_post_meta( $post_id, '_edit_lock', true ) );
		if ( isset( $lock[1] ) && (int) $lock[1] === $this->ID ) {
			delete_post_meta( $post_id, '_edit_lock' );
		}
	}

	/**
	 * Whether a room shows them, optionally as one client.
	 *
	 * @param string $room      Room.
	 * @param string $client_id Optional. The client that must show them. Default any.
	 * @return bool
	 */
	private function in_room( $room, $client_id = '' ) {
		foreach ( wp_get_presence( $room ) as $entry ) {
			if ( (int) $entry->user_id === $this->ID && ( '' === $client_id || $client_id === $entry->client_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Who they see holding a post's lock.
	 *
	 * @param int $post_id Post ID.
	 * @return int|false The other user's ID, or false when nobody else holds it.
	 */
	private function sees_lock( $post_id ) {
		return $this->act(
			function () use ( $post_id ) {
				return wp_check_post_lock( $post_id );
			}
		);
	}

	/**
	 * Takes a post's lock as this actor.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @throws RuntimeException When core refuses the lock.
	 */
	private function lock( $post_id ) {
		$lock = $this->act(
			function () use ( $post_id ) {
				return wp_set_post_lock( $post_id );
			}
		);

		if ( ! $lock ) {
			/* translators: %d: Post ID. */
			throw new RuntimeException( sprintf( __( 'wp_set_post_lock() refused post %d.', 'presence-scenes' ), $post_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Printed as text.
		}
	}

	/**
	 * Returns a post the scene created, refusing anything else.
	 *
	 * @param int $post_id Post ID.
	 * @return WP_Post The post.
	 *
	 * @throws RuntimeException When the post is not the scene's.
	 */
	private function scene_post( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post || ! in_array( (int) $post_id, $this->run['posts'], true ) ) {
			/* translators: %d: Post ID. */
			throw new RuntimeException( sprintf( __( 'Post %d is not part of the scene.', 'presence-scenes' ), $post_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Printed as text.
		}

		if ( ! user_can( $this->ID, 'edit_post', $post->ID ) ) {
			/* translators: %s: Post title. */
			throw new RuntimeException( sprintf( __( 'Their role cannot edit “%s”.', 'presence-scenes' ), $post->post_title ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Printed as text.
		}

		return $post;
	}

	/**
	 * Runs a callback as this actor.
	 *
	 * @param callable $callback Callback.
	 * @return mixed The callback's return value.
	 */
	private function act( $callback ) {
		$previous = get_current_user_id();
		wp_set_current_user( $this->ID );

		try {
			return $callback();
		} finally {
			wp_set_current_user( $previous );
		}
	}

	/**
	 * Returns a user's display name, or their ID once they are gone.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private static function name_of( $user_id ) {
		$user = get_userdata( $user_id );

		return $user ? $user->display_name : '#' . $user_id;
	}

	/**
	 * Wraps text in a paragraph block.
	 *
	 * @param string $text Paragraph text.
	 * @return string Block markup.
	 */
	private static function paragraph( $text ) {
		return get_comment_delimited_block_content( 'core/paragraph', array(), '<p>' . esc_html( $text ) . '</p>' );
	}
}
