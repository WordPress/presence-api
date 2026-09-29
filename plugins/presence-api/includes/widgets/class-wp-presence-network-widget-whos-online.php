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
 *
 * @since 0.2.0
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
	 *
	 * @since 0.2.0
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
	 * Enqueues the widget's CSS.
	 *
	 * @since 0.2.0
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public static function enqueue_scripts( $hook_suffix ) {
		if ( 'index.php' !== $hook_suffix ) {
			return;
		}

		wp_presence_enqueue_avatar_stack_style();

		wp_register_style( 'presence-network-widget', false, array(), WP_PRESENCE_VERSION );
		wp_enqueue_style( 'presence-network-widget' );
		wp_add_inline_style( 'presence-network-widget', self::get_inline_css() );
	}

	/**
	 * Returns the inline CSS for the widget.
	 *
	 * @since 0.2.0
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
	 *
	 * @since 0.2.0
	 */
	public static function render() {
		echo '<div id="presence-network-widget-list" aria-live="polite" tabindex="-1">';
		echo self::summary_markup( self::get_summary() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped as it is built.
		echo '</div>';
	}

	/**
	 * Reads the slice of the network this widget draws.
	 *
	 * Five sites with four avatars each, asked for as five sites with four
	 * avatars each. The widget is on the network dashboard, so on a large
	 * network this read is the one that has to stay cheap.
	 *
	 * @since 0.2.0
	 * @since 0.11.0 Names each site by its title and links it to its settings, as the Sites list does.
	 *
	 * @return array See wp_presence_get_network_summary(), plus each site's `name` and `edit_url`.
	 */
	private static function get_summary() {
		$summary = wp_presence_get_network_summary(
			array(
				'sites'          => self::VISIBLE_SITES,
				'users_per_site' => WP_PRESENCE_NETWORK_AVATARS,
			)
		);

		foreach ( $summary['sites'] as $index => $site ) {
			// Stored HTML-escaped, so decoded here for the escaping on output.
			$name = trim( wp_specialchars_decode( get_site( $site['blog_id'] )->blogname, ENT_QUOTES ) );

			$summary['sites'][ $index ]['name']     = '' !== $name ? $name : untrailingslashit( $site['domain'] . $site['path'] );
			$summary['sites'][ $index ]['edit_url'] = network_admin_url( 'site-info.php?id=' . $site['blog_id'] );
		}

		return $summary;
	}

	/**
	 * Returns how many sites are online beyond the ones being shown.
	 *
	 * @since 0.2.0
	 *
	 * @param array $summary Return value of self::get_summary().
	 * @return int Site count, zero if the whole network fits.
	 */
	private static function overflow_count( $summary ) {
		return max( 0, (int) $summary['total_sites_online'] - count( $summary['sites'] ) );
	}

	/**
	 * Returns the compact site list for a network summary, as drawn on load and sent with each Heartbeat.
	 *
	 * @since 0.11.0
	 *
	 * @param array $summary Return value of self::get_summary().
	 * @return string HTML markup.
	 */
	private static function summary_markup( $summary ) {
		if ( ! $summary['aggregating'] ) {
			return '<p>' . esc_html__( 'Presence is not aggregated across this network, so who is online cannot be shown.', 'presence-api' ) . '</p>';
		}

		if ( empty( $summary['sites'] ) ) {
			return '<p>' . esc_html__( 'No users are currently online anywhere on the network.', 'presence-api' ) . '</p>';
		}

		$html = '<ul class="presence-user-list" aria-label="' . esc_attr__( 'Sites with online users', 'presence-api' ) . '">';

		foreach ( $summary['sites'] as $site ) {
			$html .= '<li class="presence-site-item" data-blog-id="' . (int) $site['blog_id'] . '">';
			$html .= wp_kses_post( wp_presence_render_avatar_stack( $site['users'], WP_PRESENCE_NETWORK_AVATARS ) );
			$html .= '<span class="presence-site-info"><a href="' . esc_url( $site['edit_url'] ) . '">' . esc_html( $site['name'] ) . '</a></span>';
			$html .= '<span class="presence-site-count">' . (int) $site['user_count'] . '</span>';
			$html .= '</li>';
		}

		$html .= '</ul>';

		$overflow = self::overflow_count( $summary );

		if ( $overflow ) {
			$html .= sprintf(
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

		return $html;
	}

	/**
	 * Handles the heartbeat received event for the network dashboard widget.
	 *
	 * Self-gates on the widget's fragment request so every other admin screen's
	 * tick costs one lookup here, never the capability check or the
	 * summary query.
	 *
	 * @since 0.2.0
	 *
	 * @param array  $response  The Heartbeat response.
	 * @param array  $data      The $_POST data sent.
	 * @param string $screen_id The screen ID.
	 * Nonce verification is handled by WordPress in wp_ajax_heartbeat().
	 *
	 * @return array The Heartbeat response.
	 */
	public static function heartbeat_received( $response, $data, $screen_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by filter signature.
		if ( ! wp_presence_fragment_request( $data, 'network-widget' ) ) {
			return $response;
		}

		if ( ! current_user_can( wp_presence_network_capability() ) ) {
			return $response;
		}

		$response['presence-fragments']['network-widget'] = self::summary_markup( self::get_summary() );

		return $response;
	}
}
