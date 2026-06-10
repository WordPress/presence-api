<?php
/**
 * Stale-screen detection: alert users when an admin screen they're viewing is out of date.
 *
 * When a user saves a Settings page, a post, a user, a term, or a comment, a
 * per-screen revision counter is bumped. Other users currently viewing the
 * same screen receive the new revision on the next Heartbeat tick and render
 * a non-blocking notice prompting them to reload.
 *
 * Coverage in this first cut: classic admin screens that submit via POST and
 * redirect on success — Settings → General/Writing/Reading/Discussion/Media,
 * post edits (post.php), user edits (user-edit.php, profile.php), term edits
 * (edit-tags.php), and comment edits (comment.php). JS-driven and REST-driven
 * screens can opt in via the `wp_presence_current_screen_key` filter plus a
 * future client-side `markScreenStale()` API.
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Bound the option row that stores per-screen revisions.
if ( ! defined( 'WP_PRESENCE_SCREEN_REV_LIMIT' ) ) {
	define( 'WP_PRESENCE_SCREEN_REV_LIMIT', 200 );
}

/**
 * Returns the full screen-revision map.
 *
 * Shape: array( '<screen_key>' => array( 'rev' => int, 'actor_id' => int, 'time' => int ) ).
 * The actor's display name and avatar are looked up fresh on each heartbeat
 * tick — not stored — so renames and avatar changes show immediately.
 *
 * @return array
 */
function wp_presence_get_screen_revisions() {
	$map = get_option( 'wp_presence_screen_revisions', array() );
	return is_array( $map ) ? $map : array();
}

/**
 * Returns the revision entry for a single screen, or null if none.
 *
 * @param string $screen_key Screen key to look up.
 * @return array|null
 */
function wp_presence_get_screen_revision( $screen_key ) {
	$map = wp_presence_get_screen_revisions();
	return isset( $map[ $screen_key ] ) ? $map[ $screen_key ] : null;
}

/**
 * Increments the revision counter for a screen and records the actor.
 *
 * @param string $screen_key Screen key to bump.
 * @param int    $actor_id   Optional. Defaults to the current user.
 * @return int|false New revision number, or false when the key is empty.
 */
function wp_presence_bump_screen_revision( $screen_key, $actor_id = 0 ) {
	$screen_key = (string) $screen_key;
	if ( '' === $screen_key ) {
		return false;
	}
	if ( ! $actor_id ) {
		$actor_id = get_current_user_id();
	}

	$map      = wp_presence_get_screen_revisions();
	$previous = isset( $map[ $screen_key ]['rev'] ) ? (int) $map[ $screen_key ]['rev'] : 0;
	$revision = $previous + 1;

	$map[ $screen_key ] = array(
		'rev'      => $revision,
		'actor_id' => (int) $actor_id,
		'time'     => time(),
	);

	// LRU-ish trim by oldest update time when over the limit.
	if ( count( $map ) > WP_PRESENCE_SCREEN_REV_LIMIT ) {
		uasort(
			$map,
			static function ( $a, $b ) {
				$at = isset( $a['time'] ) ? (int) $a['time'] : 0;
				$bt = isset( $b['time'] ) ? (int) $b['time'] : 0;
				return $at <=> $bt;
			}
		);
		$map = array_slice( $map, - WP_PRESENCE_SCREEN_REV_LIMIT, null, true );
	}

	update_option( 'wp_presence_screen_revisions', $map, false );

	/**
	 * Fires after a screen revision is bumped.
	 *
	 * @param string $screen_key Screen key that was bumped.
	 * @param int    $revision   New revision number.
	 * @param int    $actor_id   User who triggered the bump.
	 */
	do_action( 'wp_presence_screen_revision_bumped', $screen_key, $revision, $actor_id );

	return $revision;
}

/**
 * True when the current request is a user-initiated admin save (not cron, CLI, or REST).
 *
 * The REST gate is verified by inspection only; defining REST_REQUEST in a
 * PHPUnit test would leak into every subsequent test in the same process
 * (PHP constants can't be unset), so the gate is intentionally not exercised
 * via integration tests.
 *
 * @return bool
 */
function wp_presence_is_admin_screen_save() {
	if ( wp_doing_cron() ) {
		return false;
	}
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return false;
	}
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return false;
	}
	return is_admin();
}

