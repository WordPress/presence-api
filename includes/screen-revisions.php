<?php
/**
 * Stale-screen detection: alert users when an admin screen they're viewing is out of date.
 *
 * When a user saves a Settings page, a post, a user, a term, or a comment, a
 * per-screen revision is recorded. Other users currently viewing the same
 * screen receive the new revision on the next Heartbeat tick and render a
 * non-blocking notice prompting them to reload.
 *
 * Coverage: classic admin screens that submit via POST and redirect on
 * success — Settings → General/Writing/Reading/Discussion/Media/Permalinks/Privacy,
 * Settings API pages whose menu slug is their option group, post edits
 * (post.php), user edits (user-edit.php, profile.php), term edits
 * (edit-tags.php), comment edits (comment.php), and on multisite Network
 * Settings and the Edit Site tabs. JS-driven and REST-driven
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

if ( ! defined( 'WP_PRESENCE_SCREEN_KEY_LIMIT' ) ) {
	define( 'WP_PRESENCE_SCREEN_KEY_LIMIT', 191 );
}

/**
 * Returns the full screen-revision map for keys with no dedicated storage.
 *
 * Shape: array( '<screen_key>' => array( 'rev' => int, 'actor_id' => int, 'time' => int ) ).
 * The actor's display name and avatar are looked up fresh on each heartbeat
 * tick — not stored — so renames and avatar changes show immediately.
 *
 * Only screen keys that don't map to a post, user, term, comment, or a known
 * Settings page land here — see wp_presence_parse_screen_key_target(). Those
 * write straight to the object they describe instead of this shared option,
 * so a save on one screen doesn't invalidate the cache entry for every other
 * screen in the map.
 *
 * @since 0.1.3
 *
 * @return array
 */
function wp_presence_get_screen_revisions() {
	$map = get_option( 'wp_presence_screen_revisions', array() );
	return is_array( $map ) ? $map : array();
}

/**
 * Returns the network-wide screen-revision map.
 *
 * Holds the `network/` keys for Network Settings and the Edit Site tabs. It is
 * a site option rather than a per-site one because core fires some of their
 * save hooks while switched to the site being edited, which would otherwise
 * write the revision to that site's options instead of where Network Admin
 * reads it.
 *
 * @since 0.10.0
 * @access private
 *
 * @return array Same shape as wp_presence_get_screen_revisions().
 */
function wp_presence_get_network_screen_revisions() {
	$map = get_site_option( 'wp_presence_network_screen_revisions', array() );
	return is_array( $map ) ? $map : array();
}

/**
 * Returns the Settings pages that get their own dedicated revision option.
 *
 * Matches the switch in wp_presence_current_screen_key(): core's own
 * $allowed_options gate in wp-admin/options.php, plus the Privacy page,
 * which saves through its own form. Anything outside this list
 * still matches the `options/` prefix but is treated as a custom key instead,
 * so an arbitrary options/{page} value from the REST endpoint can't create an
 * unbounded number of option rows.
 *
 * @since 0.1.20
 *
 * @return string[]
 */
function wp_presence_known_options_pages() {
	return array( 'general', 'writing', 'reading', 'discussion', 'media', 'permalink', 'privacy' );
}

/**
 * Resolves what a screen key's revision is stored on.
 *
 * @since 0.1.20
 *
 * @param string $screen_key Normalized screen key.
 * @return array {
 *     @type string $type     One of 'post', 'user', 'term', 'comment', 'options', 'network', 'custom'.
 *     @type int    $id       Object ID. Present for 'post', 'user', 'term', 'comment'.
 *     @type string $taxonomy Taxonomy slug. Present for 'term'.
 *     @type string $page     Settings page slug. Present for 'options'.
 * }
 */
