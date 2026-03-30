<?php
/**
 * Plugin Name: Presence API
 * Description: Standalone presence/awareness API for WordPress with REST endpoints, dashboard widget, and post-lock bridge.
 * Version: 0.4.0
 * Requires at least: 7.0
 * Requires PHP: 7.4
 * Author: Joe Fusco
 * Text Domain: presence-api
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Feature plugin: enforce minimum WordPress version at runtime.
global $wp_version;
if ( version_compare( $wp_version, '7.0-alpha', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Presence API requires WordPress 7.0 or later.', 'presence-api' );
			echo '</p></div>';
		}
	);
	return;
}

define( 'WP_PRESENCE_VERSION', '0.4.0' );
define( 'WP_PRESENCE_DB_VERSION', '1.4' );
define( 'WP_PRESENCE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
if ( ! defined( 'WP_PRESENCE_DEFAULT_TTL' ) ) {
	define( 'WP_PRESENCE_DEFAULT_TTL', 60 );
}

// Load translations.
add_action(
	'init',
	function () {
		load_plugin_textdomain( 'presence-api', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);

/**
 * Registers the presence table name on $wpdb.
 *
 * @since 7.1.0
 */
function wp_presence_register_table() {
	global $wpdb;
	$wpdb->presence = $wpdb->prefix . 'presence';
	$wpdb->tables[] = 'presence';
}
add_action( 'init', 'wp_presence_register_table', 0 );
// Also call immediately so the table name is available during plugin load.
wp_presence_register_table();

// Core files.
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/functions.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/class-wp-rest-presence-controller.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/post-lock-bridge.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/lifecycle.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/widgets/class-wp-presence-widget-whos-online.php';
require_once WP_PRESENCE_PLUGIN_DIR . 'includes/widgets/class-wp-presence-widget-active-posts.php';

/*
 * Feature plugin shim: ensure the presence table exists.
 *
 * In core, this table would be created by dbDelta() during the database
 * upgrade routine in wp-admin/includes/upgrade-schema.php. This hook is
 * only needed while Presence API ships as a standalone plugin.
 */
add_action( 'admin_init', 'wp_maybe_create_presence_table' );
add_action( 'cli_init', 'wp_maybe_create_presence_table' );

// REST route registration.
add_action( 'rest_api_init', 'wp_presence_register_rest_routes' );

// Cron cleanup.
add_action( 'wp_delete_expired_presence_data', 'wp_delete_expired_presence_data' );

// Schedule cron if not already scheduled (fallback for missed activation).
add_action( 'admin_init', 'wp_presence_schedule_cleanup' );

// Register presence support for core post types.
add_action( 'init', 'wp_presence_register_post_type_support' );

// Enqueue heartbeat presence ping on all admin pages and front-end when admin bar is showing.
add_action( 'admin_enqueue_scripts', 'wp_presence_enqueue_heartbeat_ping' );
add_action( 'wp_enqueue_scripts', 'wp_presence_enqueue_heartbeat_ping' );

// Block editor: send presence via heartbeat when editing a post.
add_action( 'admin_enqueue_scripts', 'wp_presence_enqueue_editor_ping' );
add_filter( 'heartbeat_received', 'wp_presence_editor_heartbeat_received', 10, 3 );

/// User list: online filter view.
add_filter( 'views_users', 'wp_presence_users_views' );
add_action( 'pre_get_users', 'wp_presence_filter_online_users' );

// Post list "Editors" column for post types with presence support.
add_action( 'admin_init', 'wp_presence_register_post_list_columns' );

// Dashboard widget: Who's Online.
add_action( 'wp_dashboard_setup', array( 'WP_Presence_Widget_Whos_Online', 'register' ) );

// Heartbeat handlers for the Who's Online widget.
add_filter( 'heartbeat_received', array( 'WP_Presence_Widget_Whos_Online', 'heartbeat_received' ), 10, 3 );

/* Dashboard widget: Active Posts. */
add_action( 'wp_dashboard_setup', array( 'WP_Presence_Widget_Active_Posts', 'register' ) );

/* Heartbeat handlers for the Active Posts widget. */
add_filter( 'heartbeat_received', array( 'WP_Presence_Widget_Active_Posts', 'heartbeat_received' ), 10, 3 );

/* Dashboard widget: Presence API Debugger (developer tool, requires WP_DEBUG). */
if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
	add_action( 'wp_dashboard_setup', 'wp_presence_heartbeat_widget_register' );
	add_filter( 'heartbeat_received', 'wp_presence_heartbeat_widget_received', 10, 3 );
	require_once WP_PRESENCE_PLUGIN_DIR . 'includes/db-viewer.php';
}

/* WP-CLI commands. */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once WP_PRESENCE_PLUGIN_DIR . 'includes/cli/class-wp-presence-cli-command.php';
	WP_CLI::add_command( 'presence', 'WP_Presence_CLI_Command' );
}

// Post-lock bridge.
add_filter( 'heartbeat_received', 'wp_presence_bridge_post_lock', 11, 3 );

// Login/logout presence.
add_action( 'wp_login', 'wp_presence_on_login', 10, 2 );
add_action( 'wp_logout', 'wp_presence_on_logout' );

// Admin bar presence indicator.
add_action( 'admin_bar_menu', 'wp_presence_admin_bar_node', 80 );
add_action( 'admin_enqueue_scripts', 'wp_presence_admin_bar_assets' );
add_action( 'wp_enqueue_scripts', 'wp_presence_admin_bar_assets' );

