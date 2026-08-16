<?php
/**
 * Heartbeat presence handlers.
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the state stored on a user's entry in a post room.
 *
 * Shared so the two writers of that entry cannot drift apart on its shape.
 *
 * @access private
 *
 * @param string $screen_id The screen ID.
 * @param bool   $locked    Whether this write carries a post lock refresh.
 * @return array The state to store.
 */
function wp_presence_editor_state( $screen_id, $locked ) {
	return array(
		'action' => 'editing',
		'screen' => $screen_id,
		'locked' => (bool) $locked,
	);
}

/**
 * Enqueues heartbeat and the presence ping script on all admin pages.
 */
function wp_presence_enqueue_heartbeat_ping() {
	if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	// On the front-end, only enqueue if the admin bar is showing.
	if ( ! is_admin() && ! is_admin_bar_showing() ) {
		return;
	}

	wp_enqueue_script( 'heartbeat' );

	$user_id = get_current_user_id();

	// Every page where the ping is enqueued occupies the admin/online room.
	$entries = array(
		array(
			'room'      => wp_presence_admin_room(),
			'client_id' => 'user-' . $user_id,
		),
	);

	// Carry a title for any frontend URL so it shows up in the Who's Online
	// widget (non-singular views — archives, search, the front page, taxonomies,
	// 404s — are labeled too). is_singular() pages also carry the post id.
	$front_context = null;
	if ( ! is_admin() ) {
		if ( is_front_page() ) {
			$title = __( 'Home', 'presence-api' );
		} else {
			$strip_branding = static function ( $parts ) {
				unset( $parts['tagline'], $parts['site'] );
				return $parts;
			};
			add_filter( 'document_title_parts', $strip_branding );
			$title = wp_get_document_title();
			remove_filter( 'document_title_parts', $strip_branding );
		}

		$front_context = array( 'title' => $title );

		if ( is_singular() ) {
			$queried = get_queried_object();
			if ( $queried instanceof WP_Post ) {
				$front_context['post_id'] = $queried->ID;
			}
		}
	}

	// On the post-edit screen, also occupy the per-post room.
	$editor_post_id = 0;
	if ( is_admin() && function_exists( 'get_current_screen' ) ) {
		$screen = get_current_screen();
		if ( $screen && 'post' === $screen->base ) {
			$post = get_post();
			if ( $post && post_type_supports( $post->post_type, 'presence' ) ) {
				$room = wp_presence_post_room( $post->ID );
				if ( $room ) {
					$editor_post_id = $post->ID;
					$entries[]      = array(
						'room'      => $room,
						'client_id' => 'editor-' . $user_id,
					);
				}
			}
		}
	}

	// Write presence server-side during this request so the new page closes the
	// gap between the old page's pagehide DELETE and the next heartbeat tick.
	$screen_id = is_admin() && function_exists( 'get_current_screen' ) && get_current_screen()
		? get_current_screen()->id
		: 'front';

	$admin_state = array( 'screen' => $screen_id );
	if ( $front_context ) {
		if ( ! empty( $front_context['title'] ) ) {
			$admin_state['title'] = $front_context['title'];
		}
		if ( ! empty( $front_context['post_id'] ) ) {
			$admin_state['post_id'] = $front_context['post_id'];
		}
	}
	wp_set_presence( wp_presence_admin_room(), 'user-' . $user_id, $admin_state, $user_id );

	if ( $editor_post_id ) {
		$editor_room = wp_presence_post_room( $editor_post_id );
		if ( $editor_room ) {
			// No tick has carried a lock refresh yet. connectNow() on load makes
			// that a single request, not a visible state.
			wp_set_presence(
				$editor_room,
				'editor-' . $user_id,
				wp_presence_editor_state( $screen_id, false ),
				$user_id
			);
		}
	}

	$config = array(
		'entries'      => $entries,
		'frontContext' => $front_context,
		'editorPostId' => $editor_post_id,
		'restUrl'      => esc_url_raw( rest_url( 'wp-presence/v1/presence' ) ),
		'nonce'        => wp_create_nonce( 'wp_rest' ),
	);

	wp_enqueue_script(
		'wp-presence-ping',
		WP_PRESENCE_PLUGIN_URL . 'assets/js/presence-ping.js',
		array( 'jquery', 'heartbeat' ),
		WP_PRESENCE_VERSION,
		true
	);

	wp_add_inline_script(
		'wp-presence-ping',
		sprintf( 'window.wpPresenceConfig = %s;', wp_json_encode( $config, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ) ),
		'before'
	);
}

/**
 * Records the current user's presence in the admin/online room on every tick.
 *
 * This is the API's primary write path. It runs regardless of which dashboard
 * widgets are registered.
 *
 * @param array  $response  The Heartbeat response.
 * @param array  $data      The $_POST data sent.
 * @param string $screen_id The screen ID.
 * Nonce verification is handled by WordPress in wp_ajax_heartbeat().
 *
 * @return array The Heartbeat response.
 */
