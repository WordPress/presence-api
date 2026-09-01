<?php
/**
 * Settings: the recording switch on Settings > General and Network Settings.
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the site recording option and its field on Settings > General.
 *
 * @access private
 */
function wp_presence_register_settings() {
	register_setting(
		'general',
		'wp_presence_recording',
		array(
			'type'              => 'boolean',
			'default'           => true,
			'sanitize_callback' => 'wp_presence_sanitize_checkbox',
			'show_in_rest'      => false,
		)
	);

	add_settings_field(
		'wp_presence_recording',
		__( 'Presence', 'presence-api' ),
		'wp_presence_render_recording_field',
		'general',
		'default',
		array( 'label_for' => 'wp_presence_recording' )
	);
}

/**
 * Casts a checkbox to the '1' or '0' the option stores.
 *
 * A boolean false is indistinguishable from an absent option, so update_option()
 * would discard it as unchanged and leave recording on.
 *
 * @access private
 *
 * @param mixed $value The submitted value.
 * @return string '1' when recording is on, '0' when off.
 */
function wp_presence_sanitize_checkbox( $value ) {
	return $value ? '1' : '0';
}

/**
 * Renders the recording checkbox on Settings > General.
 *
 * @access private
 */
function wp_presence_render_recording_field() {
	$enabled = (bool) get_option( 'wp_presence_recording', true );
	?>
	<input type="hidden" name="wp_presence_recording" value="0" />
	<label>
		<input type="checkbox" id="wp_presence_recording" name="wp_presence_recording" value="1" <?php checked( $enabled ); ?> />
		<?php esc_html_e( 'Record who is working where in the admin', 'presence-api' ); ?>
	</label>
	<p class="description">
		<?php esc_html_e( 'Presence rows expire on their own, so switching this off empties every presence screen within a few minutes.', 'presence-api' ); ?>
	</p>
	<?php
}

/**
 * Renders the network recording checkbox on Network Admin > Settings.
 *
 * Network Settings has no Settings API equivalent, so the field is printed on
 * wpmu_options and read back in wp_presence_save_network_settings().
 *
 * @access private
 */
function wp_presence_render_network_settings() {
	$enabled = (bool) get_site_option( 'wp_presence_network_recording', true );
	?>
	<h2><?php esc_html_e( 'Presence', 'presence-api' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Recording', 'presence-api' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="wp_presence_network_recording" value="1" <?php checked( $enabled ); ?> />
					<?php esc_html_e( 'Record presence on sites in this network', 'presence-api' ); ?>
				</label>
				<p class="description">
					<?php esc_html_e( 'Switching this off stops recording everywhere, whatever an individual site has chosen.', 'presence-api' ); ?>
				</p>
			</td>
		</tr>
	</table>
	<?php
}

/**
 * Saves the network recording checkbox.
 *
 * Core verifies the siteoptions nonce before firing this action.
 *
 * @access private
 */
function wp_presence_save_network_settings() {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by core in wp-admin/network/settings.php.
	$submitted = ! empty( $_POST['wp_presence_network_recording'] );

	update_site_option( 'wp_presence_network_recording', wp_presence_sanitize_checkbox( $submitted ) );
}
