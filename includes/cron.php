<?php
/**
 * Cron scheduling for presence cleanup.
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schedules the presence cleanup cron event.
 */
function wp_presence_schedule_cleanup() {
	if ( ! wp_next_scheduled( 'wp_delete_expired_presence_data' ) ) {
		wp_schedule_event( time(), 'wp_presence_every_minute', 'wp_delete_expired_presence_data' );
	}
}

/**
 * Adds a custom one-minute cron interval.
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
