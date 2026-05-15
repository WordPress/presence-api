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
			'room'      => 'admin/online',
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
				$front_context['postId'] = $queried->ID;
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
					// The post-lock bridge writes this entry via the wp-refresh-post-lock heartbeat.
					$entries[] = array(
						'room'      => $room,
						'client_id' => 'lock-' . $user_id,
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
		if ( ! empty( $front_context['postId'] ) ) {
			$admin_state['post_id'] = $front_context['postId'];
		}
	}
	wp_set_presence( 'admin/online', 'user-' . $user_id, $admin_state, $user_id );

	if ( $editor_post_id ) {
		$editor_room = wp_presence_post_room( $editor_post_id );
		if ( $editor_room ) {
			wp_set_presence(
				$editor_room,
				'editor-' . $user_id,
				array(
					'action' => 'editing',
					'screen' => $screen_id,
				),
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

	wp_add_inline_script(
		'heartbeat',
		sprintf( 'window.wpPresenceConfig = %s;', wp_json_encode( $config, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ) ),
		'before'
	);

	wp_add_inline_script(
		'heartbeat',
		<<<'JS'
		(function ($) {
			if (typeof wp === 'undefined' || typeof wp.heartbeat === 'undefined') {
				return;
			}

			const config = window.wpPresenceConfig || {};
			const entries = Array.isArray(config.entries) ? config.entries : [];
			const frontContext = config.frontContext || null;
			const editorPostId = parseInt(config.editorPostId, 10) || 0;
			const restUrl = config.restUrl || '';
			const nonce = config.nonce || '';

			// Guards against duplicate leave() invocations.
			let hasLeft = false;

			$(document).on('heartbeat-send', function (event, data) {
				const ping = { screen: window.pagenow || 'front' };
				if (frontContext) {
					if (frontContext.title) {
						ping.title = frontContext.title;
					}
					if (frontContext.postId) {
						ping.post_id = frontContext.postId;
					}
				}
				data['presence-ping'] = ping;

				if (editorPostId) {
					data['presence-editor-ping'] = { post_id: editorPostId };
				}

				hasLeft = false;
			});

			function leave() {
				if (hasLeft || !restUrl || !entries.length) {
					return;
				}
				hasLeft = true;

				// keepalive lets the DELETE outlive the unload; sendBeacon is POST-only.
				if (typeof window.fetch !== 'function') {
					return;
				}

				entries.forEach(function (entry) {
					if (!entry || !entry.room || !entry.client_id) {
						return;
					}
					const url = new URL(restUrl);
					url.searchParams.set('room', entry.room);
					url.searchParams.set('client_id', entry.client_id);
					try {
						window.fetch(url, {
							method: 'DELETE',
							credentials: 'same-origin',
							keepalive: true,
							headers: { 'X-WP-Nonce': nonce }
						});
					} catch {
						// Best-effort: TTL cleanup will catch entries we couldn't remove.
					}
				});
			}

			// Re-establish presence on every page load so in-admin navigation doesn't
			// leave a gap between the unload DELETE and the heartbeat's first tick.
			function tickNow() {
				if (typeof wp?.heartbeat?.connectNow === 'function') {
					wp.heartbeat.connectNow();
				}
			}
			$(tickNow);
			// bfcache restore: DOMContentLoaded won't fire.
			window.addEventListener('pageshow', function (event) {
				if (event.persisted) {
					tickNow();
				}
			});

			window.addEventListener('pagehide', function () {
				leave();
			});
		})(jQuery);
		JS
	);
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

	wp_set_presence(
		$room,
		'editor-' . $user_id,
		array(
			'action' => 'editing',
			'screen' => $screen_id,
		),
		$user_id
	);

	return $response;
}