/**
 * Resolves the screen key for the currently rendered admin screen.
 *
 * @return string Empty string when the current screen has no stale-detection coverage.
 */
function wp_presence_current_screen_key() {
	if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
		return '';
	}
	$screen = get_current_screen();
	if ( ! $screen ) {
		return '';
	}

	$key = '';

	switch ( $screen->base ) {
		case 'options-general':
		case 'options-writing':
		case 'options-reading':
		case 'options-discussion':
		case 'options-media':
		case 'options-permalink':
			// Slash-separated to match the plugin's room naming convention
			// (cf. `admin/online`, `postType/post:42`).
			$key = str_replace( 'options-', 'options/', $screen->base );
			break;

		case 'post':
			$post = get_post();
			if ( $post ) {
				$key = 'post/' . $post->ID;
			}
			break;

		case 'user-edit':
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen identification.
			$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
			if ( $user_id ) {
				$key = 'user-edit/' . $user_id;
			}
			break;

		case 'profile':
			$current = get_current_user_id();
			if ( $current ) {
				$key = 'user-edit/' . $current;
			}
			break;

		case 'edit-tags':
		case 'term':
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen identification.
			$taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen identification.
			$term_id = isset( $_GET['tag_ID'] ) ? absint( $_GET['tag_ID'] ) : 0;
			if ( $taxonomy && $term_id ) {
				$key = 'term/' . $taxonomy . '/' . $term_id;
			}
			break;

		case 'comment':
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen identification.
			$comment_id = isset( $_GET['c'] ) ? absint( $_GET['c'] ) : 0;
			if ( $comment_id ) {
				$key = 'comment/' . $comment_id;
			}
			break;
	}

	/**
	 * Filters the screen key used to track stale-screen state.
	 *
	 * Return a non-empty string to opt a custom screen into stale-screen detection.
	 *
	 * @param string    $key    Computed screen key, or '' when none applies.
	 * @param WP_Screen $screen Current screen.
	 */
	return (string) apply_filters( 'wp_presence_current_screen_key', $key, $screen );
}

/**
 * Bumps the Settings screen's revision when its option_page is saved.
 *
 * @param string $option Updated option name. Unused; we key on $_POST['option_page'].
 */
function wp_presence_on_updated_option( $option ) {
	unset( $option );
	if ( ! wp_presence_is_admin_screen_save() ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php verifies its own nonce; we only read $_POST to identify the originating screen.
	$option_page = isset( $_POST['option_page'] ) ? sanitize_key( wp_unslash( $_POST['option_page'] ) ) : '';
	if ( ! $option_page ) {
		return;
	}
	static $bumped_option_pages = array();
	if ( isset( $bumped_option_pages[ $option_page ] ) ) {
		return;
	}
	$bumped_option_pages[ $option_page ] = true;
	wp_presence_bump_screen_revision( 'options/' . $option_page );
}

/**
 * Bumps a post's screen revision when the post is updated.
 *
 * @param int     $post_id     Post ID.
 * @param WP_Post $post_after  Post after update.
 * @param WP_Post $post_before Post before update. Unused.
 */
function wp_presence_on_post_updated( $post_id, $post_after, $post_before ) {
	unset( $post_before );
	if ( ! wp_presence_is_admin_screen_save() ) {
		return;
	}
	if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
		return;
	}
	if ( isset( $post_after->post_status ) && 'auto-draft' === $post_after->post_status ) {
		return;
	}
	wp_presence_bump_screen_revision( 'post/' . (int) $post_id );
}

/**
 * Bumps a user-edit screen's revision when the user is updated.
 *
 * @param int $user_id User ID.
 */
function wp_presence_on_profile_update( $user_id ) {
	if ( ! wp_presence_is_admin_screen_save() ) {
		return;
	}
	wp_presence_bump_screen_revision( 'user-edit/' . (int) $user_id );
}

/**
 * Bumps a term-edit screen's revision when the term is updated.
 *
 * @param int    $term_id  Term ID.
 * @param int    $tt_id    Term taxonomy ID. Unused.
 * @param string $taxonomy Taxonomy slug.
 */
function wp_presence_on_edited_term( $term_id, $tt_id, $taxonomy ) {
	unset( $tt_id );
	if ( ! wp_presence_is_admin_screen_save() ) {
		return;
	}
	wp_presence_bump_screen_revision( 'term/' . sanitize_key( $taxonomy ) . '/' . (int) $term_id );
}

