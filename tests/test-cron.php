<?php
/**
 * Tests for cron scheduling (includes/cron.php).
 *
 * @package Presence_API
 *
 * @group presence
 * @group cron
 */
class WP_Test_Presence_Cron extends WP_UnitTestCase {

	const HOOK = 'wp_delete_expired_presence_data';

	public function set_up() {
		parent::set_up();
		// Bootstrap and other tests may have scheduled the cleanup event; start clean.
		wp_clear_scheduled_hook( self::HOOK );
	}

	public function tear_down() {
		wp_clear_scheduled_hook( self::HOOK );
		parent::tear_down();
	}

	/**
	 * @covers ::wp_presence_cron_schedules
	 */
	public function test_cron_schedules_adds_every_minute_interval() {
		$schedules = wp_presence_cron_schedules( array() );

		$this->assertArrayHasKey( 'wp_presence_every_minute', $schedules );
		$this->assertSame( 60, $schedules['wp_presence_every_minute']['interval'] );
		$this->assertArrayHasKey( 'display', $schedules['wp_presence_every_minute'] );
		$this->assertNotEmpty( $schedules['wp_presence_every_minute']['display'], 'The interval should have a display string.' );
	}

	/**
	 * @covers ::wp_presence_cron_schedules
	 */
	public function test_cron_schedules_preserves_existing_schedules() {
		$existing = array(
			'hourly' => array(
				'interval' => HOUR_IN_SECONDS,
				'display'  => 'Once Hourly',
			),
		);

		$schedules = wp_presence_cron_schedules( $existing );

		$this->assertArrayHasKey( 'hourly', $schedules, 'Existing schedules should be preserved.' );
		$this->assertSame( HOUR_IN_SECONDS, $schedules['hourly']['interval'] );
		$this->assertArrayHasKey( 'wp_presence_every_minute', $schedules );
	}

	/**
	 * The plugin registers wp_presence_cron_schedules() on the cron_schedules
	 * filter at load, so the interval is visible through the public API.
	 *
	 * @covers ::wp_presence_cron_schedules
	 */
	public function test_interval_is_registered_via_filter() {
		$schedules = wp_get_schedules();

		$this->assertArrayHasKey( 'wp_presence_every_minute', $schedules, 'The filter should register the interval.' );
		$this->assertSame( 60, $schedules['wp_presence_every_minute']['interval'] );
	}

	/**
	 * @covers ::wp_presence_schedule_cleanup
	 */
	public function test_schedule_cleanup_schedules_the_event() {
		$this->assertFalse( wp_next_scheduled( self::HOOK ), 'No event should be scheduled to begin with.' );

		wp_presence_schedule_cleanup();

		$this->assertNotFalse( wp_next_scheduled( self::HOOK ), 'The cleanup event should be scheduled.' );
		$this->assertSame( 'wp_presence_every_minute', wp_get_schedule( self::HOOK ), 'Cleanup should run on the one-minute interval.' );
	}

	/**
	 * The guard in wp_presence_schedule_cleanup() must prevent a second event.
	 *
	 * @covers ::wp_presence_schedule_cleanup
	 */
	public function test_schedule_cleanup_does_not_double_schedule() {
		wp_presence_schedule_cleanup();
		$first = wp_next_scheduled( self::HOOK );

		wp_presence_schedule_cleanup();
		$second = wp_next_scheduled( self::HOOK );

		$this->assertSame( $first, $second, 'Scheduling twice should not move or duplicate the event.' );
		$this->assertSame( 1, $this->count_scheduled_events( self::HOOK ), 'Exactly one cleanup event should be scheduled.' );
	}

	/**
	 * Counts how many cron events are registered for a hook across all timestamps.
	 *
	 * @param string $hook The hook name.
	 * @return int Number of scheduled events for the hook.
	 */
	private function count_scheduled_events( $hook ) {
		$crons = _get_cron_array();
		if ( ! is_array( $crons ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $crons as $events ) {
			if ( isset( $events[ $hook ] ) ) {
				$count += count( $events[ $hook ] );
			}
		}
		return $count;
	}
}