/**
 * Registers presence support for core post types.
 *
 * Plugins can opt in their own post types with:
 *     add_post_type_support( 'product', 'presence' );
 *
 * @since 7.1.0
 */
function wp_presence_register_post_type_support() {
	add_post_type_support( 'post', 'presence' );
	add_post_type_support( 'page', 'presence' );
}

/**
 * Enqueues heartbeat and the presence ping script on all admin pages.
 *
 * @since 7.1.0
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

	// On frontend singular views, pass the current post context to the heartbeat ping.
	$front_context = '{}';
	if ( ! is_admin() && is_singular() ) {
		$queried = get_queried_object();
		if ( $queried instanceof WP_Post ) {
			$front_context = wp_json_encode(
				array(
					'postId'   => $queried->ID,
					'postType' => $queried->post_type,
					'title'    => get_the_title( $queried ),
				)
			);
		}
	}

	wp_add_inline_script(
		'heartbeat',
		sprintf(
			'(function($) {
			if (typeof wp === "undefined" || typeof wp.heartbeat === "undefined") { return; }
			var frontContext = %s;
			$(document).on("heartbeat-send", function(event, data) {
				var ping = { screen: window.pagenow || "front" };
				if (frontContext.postId) {
					ping.post_id = frontContext.postId;
					ping.post_type = frontContext.postType;
					ping.title = frontContext.title;
				}
				data["presence-ping"] = ping;
			});
		})(jQuery);',
			$front_context
		)
	);
}

/**
 * Enqueues a heartbeat ping for the block editor that reports which post is being edited.
 *
 * @since 7.1.0
 *
 * @param string $hook_suffix The current admin page.
 */
function wp_presence_enqueue_editor_ping( $hook_suffix ) {
	if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
		return;
	}

	$post = get_post();

	if ( ! $post || ! post_type_supports( $post->post_type, 'presence' ) ) {
		return;
	}

	wp_add_inline_script(
		'heartbeat',
		sprintf(
			'(function($) {
			if (typeof wp === "undefined" || typeof wp.heartbeat === "undefined") { return; }
			$(document).on("heartbeat-send", function(event, data) {
				data["presence-editor-ping"] = { post_id: %d };
			});
		})(jQuery);',
			$post->ID
		)
	);
}

/**
 * Handles the editor presence heartbeat and creates a presence entry for the post being edited.
 *
 * @since 7.1.0
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

/**
 * Registers the presence REST routes.
 *
 * @since 7.1.0
 */
function wp_presence_register_rest_routes() {
	$controller = new WP_REST_Presence_Controller();
	$controller->register_routes();

}

/**
 * Schedules the presence cleanup cron event.
 *
 * @since 7.1.0
 */
function wp_presence_schedule_cleanup() {
	if ( ! wp_next_scheduled( 'wp_delete_expired_presence_data' ) ) {
		wp_schedule_event( time(), 'wp_presence_every_minute', 'wp_delete_expired_presence_data' );
	}
}

/**
 * Adds a custom one-minute cron interval.
 *
 * @since 7.1.0
 *
 * @param array $schedules Existing cron schedules.
 * @return array Modified cron schedules.
 */
function wp_presence_cron_schedules( $schedules ) {
	$schedules['wp_presence_every_minute'] = array(
		'interval' => 60,
		'display'  => __( 'Every Minute (Presence Cleanup)', 'presence-api' ),
	);
	return $schedules;
}
// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- 60-second interval is intentional for presence cleanup.
add_filter( 'cron_schedules', 'wp_presence_cron_schedules' );

// Activation hook for cron scheduling.
register_activation_hook( __FILE__, 'wp_presence_activate' );

/**
 * Handles plugin activation.
 *
 * @since 7.1.0
 */
function wp_presence_activate() {
	wp_maybe_create_presence_table();
	wp_presence_schedule_cleanup();
}

// Cleanup on deactivation.
register_deactivation_hook( __FILE__, 'wp_presence_deactivate' );

/**
 * Cleans up on plugin deactivation.
 *
 * @since 7.1.0
 */
function wp_presence_deactivate() {
	wp_clear_scheduled_hook( 'wp_delete_expired_presence_data' );
}


/**
 * Registers the Heartbeat dashboard widget.
 *
 * @since 7.1.0
 */
function wp_presence_heartbeat_widget_register() {
	wp_add_dashboard_widget(
		'presence_heartbeat',
		__( 'Presence API Debugger', 'presence-api' ),
		'wp_presence_heartbeat_widget_render',
		null,
		null,
		'side',
		'high'
	);

	// Hidden by default — discoverable via Screen Options.
	add_filter(
		'default_hidden_meta_boxes',
		static function ( $hidden ) {
			$hidden[] = 'presence_heartbeat';
			return $hidden;
		}
	);

	add_action( 'admin_enqueue_scripts', 'wp_presence_heartbeat_widget_assets' );
}

/**
 * Renders the Heartbeat dashboard widget.
 *
 * @since 7.1.0
 */
