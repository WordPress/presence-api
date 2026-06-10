<?php
/**
 * Stale-screen detection demo helper.
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
			<?php esc_html_e( 'pretend another user just saved this screen — the stale-screen notice should appear within a few seconds.', 'presence-api' ); ?>
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
	wp_presence_bump_screen_revision( $key, $actor );
	wp_send_json_success();
}
add_action( 'wp_ajax_presence_demo_bump_screen', 'presence_demo_handle_bump' );