function wp_presence_parse_screen_key_target( $screen_key ) {
	if ( preg_match( '#^post/(\d+)$#', $screen_key, $m ) ) {
		return array(
			'type' => 'post',
			'id'   => (int) $m[1],
		);
	}
	if ( preg_match( '#^user-edit/(\d+)$#', $screen_key, $m ) ) {
		return array(
			'type' => 'user',
			'id'   => (int) $m[1],
		);
	}
	if ( preg_match( '#^term/([^/]+)/(\d+)$#', $screen_key, $m ) ) {
		return array(
			'type'     => 'term',
			'taxonomy' => $m[1],
			'id'       => (int) $m[2],
		);
	}
	if ( preg_match( '#^comment/(\d+)$#', $screen_key, $m ) ) {
		return array(
			'type' => 'comment',
			'id'   => (int) $m[1],
		);
	}
	if ( 0 === strpos( $screen_key, 'options/' ) ) {
		$page = substr( $screen_key, strlen( 'options/' ) );
		if ( in_array( $page, wp_presence_known_options_pages(), true ) ) {
			return array(
				'type' => 'options',
				'page' => $page,
			);
		}
	}

	if ( 0 === strpos( $screen_key, 'network/' ) ) {
		return array( 'type' => 'network' );
	}

	return array( 'type' => 'custom' );
}

/**
 * Returns the revision entry for a single screen, or null if none.
 *
 * @since 0.1.3
 *
 * @param string $screen_key Screen key to look up.
 * @return array|null
 */
function wp_presence_get_screen_revision( $screen_key ) {
	global $wpdb;

	$screen_key = wp_presence_normalize_screen_key( $screen_key );
	if ( '' === $screen_key ) {
		return null;
	}

	$target = wp_presence_parse_screen_key_target( $screen_key );

	switch ( $target['type'] ) {
		case 'post':
			// get_post() + get_post_meta() would be free here if the post and
			// its meta were already cached, but a Heartbeat tick is its own
			// request with a cold cache, so that's two queries where the old
			// shared option cost one. A single joined query keeps this to
			// one query in the case that actually happens on every tick, at
			// the cost of one query instead of a cache hit on the rare path
			// where wp_presence_on_post_updated() reads this back right
			// after its own save.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT p.post_modified_gmt, pm.meta_value AS edit_last
					FROM {$wpdb->posts} p
					LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_edit_last'
					WHERE p.ID = %d
					LIMIT 1",
					$target['id']
				)
			);
			if ( ! $row ) {
				return null;
			}
			// mysql2date( 'U', ... ) adds the site's timezone offset even when
			// $translate is false — core's own get_post_time() skips that add
			// only for its $gmt branch. post_modified_gmt is already a naive
			// UTC string, and WordPress forces PHP's default timezone to UTC
			// at bootstrap, so a plain strtotime() is the correct conversion.
			$revision = (int) strtotime( $row->post_modified_gmt );
			return array(
				'rev'      => $revision,
				'actor_id' => (int) $row->edit_last,
				'time'     => $revision,
			);

		case 'user':
		case 'term':
		case 'comment':
			$entry = get_metadata( $target['type'], $target['id'], '_wp_presence_screen_rev', true );
			return $entry ? $entry : null;

		case 'options':
			$entry = get_option( 'wp_presence_screen_rev_options_' . $target['page'], null );
			return $entry ? $entry : null;

		case 'network':
			$map = wp_presence_get_network_screen_revisions();
			return isset( $map[ $screen_key ] ) ? $map[ $screen_key ] : null;

		default:
			$map = wp_presence_get_screen_revisions();
			return isset( $map[ $screen_key ] ) ? $map[ $screen_key ] : null;
	}
}

/**
 * Advances one key in a bounded screen-revision map.
 *
 * @since 0.10.0
 * @access private
 *
 * @param array  $map        Map from wp_presence_get_screen_revisions() or
 *                           wp_presence_get_network_screen_revisions().
 * @param string $screen_key Normalized screen key.
 * @param int    $actor_id   User who triggered the bump.
 * @return array The updated map. The new revision is in `$map[ $screen_key ]['rev']`.
 */