function wp_presence_heartbeat_widget_render() {
	$summary = wp_get_presence_summary();

	// The current user may not have a presence entry yet (first page load before heartbeat fires).
	// Ensure the count reflects at least the viewing user.
	if ( $summary['total_users'] < 1 && is_user_logged_in() ) {
		$summary['total_users'] = 1;
	}
	?>
	<?php $ttl = wp_presence_get_timeout( WP_PRESENCE_DEFAULT_TTL ); ?>
	<div id="presence-heartbeat-widget">
		<div class="presence-debugger-header">
			<div class="presence-heartbeat-pulse">
				<span class="presence-heartbeat-ripple"></span>
				<span class="presence-heartbeat-ripple presence-heartbeat-ripple--delayed"></span>
				<span class="dashicons dashicons-heart"></span>
			</div>
			<div class="presence-debugger-info">
				<span class="presence-heartbeat-users"><?php echo esc_html( $summary['total_users'] ); ?></span>
				<span class="presence-heartbeat-users-label"><?php echo esc_html( _n( 'user', 'users', $summary['total_users'], 'presence-api' ) ); ?></span>
				&middot;
				<span class="presence-heartbeat-last-beat">&mdash;</span>
				<span class="presence-heartbeat-last-beat-label"><?php esc_html_e( 'last beat', 'presence-api' ); ?></span>
				&middot;
				<span class="presence-heartbeat-ttl-value"><?php echo esc_html( $ttl ); ?>s</span>
				<span class="presence-heartbeat-ttl-label"><?php esc_html_e( 'TTL', 'presence-api' ); ?></span>
				<span class="presence-heartbeat-timing"></span>
			</div>
		</div>
		<div class="presence-heartbeat-rooms-list">
			<?php if ( count( $summary['by_prefix'] ) > 1 ) : ?>
				<?php foreach ( $summary['by_prefix'] as $prefix => $info ) : ?>
					<div class="presence-heartbeat-room-row">
						<code><?php echo esc_html( $prefix . '/*' ); ?></code>
						<span><?php echo esc_html( $info['entries'] . ' ' . _n( 'entry', 'entries', $info['entries'], 'presence-api' ) . ', ' . $info['users'] . ' ' . _n( 'user', 'users', $info['users'], 'presence-api' ) ); ?></span>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<div class="presence-debugger-table">
			<iframe src="<?php echo esc_url( wp_nonce_url( home_url( '/?presence-db=1' ), 'wp_presence_db_viewer' ) ); ?>" title="<?php esc_attr_e( 'wp_presence table', 'presence-api' ); ?>"></iframe>
		</div>
	</div>
	<?php
}

/**
 * Handles the heartbeat received event for the Heartbeat widget.
 *
 * Returns the site-wide presence summary so the widget can update
 * its user and room counts on each tick.
 *
 * @since 7.1.0
 *
 * @param array  $response  The Heartbeat response.
 * @param array  $data      The $_POST data sent.
 * @param string $screen_id The screen ID.
 * @return array The Heartbeat response.
 */
function wp_presence_heartbeat_widget_received( $response, $data, $screen_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by filter signature.
	if ( empty( $data['presence-ping'] ) ) {
		return $response;
	}

	$start   = microtime( true );
	$summary = wp_get_presence_summary();
	$query_ms = round( ( microtime( true ) - $start ) * 1000, 1 );

	$response['presence-heartbeat-users']    = $summary['total_users'];
	$response['presence-heartbeat-entries']  = $summary['total_entries'];
	$response['presence-heartbeat-query-ms'] = $query_ms;
	$response['presence-heartbeat-ttl']      = wp_presence_get_timeout( WP_PRESENCE_DEFAULT_TTL );

	$room_list = array();
	foreach ( $summary['by_prefix'] as $prefix => $info ) {
		$room_list[] = array(
			'prefix'  => $prefix . '/*',
			'entries' => $info['entries'],
			'users'   => $info['users'],
		);
	}
	$response['presence-heartbeat-room-list'] = $room_list;


	return $response;
}

/**
 * Enqueues assets for the Heartbeat dashboard widget.
 *
 * @since 7.1.0
 *
 * @param string $hook_suffix The current admin page.
 */
