<?php
/**
 * Dashboard widget: Presence API Debugger.
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Heartbeat dashboard widget.
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
 * @param array  $response  The Heartbeat response.
 * @param array  $data      The $_POST data sent.
 * @param string $screen_id The screen ID.
 * @return array The Heartbeat response.
 */
function wp_presence_heartbeat_widget_received( $response, $data, $screen_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by filter signature.
	if ( empty( $data['presence-ping'] ) ) {
		return $response;
	}

	$start    = microtime( true );
	$summary  = wp_get_presence_summary();
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