function wp_presence_admin_heartbeat_received( $response, $data, $screen_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by filter signature.
	if ( empty( $data['presence-ping'] ) ) {
		return $response;
	}

	if ( ! current_user_can( 'edit_posts' ) ) {
		return $response;
	}

	$user_id = get_current_user_id();
	$screen  = isset( $data['presence-ping']['screen'] ) ? sanitize_text_field( $data['presence-ping']['screen'] ) : '';

	// Enrich post-editing screens with the post status.
	$post_status = '';
	if ( in_array( $screen, array( 'post', 'edit-post', 'page' ), true ) ) {
		// The editor heartbeat includes the post ID in wp-refresh-post-lock.
		$post_id = 0;
		if ( ! empty( $data['wp-refresh-post-lock']['post_id'] ) ) {
			$post_id = absint( $data['wp-refresh-post-lock']['post_id'] );
		} elseif ( ! empty( $data['presence-editor-ping']['post_id'] ) ) {
			$post_id = absint( $data['presence-editor-ping']['post_id'] );
		}
		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( $post && current_user_can( 'edit_post', $post_id ) && isset( get_post_stati()[ $post->post_status ] ) ) {
				$post_status = $post->post_status;
			}
		}
	}

	$state = array( 'screen' => $screen );
	if ( $post_status ) {
		$state['post_status'] = $post_status;
	}

	// Store the frontend page label whenever the ping is from the public site.
	// title becomes the row's screen label in Who's Online; post_id is recorded
	// when the ping carries one (singular views).
	if ( 'front' === $screen ) {
		if ( ! empty( $data['presence-ping']['title'] ) ) {
			$state['title'] = sanitize_text_field( $data['presence-ping']['title'] );
		}
		$post_id = (int) ( $data['presence-ping']['post_id'] ?? 0 );
		if ( $post_id > 0 ) {
			$front_post = get_post( $post_id );
			if ( $front_post ) {
				$state['post_id'] = $front_post->ID;
			}
		}
	}

	wp_set_presence( wp_presence_admin_room(), 'user-' . $user_id, $state, $user_id );

	return $response;
}

/**
 * Handles the editor presence heartbeat and creates a presence entry for the post being edited.
 *
 * @param array  $response  The Heartbeat response.
 * @param array  $data      The $_POST data sent.
 * @param string $screen_id The screen ID.
 * @return array The Heartbeat response.
 */
function wp_presence_editor_heartbeat_received( $response, $data, $screen_id ) {
	if ( empty( $data['presence-editor-ping']['post_id'] ) ) {
		return $response;
	}

	$post_id = absint( $data['presence-editor-ping']['post_id'] );
	$user_id = get_current_user_id();

	if ( ! $user_id || ! current_user_can( 'edit_post', $post_id ) ) {
		return $response;
	}

	$room = wp_presence_post_room( $post_id );

	if ( ! $room ) {
		return $response;
	}

	// Read per tick: a tick carrying no refresh means the lock is no longer
	// being held open, which is what the separate row's expiry used to say.
	$locked = ! empty( $data['wp-refresh-post-lock']['post_id'] )
		&& absint( $data['wp-refresh-post-lock']['post_id'] ) === $post_id;

	$state = wp_presence_editor_state( $screen_id, $locked );

	/**
	 * Filters the editor presence state before it's saved.
	 *
	 * Allows plugins to enrich the state data with additional metadata
	 * (e.g., cursor position, selected blocks, collaboration status).
	 *
	 * @since 0.1.21
	 *
	 * @param array $state   The presence state data.
	 * @param int   $post_id The post ID being edited.
	 * @param int   $user_id The user ID.
	 */
	$state = apply_filters( 'wp_presence_editor_state', $state, $post_id, $user_id );

	wp_set_presence(
		$room,
		'editor-' . $user_id,
		$state,
		$user_id
	);

	/**
	 * Fires when an editor's presence is updated via heartbeat.
	 *
	 * @since 0.1.21
	 *
	 * @param int    $post_id The post ID being edited.
	 * @param int    $user_id The user ID.
	 * @param string $room    The presence room identifier.
	 */
	do_action( 'wp_presence_editor_active', $post_id, $user_id, $room );

	wp_presence_check_collaboration_threshold( $room );

	return $response;
}

/**
 * Checks if the collaboration threshold has been crossed and fires appropriate actions.
 *
 * Fires 'wp_presence_collaboration_started' when editor count goes from 1 to 2+.
 * Fires 'wp_presence_collaboration_ended' when editor count goes from 2+ to 1.
 *
 * @since 0.1.21
 *
 * @param string $room The presence room identifier.
 */
function wp_presence_check_collaboration_threshold( $room ) {
	$entries = wp_get_presence( $room );

	$editor_count = count(
		array_filter(
			$entries,
			static function ( $entry ) {
				return str_starts_with( $entry->client_id, 'editor-' );
			}
		)
	);

	static $previous = array();
	$prev_count      = $previous[ $room ] ?? 1;

	if ( 1 === $prev_count && $editor_count >= 2 ) {
		/**
		 * Fires when collaboration starts (1 to 2+ editors).
		 *
		 * @since 0.1.21
		 *
		 * @param string $room    The presence room identifier.
		 * @param array  $entries The current presence entries.
		 */
		do_action( 'wp_presence_collaboration_started', $room, $entries );
	} elseif ( $prev_count >= 2 && 1 === $editor_count ) {
		/**
		 * Fires when collaboration ends (2+ to 1 editor).
		 *
		 * @since 0.1.21
		 *
		 * @param string $room    The presence room identifier.
		 * @param array  $entries The current presence entries.
		 */
		do_action( 'wp_presence_collaboration_ended', $room, $entries );
	}

	$previous[ $room ] = $editor_count;
}