function wp_presence_heartbeat_widget_assets( $hook_suffix ) {
	if ( 'index.php' !== $hook_suffix ) {
		return;
	}

	$css = '
		#presence-heartbeat-widget .presence-debugger-header { display: flex; align-items: center; gap: 10px; padding: 10px 12px; }
		#presence-heartbeat-widget .presence-heartbeat-pulse { position: relative; display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 50%; flex-shrink: 0; overflow: hidden; }
		#presence-heartbeat-widget .presence-heartbeat-ripple { position: absolute; inset: 0; border-radius: 50%; background: radial-gradient(circle, transparent 45%, rgba(214, 54, 56, 0.15) 60%, transparent 75%); opacity: 0; pointer-events: none; transform-origin: center; }
		#presence-heartbeat-widget .presence-heartbeat-pulse .dashicons { position: relative; font-size: 22px; width: 22px; height: 22px; color: #d63638; z-index: 1; transition: color 0.8s ease; }
		#presence-heartbeat-widget .presence-heartbeat-pulse.is-beating .dashicons { animation: presence-heart 2.0s cubic-bezier(0.22, 0.61, 0.36, 1); }
		#presence-heartbeat-widget .presence-heartbeat-pulse.is-beating .presence-heartbeat-ripple { animation: presence-ripple 3.0s cubic-bezier(0.0, 0.0, 0.15, 1) forwards; }
		#presence-heartbeat-widget .presence-heartbeat-pulse.is-beating .presence-heartbeat-ripple--delayed { animation-delay: 0.35s; animation-duration: 3.4s; }
		#presence-heartbeat-widget .presence-heartbeat-pulse.is-skipped .dashicons { color: #c9880a; }
		#presence-heartbeat-widget .presence-heartbeat-pulse.is-skipped .presence-heartbeat-ripple { background: radial-gradient(circle, transparent 45%, rgba(200, 140, 10, 0.12) 60%, transparent 75%); }
		#presence-heartbeat-widget .presence-heartbeat-pulse.is-flat .dashicons { color: #a7aaad; }
		#presence-heartbeat-widget .presence-debugger-info { flex: 1; font-size: 12px; color: #646970; line-height: 1.6; }
		#presence-heartbeat-widget .presence-debugger-info span { font-weight: 600; color: #1d2327; }
		#presence-heartbeat-widget .presence-debugger-info .presence-heartbeat-users-label,
		#presence-heartbeat-widget .presence-debugger-info .presence-heartbeat-last-beat-label,
		#presence-heartbeat-widget .presence-debugger-info .presence-heartbeat-ttl-label { font-weight: 400; color: #646970; }
		#presence-heartbeat-widget .presence-heartbeat-timing { display: block; font-weight: 400; color: #a7aaad; font-size: 11px; }
		#presence-heartbeat-widget .presence-heartbeat-rooms-list { margin: 0; padding: 0; }
		#presence-heartbeat-widget .presence-heartbeat-room-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; font-size: 12px; color: #646970; border-bottom: 1px solid #f0f0f1; }
		#presence-heartbeat-widget .presence-heartbeat-room-row:last-child { border-bottom: none; }
		#presence-heartbeat-widget .presence-heartbeat-room-row code { background: #f0f0f1; padding: 1px 6px; border-radius: 3px; font-size: 11px; color: #1d2327; }
		#presence-heartbeat-widget .presence-heartbeat-room-row span { font-size: 11px; color: #a7aaad; }
		#presence-heartbeat-widget .presence-heartbeat-room-empty { color: #a7aaad; font-size: 13px; font-style: italic; padding: 8px 12px; }
		#presence-heartbeat-widget .presence-debugger-table { border-top: 1px solid #f0f0f1; }
		#presence-heartbeat-widget .presence-debugger-table iframe { width: 100%; min-height: 60px; border: none; display: block; }
		#presence-heartbeat-widget .presence-heartbeat-desc { margin: 0; font-size: 12px; color: #a7aaad; }
		#presence-heartbeat-widget .presence-heartbeat-desc a { color: #2271b1; text-decoration: none; }
		#presence-heartbeat-widget .presence-heartbeat-desc a:hover { color: #135e96; text-decoration: underline; }
		@keyframes presence-heart {
			0% { transform: scale(1); }
			5% { transform: scale(1.45); }
			14% { transform: scale(0.92); }
			22% { transform: scale(1.3); }
			32% { transform: scale(0.97); }
			42% { transform: scale(1); }
			100% { transform: scale(1); }
		}
		@keyframes presence-ripple {
			0% { transform: scale(0.4); opacity: 0; }
			4% { opacity: 0.7; }
			18% { opacity: 0.4; }
			50% { opacity: 0.15; }
			100% { transform: scale(1.8); opacity: 0; }
		}
		@media (prefers-reduced-motion: reduce) {
			#presence-heartbeat-widget .presence-heartbeat-pulse.is-beating .dashicons { animation: none; }
			#presence-heartbeat-widget .presence-heartbeat-pulse.is-beating .presence-heartbeat-ripple { animation: none; }
		}
	';

	$i18n = wp_json_encode(
		array(
			'listening'   => __( 'Listening', 'presence-api' ),
			'resting'     => __( 'Resting', 'presence-api' ),
			'sleeping'    => __( 'Sleeping', 'presence-api' ),
			'activeUser'  => __( 'active user', 'presence-api' ),
			'activeUsers' => __( 'active users', 'presence-api' ),
			'entry'       => __( 'entry', 'presence-api' ),
			'entries'     => __( 'entries', 'presence-api' ),
			'user'        => __( 'user', 'presence-api' ),
			'users'       => __( 'users', 'presence-api' ),
			'noRooms'     => __( 'No active rooms.', 'presence-api' ),
		)
	);

	$js = sprintf(
		'(function($) {
		if (typeof wp === "undefined" || typeof wp.heartbeat === "undefined") { return; }
		var i18n = %s;
		var pulseEl = document.querySelector("#presence-heartbeat-widget .presence-heartbeat-pulse");
		var timingEl = document.querySelector("#presence-heartbeat-widget .presence-heartbeat-timing");
		var usersEl = document.querySelector("#presence-heartbeat-widget .presence-heartbeat-users");
		var usersLabelEl = document.querySelector("#presence-heartbeat-widget .presence-heartbeat-users-label");
		var lastBeatEl = document.querySelector("#presence-heartbeat-widget .presence-heartbeat-last-beat");
		var ttlEl = document.querySelector("#presence-heartbeat-widget .presence-heartbeat-ttl-value");
		var dbIframe = document.querySelector("#presence-heartbeat-widget .presence-debugger-table iframe");
		if (!pulseEl) return;

		window.addEventListener("message", function(e) {
			if (e.data && e.data.presenceDbHeight && dbIframe) {
				dbIframe.style.height = e.data.presenceDbHeight + "px";
			}
		});

		var lastBeatTime = Date.now();
		var expectedInterval = wp.heartbeat.interval() * 1000;
		var tickCount = 0;

		function updateDisplay() {
			expectedInterval = wp.heartbeat.interval() * 1000;
			if (timingEl) timingEl.textContent = "";
			pulseEl.classList.remove("is-skipped", "is-flat");
		}

		// Show the current interval and trigger initial beat animation.
		updateDisplay();
		pulseEl.classList.add("is-beating");

		// Check health every second.
		setInterval(function() {
			var elapsed = Date.now() - lastBeatTime;
			if (elapsed > expectedInterval * 3) {
				pulseEl.classList.remove("is-skipped");
				pulseEl.classList.add("is-flat");
				if (timingEl) timingEl.textContent = i18n.sleeping;
				if (usersEl) usersEl.textContent = "0";
				if (usersLabelEl) usersLabelEl.textContent = i18n.activeUsers;
			} else if (elapsed > expectedInterval * 1.5) {
				pulseEl.classList.remove("is-flat");
				pulseEl.classList.add("is-skipped");
				if (timingEl) timingEl.textContent = i18n.resting;
			}
			if (lastBeatEl) {
				lastBeatEl.textContent = Math.round((Date.now() - lastBeatTime) / 1000) + "s ago";
			}
		}, 1000);

		$(document).on("heartbeat-tick", function(event, data) {
			lastBeatTime = Date.now();
			tickCount++;
			pulseEl.classList.remove("is-beating");
			pulseEl.offsetHeight;
			pulseEl.classList.add("is-beating");
			updateDisplay();
			if (data && data["presence-heartbeat-users"] != null && usersEl) {
				var u = data["presence-heartbeat-users"];
				usersEl.textContent = u;
				if (usersLabelEl) { usersLabelEl.textContent = u === 1 ? i18n.activeUser : i18n.activeUsers; }
			}
			if (data && data["presence-heartbeat-ttl"] != null && ttlEl) {
				ttlEl.textContent = data["presence-heartbeat-ttl"] + "s";
			}
			if (dbIframe) {
				try { dbIframe.contentWindow.location.reload(); } catch(e) {}
			}
			if (data && data["presence-heartbeat-room-list"]) {
				var listEl = document.querySelector("#presence-heartbeat-widget .presence-heartbeat-rooms-list");
				if (listEl) {
					var rooms = data["presence-heartbeat-room-list"];
					if (rooms.length <= 1) {
						listEl.innerHTML = "";
					} else {
						var html = "";
						for (var i = 0; i < rooms.length; i++) {
							html += "<div class=\"presence-heartbeat-room-row\"><code>" + rooms[i].prefix + "</code><span>" + rooms[i].entries + " " + (rooms[i].entries === 1 ? i18n.entry : i18n.entries) + ", " + rooms[i].users + " " + (rooms[i].users === 1 ? i18n.user : i18n.users) + "</span></div>";
						}
						listEl.innerHTML = html;
					}
				}
			}
		});

		$(document).on("heartbeat-send", function() {
			pulseEl.classList.remove("is-beating");
			pulseEl.offsetHeight;
			pulseEl.classList.add("is-beating");
		});

		pulseEl.addEventListener("animationend", function() {
			pulseEl.classList.remove("is-beating");
		});
	})(jQuery);',
		$i18n
	);

	wp_register_style( 'presence-heartbeat-widget', false, array(), WP_PRESENCE_VERSION );
	wp_enqueue_style( 'presence-heartbeat-widget' );
	wp_add_inline_style( 'presence-heartbeat-widget', $css );

	wp_add_inline_script( 'heartbeat', $js );
}

