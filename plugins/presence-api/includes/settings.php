<?php
/**
 * Settings: the recording and feature switches on Settings > General and
 * Network Settings.
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
 *
 * @since 0.3.0
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

	register_setting(
		'general',
		'wp_presence_features',
		array(
			'type'              => 'object',
			'default'           => array(),
			'sanitize_callback' => 'wp_presence_sanitize_features',
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
 * @since 0.3.0
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
 *
 * @since 0.3.0
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
	wp_presence_render_feature_checkboxes( 'wp_presence_features', get_option( 'wp_presence_features', array() ) );
}

/**
 * Renders a checkbox per feature, posted as an array under one option name.
 *
 * Each box has a hidden 0 before it, so an unchecked box still posts and is
 * stored as off rather than missing, which reads as on.
 *
 * @access private
 *
 * @since 0.16.0
 *
 * @param string $name   The option name the boxes post under.
 * @param mixed  $stored The stored option value.
 */
function wp_presence_render_feature_checkboxes( $name, $stored ) {
	$features = wp_presence_get_features();

	if ( empty( $features ) ) {
		return;
	}
	?>
	<fieldset>
		<legend class="screen-reader-text"><?php esc_html_e( 'Presence features', 'presence-api' ); ?></legend>
		<?php foreach ( $features as $feature => $details ) : ?>
			<?php
			$field_name     = $name . '[' . $feature . ']';
			$description_id = $name . '-' . $feature . '-description';
			?>
			<input type="hidden" name="<?php echo esc_attr( $field_name ); ?>" value="0" />
			<label>
				<input type="checkbox" name="<?php echo esc_attr( $field_name ); ?>" value="1" aria-describedby="<?php echo esc_attr( $description_id ); ?>" <?php checked( wp_presence_feature_stored_choice( $stored, $feature ) ); ?> />
				<?php echo esc_html( $details['label'] ); ?>
			</label>
			<p class="description" id="<?php echo esc_attr( $description_id ); ?>"><?php echo esc_html( $details['description'] ); ?></p>
		<?php endforeach; ?>
	</fieldset>
	<?php
}

/**
 * Renders the network recording checkbox on Network Admin > Settings.
 *
 * Network Settings has no Settings API equivalent, so the field is printed on
 * wpmu_options and read back in wp_presence_save_network_settings().
 *
 * @access private
 *
 * @since 0.3.0
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
		<?php if ( wp_presence_get_features() ) : ?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Features', 'presence-api' ); ?></th>
			<td>
				<?php wp_presence_render_feature_checkboxes( 'wp_presence_network_features', get_site_option( 'wp_presence_network_features', array() ) ); ?>
				<p class="description">
					<?php esc_html_e( 'Switching a feature off here turns it off on every site, whatever an individual site has chosen.', 'presence-api' ); ?>
				</p>
			</td>
		</tr>
		<?php endif; ?>
	</table>
	<?php
}

/**
 * Saves the network recording checkbox.
 *
 * Core verifies the siteoptions nonce before firing this action.
 *
 * @access private
 *
 * @since 0.3.0
 */
function wp_presence_save_network_settings() {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by core in wp-admin/network/settings.php.
	$submitted = ! empty( $_POST['wp_presence_network_recording'] );

	update_site_option( 'wp_presence_network_recording', wp_presence_sanitize_checkbox( $submitted ) );

	// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified by core in wp-admin/network/settings.php; sanitized to known keys and 0 or 1 below.
	$features = isset( $_POST['wp_presence_network_features'] ) ? wp_unslash( $_POST['wp_presence_network_features'] ) : array();

	update_site_option( 'wp_presence_network_features', wp_presence_sanitize_features( $features ) );
}
