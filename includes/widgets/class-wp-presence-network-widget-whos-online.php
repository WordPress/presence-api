<?php
/**
 * Network Dashboard Widget: Who's Online
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the network dashboard's "Who's Online" widget with Heartbeat integration.
 *
 * The one new surface this plugin adds for multisite: everything else folds
 * into existing Network Admin screens (Sites and Users list columns), but a
 * dashboard widget mirrors the single-site "at a glance" pattern and has no
 * existing native screen to fold into.
 */
class WP_Presence_Network_Widget_Whos_Online {

	/**
	 * Maximum number of sites shown before linking out to the Sites list.
	 *
	 * @var int
	 */
	const VISIBLE_SITES = 5;

	/**
	 * Registers the network dashboard widget.
	 */
	public static function register() {
		if ( ! current_user_can( wp_presence_network_capability() ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'presence_network_whos_online',
			__( "Who's Online", 'presence-api' ),
			array( __CLASS__, 'render' )
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
		wp_presence_enqueue_avatar_stack_script();

		// A handle of its own so the inline script can declare what it needs.
		wp_register_script( 'presence-network-widget', false, array( 'jquery', 'heartbeat', 'wp-presence-avatar-stack' ), WP_PRESENCE_VERSION, true );
		wp_enqueue_script( 'presence-network-widget' );
		wp_add_inline_script( 'presence-network-widget', self::get_inline_script() );

		wp_presence_enqueue_avatar_stack_style();

		wp_register_style( 'presence-network-widget', false, array(), WP_PRESENCE_VERSION );
		wp_enqueue_style( 'presence-network-widget' );
		wp_add_inline_style( 'presence-network-widget', self::get_inline_css() );
	}

	/**
	 * Returns the inline CSS for the widget.
	 *
	 * @return string CSS code.
	 */
	private static function get_inline_css() {
		return '#presence-network-widget-list p { margin: 0; padding: 6px 12px; color: #646970; }
			#presence-network-widget-list .presence-user-list { margin: 0; }
			#presence-network-widget-list .presence-site-item { display: flex; align-items: center; gap: 8px; padding: 6px 12px; border-bottom: 1px solid #f0f0f1; }
			#presence-network-widget-list .presence-site-item:last-child { border-bottom: none; }
			#presence-network-widget-list .presence-site-info { flex: 1; min-width: 0; }
			#presence-network-widget-list .presence-site-count { color: #646970; font-size: 12px; }
			#presence-network-widget-list .presence-more-link { display: block; padding: 6px 12px; color: var(--wp-admin-theme-color, #2271b1); font-size: 13px; text-decoration: none; }
			#presence-network-widget-list .presence-more-link:hover { text-decoration: underline; }';
	}

	/**
	 * Renders the widget.
	 */
	public static function render() {
		echo '<div id="presence-network-widget-list" aria-live="polite" tabindex="-1">';
		self::render_summary( self::get_summary() );
		echo '</div>';
	}

	/**
	 * Reads the slice of the network this widget draws.
	 *
	 * Five sites with four avatars each, asked for as five sites with four
	 * avatars each. The widget is on the network dashboard, so on a large
	 * network this read is the one that has to stay cheap.
	 *
	 * @return array See wp_presence_get_network_summary().
	 */
	private static function get_summary() {
		return wp_presence_get_network_summary(
			array(
				'sites'          => self::VISIBLE_SITES,
				'users_per_site' => WP_PRESENCE_NETWORK_AVATARS,
			)
		);
	}

	/**
	 * Returns how many sites are online beyond the ones being shown.
	 *
	 * @param array $summary Return value of self::get_summary().
	 * @return int Site count, zero if the whole network fits.
	 */
	private static function overflow_count( $summary ) {
		return max( 0, (int) $summary['total_sites_online'] - count( $summary['sites'] ) );
	}

	/**
	 * Renders the compact site list for a network summary.
	 *
	 * @param array $summary Return value of self::get_summary().
	 */
	private static function render_summary( $summary ) {
		if ( empty( $summary['sites'] ) ) {
			echo '<p>' . esc_html__( 'No users are currently online anywhere on the network.', 'presence-api' ) . '</p>';
			return;
		}

		echo '<ul class="presence-user-list" aria-label="' . esc_attr__( 'Sites with online users', 'presence-api' ) . '">';

		foreach ( $summary['sites'] as $site ) {
			echo '<li class="presence-site-item">';
			echo wp_kses_post( wp_presence_render_avatar_stack( $site['users'], WP_PRESENCE_NETWORK_AVATARS ) );
			echo '<span class="presence-site-info"><a href="' . esc_url( $site['url'] ) . '">' . esc_html( $site['domain'] . $site['path'] ) . '</a></span>';
			echo '<span class="presence-site-count">' . (int) $site['user_count'] . '</span>';
			echo '</li>';
		}

		echo '</ul>';

		$overflow = self::overflow_count( $summary );

		if ( $overflow ) {
			printf(
				'<a href="%1$s" class="presence-more-link">%2$s</a>',
				esc_url( network_admin_url( 'sites.php' ) ),
				esc_html(
					sprintf(
						/* translators: %d: Number of additional sites with online users. */
						_n( '+%d more site — view all', '+%d more sites — view all', $overflow, 'presence-api' ),
						$overflow
					)
				)
			);
		}
	}

	/**
	 * Handles the heartbeat received event for the network dashboard widget.
	 *
	 * Self-gates on a widget-specific ping key so every other admin screen's
	 * tick costs one empty() check here, never the capability check or the
	 * summary query.
	 *
	 * @param array  $response  The Heartbeat response.
	 * @param array  $data      The $_POST data sent.
	 * @param string $screen_id The screen ID.
	 * Nonce verification is handled by WordPress in wp_ajax_heartbeat().
	 *
	 * @return array The Heartbeat response.
	 */
	public static function heartbeat_received( $response, $data, $screen_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by filter signature.
		if ( empty( $data['presence-network-widget-ping'] ) ) {
			return $response;
		}

		if ( ! current_user_can( wp_presence_network_capability() ) ) {
			return $response;
		}

		$summary     = self::get_summary();
		$overflow    = self::overflow_count( $summary );
		$hash        = self::hash_summary( $summary, $overflow );
		$client_hash = isset( $data['presence-network-widget-hash'] ) ? sanitize_text_field( $data['presence-network-widget-hash'] ) : '';

		if ( $client_hash && $client_hash === $hash ) {
			$response['presence-network-widget-unchanged'] = true;

			return $response;
		}

		$response['presence-network-widget']          = $summary['sites'];
		$response['presence-network-widget-overflow'] = $overflow;
		$response['presence-network-widget-hash']     = $hash;

		return $response;
	}

	/**
	 * Hashes the state this widget draws.
	 *
	 * The payload itself, rather than a picked-out subset of it. A hash over
	 * blog IDs and user IDs alone held a stale rename or a changed avatar on
	 * screen for as long as the same people stayed online, and a hash over the
	 * whole network never matched twice on a network large enough for the sixth
	 * site to keep changing out of sight.
	 *
	 * The read path already returns sites busiest-first and users by name, so
	 * there is nothing left to normalize here.
	 *
	 * @param array $summary  Return value of self::get_summary().
	 * @param int   $overflow Sites online beyond the ones being sent.
	 * @return string The state hash.
	 */
	private static function hash_summary( $summary, $overflow ) {
		return md5( (string) wp_json_encode( array( $summary['sites'], $overflow ) ) );
	}

	/**
	 * Returns the inline JavaScript for Heartbeat integration.
	 *
	 * @return string JavaScript code.
	 */
	private static function get_inline_script() {
		$i18n_json = wp_json_encode(
			array(
				'noUsersOnline' => __( 'No users are currently online anywhere on the network.', 'presence-api' ),
				'viewAll'       => __( 'view all', 'presence-api' ),
			)
		);

		return sprintf(
			<<<'JS'
(function($) {
	if (typeof wp === 'undefined' || typeof wp.heartbeat === 'undefined') {
		return;
	}

	var i18n = %s;
	var viewAllUrl = %s;
	var avatarMax = %d;
	var lastHash = '';
	var lastSignature = '';

	function esc(str) {
		var el = document.createElement('span');
		el.textContent = str;
		return el.innerHTML;
	}

	// Already cut to the sites and avatars this widget shows, so nothing is
	// sliced here; overflow is a count the server sends, not what is left over.
	function buildListHtml(sites, overflow) {
		if (!sites.length) {
			return '<p>' + esc(i18n.noUsersOnline) + '</p>';
		}

		var html = '<ul class="presence-user-list">';
		sites.forEach(function(site) {
			html += '<li class="presence-site-item">' + window.wpPresenceBuildAvatarStack(site.users, avatarMax);
			html += '<span class="presence-site-info"><a href="' + esc(site.url) + '">' + esc(site.domain + site.path) + '</a></span>';
			html += '<span class="presence-site-count">' + site.user_count + '</span></li>';
		});
		html += '</ul>';

		if (overflow > 0) {
			html += '<a href="' + esc(viewAllUrl) + '" class="presence-more-link">+' + overflow + ' — ' + esc(i18n.viewAll) + '</a>';
		}

		return html;
	}

	$(document).on('heartbeat-send', function(event, data) {
		data['presence-network-widget-ping'] = true;
		if (lastHash) {
			data['presence-network-widget-hash'] = lastHash;
		}
	});

	$(document).on('heartbeat-tick', function(event, data) {
		if (data['presence-network-widget-unchanged']) {
			return;
		}

		if (!data['presence-network-widget']) {
			return;
		}

		lastHash = data['presence-network-widget-hash'] || '';

		var container = $('#presence-network-widget-list');
		if (!container.length) {
			return;
		}

		var sites = data['presence-network-widget'];
		var overflow = data['presence-network-widget-overflow'] || 0;

		// Signature over what gets drawn, matching the server-side hash: a
		// rename or a new avatar has to repaint, and the order is already the
		// order it renders in.
		var sig = JSON.stringify([sites, overflow]);

		if (sig !== lastSignature) {
			container.html(buildListHtml(sites, overflow));
			lastSignature = sig;
		}
	});
})(jQuery);
JS,
			$i18n_json,
			wp_json_encode( esc_url_raw( network_admin_url( 'sites.php' ) ) ),
			WP_PRESENCE_NETWORK_AVATARS
		);
	}
}