/**
 * Adds a presence indicator to the admin bar showing other online users.
 *
 * @since 7.1.0
 *
 * @param WP_Admin_Bar $wp_admin_bar The admin bar instance.
 */
function wp_presence_admin_bar_node( $wp_admin_bar ) {
	if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	$entries     = wp_get_presence( 'admin/online' );
	$current_uid = get_current_user_id();

	// Only show the admin bar node when other users are online.
	$others = array_filter(
		$entries,
		function ( $e ) use ( $current_uid ) {
			return (int) $e->user_id !== $current_uid;
		}
	);

	if ( empty( $others ) ) {
		return;
	}

	/*
	 * Determine the current screen slug to match against what the JS heartbeat
	 * sends as window.pagenow. Map $pagenow → pagenow values.
	 */
	global $pagenow;
	$pagenow_map = array(
		'index.php'              => 'dashboard',
		'edit.php'               => 'edit',
		'post.php'               => 'post',
		'post-new.php'           => 'post-new',
		'upload.php'             => 'upload',
		'edit-comments.php'      => 'edit-comments',
		'themes.php'             => 'themes',
		'widgets.php'            => 'widgets',
		'nav-menus.php'          => 'nav-menus',
		'plugins.php'            => 'plugins',
		'users.php'              => 'users',
		'profile.php'            => 'profile',
		'user-edit.php'          => 'user-edit',
		'tools.php'              => 'tools',
		'import.php'             => 'import',
		'export.php'             => 'export',
		'options-general.php'    => 'options-general',
		'options-writing.php'    => 'options-writing',
		'options-reading.php'    => 'options-reading',
		'options-discussion.php' => 'options-discussion',
		'options-media.php'      => 'options-media',
		'options-permalink.php'  => 'options-permalink',
	);

	if ( ! is_admin() ) {
		$current_screen = 'front';
	} elseif ( isset( $pagenow_map[ $pagenow ] ) ) {
		$current_screen = $pagenow_map[ $pagenow ];
	} else {
		$current_screen = $pagenow ? str_replace( '.php', '', $pagenow ) : 'unknown';
	}

	// Split others into "here" (same screen) and "elsewhere".
	$here      = array();
	$elsewhere = array();

	foreach ( $others as $entry ) {
		$screen = isset( $entry->data['screen'] ) ? $entry->data['screen'] : '';
		if ( $screen === $current_screen ) {
			$here[] = $entry;
		} else {
			$elsewhere[] = $entry;
		}
	}

	cache_users( wp_list_pluck( $entries, 'user_id' ) );

	// Sort both groups alphabetically by display name.
	$sort_by_name = function ( $a, $b ) {
		$user_a = get_userdata( $a->user_id );
		$user_b = get_userdata( $b->user_id );
		$name_a = $user_a ? $user_a->display_name : '';
		$name_b = $user_b ? $user_b->display_name : '';
		return strcasecmp( $name_a, $name_b );
	};
	usort( $here, $sort_by_name );
	usort( $elsewhere, $sort_by_name );

	// Build a map of user_id → post_id for users currently editing a post.
	$user_editing_post = array();
	$post_entries      = wp_get_presence_by_room_prefix( 'postType/' );
	foreach ( $post_entries as $pe ) {
		// Room format: postType/{type}:{id}.
		if ( preg_match( '/^postType\/[^:]+:(\d+)$/', $pe->room, $m ) ) {
			$user_editing_post[ (int) $pe->user_id ] = (int) $m[1];
		}
	}
	if ( ! empty( $user_editing_post ) ) {
		_prime_post_caches( array_unique( array_values( $user_editing_post ) ) );
	}

	// "Here" count includes you.
	$here_count      = count( $here ) + 1; // +1 for current user.
	$elsewhere_count = count( $elsewhere );

	// Build avatar stack from users on this page only (not current user), capped at 10.
	$stack_html   = '<span class="presence-bar-avatars">';
	$current_user = get_userdata( $current_uid );
	$stack_limit  = 10;
	$stack_show   = array_slice( $here, 0, $stack_limit );
	$z            = count( $stack_show );

	foreach ( $stack_show as $entry ) {
		$user = get_userdata( $entry->user_id );
		if ( ! $user ) {
			continue;
		}
		$stack_html .= '<img src="' . esc_url( get_avatar_url( $user->ID, array( 'size' => 32 ) ) ) . '" width="16" height="16" style="z-index:' . (int) $z . '" alt="' . esc_attr( $user->display_name ) . '" title="' . esc_attr( $user->display_name ) . '" />';
		--$z;
	}

	$stack_html .= '</span>';

	$others_count = count( $others );

	/* translators: %d: Number of other online users (excluding current user). */
	$label = sprintf( _n( '%d online', '%d online', $others_count, 'presence-api' ), $others_count );

	$wp_admin_bar->add_node(
		array(
			'id'    => 'presence-online',
			'title' => $stack_html . '<span class="presence-bar-count">' . esc_html( $label ) . '</span>',
			'href'  => false,
			'meta'  => array(
				'class'      => 'presence-bar-node menupop',
				'tabindex'   => 0,
				'aria-label' => sprintf(
				/* translators: %d: Number of other users currently online. */
					_n( '%d other user online', '%d other users online', $others_count, 'presence-api' ),
					$others_count
				),
			),
		)
	);

	// Add dropdown items grouped by "here" then "elsewhere".
	// "On this page" always shows (you're always here).
	$wp_admin_bar->add_node(
		array(
			'parent' => 'presence-online',
			'id'     => 'presence-group-here',
			'title'  => '<span class="presence-bar-group-label">' . esc_html__( 'On this page', 'presence-api' ) . '</span>',
			'href'   => false,
			'meta'   => array( 'class' => 'presence-bar-group-header' ),
		)
	);

	// Current user first.
	$wp_admin_bar->add_node(
		array(
			'parent' => 'presence-online',
			'id'     => 'presence-user-self',
			'title'  => esc_html( $current_user ? $current_user->display_name : __( 'You', 'presence-api' ) ) . ' <span class="presence-bar-you">(' . esc_html__( 'you', 'presence-api' ) . ')</span>',
			'href'   => false,
		)
	);

	// Others on the same page (capped at 10).
	$max_here = 10;
	$shown    = 0;

	foreach ( $here as $entry ) {
		if ( $shown >= $max_here ) {
			$remaining = count( $here ) - $max_here;
			$wp_admin_bar->add_node(
				array(
					'parent' => 'presence-online',
					'id'     => 'presence-here-overflow',
					/* translators: %d: Number of additional online users. */
					'title'  => '<span class="presence-bar-screen">' . esc_html( sprintf( __( '+%d more', 'presence-api' ), $remaining ) ) . '</span>',
					'href'   => false,
				)
			);
			break;
		}

		$user = get_userdata( $entry->user_id );
		if ( ! $user ) {
			continue;
		}
		$wp_admin_bar->add_node(
			array(
				'parent' => 'presence-online',
				'id'     => 'presence-user-' . $entry->user_id,
				'title'  => esc_html( $user->display_name ),
				'href'   => false,
			)
		);
		++$shown;
	}

	if ( ! empty( $elsewhere ) ) {
		$wp_admin_bar->add_node(
			array(
				'parent' => 'presence-online',
				'id'     => 'presence-group-elsewhere',
				'title'  => '<span class="presence-bar-group-label">' . esc_html__( 'Elsewhere', 'presence-api' ) . '</span>',
				'href'   => false,
				'meta'   => array( 'class' => 'presence-bar-group-header' ),
			)
		);

		$max_elsewhere = 10;
		$shown         = 0;

		foreach ( $elsewhere as $entry ) {
			if ( $shown >= $max_elsewhere ) {
				$remaining = count( $elsewhere ) - $max_elsewhere;
				$wp_admin_bar->add_node(
					array(
						'parent' => 'presence-online',
						'id'     => 'presence-elsewhere-overflow',
						/* translators: %d: Number of additional online users. */
						'title'  => '<span class="presence-bar-screen">' . esc_html( sprintf( __( '+%d more', 'presence-api' ), $remaining ) ) . '</span>',
						'href'   => admin_url( 'users.php?presence_status=online' ),
					)
				);
				break;
			}

			$user = get_userdata( $entry->user_id );
			if ( ! $user ) {
				continue;
			}
			$screen       = isset( $entry->data['screen'] ) ? $entry->data['screen'] : '';
			$entry_ps     = isset( $entry->data['post_status'] ) ? $entry->data['post_status'] : '';
			$screen_label = $screen ? WP_Presence_Widget_Whos_Online::get_rich_screen_label( $screen, $entry_ps ) : '';
			$screen_url   = $screen ? WP_Presence_Widget_Whos_Online::get_screen_url( $screen ) : false;
			$is_title     = false;

			// If user is editing a specific post, show the post title and link to it.
			if ( in_array( $screen, array( 'post', 'edit-post' ), true ) && isset( $user_editing_post[ (int) $entry->user_id ] ) ) {
				$post_id    = $user_editing_post[ (int) $entry->user_id ];
				$post_title = get_the_title( $post_id );
				if ( $post_title ) {
					$screen_label = $post_title;
					$is_title     = true;
				}
				$screen_url = get_edit_post_link( $post_id, 'raw' );
			}

			// If user is viewing a post on the frontend, show the post title and link to it.
			if ( 'front' === $screen && ! empty( $entry->data['title'] ) ) {
				$screen_label = $entry->data['title'];
				$is_title     = true;
				if ( ! empty( $entry->data['post_id'] ) ) {
					$screen_url = get_permalink( (int) $entry->data['post_id'] );
				}
			}

			$item_title = esc_html( $user->display_name );

			if ( $screen_label ) {
				if ( $is_title ) {
					$formatted = esc_html( $screen_label );
				} else {
					$parts     = explode( ' ', $screen_label, 2 );
					$formatted = count( $parts ) > 1
						? '<em>' . esc_html( $parts[0] ) . '</em> ' . esc_html( $parts[1] )
						: esc_html( $screen_label );
				}
				$item_title .= ' <span class="presence-bar-screen">&middot; ' . $formatted . '</span>';
			}

			$wp_admin_bar->add_node(
				array(
					'parent' => 'presence-online',
					'id'     => 'presence-user-' . $entry->user_id,
					'title'  => $item_title,
					'href'   => $screen_url ? $screen_url : false,
				)
			);
			++$shown;
		}
	}

	// Add "View online users" link at the bottom.
	$wp_admin_bar->add_node(
		array(
			'parent' => 'presence-online',
			'id'     => 'presence-view-all',
			'title'  => __( 'View online users', 'presence-api' ),
			'href'   => admin_url( 'users.php?presence_status=online' ),
		)
	);
}