function wp_presence_advance_screen_revision_map( $map, $screen_key, $actor_id ) {
	$previous = isset( $map[ $screen_key ]['rev'] ) ? (int) $map[ $screen_key ]['rev'] : 0;

	$map[ $screen_key ] = array(
		'rev'      => $previous + 1,
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

	return $map;
}

/**
 * Normalizes a screen key for storage and lookup.
 *
 * @since 0.1.3
 *
 * @param string $screen_key Screen key to normalize.
 * @return string
 */
function wp_presence_normalize_screen_key( $screen_key ) {
	return substr( (string) $screen_key, 0, WP_PRESENCE_SCREEN_KEY_LIMIT );
}

/**
 * Records a screen's revision and actor.
 *
 * Posts, users, terms, comments, and the six known Settings pages each write
 * straight to the object (or their own option) they describe, with no prior
 * read — the revision is the current time, not an incremented counter, so
 * there's nothing to read-modify-write and nothing for two overlapping saves
 * to race over. Posts write nothing at all: post_modified_gmt and _edit_last
 * are already set by core on the same save.
 *
 * Anything else falls back to a shared, size-bounded option keyed by screen
 * key, the same storage this function used for every key before this split.
 * `network/` keys use a network-wide equivalent.
 *
 * @since 0.1.3
 *
 * @param string $screen_key Screen key to bump.
 * @param int    $actor_id   Optional. Defaults to the current user.
 * @return int|false New revision (a Unix timestamp for dedicated storage, an
 *                    incrementing counter for the shared-option fallback), or
 *                    false when the key is empty.
 */
function wp_presence_bump_screen_revision( $screen_key, $actor_id = 0 ) {
	$screen_key = wp_presence_normalize_screen_key( $screen_key );
	if ( '' === $screen_key ) {
		return false;
	}
	if ( ! $actor_id ) {
		$actor_id = get_current_user_id();
	}

	$target = wp_presence_parse_screen_key_target( $screen_key );

	switch ( $target['type'] ) {
		case 'post':
			// Nothing to write. Read back what core already recorded on this save.
			$entry    = wp_presence_get_screen_revision( $screen_key );
			$revision = $entry ? $entry['rev'] : time();
			break;

		case 'user':
			$revision = time();
			update_user_meta(
				$target['id'],
				'_wp_presence_screen_rev',
				array(
					'rev'      => $revision,
					'actor_id' => (int) $actor_id,
					'time'     => $revision,
				)
			);
			break;

		case 'term':
			$revision = time();
			update_term_meta(
				$target['id'],
				'_wp_presence_screen_rev',
				array(
					'rev'      => $revision,
					'actor_id' => (int) $actor_id,
					'time'     => $revision,
				)
			);
			break;

		case 'comment':
			$revision = time();
			update_comment_meta(
				$target['id'],
				'_wp_presence_screen_rev',
				array(
					'rev'      => $revision,
					'actor_id' => (int) $actor_id,
					'time'     => $revision,
				)
			);
			break;

		case 'options':
			$revision = time();
			update_option(
				'wp_presence_screen_rev_options_' . $target['page'],
				array(
					'rev'      => $revision,
					'actor_id' => (int) $actor_id,
					'time'     => $revision,
				),
				false
			);
			break;

		case 'network':
			$map      = wp_presence_advance_screen_revision_map( wp_presence_get_network_screen_revisions(), $screen_key, $actor_id );
			$revision = $map[ $screen_key ]['rev'];
			update_site_option( 'wp_presence_network_screen_revisions', $map );
			break;

		default:
			$map      = wp_presence_advance_screen_revision_map( wp_presence_get_screen_revisions(), $screen_key, $actor_id );
			$revision = $map[ $screen_key ]['rev'];
			update_option( 'wp_presence_screen_revisions', $map, false );
			break;
	}

	/**
	 * Fires after a screen revision is bumped.
	 *
	 * @since 0.1.3
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
 * @since 0.1.3
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
 * @since 0.1.3
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

		case 'options-privacy':
			$key = 'options/privacy';
			break;

		case 'settings-network':
			$key = 'network/settings';
			break;

		case 'site-info-network':
		case 'site-users-network':
		case 'site-themes-network':
		case 'site-settings-network':
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen identification.
			$site_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
			if ( $site_id ) {
				$key = 'network/' . substr( $screen->base, 0, - strlen( '-network' ) ) . '/' . $site_id;
			}
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

		default:
			$group = wp_presence_settings_page_option_group( $screen->base );
			if ( $group ) {
				// Sanitized the same way wp_presence_on_updated_option() reads option_page.
				$key = 'options/' . sanitize_key( $group );
			}
			break;
	}

	/**
	 * Filters the screen key used to track stale-screen state.
	 *
	 * Return a non-empty string to opt a custom screen into stale-screen detection.
	 *
	 * @since 0.1.3
	 *
	 * @param string    $key    Computed screen key, or '' when none applies.
	 * @param WP_Screen $screen Current screen.
	 */
	$key = (string) apply_filters( 'wp_presence_current_screen_key', $key, $screen );
	return '' === $key ? '' : wp_presence_normalize_screen_key( $key );
}

/**
 * Returns the option group a Settings API page saves, or '' if it isn't one.
 *
 * A page is recognized when its menu slug is also a registered option group,
 * the convention core's own Settings API examples follow. Nothing records
 * which group a page's form submits until settings_fields() prints it, so a
 * page that uses a different slug can opt in through the
 * `wp_presence_current_screen_key` filter instead.
 *
 * @since 0.10.0
 * @access private
 *
 * @param string $screen_base Screen base, e.g. `settings_page_my-plugin`.
 * @return string
 */
function wp_presence_settings_page_option_group( $screen_base ) {
	foreach ( array( 'settings_page_', 'toplevel_page_' ) as $prefix ) {
		if ( 0 !== strpos( $screen_base, $prefix ) ) {
			continue;
		}
		$slug   = substr( $screen_base, strlen( $prefix ) );
		$groups = wp_list_pluck( get_registered_settings(), 'group' );
		return in_array( $slug, $groups, true ) ? $slug : '';
	}
	return '';
}

/**
 * Bumps the Settings screen's revision when its option_page is saved.
 *
 * @since 0.1.3
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
 * @since 0.1.3
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
 * @since 0.1.3
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
 * @since 0.1.3
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
 * @since 0.1.3
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
 * Bumps the Privacy settings screen's revision when its page is changed.
 *
 * The Privacy page saves through its own form rather than options.php, so
 * there is no option_page for wp_presence_on_updated_option() to read.
 *
 * @since 0.10.0
 */
function wp_presence_on_privacy_policy_page_updated() {
	if ( ! wp_presence_is_admin_screen_save() ) {
		return;
	}
	wp_presence_bump_screen_revision( 'options/privacy' );
}

/**
 * Bumps the Network Settings screen's revision when it is saved.
 *
 * @since 0.10.0
 */
function wp_presence_on_update_network_options() {
	if ( ! wp_presence_is_admin_screen_save() ) {
		return;
	}
	wp_presence_bump_screen_revision( 'network/settings' );
}

/**
 * Bumps an Edit Site → Info screen's revision when the site is updated.
 *
 * @since 0.10.0
 *
 * @param WP_Site $new_site Site after the update.
 */
function wp_presence_on_update_site( $new_site ) {
	if ( ! wp_presence_is_admin_screen_save() ) {
		return;
	}
	wp_presence_bump_screen_revision( 'network/site-info/' . (int) $new_site->id );
}

/**
 * Bumps an Edit Site → Settings screen's revision when it is saved.
 *
 * @since 0.10.0
 *
 * @param int $site_id Site ID.
 */
function wp_presence_on_update_site_options( $site_id ) {
	if ( ! wp_presence_is_admin_screen_save() ) {
		return;
	}
	wp_presence_bump_screen_revision( 'network/site-settings/' . (int) $site_id );
}

/**
 * Bumps an Edit Site → Themes screen's revision when its allowed themes change.
 *
 * Core's site-themes.php writes `allowedthemes` while switched to the site, so the
 * current blog is the site being edited.
 *
 * @since 0.10.0
 */
function wp_presence_on_site_allowed_themes_updated() {
	if ( ! wp_presence_is_admin_screen_save() ) {
		return;
	}
	wp_presence_bump_screen_revision( 'network/site-themes/' . get_current_blog_id() );
}

/**
 * Bumps an Edit Site → Users screen's revision when the site's users change.
 *
 * Hooked to add_user_to_blog and remove_user_from_blog, which pass the site ID,
 * and set_user_role, which runs while switched to the site.
 *
 * @since 0.10.0
 *
 * @param int        $user_id User ID. Unused.
 * @param int|string $arg2    Site ID for remove_user_from_blog, a role otherwise.
 * @param mixed      $arg3    Site ID for add_user_to_blog. Unused otherwise.
 */
function wp_presence_on_site_users_changed( $user_id, $arg2 = null, $arg3 = null ) {
	unset( $user_id );
	if ( ! wp_presence_is_admin_screen_save() ) {
		return;
	}
	if ( 'add_user_to_blog' === current_action() ) {
		$site_id = (int) $arg3;
	} elseif ( 'remove_user_from_blog' === current_action() ) {
		$site_id = (int) $arg2;
	} else {
		$site_id = get_current_blog_id();
	}
	wp_presence_bump_screen_revision( 'network/site-users/' . $site_id );
}

/**
 * Checks if the current user has permission to access or edit a given screen.
 *
 * @since 0.1.7
 *
 * @param string $screen_key Normalized screen key.
 * @return bool
 */
function wp_presence_current_user_can_access_screen( $screen_key ) {
	if ( 'options/privacy' === $screen_key ) {
		if ( ! current_user_can( 'manage_privacy_options' ) ) {
			return false;
		}
	} elseif ( 0 === strpos( $screen_key, 'options/' ) ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
	} elseif ( 0 === strpos( $screen_key, 'network/' ) ) {
		$cap = 'network/settings' === $screen_key ? 'manage_network_options' : 'manage_sites';
		if ( ! is_multisite() || ! current_user_can( $cap ) ) {
			return false;
		}
	} elseif ( preg_match( '#^post/(\d+)$#', $screen_key, $m ) ) {
		if ( ! current_user_can( 'edit_post', (int) $m[1] ) ) {
			return false;
		}
	} elseif ( preg_match( '#^user-edit/(\d+)$#', $screen_key, $m ) ) {
		if ( ! current_user_can( 'edit_user', (int) $m[1] ) ) {
			return false;
		}
	} elseif ( preg_match( '#^term/([^/]+)/(\d+)$#', $screen_key, $m ) ) {
		if ( ! current_user_can( 'edit_term', (int) $m[2], $m[1] ) ) {
			return false;
		}
	} elseif ( preg_match( '#^comment/(\d+)$#', $screen_key, $m ) ) {
		if ( ! current_user_can( 'edit_comment', (int) $m[1] ) ) {
			return false;
		}
	} elseif ( ! current_user_can( 'edit_posts' ) ) {
		return false;
	}

	return true;
}

/**
 * Returns the current revision for the screen the client claims to be on.
 *
 * @since 0.1.3
 *
 * @param array  $response  Heartbeat response.
 * @param array  $data      $_POST data.
 * @param string $screen_id Heartbeat screen id. Unused.
 * @return array
 */
function wp_presence_screen_heartbeat_received( $response, $data, $screen_id ) {
	unset( $screen_id );
	$ping    = $data['presence-screen-ping'] ?? null;
	$raw_key = is_array( $ping ) && array_key_exists( 'key', $ping ) ? $ping['key'] : null;
	if ( ! is_scalar( $raw_key ) || '' === (string) $raw_key ) {
		return $response;
	}
	// Cap key length to the InnoDB index limit so we can't be made to do
	// pointless work by clients pinging with megabyte-long keys.
	$key = wp_presence_normalize_screen_key( $raw_key );

	// Require the viewer to have access to the screen whose revision is being disclosed.
	if ( ! wp_presence_current_user_can_access_screen( $key ) ) {
		return $response;
	}

	$entry = wp_presence_get_screen_revision( $key );
	if ( ! $entry ) {
		return $response;
	}
	$actor_id   = (int) ( $entry['actor_id'] ?? 0 );
	$actor_time = (int) ( $entry['time'] ?? 0 );
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
			human_time_diff( $actor_time, time() )
		)
		: '';

	$response['presence-screen-rev'] = array(
		'key'              => $key,
		'rev'              => isset( $entry['rev'] ) ? (int) $entry['rev'] : 0,
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
 *
 * @since 0.1.3
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
		'wp-presence-tab-coordinator',
		WP_PRESENCE_PLUGIN_URL . 'assets/js/tab-coordinator.js',
		array( 'jquery' ),
		WP_PRESENCE_VERSION,
		true
	);

	wp_enqueue_script(
		'wp-presence-stale-screen',
		WP_PRESENCE_PLUGIN_URL . 'assets/js/stale-screen.js',
		array( 'jquery', 'heartbeat', 'wp-presence-tab-coordinator' ),
		WP_PRESENCE_VERSION,
		true
	);

	$config = array(
		'screenKey'   => $screen_key,
		'baselineRev' => $baseline_rev,
		'restUrl'     => esc_url_raw( rest_url( 'wp-presence/v1/presence/screen-revisions/stale' ) ),
		'nonce'       => wp_create_nonce( 'wp_rest' ),
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
