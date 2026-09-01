<?php
/**
 * Privacy: policy content, personal data exporter and eraser for presence data.
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

/**
 * Reads every stored presence row for one user on the current site.
 *
 * Unfiltered by TTL, so the export reports exactly what the eraser would
 * delete. A row past the timeout is no longer presence, but it is still held
 * until the next cleanup run.
 *
 * @since 0.3.0
 *
 * @access private
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 *
 * @param int $user_id The user whose rows to read.
 * @return object[] Rows with room, data and date_gmt.
 */
function wp_presence_get_rows_for_user( $user_id ) {
	global $wpdb;

	if ( ! wp_presence_has_table() ) {
		return array();
	}

	// Presence changes on every heartbeat, so a cached read would be stale.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return $wpdb->get_results(
		$wpdb->prepare(
			"SELECT room, data, date_gmt FROM {$wpdb->presence} WHERE user_id = %d ORDER BY date_gmt DESC",
			$user_id
		)
	);
}

/**
 * Registers presence with Tools > Export Personal Data.
 *
 * @since 0.3.0
 *
 * @access private
 *
 * @param array $exporters Registered exporters, keyed by slug.
 * @return array Exporters with presence added.
 */
function wp_presence_register_personal_data_exporter( $exporters ) {
	$exporters['presence-api'] = array(
		'exporter_friendly_name' => __( 'Presence API', 'presence-api' ),
		'callback'               => 'wp_presence_personal_data_exporter',
	);

	return $exporters;
}

/**
 * Exports the presence held for one user.
 *
 * Always returns an item, even with nothing stored. An exporter returning an
 * empty data array renders as nothing at all, which reads the same as never
 * having registered, and the statement that nothing is kept is the point.
 *
 * @since 0.3.0
 *
 * @access private
 *
 * @param string $email_address Email address of the user being exported.
 * @return array Export response.
 */
function wp_presence_personal_data_exporter( $email_address ) {
	$user = get_user_by( 'email', $email_address );

	// Presence is only recorded for signed-in users, so an address with no
	// account has none by definition.
	if ( ! $user ) {
		return array(
			'data' => array(),
			'done' => true,
		);
	}

	$data = array();

	foreach ( wp_presence_get_rows_for_user( $user->ID ) as $row ) {
		$state  = json_decode( $row->data, true );
		$state  = is_array( $state ) ? $state : array();
		$parsed = wp_presence_parse_room( $row->room );

		$data[] = array(
			'name'  => __( 'Screen', 'presence-api' ),
			'value' => isset( $state['screen'] ) ? $state['screen'] : $row->room,
		);

		if ( $parsed ) {
			$data[] = array(
				'name'  => __( 'Post being edited', 'presence-api' ),
				'value' => get_the_title( $parsed['post_id'] ),
			);
		}

		$data[] = array(
			'name'  => __( 'Last active', 'presence-api' ),
			'value' => $row->date_gmt . ' GMT',
		);
	}

	if ( ! $data ) {
		$data[] = array(
			'name'  => __( 'Presence', 'presence-api' ),
			'value' => __( 'No presence is recorded for this account.', 'presence-api' ),
		);
	}

	$data[] = array(
		'name'  => __( 'Retention', 'presence-api' ),
		'value' => sprintf(
			/* translators: %s: Number of seconds presence data is retained. */
			__( 'Presence is deleted at most %s seconds after the last activity. Nothing is kept beyond that, so there is no history to export.', 'presence-api' ),
			number_format_i18n( wp_presence_get_timeout( WP_PRESENCE_DEFAULT_TTL ) )
		),
	);

	return array(
		'data' => array(
			array(
				'group_id'          => 'presence',
				'group_label'       => __( 'Presence', 'presence-api' ),
				'group_description' => __( 'Which screen you are viewing and which post you are editing, while you are signed in.', 'presence-api' ),
				'item_id'           => 'presence-' . $user->ID,
				'data'              => $data,
			),
		),
		'done' => true,
	);
}

/**
 * Registers presence with Tools > Erase Personal Data.
 *
 * @since 0.3.0
 *
 * @access private
 *
 * @param array $erasers Registered erasers, keyed by slug.
 * @return array Erasers with presence added.
 */
function wp_presence_register_personal_data_eraser( $erasers ) {
	$erasers['presence-api'] = array(
		'eraser_friendly_name' => __( 'Presence API', 'presence-api' ),
		'callback'             => 'wp_presence_personal_data_eraser',
	);

	return $erasers;
}

/**
 * Erases the presence held for one user on the current site.
 *
 * @since 0.3.0
 *
 * @access private
 *
 * @param string $email_address Email address of the user being erased.
 * @return array Erasure response.
 */
function wp_presence_personal_data_eraser( $email_address ) {
	$response = array(
		'items_removed'  => false,
		'items_retained' => false,
		'messages'       => array(),
		'done'           => true,
	);

	$user = get_user_by( 'email', $email_address );

	// Reported as removed only when there was something to remove, so a request
	// against an account with nothing stored does not claim otherwise.
	if ( ! $user || ! wp_presence_get_rows_for_user( $user->ID ) ) {
		return $response;
	}

	if ( wp_remove_user_presence( $user->ID ) ) {
		$response['items_removed'] = true;
	} else {
		$response['items_retained'] = true;
		$response['messages'][]     = __( 'Presence could not be deleted.', 'presence-api' );
	}

	return $response;
}