/**
 * Enqueues CSS for the admin bar presence indicator.
 *
 * @since 7.1.0
 */
function wp_presence_admin_bar_assets() {
	if ( ! is_user_logged_in() || ! is_admin_bar_showing() || ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	$css = '
		#wp-admin-bar-presence-online > .ab-item { display: flex !important; align-items: center; gap: 2px; cursor: default; }
		#wp-admin-bar-presence-online .presence-bar-avatars { display: inline-flex; align-items: center; vertical-align: middle; margin-right: 4px; }
		#wp-admin-bar-presence-online .presence-bar-avatars img { border-radius: 50%; width: 16px !important; height: 16px !important; margin-inline-start: -4px; box-shadow: 0 0 0 1.5px #1d2327; position: relative; }
		#wp-admin-bar-presence-online .presence-bar-avatars img:first-child { margin-inline-start: 0; }
		#wp-admin-bar-presence-online .presence-bar-count { vertical-align: middle; }
		#wp-admin-bar-presence-online .presence-bar-you { color: #a7aaad; font-weight: normal; }
		#wp-admin-bar-presence-online .presence-bar-screen { color: #a7aaad; font-size: 12px; }
		#wp-admin-bar-presence-online .presence-bar-screen em { font-style: italic; }
		#wp-admin-bar-presence-online .presence-bar-group-header > .ab-item { color: #a7aaad !important; font-size: 11px !important; text-transform: uppercase; letter-spacing: 0.5px; pointer-events: none; padding-bottom: 0 !important; }
		#wp-admin-bar-presence-group-elsewhere > .ab-item { border-top: 1px solid #3c4043 !important; margin-top: 4px !important; padding-top: 8px !important; }
		#wp-admin-bar-presence-view-all .ab-item { border-top: 1px solid #3c4043 !important; font-style: italic; }
	';

	wp_register_style( 'presence-admin-bar', false, array(), WP_PRESENCE_VERSION );
	wp_enqueue_style( 'presence-admin-bar' );
	wp_add_inline_style( 'presence-admin-bar', $css );
}

/**
 * Adds an "Online" view to the users list table.
 *
 * Displays a tab alongside the role-based views (All | Administrator | Editor | etc.)
 * that filters the list to only show users with active presence entries.
 *
 * @since 7.1.0
 *
 * @param array $views Existing views.
 * @return array Modified views.
 */
function wp_presence_users_views( $views ) {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return $views;
	}

	$entries      = wp_get_presence( 'admin/online' );
	$online_ids   = array_unique( wp_list_pluck( $entries, 'user_id' ) );
	$online_count = count( $online_ids );
	$is_current   = isset( $_GET['presence_status'] ) && 'online' === $_GET['presence_status']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	$class = $is_current ? 'current' : '';
	$url   = admin_url( 'users.php?presence_status=online' );

	$views['presence_online'] = sprintf(
		'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
		esc_url( $url ),
		$class,
		esc_html__( 'Online', 'presence-api' ),
		$online_count
	);

	// Remove "current" from "All" when our filter is active.
	if ( $is_current && isset( $views['all'] ) ) {
		$views['all'] = str_replace( 'class="current"', '', $views['all'] );
	}

	return $views;
}

