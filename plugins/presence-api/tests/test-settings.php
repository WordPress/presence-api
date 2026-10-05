<?php
/**
 * Tests for the recording settings screens.
 *
 * @package Presence_API
 *
 * @group presence
 */

class WP_Test_Presence_Settings extends WP_Presence_UnitTestCase {

	public function tear_down() {
		unset( $_POST['wp_presence_network_recording'] );
		parent::tear_down();
	}

	private function render( $callback ) {
		ob_start();
		$callback();

		return ob_get_clean();
	}

	/**
	 * A field registered against any other page would never appear on the
	 * screen the switch is meant to live on.
	 *
	 * @covers ::wp_presence_register_settings
	 */
	public function test_the_checkbox_is_registered_on_settings_general() {
		global $wp_settings_fields;

		wp_presence_register_settings();

		$this->assertArrayHasKey( 'wp_presence_recording', $wp_settings_fields['general']['default'] );
	}

	/**
	 * register_setting() is what lets options.php accept the field at all.
	 * Without it the checkbox would post and be discarded.
	 *
	 * @covers ::wp_presence_register_settings
	 */
	public function test_the_option_is_allowed_through_options_php() {
		wp_presence_register_settings();

		$this->assertArrayHasKey( 'wp_presence_recording', get_registered_settings() );
	}

	/**
	 * @covers ::wp_presence_render_recording_field
	 */
	public function test_the_checkbox_follows_the_stored_option() {
		// Read the box itself, so nothing else in the field can satisfy the assertion.
		$checkbox = '/<input type="checkbox" id="wp_presence_recording"[^>]*>/';

		update_option( 'wp_presence_recording', '1' );
		preg_match( $checkbox, $this->render( 'wp_presence_render_recording_field' ), $box );
		$this->assertStringContainsString( 'checked', $box[0] );

		update_option( 'wp_presence_recording', '0' );
		$off = $this->render( 'wp_presence_render_recording_field' );
		preg_match( $checkbox, $off, $box );

		$this->assertStringNotContainsString( 'checked', $box[0] );
		// An unchecked box posts nothing, so the hidden field is what carries the off.
		$this->assertStringContainsString( 'name="wp_presence_recording" value="0"', $off );
	}

	/**
	 * @covers ::wp_presence_render_network_settings
	 */
	public function test_the_network_checkbox_follows_the_stored_option() {
		update_site_option( 'wp_presence_network_recording', '0' );
		preg_match( '/<input type="checkbox" name="wp_presence_network_recording"[^>]*>/', $this->render( 'wp_presence_render_network_settings' ), $box );

		// The feature checkboxes share the screen, so look at this box alone.
		$this->assertStringNotContainsString( 'checked', $box[0] );
	}

	/**
	 * @covers ::wp_presence_save_network_settings
	 */
	public function test_the_network_box_saves_both_ways() {
		// Not seeded first: an install that has never touched the switch is
		// where storing a boolean false would read back as unchanged.
		wp_presence_save_network_settings();

		$this->assertSame( '0', get_site_option( 'wp_presence_network_recording' ), 'An unchecked box posts nothing and should switch recording off.' );

		$_POST['wp_presence_network_recording'] = '1';

		wp_presence_save_network_settings();

		$this->assertTrue( (bool) get_site_option( 'wp_presence_network_recording' ) );
	}
}
