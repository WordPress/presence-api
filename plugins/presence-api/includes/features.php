<?php
/**
 * Features: the pieces of the plugin a site can switch off while core adopts
 * them one at a time.
 *
 * Plugin only. Core has no switch for its own features, so when a piece is
 * merged its entry here and the check around its hooks in default-filters.php
 * go with it.
 *
 * Shaped like Gutenberg's experiments (lib/experimental/experiments/load.php):
 * one list declared in code, one option keyed by feature, one function to ask.
 * Unlike an experiment, a feature is on until someone switches it off, so a
 * piece added in a later release starts on for every site that already has
 * the option.
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns the pieces a site can switch off, keyed by feature.
 *
 * Each piece is added here in the same change that puts a check around its
 * hooks, so a checkbox never appears for something it cannot turn off.
 *
 * Only call this once translations can load (init or later). Gating hooks at
 * load time goes through wp_presence_feature_enabled(), which never needs the
 * labels.
 *
 * @access private
 *
 * @since 0.16.0
 *
 * @return array<string, array{label: string, description: string}> Features keyed by feature.
 */
function wp_presence_get_features() {
	return array(
		'post-locks' => array(
			'label'       => __( 'Post locks', 'presence-api' ),
			'description' => __( 'Keep post locks in the presence table instead of post meta, so refreshing a lock does not make cached post queries stale.', 'presence-api' ),
		),
	);
}

/**
 * Whether a piece of the plugin is switched on for this site.
 *
 * A feature with no stored choice is on. On multisite, the network can switch
 * a feature off for every site, and a site cannot switch it back on, the same
 * way the recording switch works.
 *
 * Unlike wp_presence_recording_enabled(), switching a feature off does not
 * stop rows being written: plugins that keep their own rows in the table only
 * call the functions every feature is built on, and those are never switched
 * off.
 *
 * @since 0.16.0
 *
 * @param string $feature Feature key from wp_presence_get_features().
 * @return bool Whether the feature is on.
 */
function wp_presence_feature_enabled( $feature ) {
	$enabled = wp_presence_feature_stored_choice( get_option( 'wp_presence_features', array() ), $feature );

	if ( $enabled && is_multisite() ) {
		$enabled = wp_presence_feature_stored_choice( get_site_option( 'wp_presence_network_features', array() ), $feature );
	}

	/**
	 * Filters whether a piece of the plugin is switched on for this site.
	 *
	 * Read when the plugin loads, so a callback has to be added before then,
	 * from a must-use plugin or an earlier-loading plugin.
	 *
	 * @since 0.16.0
	 *
	 * @param bool   $enabled Whether the feature is on. Default is the stored
	 *                        choice, true when there is none.
	 * @param string $feature Feature key.
	 */
	return (bool) apply_filters( 'wp_presence_feature_enabled', $enabled, $feature );
}

/**
 * Reads one feature's choice out of a stored option, on when it holds none.
 *
 * @access private
 *
 * @since 0.16.0
 *
 * @param mixed  $stored  The stored option value.
 * @param string $feature Feature key.
 * @return bool Whether the stored choice leaves the feature on.
 */
function wp_presence_feature_stored_choice( $stored, $feature ) {
	if ( ! is_array( $stored ) || ! array_key_exists( $feature, $stored ) ) {
		return true;
	}

	return (bool) $stored[ $feature ];
}

/**
 * Casts submitted feature checkboxes to the 1 or 0 the option stores.
 *
 * Every known feature is written, on or off, so an unchecked box is stored as
 * off rather than missing, and missing keeps meaning "never chosen". Keys that
 * are not features are dropped.
 *
 * @access private
 *
 * @since 0.16.0
 *
 * @param mixed $value The submitted value.
 * @return array<string, int> Each feature's choice, 1 for on and 0 for off.
 */
function wp_presence_sanitize_features( $value ) {
	$value     = is_array( $value ) ? $value : array();
	$sanitized = array();

	foreach ( array_keys( wp_presence_get_features() ) as $feature ) {
		$sanitized[ $feature ] = empty( $value[ $feature ] ) ? 0 : 1;
	}

	return $sanitized;
}
