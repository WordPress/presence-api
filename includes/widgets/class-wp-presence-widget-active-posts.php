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
 */
class WP_Presence_Widget_Active_Posts {

	/**
	 * Seconds after which a user is considered idle.
	 *
	 * @var int
	 */
	const IDLE_THRESHOLD = 30;

	/**
	 * Registers the dashboard widget.
	 *
	 */
	public static function register() {
		if ( ! current_user_can( 'edit_posts' ) ) {
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
	 * Enqueues the widget's JavaScript and CSS.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public static function enqueue_scripts( $hook_suffix ) {
		if ( 'index.php' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_script( 'heartbeat' );

		wp_add_inline_script(
			'heartbeat',
			self::get_inline_script()
		);

		wp_register_style( 'presence-active-posts-widget', false, array(), WP_PRESENCE_VERSION );
		wp_enqueue_style( 'presence-active-posts-widget' );
		wp_add_inline_style( 'presence-active-posts-widget', self::get_inline_css() );
	}

	/**
	 * Returns the inline CSS for the dashboard widget.
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
	 * Returns the inline JavaScript for Heartbeat integration.
	 *
	 * @return string JavaScript code.
	 */
	private static function get_inline_script() {
		$i18n_json = wp_json_encode(
			array(
				'noPostsEdited'    => __( 'All quiet.', 'presence-api' ),
				'postsBeingEdited' => __( 'Posts currently being edited', 'presence-api' ),
				'statusEditing'    => __( 'Editing', 'presence-api' ),
				'statusIdle'       => __( 'Idle', 'presence-api' ),
				/* translators: %d: Number of editors. */
				'editorCount'      => __( '%d editors', 'presence-api' ),
			)
		);

		return sprintf(
			<<<'JS'
(function($) {
	if (typeof wp === 'undefined' || typeof wp.heartbeat === 'undefined') {
		return;
	}

	var i18n = %s;

	function esc(str) {
		var el = document.createElement('span');
		el.textContent = str;
		return el.innerHTML;
	}

	$(document).on('heartbeat-send', function(event, data) {
		data['presence-active-posts-ping'] = true;
	});

	var lastSignature = '';

	function buildFullPostsHtml(posts) {
		var html = '<ul class="presence-active-posts-list" aria-label="' + esc(i18n.postsBeingEdited) + '">';
		posts.forEach(function(post) {
			var anyActive = post.editors.some(function(e) { return e.status === 'active'; });
			var statusLabel = anyActive ? '' : i18n.statusIdle;
			html += '<li class="presence-active-post-item">';
			html += '<span class="presence-editor-stack">';
			var stackMax = Math.min(post.editors.length, 4);
			post.editors.slice(0, stackMax).forEach(function(editor, idx) {
				if (editor.avatar_url) {
					html += '<img src="' + esc(editor.avatar_url) + '" width="24" height="24" style="z-index:' + (stackMax - idx) + '" alt="' + esc(editor.display_name) + '" />';
				}
			});
			html += '</span>';
			html += '<div class="presence-active-post-info">';
			if (post.editors.length === 1) {
				html += '<div><span class="presence-editor-count">' + esc(post.editors[0].display_name) + '</span></div>';
			} else {
				html += '<div><span class="presence-editor-count">' + esc(i18n.editorCount.replace('%%d', post.editors.length)) + '</span></div>';
			}
			html += '<div><span class="presence-post-title"><a href="' + esc(post.edit_url) + '">' + esc(post.post_title) + '</a></span></div>';
			html += '</div>';
			html += '<span class="presence-status-text">' + esc(statusLabel) + '</span>';
			html += '</li>';
		});
		html += '</ul>';
		return html;
	}

	$(document).on('heartbeat-tick', function(event, data) {
		if (!data['presence-active-posts']) {
			return;
		}

		var container = $('#presence-active-posts-list');
		if (!container.length) {
			return;
		}

		var posts = data['presence-active-posts'];
		if (!posts.length) {
			if (lastSignature !== '') {
				container.html('<p>' + esc(i18n.noPostsEdited) + '</p>');
				lastSignature = '';
			}
			return;
		}

		var sig = posts.map(function(p) {
			return p.post_id + ':' + p.editors.map(function(e) { return e.user_id + '/' + e.status; }).join('+');
		}).join(',');
		if (sig !== lastSignature) {
			container.html(buildFullPostsHtml(posts));
			lastSignature = sig;
		}
	});
})(jQuery);
JS,
			$i18n_json
		);
	}

	/**
	 * Renders the dashboard widget.
	 *
	 */
	public static function render() {
		$posts = self::build_active_posts_data();

		echo '<div id="presence-active-posts-list" aria-live="polite">';

		if ( empty( $posts ) ) {
			echo '<p>' . esc_html__( 'All quiet.', 'presence-api' ) . '</p>';
		} else {
			echo '<ul class="presence-active-posts-list" aria-label="' . esc_attr__( 'Posts currently being edited', 'presence-api' ) . '">';

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

				echo '<li class="presence-active-post-item">';

				// Avatar stack.
				echo '<span class="presence-editor-stack">';
				$stack_max = min( count( $post_data['editors'] ), 4 );
				foreach ( array_slice( $post_data['editors'], 0, $stack_max ) as $index => $editor ) {
					$z = $stack_max - $index;
					echo '<img src="' . esc_url( $editor['avatar_url'] ) . '" width="24" height="24" style="z-index:' . (int) $z . '" alt="' . esc_attr( $editor['display_name'] ) . '" />';
				}
				echo '</span>';

				echo '<div class="presence-active-post-info">';
				if ( 1 === count( $post_data['editors'] ) ) {
					echo '<div><span class="presence-editor-count">' . esc_html( $post_data['editors'][0]['display_name'] ) . '</span></div>';
				} else {
					/* translators: %d: Number of editors. */
					echo '<div><span class="presence-editor-count">' . esc_html( sprintf( __( '%d editors', 'presence-api' ), count( $post_data['editors'] ) ) ) . '</span></div>';
				}
				echo '<div><span class="presence-post-title"><a href="' . esc_url( $post_data['edit_url'] ) . '">' . esc_html( $post_data['post_title'] ) . '</a></span></div>';
				echo '</div>';

				echo '<span class="presence-status-text">' . esc_html( $status_label ) . '</span>';
				echo '</li>';
			}

			echo '</ul>';
		}

		echo '</div>';
	}

	/**
	 * Handles the heartbeat received event for active posts updates.
	 *
	 * @param array  $response  The Heartbeat response.
	 * @param array  $data      The $_POST data sent.
	 * @param string $screen_id The screen ID.
	 * @return array The Heartbeat response.
	 */
	public static function heartbeat_received( $response, $data, $screen_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by filter signature.
		if ( empty( $data['presence-active-posts-ping'] ) ) {
			return $response;
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			return $response;
		}

		$response['presence-active-posts'] = self::build_active_posts_data();

		return $response;
	}

	/**
	 * Builds active posts data grouped by post.
	 *
	 * Returns an array of posts, each with an 'editors' array containing
	 * the users currently editing that post.
	 *
	 * @return array Array of post data with grouped editors.
	 */
	private static function build_active_posts_data() {
		$entries = wp_get_presence_by_room_prefix( 'postType/' );
		$by_post = array();
		$now     = time();

		cache_users( wp_list_pluck( $entries, 'user_id' ) );

		// Prime post caches to avoid N+1 queries from get_post() in the loop.
		$post_ids = array();
		foreach ( $entries as $entry ) {
			$parts = explode( ':', $entry->room, 2 );
			if ( count( $parts ) >= 2 ) {
				$post_ids[] = (int) $parts[1];
			}
		}
		if ( ! empty( $post_ids ) ) {
			_prime_post_caches( array_unique( $post_ids ), true, true );
		}

		foreach ( $entries as $entry ) {
			$user = get_userdata( $entry->user_id );

			if ( ! $user ) {
				continue;
			}

			/* Parse room format: postType/{type}:{id} */
			$room_parts = explode( ':', $entry->room, 2 );

			if ( count( $room_parts ) < 2 ) {
				continue;
			}

			$post_id   = (int) $room_parts[1];
			$post_type = str_replace( 'postType/', '', $room_parts[0] );

			if ( ! post_type_supports( $post_type, 'presence' ) ) {
				continue;
			}

			$post = get_post( $post_id );

			if ( ! $post ) {
				continue;
			}

			$elapsed = $now - strtotime( $entry->date_gmt . ' +0000' );
			$status  = $elapsed > self::IDLE_THRESHOLD ? 'idle' : 'active';

			if ( ! isset( $by_post[ $post_id ] ) ) {
				$by_post[ $post_id ] = array(
					'post_id'    => $post_id,
					'post_title' => $post->post_title,
					'post_type'  => $post_type,
					'edit_url'   => get_edit_post_link( $post_id, 'raw' ),
					'editors'    => array(),
				);
			}

			$by_post[ $post_id ]['editors'][] = array(
				'user_id'      => (int) $entry->user_id,
				'display_name' => $user->display_name,
				'avatar_url'   => get_avatar_url( $user->ID, array( 'size' => 24 ) ),
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

		return array_values( $by_post );
	}
}