/**
 * Filters the users query to only include online users when presence_status=online.
 *
 * @since 7.1.0
 *
 * @param WP_User_Query $query The user query.
 */
function wp_presence_filter_online_users( $query ) {
	if ( ! is_admin() || ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	if ( empty( $_GET['presence_status'] ) || 'online' !== $_GET['presence_status'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	$entries    = wp_get_presence( 'admin/online' );
	$online_ids = array_unique( wp_list_pluck( $entries, 'user_id' ) );

	if ( empty( $online_ids ) ) {
		$query->set( 'include', array( 0 ) );
	} else {
		$query->set( 'include', array_map( 'intval', $online_ids ) );
	}
}

/**
 * Registers the "Editors" column for post types with presence support.
 *
 * @since 7.1.0
 */
function wp_presence_register_post_list_columns() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	$post_types = get_post_types( array( 'public' => true ) );

	foreach ( $post_types as $post_type ) {
		if ( ! post_type_supports( $post_type, 'presence' ) ) {
			continue;
		}

		add_filter( "manage_{$post_type}_posts_columns", 'wp_presence_add_editors_column' );
		add_action( "manage_{$post_type}_posts_custom_column", 'wp_presence_render_editors_column', 10, 2 );
	}

	add_action( 'admin_head-edit.php', 'wp_presence_editors_column_css' );
}

/**
 * Adds the "Editors" column to the post list table.
 *
 * @since 7.1.0
 *
 * @param array $columns Existing columns.
 * @return array Modified columns.
 */
function wp_presence_add_editors_column( $columns ) {
	// Insert before the "date" column.
	$new_columns = array();

	foreach ( $columns as $key => $label ) {
		if ( 'date' === $key ) {
			$new_columns['presence_editors'] = __( 'Editors', 'presence-api' );
		}
		$new_columns[ $key ] = $label;
	}

	// If no date column, append.
	if ( ! isset( $new_columns['presence_editors'] ) ) {
		$new_columns['presence_editors'] = __( 'Editors', 'presence-api' );
	}

	return $new_columns;
}

/**
 * Renders the "Editors" column content for a post.
 *
 * Queries presence data once and caches it for the entire page load.
 *
 * @since 7.1.0
 *
 * @param string $column_name The column name.
 * @param int    $post_id     The post ID.
 */
function wp_presence_render_editors_column( $column_name, $post_id ) {
	if ( 'presence_editors' !== $column_name ) {
		return;
	}

	static $presence_map = null;

	if ( null === $presence_map ) {
		$presence_map = array();
		$entries      = wp_get_presence_by_room_prefix( 'postType/' );

		foreach ( $entries as $entry ) {
			$room_parts = explode( ':', $entry->room, 2 );

			if ( count( $room_parts ) < 2 ) {
				continue;
			}

			$pid = (int) $room_parts[1];

			if ( ! isset( $presence_map[ $pid ] ) ) {
				$presence_map[ $pid ] = array();
			}

			// Deduplicate by user_id.
			$presence_map[ $pid ][ $entry->user_id ] = $entry;
		}

		// Prime user cache for all editors in one query.
		$all_user_ids = array();
		foreach ( $presence_map as $editors ) {
			$all_user_ids = array_merge( $all_user_ids, array_keys( $editors ) );
		}
		cache_users( array_unique( $all_user_ids ) );
	}

	if ( empty( $presence_map[ $post_id ] ) ) {
		return;
	}

	$editors = $presence_map[ $post_id ];
	$count   = count( $editors );
	$index   = 0;

	echo '<div class="presence-editors-stack">';

	foreach ( $editors as $entry ) {
		$user = get_userdata( $entry->user_id );

		if ( ! $user ) {
			continue;
		}

		$z      = $count - $index;
		$avatar = get_avatar( $user->ID, 24, '', $user->display_name );
		$avatar = str_replace( '<img ', '<img style="z-index:' . $z . '" title="' . esc_attr( $user->display_name ) . '" ', $avatar );
		echo wp_kses_post( $avatar );
		++$index;
	}

	echo '</div>';
}

/**
 * Outputs CSS for the editors column on the post list screen.
 *
 * @since 7.1.0
 */
function wp_presence_editors_column_css() {
	?>
	<style>
		.column-presence_editors { width: 80px; }
		.presence-editors-stack { display: flex; align-items: center; }
		.presence-editors-stack img {
			border-radius: 9999px;
			margin-inline-start: -8px;
			box-shadow: 0 0 0 2px #fff;
			position: relative;
		}
		.presence-editors-stack img:first-child { margin-inline-start: 0; }
	</style>
	<?php
}
