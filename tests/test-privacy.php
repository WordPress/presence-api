<?php
/**
 * Tests for the privacy policy content, exporter and eraser.
 *
 * @package Presence_API
 *
 * @group presence
 */

class WP_Test_Presence_Privacy extends WP_Presence_UnitTestCase {

	/**
	 * Editor whose presence is exported and erased.
	 *
	 * @var int
	 */
	protected static $editor_id;

	public static function wpSetUpBeforeClass( $factory ) {
		self::$editor_id = $factory->user->create(
			array(
				'role'       => 'editor',
				'user_email' => 'editor@presence.test',
			)
		);
	}

	/**
	 * A site that widens the TTL keeps presence longer than the constant says,
	 * so a hard-coded number would understate its own retention.
	 *
	 * @covers ::wp_presence_get_privacy_policy_content
	 */
	public function test_the_retention_window_follows_the_ttl_filter() {
		add_filter(
			'wp_presence_default_ttl',
			static function () {
				return 900;
			}
		);

		$this->assertStringContainsString( '900 seconds', wp_presence_get_privacy_policy_content() );
	}

	/**
	 * Naming the switch is the acceptance criterion #400 waits on, so the
	 * mention is the deliverable rather than incidental wording.
	 *
	 * @covers ::wp_presence_get_privacy_policy_content
	 */
	public function test_the_content_names_the_recording_switch() {
		$this->assertStringContainsString(
			'wp_presence_recording_enabled',
			wp_presence_get_privacy_policy_content()
		);
	}

	/**
	 * Core only collects suggestions during admin_init. Unhooked, the guide
	 * stays empty and nothing else in the plugin would notice.
	 *
	 * @covers ::wp_presence_add_privacy_policy_content
	 */
	public function test_the_content_is_registered_on_admin_init() {
		$this->assertNotFalse(
			has_action( 'admin_init', 'wp_presence_add_privacy_policy_content' )
		);
	}

	/**
	 * Without a registration on both filters, neither tool knows presence
	 * exists and the Tools screens are silent about it.
	 *
	 * @covers ::wp_presence_register_personal_data_exporter
	 * @covers ::wp_presence_register_personal_data_eraser
	 */
	public function test_presence_appears_on_both_tools_screens() {
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );

		$this->assertArrayHasKey( 'presence-api', $exporters );
		$this->assertArrayHasKey( 'presence-api', $erasers );
	}

	/**
	 * An exporter returning no items renders as nothing at all, which reads the
	 * same as never registering. The statement that nothing is kept is the
	 * useful output, so it has to survive an account with no rows.
	 *
	 * @covers ::wp_presence_personal_data_exporter
	 */
	public function test_the_export_states_that_nothing_is_kept_when_no_presence_is_stored() {
		$export = wp_presence_personal_data_exporter( 'editor@presence.test' );

		$this->assertCount( 1, $export['data'] );
		$this->assertStringContainsString(
			'no history to export',
			implode( ' ', wp_list_pluck( $export['data'][0]['data'], 'value' ) )
		);
	}

	/**
	 * The export has to match what the eraser would delete, and the eraser
	 * takes every row. A TTL filter here would hide rows the site still holds.
	 *
	 * @covers ::wp_presence_personal_data_exporter
	 */
	public function test_the_export_reports_every_stored_row_including_expired_ones() {
		global $wpdb;

		wp_set_presence( 'admin', 'client-1', array( 'screen' => 'dashboard' ), self::$editor_id );
		wp_set_presence( 'admin', 'client-2', array( 'screen' => 'edit-post' ), self::$editor_id );

		$wpdb->update(
			$wpdb->presence,
			array( 'date_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( WP_PRESENCE_DEFAULT_TTL * 2 ) ) ),
			array( 'client_id' => 'client-1' ),
			array( '%s' ),
			array( '%s' )
		);

		$export = wp_presence_personal_data_exporter( 'editor@presence.test' );
		$values = wp_list_pluck( $export['data'][0]['data'], 'value' );

		$this->assertContains( 'dashboard', $values, 'The expired row is still stored, so it is still exported.' );
		$this->assertContains( 'edit-post', $values );
	}

	/**
	 * @covers ::wp_presence_personal_data_eraser
	 */
	public function test_the_eraser_clears_the_users_rows() {
		wp_set_presence( 'admin', 'client-1', array( 'screen' => 'dashboard' ), self::$editor_id );

		$response = wp_presence_personal_data_eraser( 'editor@presence.test' );

		$this->assertTrue( $response['items_removed'] );
		$this->assertEmpty( $this->presence_for_user( self::$editor_id ) );
	}

	/**
	 * Reporting a removal that never happened tells a data subject their data
	 * was deleted on a site that never held any.
	 *
	 * @covers ::wp_presence_personal_data_eraser
	 */
	public function test_the_eraser_reports_no_removal_when_nothing_is_stored() {
		$response = wp_presence_personal_data_eraser( 'editor@presence.test' );

		$this->assertFalse( $response['items_removed'] );
		$this->assertFalse( $response['items_retained'] );
	}
}