/**
 * Bumps a comment-edit screen's revision when the comment is updated.
 *
 * @param int $comment_id Comment ID.
 */
function wp_presence_on_edit_comment( $comment_id ) {
	if ( ! wp_presence_is_admin_screen_save() ) {
		return;
	}
	wp_presence_bump_screen_revision( 'comment/' . (int) $comment_id );
}

/**
 * Returns the current revision for the screen the client claims to be on.
 *
 * @param array  $response  Heartbeat response.
 * @param array  $data      $_POST data.
 * @param string $screen_id Heartbeat screen id. Unused.
 * @return array
 */
function wp_presence_screen_heartbeat_received( $response, $data, $screen_id ) {
	unset( $screen_id );
	if ( empty( $data['presence-screen-ping']['key'] ) ) {
		return $response;
	}
	if ( ! current_user_can( 'edit_posts' ) ) {
		return $response;
	}
	// Cap key length to the InnoDB index limit so we can't be made to do
	// pointless work by clients pinging with megabyte-long keys.
	$key   = substr( (string) $data['presence-screen-ping']['key'], 0, 191 );
	$entry = wp_presence_get_screen_revision( $key );
	if ( ! $entry ) {
		return $response;
	}
	$actor_id   = (int) $entry['actor_id'];
	$actor_time = (int) $entry['time'];
	// Resolve display name and avatar fresh on every tick so renames and
	// avatar changes show immediately instead of carrying stale data from
	// the moment of the bump.
	$user       = $actor_id ? get_userdata( $actor_id ) : null;
	$actor_name = $user ? (string) $user->display_name : '';
	$avatar_url = $user ? (string) get_avatar_url( $actor_id, array( 'size' => 48 ) ) : '';
	$time_ago   = $actor_time
		? sprintf(
			/* translators: %s: human-readable time difference like "2 minutes". */
			__( '%s ago', 'default' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Intentionally reuses core's "%s ago" string so we inherit its translation for every locale.
			human_time_diff( $actor_time )
		)
		: '';

	$response['presence-screen-rev'] = array(
		'key'              => $key,
		'rev'              => (int) $entry['rev'],
		'actor_id'         => $actor_id,
		'actor_name'       => $actor_name,
		'actor_avatar_url' => $avatar_url,
		'time'             => $actor_time,
		'time_ago'         => $time_ago,
		// Only treat the viewer as the actor when both sides are a real user;
		// `0 === 0` would otherwise advance the baseline for anonymous bumps.
		'actor_is_me'      => $actor_id > 0 && get_current_user_id() === $actor_id,
	);
	return $response;
}

/**
 * Enqueues the stale-screen banner script on screens we cover.
 */
function wp_presence_enqueue_stale_screen_banner() {
	if ( ! is_admin() ) {
		return;
	}
	if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	$screen_key = wp_presence_current_screen_key();
	if ( '' === $screen_key ) {
		return;
	}

	$entry        = wp_presence_get_screen_revision( $screen_key );
	$baseline_rev = $entry ? (int) $entry['rev'] : 0;

	wp_enqueue_style(
		'wp-presence-stale-screen',
		WP_PRESENCE_PLUGIN_URL . 'assets/css/stale-screen.css',
		array(),
		WP_PRESENCE_VERSION
	);

	wp_enqueue_script(
		'wp-presence-stale-screen',
		WP_PRESENCE_PLUGIN_URL . 'assets/js/stale-screen.js',
		array( 'jquery', 'heartbeat' ),
		WP_PRESENCE_VERSION,
		true
	);

	$config = array(
		'screenKey'   => $screen_key,
		'baselineRev' => $baseline_rev,
		'strings'     => array(
			/* translators: 1: display name, 2: relative time like "2 minutes ago". */
			'updatedBy'          => __( '%1$s updated this screen %2$s.', 'presence-api' ),
			/* translators: %s: relative time like "2 minutes ago". */
			'updatedAnonymously' => __( 'This screen was updated %s.', 'presence-api' ),
			'reload'             => __( 'Reload', 'presence-api' ),
			'dismiss'            => __( 'Dismiss this notice.', 'presence-api' ),
		),
	);

	wp_add_inline_script(
		'wp-presence-stale-screen',
		sprintf( 'window.wpPresenceStaleScreen = %s;', wp_json_encode( $config, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ) ),
		'before'
	);
}
