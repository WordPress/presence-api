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
 * Like Gutenberg's, the switches sit on the plugin's own page under Settings,
 * apart from the recording switch on Settings > General that a site keeps.
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
 * @since 0.15.0
 *
 * @return array<string, array{label: string, description: string}> Features keyed by feature.
 */
function wp_presence_get_features() {
	return array(
		'post-locks' => array(
			'label'       => __( 'Post locks', 'presence-api' ),
			'description' => __( 'Keep post locks in the presence table instead of post meta, so refreshing a lock does not make cached post queries stale.', 'presence-api' ),
		),
		'post-list'  => array(
			'label'       => __( 'Posts list', 'presence-api' ),
			'description' => __( 'Show who has each post open in an Editors column on post lists.', 'presence-api' ),
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
 * Hooks are registered once, when the plugin loads, so the answer for the
 * site a request starts on holds for the whole request, including any
 * switch_to_blog() inside it.
 *
 * Unlike wp_presence_recording_enabled(), switching a feature off does not
 * stop rows being written: plugins that keep their own rows in the table only
 * call the functions every feature is built on, and those are never switched
 * off.
 *
 * @since 0.15.0
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
	 * @since 0.15.0
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
 * @since 0.15.0
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
 * @since 0.15.0
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

/**
 * Registers the features option and a row per feature for the site and network pages.
 *
 * @access private
 *
 * @since 0.15.0
 */
function wp_presence_register_feature_settings() {
	register_setting(
		'presence-api',
		'wp_presence_features',
		array(
			'type'              => 'object',
			'default'           => array(),
			'sanitize_callback' => 'wp_presence_sanitize_features',
			'show_in_rest'      => false,
		)
	);

	$pages = array( 'presence-api' => 'wp_presence_features' );

	if ( is_multisite() ) {
		$pages['presence-api-network'] = 'wp_presence_network_features';
	}

	foreach ( $pages as $page => $option ) {
		add_settings_section( 'wp_presence_features', __( 'Features', 'presence-api' ), 'wp_presence_render_features_section', $page );

		foreach ( wp_presence_get_features() as $feature => $details ) {
			add_settings_field(
				$option . '_' . $feature,
				$details['label'],
				'wp_presence_render_feature_field',
				$page,
				'wp_presence_features',
				array(
					'label_for'   => $option . '_' . $feature,
					'option'      => $option,
					'feature'     => $feature,
					'description' => $details['description'],
				)
			);
		}
	}
}

/**
 * Adds the plugin's own page under Settings, named after the plugin as Gutenberg's is.
 *
 * @access private
 *
 * @since 0.15.0
 */
function wp_presence_add_features_page() {
	add_submenu_page( 'options-general.php', 'Presence API', 'Presence API', 'manage_options', 'presence-api', 'wp_presence_render_features_page' );
}

/**
 * Adds the network's copy of the page under Network Admin > Settings.
 *
 * @access private
 *
 * @since 0.15.0
 */
function wp_presence_add_network_features_page() {
	add_submenu_page( 'settings.php', 'Presence API', 'Presence API', 'manage_network_options', 'presence-api', 'wp_presence_render_network_features_page' );
}

/**
 * Renders the site's features page, saved through options.php.
 *
 * @access private
 *
 * @since 0.15.0
 */
function wp_presence_render_features_page() {
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<form method="post" action="options.php">
			<?php
			settings_fields( 'presence-api' );
			do_settings_sections( 'presence-api' );
			submit_button();
			?>
		</form>
	</div>
	<?php
}

/**
 * Renders the network's features page, saved by wp_presence_save_network_features() because options.php only saves site options.
 *
 * @access private
 *
 * @since 0.15.0
 */
function wp_presence_render_network_features_page() {
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only decides whether to show a notice. ?>
		<?php if ( isset( $_GET['updated'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'presence-api' ); ?></p></div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=wp_presence_features' ) ); ?>">
			<?php
			wp_nonce_field( 'wp_presence_network_features' );
			do_settings_sections( 'presence-api-network' );
			submit_button();
			?>
		</form>
	</div>
	<?php
}

/**
 * Saves the network's features page and returns to it.
 *
 * @access private
 *
 * @since 0.15.0
 */
function wp_presence_save_network_features() {
	check_admin_referer( 'wp_presence_network_features' );

	if ( ! current_user_can( 'manage_network_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to manage options for this network.', 'presence-api' ), 403 );
	}

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Reduced to known keys and 0 or 1 by wp_presence_sanitize_features().
	$features = isset( $_POST['wp_presence_network_features'] ) ? wp_unslash( $_POST['wp_presence_network_features'] ) : array();

	update_site_option( 'wp_presence_network_features', wp_presence_sanitize_features( $features ) );

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'    => 'presence-api',
				'updated' => 'true',
			),
			network_admin_url( 'settings.php' )
		)
	);
	exit;
}

/**
 * Renders the sentence above the feature rows, which differs between the site and the network.
 *
 * @access private
 *
 * @since 0.15.0
 */
function wp_presence_render_features_section() {
	$network = is_network_admin();
	?>
	<p>
		<?php
		if ( $network ) {
			esc_html_e( 'Switching a feature off here turns it off on every site, whatever an individual site has chosen.', 'presence-api' );
		} else {
			esc_html_e( 'These switches last only while WordPress core adopts each feature, and with recording off the ones that show who is online have nothing to show.', 'presence-api' );
		}
		?>
	</p>
	<?php
}

/**
 * Renders one feature's checkbox, with a hidden 0 before it so an unchecked box is stored as off rather than missing.
 *
 * @access private
 *
 * @since 0.15.0
 *
 * @param array $args The field's option name, feature key, description and input ID.
 */
function wp_presence_render_feature_field( $args ) {
	$network = 'wp_presence_network_features' === $args['option'];
	$stored  = $network ? get_site_option( $args['option'], array() ) : get_option( $args['option'], array() );
	$name    = $args['option'] . '[' . $args['feature'] . ']';
	?>
	<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0" />
	<label>
		<input type="checkbox" id="<?php echo esc_attr( $args['label_for'] ); ?>" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( wp_presence_feature_stored_choice( $stored, $args['feature'] ) ); ?> />
		<?php echo esc_html( $args['description'] ); ?>
	</label>
	<?php if ( ! $network && is_multisite() && ! wp_presence_feature_stored_choice( get_site_option( 'wp_presence_network_features', array() ), $args['feature'] ) ) : ?>
		<p class="description"><?php esc_html_e( 'Switched off for every site on this network.', 'presence-api' ); ?></p>
	<?php endif; ?>
	<?php
}
