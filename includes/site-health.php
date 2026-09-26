<?php
/**
 * Presence API: Site Health checks.
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the Heartbeat check to Site Health.
 *
 * @access private
 *
 * @since 0.7.1
 *
 * @param array $tests Site Health tests.
 * @return array The tests.
 */
function wp_presence_site_status_tests( $tests ) {
	if ( wp_presence_is_available() ) {
		$tests['direct']['presence_heartbeat'] = array(
			'label' => __( 'Heartbeat keeps presence current', 'presence-api' ),
			'test'  => 'wp_presence_site_health_heartbeat_test',
		);
	}

	return $tests;
}

/**
 * Tests whether Heartbeat refreshes presence rows before they expire.
 *
 * Only a page load writes presence without Heartbeat, so a user who stays on
 * one screen past the TTL drops out of Who's Online while still there.
 *
 * @access private
 *
 * @since 0.7.1
 *
 * @return array The Site Health result.
 */
function wp_presence_site_health_heartbeat_test() {
	$result = array(
		'label'       => __( 'Heartbeat keeps presence current', 'presence-api' ),
		'status'      => 'good',
		'badge'       => array(
			'label' => __( 'Presence', 'presence-api' ),
			'color' => 'blue',
		),
		'description' => '<p>' . __( 'Heartbeat refreshes each user&#8217;s presence while they stay on one screen.', 'presence-api' ) . '</p>',
		'actions'     => '',
		'test'        => 'presence_heartbeat',
	);

	$limit = wp_presence_get_timeout() - wp_presence_ttl_margin();

	if ( ! wp_script_is( 'heartbeat', 'registered' ) ) {
		/* translators: %d: Presence timeout in seconds. */
		$problem = sprintf( __( 'Heartbeat is turned off, so Who&#8217;s Online misses anyone who has not loaded a page in the last %d seconds.', 'presence-api' ), wp_presence_get_timeout() );
	} else {
		// Read what core already filtered for this page, so no callback runs twice.
		$settings = preg_match( '/var heartbeatSettings = (.*);$/m', (string) wp_scripts()->get_data( 'heartbeat', 'data' ), $matches ) ? json_decode( $matches[1], true ) : array();
		$interval = empty( $settings['interval'] ) ? 60 : (int) $settings['interval'];

		// Core ticks a background tab every two minutes, whatever the interval.
		if ( max( $interval, MINUTE_IN_SECONDS * 2 ) <= $limit ) {
			return $result;
		}

		/* translators: %d: Longest Heartbeat interval presence tolerates, in seconds. */
		$problem = sprintf( __( 'Heartbeat runs less often than every %d seconds, so Who&#8217;s Online drops people who stay on one screen.', 'presence-api' ), $limit );
	}

	$result['label']       = __( 'Heartbeat is not keeping presence current', 'presence-api' );
	$result['status']      = 'recommended';
	$result['description'] = '<p>' . $problem . '</p><p>' . __( 'A plugin that controls Heartbeat, or code removing its script, is the usual cause.', 'presence-api' ) . '</p>';

	return $result;
}
