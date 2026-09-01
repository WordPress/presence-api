<?php
/**
 * Tests for the suggested privacy policy content.
 *
 * @package Presence_API
 *
 * @group presence
 */

class WP_Test_Presence_Privacy extends WP_Presence_UnitTestCase {

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
}
