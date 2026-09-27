<?php
/**
 * Dashboard Widget: Active Posts
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the "Active Posts" dashboard widget with Heartbeat integration.
 *
 * Shows which posts are currently being edited, grouped by post with
 * an avatar stack of editors.
 *
 * @since 0.1.1
 */
class WP_Presence_Widget_Active_Posts {

	/**
	 * Registers the dashboard widget.
	 *
	 * @since 0.1.1
	 */
	public static function register() {
		if ( ! wp_presence_user_can_edit_any_post_type() ) {
			return;
		}

		wp_add_dashboard_widget(
			'presence_active_posts',
			__( 'Active Posts', 'presence-api' ),
			array( __CLASS__, 'render' ),
			null,
			null,
			'normal',
			'default'
		);

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueues the widget's CSS.
	 *
	 * @since 0.1.1
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public static function enqueue_scripts( $hook_suffix ) {
		if ( 'index.php' !== $hook_suffix ) {
			return;
		}

		wp_register_style( 'presence-active-posts-widget', false, array(), WP_PRESENCE_VERSION );
		wp_enqueue_style( 'presence-active-posts-widget' );
		wp_add_inline_style( 'presence-active-posts-widget', self::get_inline_css() );
	}

	/**
	 * Returns the inline CSS for the dashboard widget.
	 *
	 * @since 0.1.1
	 *
	 * @return string CSS code.
	 */
	private static function get_inline_css() {
		return '#presence-active-posts-list p { margin: 0; padding: 8px 12px; color: #646970; }
			#presence-active-posts-list .presence-active-posts-list { margin: 0; }
			#presence-active-posts-list .presence-active-post-item { display: flex; align-items: center; gap: 8px; padding: 8px 12px; border-bottom: 1px solid #f0f0f1; }
			#presence-active-posts-list .presence-active-post-item:last-child { border-bottom: none; }
			#presence-active-posts-list .presence-active-post-info { flex: 1; min-width: 0; }
			#presence-active-posts-list .presence-post-title a { text-decoration: none; font-weight: 400; }
			#presence-active-posts-list .presence-editor-count { color: #646970; font-size: 13px; }
			#presence-active-posts-list .presence-editor-stack { display: flex; align-items: center; }
			#presence-active-posts-list .presence-editor-stack img { border-radius: 50%; width: 24px; height: 24px; margin-inline-start: -6px; box-shadow: 0 0 0 2px #fff; position: relative; }
			#presence-active-posts-list .presence-editor-stack img:first-child { margin-inline-start: 0; }
			#presence-active-posts-list .presence-status-text { font-size: 12px; margin-left: auto; white-space: nowrap; flex-shrink: 0; color: #50575e; }
';
	}

	/**
	 * Renders the dashboard widget.
	 *
	 * @since 0.1.1
	 */
	public static function render() {
		echo '<div id="presence-active-posts-list" aria-live="polite" tabindex="-1">';
		echo self::list_markup( self::build_active_posts_data() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped as it is built.
		echo '</div>';
	}

	/**
	 * Returns the widget's list, as drawn on load and sent with each Heartbeat.
	 *
	 * @since 0.11.0
	 *
	 * @param array $posts Return value of self::build_active_posts_data().
	 * @return string HTML markup.
	 */
	private static function list_markup( $posts ) {
		if ( empty( $posts ) ) {
			return '<p>' . esc_html__( 'All quiet.', 'presence-api' ) . '</p>';
		}

		$html = '<ul class="presence-active-posts-list" aria-label="' . esc_attr__( 'Posts currently being edited', 'presence-api' ) . '">';

		foreach ( $posts as $post_data ) {
			$any_active = false;
			foreach ( $post_data['editors'] as $editor ) {
				if ( 'active' === $editor['status'] ) {
					$any_active = true;
					break;
				}
			}
			// Only show status when it differs — "Idle" is the signal.
			// Active is the default state; labeling it adds noise.
			$status_label = $any_active ? '' : __( 'Idle', 'presence-api' );

			$html .= '<li class="presence-active-post-item" data-post-id="' . (int) $post_data['post_id'] . '">';

			$html     .= '<span class="presence-editor-stack">';
			$stack_max = min( count( $post_data['editors'] ), 4 );
			foreach ( array_slice( $post_data['editors'], 0, $stack_max ) as $index => $editor ) {
				$z     = $stack_max - $index;
				$html .= '<img src="' . esc_url( $editor['avatar_url'] ) . '" width="24" height="24" style="z-index:' . (int) $z . '" alt="' . esc_attr( $editor['display_name'] ) . '" />';
			}
			$html .= '</span>';

			$html .= '<div class="presence-active-post-info">';
			$html .= '<div><span class="presence-editor-count">' . esc_html( $post_data['editor_label'] ) . '</span></div>';
			$html .= '<div><span class="presence-post-title"><a href="' . esc_url( $post_data['edit_url'] ) . '">' . esc_html( $post_data['post_title'] ) . '</a></span></div>';
			$html .= '</div>';

			$html .= '<span class="presence-status-text">' . esc_html( $status_label ) . '</span>';
			$html .= '</li>';
		}

		return $html . '</ul>';
	}

	/**
	 * Handles the heartbeat received event for active posts updates.
	 *
	 * @since 0.1.1
	 *
	 * @param array  $response  The Heartbeat response.
	 * @param array  $data      The $_POST data sent.
	 * @param string $screen_id The screen ID.
	 * @return array The Heartbeat response.
	 */
	public static function heartbeat_received( $response, $data, $screen_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by filter signature.
		if ( ! wp_presence_fragment_request( $data, 'active-posts' ) ) {
			return $response;
		}

		if ( ! wp_presence_user_can_edit_any_post_type() ) {
			return $response;
		}

		$response['presence-fragments']['active-posts'] = self::list_markup( self::build_active_posts_data() );

		return $response;
	}

	/**
	 * Builds active posts data grouped by post.
	 *
	 * Returns an array of posts, each with an 'editors' array containing
	 * the users currently editing that post.
	 *
	 * @since 0.1.1
	 * @since 0.11.0 Adds the post type to each editor label and titles untitled posts "(no title)".
	 *
	 * @return array Array of post data with grouped editors.
	 */
	private static function build_active_posts_data() {
		$entries         = wp_get_presence_by_room_prefix( 'postType/' );
		$by_post         = array();
		$now             = time();
		$current_user_id = get_current_user_id();

		cache_users( wp_list_pluck( $entries, 'user_id' ) );

		// Prime post caches to avoid N+1 queries from get_post() and the
		// capability check in the loop. Neither reads the term or meta cache,
		// so priming those is two queries spent on nothing.
		$post_ids = array();
		foreach ( $entries as $entry ) {
			$parsed = wp_presence_parse_room( $entry->room );
			if ( $parsed ) {
				$post_ids[] = $parsed['post_id'];
			}
		}
		if ( ! empty( $post_ids ) ) {
			_prime_post_caches( array_unique( $post_ids ), false, false );
		}

		foreach ( $entries as $entry ) {
			$user = get_userdata( $entry->user_id );

			if ( ! $user ) {
				continue;
			}

			/* Parse room format: postType/{type}:{id} */
			$parsed = wp_presence_parse_room( $entry->room );

			if ( ! $parsed ) {
				continue;
			}

			$post_id   = $parsed['post_id'];
			$post_type = $parsed['post_type'];

			if ( ! post_type_supports( $post_type, 'presence' ) ) {
				continue;
			}

			// Rendering the widget only takes editing some post type, so without this a
			// contributor would receive the title, edit link and editors of
			// every post being worked on. Same check the REST controller
			// applies to the room collection.
			if ( ! wp_can_access_presence_room( $entry->room, $current_user_id ) ) {
				continue;
			}

			$post = get_post( $post_id );

			if ( ! $post ) {
				continue;
			}

			$elapsed = $now - strtotime( $entry->date_gmt . ' +0000' );
			$status  = $elapsed > wp_presence_idle_threshold() ? 'idle' : 'active';

			if ( ! isset( $by_post[ $post_id ] ) ) {
				// A new post holds core's "Auto Draft" placeholder until its first save.
				$untitled = '' === $post->post_title || 'auto-draft' === $post->post_status;

				$by_post[ $post_id ] = array(
					'post_id'    => $post_id,
					'post_title' => $untitled ? __( '(no title)', 'presence-api' ) : $post->post_title,
					'post_type'  => $post_type,
					'edit_url'   => get_edit_post_link( $post_id, 'raw' ),
					'editors'    => array(),
				);
			}

			$editor_id = (int) $entry->user_id;

			// A user can hold more than one entry in a room. Rows arrive newest
			// first, so the one already seen is the freshest.
			if ( isset( $by_post[ $post_id ]['editors'][ $editor_id ] ) ) {
				continue;
			}

			$by_post[ $post_id ]['editors'][ $editor_id ] = array(
				'user_id'      => $editor_id,
				'display_name' => $user->display_name,
				'avatar_url'   => get_avatar_url( $user->ID, array( 'size' => wp_presence_get_avatar_fetch_size( 24 ) ) ),
				'status'       => $status,
			);
		}

		// Sort by number of editors descending.
		usort(
			$by_post,
			function ( $a, $b ) {
				return count( $b['editors'] ) - count( $a['editors'] );
			}
		);

		// Keyed by user id above; hand back a list.
		foreach ( $by_post as $index => $post_data ) {
			$editors = array_values( $post_data['editors'] );
			$count   = count( $editors );

			$editor_label = 1 === $count
				? $editors[0]['display_name']
				/* translators: %d: Number of people editing the post. */
				: sprintf( _n( '%d person', '%d people', $count, 'presence-api' ), $count );

			$by_post[ $index ]['editors']      = $editors;
			$by_post[ $index ]['editor_label'] = sprintf(
				/* translators: 1: Who is editing, a name or a count of people. 2: Singular post type name, such as Page. */
				__( '%1$s · %2$s', 'presence-api' ),
				$editor_label,
				get_post_type_object( $post_data['post_type'] )->labels->singular_name
			);
		}

		return array_values( $by_post );
	}
}
