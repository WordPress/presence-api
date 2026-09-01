<?php
/**
 * Privacy: suggested privacy policy content for presence data.
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the suggested privacy policy text for presence data.
 *
 * The retention window is read through wp_presence_get_timeout() rather than
 * printed from the constant, so a site filtering wp_presence_default_ttl gets
 * a suggestion that matches what it actually stores.
 *
 * @since 0.3.0
 *
 * @access private
 *
 * @return string Policy content, ready for wp_add_privacy_policy_content().
 */
function wp_presence_get_privacy_policy_content() {
	$timeout = wp_presence_get_timeout( WP_PRESENCE_DEFAULT_TTL );

	$content =
		'<p class="privacy-policy-tutorial">' .
		__( 'This site records which signed-in users are currently active, so that people working on the same content can see each other. Rows expire on their own and a scheduled task deletes them, so there is no archive to disclose.', 'presence-api' ) .
		'</p>' .
		'<strong class="privacy-policy-tutorial">' .
		__( 'Suggested text:', 'presence-api' ) .
		'</strong> ' .
		sprintf(
			/* translators: %s: Number of seconds presence data is retained. */
			__( 'While you are signed in, this site records that you are active, which screen you are viewing, and which post you are editing. Other signed-in users who can edit content are able to see this. It is kept for at most %s seconds after your last activity and is then deleted automatically. Nothing is retained beyond that.', 'presence-api' ),
			number_format_i18n( $timeout )
		) .
		'<p class="privacy-policy-tutorial">' .
		__( 'Recording can be switched off for the whole site under Settings &gt; General, or in code with the wp_presence_recording_enabled filter. Remove the paragraph above if you switch it off.', 'presence-api' ) .
		'</p>';

	return wp_kses_post( wpautop( $content, false ) );
}

/**
 * Adds presence to the Privacy Policy Guide.
 *
 * @since 0.3.0
 *
 * @access private
 */
function wp_presence_add_privacy_policy_content() {
	wp_add_privacy_policy_content(
		__( 'Presence API', 'presence-api' ),
		wp_presence_get_privacy_policy_content()
	);
}
