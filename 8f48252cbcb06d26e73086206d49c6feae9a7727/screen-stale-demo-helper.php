<?php
/**
 * Playground demo helpers for stale-screen detection and post locks.
 *
 * Loaded as a must-use plugin by the Playground demo blueprint. Renders
 * a "pretend another user just saved this screen" button bar on every
 * covered admin screen so a single-session Playground viewer can see
 * the stale-screen notice fire as if another user had saved.
 *
 * This file ships only with the Playground demo blueprint; it is not
 * part of the plugin itself.
 *
 * @package Presence_API_Demo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const PRESENCE_DEMO_NONCE_ACTION = 'presence_demo_bump_screen';

/**
 * Renders the demo button bar on covered admin screens.
 */
function presence_demo_render_buttons() {
	if ( ! function_exists( 'wp_presence_current_screen_key' ) ) {
		return;
	}
	$key = wp_presence_current_screen_key();
	if ( '' === $key ) {
		return;
	}
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	$me     = get_current_user_id();
	$others = get_users(
		array(
			'exclude' => array( $me ),
			'number'  => 4,
			'orderby' => 'ID',
			'fields'  => array( 'ID', 'display_name' ),
		)
	);
	if ( ! $others ) {
		return;
	}

	$nonce    = wp_create_nonce( PRESENCE_DEMO_NONCE_ACTION );
	$ajax_url = admin_url( 'admin-ajax.php' );
	?>
	<div class="notice notice-info is-dismissible">
		<p>
			<strong><?php esc_html_e( 'Demo:', 'presence-api' ); ?></strong>
			<?php esc_html_e( 'simulate another user saving this screen. The stale notice will appear within seconds.', 'presence-api' ); ?>
		</p>
		<p>
			<?php foreach ( $others as $user ) : ?>
				<button
					type="button"
					class="button"
					data-presence-demo-bump
					data-screen-key="<?php echo esc_attr( $key ); ?>"
					data-actor-id="<?php echo esc_attr( (string) $user->ID ); ?>"
				>
					<?php
					/* translators: %s: demo user's display name. */
					printf( esc_html__( 'Save as %s', 'presence-api' ), esc_html( $user->display_name ) );
					?>
				</button>
			<?php endforeach; ?>
		</p>
	</div>
	<script>
	(function () {
		const ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;
		const nonce   = <?php echo wp_json_encode( $nonce ); ?>;
		document.querySelectorAll('[data-presence-demo-bump]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				const body = new FormData();
				body.append('action', 'presence_demo_bump_screen');
				body.append('screen_key', btn.dataset.screenKey);
				body.append('actor_id', btn.dataset.actorId);
				body.append('_wpnonce', nonce);
				btn.disabled = true;
				btn.textContent = '✓ ' + btn.textContent;
				fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
					.then(function () {
						if (window.wp && window.wp.heartbeat && typeof window.wp.heartbeat.connectNow === 'function') {
							window.wp.heartbeat.connectNow();
						}
					});
			});
		});
	})();
	</script>
	<?php
}
add_action( 'admin_notices', 'presence_demo_render_buttons' );

/**
 * AJAX: bump the current screen's revision with a chosen seeded user as actor.
 */
function presence_demo_handle_bump() {
	if ( ! function_exists( 'wp_presence_bump_screen_revision' ) ) {
		wp_send_json_error( array( 'reason' => 'plugin-missing' ), 500 );
	}
	check_ajax_referer( PRESENCE_DEMO_NONCE_ACTION );
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'reason' => 'forbidden' ), 403 );
	}
	$key   = isset( $_POST['screen_key'] ) ? sanitize_text_field( wp_unslash( $_POST['screen_key'] ) ) : '';
	$actor = isset( $_POST['actor_id'] ) ? absint( $_POST['actor_id'] ) : 0;
	if ( '' === $key || ! $actor ) {
		wp_send_json_error( array( 'reason' => 'bad-input' ), 400 );
	}

	// Post screens require an actual save to bump the revision.
	if ( preg_match( '#^post/(\d+)$#', $key, $matches ) ) {
		$post_id = (int) $matches[1];
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'reason' => 'forbidden' ), 403 );
		}
		$original_user = get_current_user_id();
		wp_set_current_user( $actor );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => get_post_field( 'post_content', $post_id ),
			)
		);
		wp_set_current_user( $original_user );
	}

	wp_presence_bump_screen_revision( $key, $actor );
	wp_send_json_success();
}
add_action( 'wp_ajax_presence_demo_bump_screen', 'presence_demo_handle_bump' );

/**
 * Refreshes the demo post locks before core's lock window expires them.
 */
function presence_demo_refresh_locks() {
	$seeder = WP_PLUGIN_DIR . '/presence-api/demo-seeder.php';
	if ( ! function_exists( 'wp_presence_post_room' ) || ! file_exists( $seeder ) ) {
		return;
	}
	require_once $seeder;
	if ( ! get_option( 'presence_demo_rtc_post' ) ) {
		wp_presence_demo_seed_locks();
	}
}
add_action( 'admin_init', 'presence_demo_refresh_locks' );

/**
 * Keeps the RTC playground's collaborators in their post, and opens it from `?presence_demo=rtc`.
 */
function presence_demo_refresh_collaborators() {
	$post_id = (int) get_option( 'presence_demo_rtc_post' );
	$seeder  = WP_PLUGIN_DIR . '/presence-api/demo-seeder.php';
	if ( ! $post_id || ! function_exists( 'wp_presence_post_room' ) || ! file_exists( $seeder ) ) {
		return;
	}
	require_once $seeder;
	wp_presence_demo_seed_collaborators( $post_id );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( is_admin() && isset( $_GET['presence_demo'] ) && current_user_can( 'edit_post', $post_id ) ) {
		wp_safe_redirect( get_edit_post_link( $post_id, 'raw' ) );
		exit;
	}
}
add_action( 'wp_loaded', 'presence_demo_refresh_collaborators' );

/**
 * Keeps the synced pattern playground's editor in the pattern, and opens the post that uses it from `?presence_demo=pattern`.
 */
function presence_demo_refresh_pattern_editor() {
	$seeded = get_option( 'presence_demo_pattern' );
	$seeder = WP_PLUGIN_DIR . '/presence-api/demo-seeder.php';
	if ( empty( $seeded['post'] ) || ! function_exists( 'wp_presence_post_room' ) || ! file_exists( $seeder ) ) {
		return;
	}
	require_once $seeder;
	wp_presence_demo_seed_pattern_editor( (int) $seeded['pattern'] );
	$post_id = (int) $seeded['post'];

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( is_admin() && isset( $_GET['presence_demo'] ) && 'pattern' === $_GET['presence_demo'] && current_user_can( 'edit_post', $post_id ) ) {
		wp_safe_redirect( get_edit_post_link( $post_id, 'raw' ) );
		exit;
	}
}
add_action( 'wp_loaded', 'presence_demo_refresh_pattern_editor' );
