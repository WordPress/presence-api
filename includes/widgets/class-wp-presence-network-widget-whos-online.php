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
		wp_add_inline_script( 'heartbeat', self::get_inline_script() );

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
			#presence-network-widget-list .presence-more-link:hover { text-decoration: underline; }
			#presence-network-widget-list .presence-avatar-stack { display: inline-flex; align-items: center; }
			#presence-network-widget-list .presence-avatar-stack img { border-radius: 50%; width: 20px; height: 20px; margin-inline-start: -6px; box-shadow: 0 0 0 2px #fff; position: relative; }
			#presence-network-widget-list .presence-avatar-stack img:first-child { margin-inline-start: 0; }';
	}

	/**
	 * Renders the widget.
	 */
	public static function render() {
		echo '<div id="presence-network-widget-list" aria-live="polite" tabindex="-1">';
		self::render_summary( wp_presence_get_network_summary() );
		echo '</div>';
	}

	/**
	 * Renders the compact site list for a network summary.
	 *
	 * @param array $summary Return value of wp_presence_get_network_summary().
	 */
	private static function render_summary( $summary ) {
		if ( empty( $summary['sites'] ) ) {
			echo '<p>' . esc_html__( 'No users are currently online anywhere on the network.', 'presence-api' ) . '</p>';
			return;
		}

		$visible  = array_slice( $summary['sites'], 0, self::VISIBLE_SITES );
		$overflow = array_slice( $summary['sites'], self::VISIBLE_SITES );

		echo '<ul class="presence-user-list" aria-label="' . esc_attr__( 'Sites with online users', 'presence-api' ) . '">';

		foreach ( $visible as $site ) {
			echo '<li class="presence-site-item">';
			echo wp_kses_post( wp_presence_render_avatar_stack( $site['users'] ) );
			echo '<span class="presence-site-info"><a href="' . esc_url( $site['url'] ) . '">' . esc_html( $site['domain'] . $site['path'] ) . '</a></span>';
			echo '<span class="presence-site-count">' . (int) $site['user_count'] . '</span>';
			echo '</li>';
		}

		echo '</ul>';

		if ( ! empty( $overflow ) ) {
			printf(
				'<a href="%1$s" class="presence-more-link">%2$s</a>',
				esc_url( network_admin_url( 'sites.php' ) ),
				esc_html(
					sprintf(
						/* translators: %d: Number of additional sites with online users. */
						_n( '+%d more site — view all', '+%d more sites — view all', count( $overflow ), 'presence-api' ),
						count( $overflow )
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

		$summary     = wp_presence_get_network_summary();
		$hash        = self::hash_summary( $summary );
		$client_hash = isset( $data['presence-network-widget-hash'] ) ? sanitize_text_field( $data['presence-network-widget-hash'] ) : '';

		if ( $client_hash && $client_hash === $hash ) {
			$response['presence-network-widget-unchanged'] = true;

			return $response;
		}

		$response['presence-network-widget']      = $summary['sites'];
		$response['presence-network-widget-hash'] = $hash;

		return $response;
	}

	/**
	 * Hashes the meaningful state of a network summary.
	 *
	 * @param array $summary Return value of wp_presence_get_network_summary().
	 * @return string The state hash.
	 */
	private static function hash_summary( $summary ) {
		$state = array();

		foreach ( $summary['sites'] as $site ) {
			$user_ids = wp_list_pluck( $site['users'], 'user_id' );
			sort( $user_ids );
			$state[] = array( $site['blog_id'], $user_ids );
		}

		sort( $state );

		return md5( (string) wp_json_encode( $state ) );
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
	var visibleMax = %d;
	var lastHash = '';
	var lastSignature = '';

	function esc(str) {
		var el = document.createElement('span');
		el.textContent = str;
		return el.innerHTML;
	}

	function buildAvatarStack(users) {
		var stackMax = Math.min(users.length, 4);
		var html = '<span class="presence-avatar-stack">';
		users.slice(0, stackMax).forEach(function(user, idx) {
			if (user.avatar_url) {
				html += '<img src="' + esc(user.avatar_url) + '" width="20" height="20" style="z-index:' + (stackMax - idx) + '" alt="' + esc(user.display_name) + '" />';
			}
		});
		html += '</span>';
		return html;
	}

	function buildListHtml(sites) {
		if (!sites.length) {
			return '<p>' + esc(i18n.noUsersOnline) + '</p>';
		}

		var visible = sites.slice(0, visibleMax);
		var overflow = sites.slice(visibleMax);

		var html = '<ul class="presence-user-list">';
		visible.forEach(function(site) {
			html += '<li class="presence-site-item">' + buildAvatarStack(site.users);
			html += '<span class="presence-site-info"><a href="' + esc(site.url) + '">' + esc(site.domain + site.path) + '</a></span>';
			html += '<span class="presence-site-count">' + site.user_count + '</span></li>';
		});
		html += '</ul>';

		if (overflow.length) {
			html += '<a href="' + esc(viewAllUrl) + '" class="presence-more-link">+' + overflow.length + ' — ' + esc(i18n.viewAll) + '</a>';
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

		var sig = data['presence-network-widget'].map(function(s) {
			return s.blog_id + ':' + s.users.map(function(u) { return u.user_id; }).sort().join(',');
		}).sort().join('|');

		if (sig !== lastSignature) {
			container.html(buildListHtml(data['presence-network-widget']));
			lastSignature = sig;
		}
	});
})(jQuery);
JS,
			$i18n_json,
			wp_json_encode( esc_url_raw( network_admin_url( 'sites.php' ) ) ),
			self::VISIBLE_SITES
		);
	}
}
