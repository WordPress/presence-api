<?php
/**
 * Shows the running scene in the Presence API debugger.
 *
 * @package Presence_Scenes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a play icon beside the debugger's countdown while a scene runs.
 *
 * @since 0.1.0
 *
 * @access private
 *
 * @param array[] $indicators Indicators to show.
 * @return array[] The indicators.
 */
function wp_presence_scene_debugger_indicators( $indicators ) {
	if ( is_array( get_option( 'wp_presence_scene' ) ) ) {
		$indicators[] = array(
			'icon'  => 'dashicons-controls-play',
			'label' => __( 'Scene playing', 'presence-scenes' ),
		);
	}

	return $indicators;
}

/**
 * Adds the running scene's progress and latest step to the debugger's menu.
 *
 * @since 0.1.0
 *
 * @access private
 *
 * @param WP_Admin_Bar $wp_admin_bar The admin bar.
 */
function wp_presence_scene_debugger_menu( $wp_admin_bar ) {
	$run = get_option( 'wp_presence_scene' );
	if ( ! is_array( $run ) ) {
		return;
	}

	$steps = $run['scene']['steps'];
	$done  = $run['done'];

	$wp_admin_bar->add_node(
		array(
			'parent' => 'presence-debug',
			'id'     => 'presence-debug-scene',
			'title'  => '<span><span class="screen-reader-text">' . esc_html__( 'Scene:', 'presence-scenes' ) . ' </span>' . esc_html( $run['title'] ) . '</span>'
				/* translators: 1: Steps played, 2: Steps in the scene. */
				. '<span class="presence-debug-value">' . esc_html( sprintf( __( '%1$s / %2$s', 'presence-scenes' ), number_format_i18n( count( $done ) ), number_format_i18n( count( $steps ) ) ) ) . '</span>',
			'meta'   => array( 'class' => 'presence-debug-row presence-debug-scene' ),
		)
	);

	if ( $done ) {
		$wp_admin_bar->add_node(
			array(
				'parent' => 'presence-debug',
				'id'     => 'presence-debug-scene-step',
				'title'  => esc_html( $steps[ array_key_last( $done ) ]['label'] ),
				'meta'   => array( 'class' => 'presence-debug-row presence-debug-step' ),
			)
		);
	}
}

/**
 * Styles the scene rows, after the debugger has enqueued its own styles.
 *
 * @since 0.1.0
 *
 * @access private
 *
 * @codeCoverageIgnore
 */
function wp_presence_scene_debugger_assets() {
	if ( ! wp_style_is( 'presence-debugger-admin-bar' ) ) {
		return;
	}

	wp_add_inline_style(
		'presence-debugger-admin-bar',
		'
		#wpadminbar #wp-admin-bar-presence-debug .presence-debug-scene > .ab-item::before { content: "\\f522"; position: static; padding: 0; font: 12px/1 dashicons; color: inherit; }
		#wpadminbar #wp-admin-bar-presence-debug .presence-debug-scene > .ab-item { gap: 6px; }
		#wpadminbar #wp-admin-bar-presence-debug .presence-debug-scene > .ab-item > .presence-debug-value { margin-inline-start: auto; }
		#wpadminbar #wp-admin-bar-presence-debug .presence-debug-step > .ab-item { min-height: 0; padding-inline-start: 34px; padding-bottom: 4px; font-size: 12px; }
		'
	);
}
