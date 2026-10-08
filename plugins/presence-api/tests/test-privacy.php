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
	 * @covers ::wp_presence_max_expires_in
	 */
	public function test_the_retention_window_follows_the_expiry_cap() {
		add_filter(
			'wp_presence_max_expires_in',
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
	 * Calling the privacy policy registration function registers content successfully.
	 *
	 * @covers ::wp_presence_add_privacy_policy_content
	 * @covers ::wp_presence_get_privacy_policy_content
	 */
	public function test_add_privacy_policy_content() {
		set_current_screen( 'options-privacy' );

		do_action( 'admin_init' );

		$this->assertStringContainsString(
			'wp_presence_recording_enabled',
			wp_presence_get_privacy_policy_content()
		);

		set_current_screen( 'front' );
	}

	/**
	 * An address without an account has no recorded presence by definition.
	 *
	 * @covers ::wp_presence_personal_data_exporter
	 */
	public function test_exporter_returns_early_when_user_does_not_exist() {
		$export = wp_presence_personal_data_exporter( 'nonexistent@presence.test' );

		$this->assertSame(
			array(
				'data' => array(),
				'done' => true,
			),
			$export
		);
	}

	/**
	 * When a user was in a post room, the export names the post being edited and its title.
	 *
	 * @covers ::wp_presence_personal_data_exporter
	 * @covers ::wp_presence_parse_room
	 */
	public function test_export_includes_post_title_when_editing_post() {
		$post_id = self::factory()->post->create(
			array(
				'post_title' => 'Sample Post for Privacy',
			)
		);
		$room    = 'postType/post:' . $post_id;

		wp_set_presence( $room, 'client-post', array( 'screen' => 'post' ), self::$editor_id );

		$export = wp_presence_personal_data_exporter( 'editor@presence.test' );
		$items  = $export['data'][0]['data'];

		$names  = wp_list_pluck( $items, 'name' );
		$values = wp_list_pluck( $items, 'value' );

		$this->assertContains( 'Post being edited', $names );
		$this->assertContains( 'Sample Post for Privacy', $values );
		$this->assertContains( 'post', $values );
	}

	/**
	 * When a state payload omits the screen name, the export falls back to the room identifier.
	 *
	 * @covers ::wp_presence_personal_data_exporter
	 */
	public function test_export_falls_back_to_room_name_when_screen_not_in_state() {
		$room = 'custom-room';

		wp_set_presence( $room, 'client-custom', array(), self::$editor_id );

		$export = wp_presence_personal_data_exporter( 'editor@presence.test' );
		$values = wp_list_pluck( $export['data'][0]['data'], 'value' );

		$this->assertContains( 'custom-room', $values );
	}

	/**
	 * When the presence table has not been provisioned, reading rows returns an empty array.
	 *
	 * @covers ::wp_presence_get_rows_for_user
	 */
	public function test_get_rows_for_user_returns_empty_when_table_is_missing() {
		add_filter( 'option_wp_presence_db_version', '__return_zero' );

		$this->assertSame( array(), wp_presence_get_rows_for_user( self::$editor_id ) );
	}

	/**
	 * An address without an account reports nothing removed and nothing retained.
	 *
	 * @covers ::wp_presence_personal_data_eraser
	 */
	public function test_eraser_returns_early_when_user_does_not_exist() {
		$response = wp_presence_personal_data_eraser( 'nonexistent@presence.test' );

		$this->assertFalse( $response['items_removed'] );
		$this->assertFalse( $response['items_retained'] );
		$this->assertSame( array(), $response['messages'] );
		$this->assertTrue( $response['done'] );
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

	/**
	 * When the database deletion query fails, the eraser reports retained items with an explanatory message.
	 *
	 * @covers ::wp_presence_personal_data_eraser
	 */
	public function test_eraser_reports_retained_when_removal_fails() {
		global $wpdb;

		wp_set_presence( 'admin', 'client-fail', array( 'screen' => 'dashboard' ), self::$editor_id );

		$fail_query = static function ( $query ) {
			if ( false !== stripos( $query, 'DELETE' ) && false !== stripos( $query, 'presence' ) ) {
				return 'INVALID SQL SYNTAX';
			}
			return $query;
		};

		add_filter( 'query', $fail_query );
		$suppress = $wpdb->suppress_errors();

		try {
			$response = wp_presence_personal_data_eraser( 'editor@presence.test' );
		} finally {
			remove_filter( 'query', $fail_query );
			$wpdb->suppress_errors( $suppress );
		}

		$this->assertFalse( $response['items_removed'] );
		$this->assertTrue( $response['items_retained'] );
		$this->assertContains( 'Presence could not be deleted.', $response['messages'] );
	}
}
