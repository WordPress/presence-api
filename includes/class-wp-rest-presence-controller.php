<?php
/**
 * REST API: WP_REST_Presence_Controller class
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core class used to manage presence via the REST API.
 *
 * @see WP_REST_Controller
 */
class WP_REST_Presence_Controller extends WP_REST_Controller {

	/**
	 * Maximum allowed size in bytes for the data payload.
	 *
	 * @var int
	 */
	const MAX_DATA_SIZE = 10240;

	/**
	 * Maximum nesting depth for the data payload.
	 *
	 * @var int
	 */
	const MAX_DATA_DEPTH = 3;

	/**
	 * Maximum number of presence entries a single user may hold.
	 *
	 * @var int
	 */
	const MAX_ENTRIES_PER_USER = 50;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = 'wp-presence/v1';
		$this->rest_base = 'presence';
	}

	/**
	 * Registers the routes for presence.
	 *
	 * @see register_rest_route()
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(
						'room'     => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'per_page' => array(
							'type'              => 'integer',
							'default'           => 100,
							'minimum'           => 1,
							'maximum'           => 100,
							'sanitize_callback' => 'absint',
						),
						'page'     => array(
							'type'              => 'integer',
							'default'           => 1,
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => array(
						'room'      => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'client_id' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'data'      => array(
							'type'              => 'object',
							'default'           => array(),
							'validate_callback' => array( $this, 'validate_data_param' ),
							'sanitize_callback' => array( $this, 'sanitize_data_param' ),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'delete_item_permissions_check' ),
					'args'                => array(
						'room'      => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'client_id' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/rooms',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_rooms' ),
					'permission_callback' => array( $this, 'get_rooms_permissions_check' ),
					'args'                => array(
						'per_page' => array(
							'type'              => 'integer',
							'default'           => 50,
							'minimum'           => 1,
							'maximum'           => 100,
							'sanitize_callback' => 'absint',
						),
						'page'     => array(
							'type'              => 'integer',
							'default'           => 1,
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * Validates the data parameter size and type.
	 *
	 * @param mixed           $value   The data parameter value.
	 * @param WP_REST_Request $request Full details about the request.
	 * @param string          $param   The parameter name.
	 * @return true|WP_Error True if valid, WP_Error otherwise.
	 */
	public function validate_data_param( $value, $request, $param ) {
		if ( ! is_array( $value ) && ! is_object( $value ) ) {
			return new WP_Error(
				'rest_invalid_type',
				/* translators: %s: Parameter name. */
				sprintf( __( '%s must be an object.', 'presence-api' ), $param ),
				array( 'status' => 400 )
			);
		}

		$encoded = wp_json_encode( $value );

		if ( strlen( $encoded ) > self::MAX_DATA_SIZE ) {
			return new WP_Error(
				'rest_presence_data_too_large',
				__( 'Presence data payload exceeds the maximum allowed size.', 'presence-api' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Sanitizes the data parameter values recursively.
	 *
	 * Preserves scalar types (strings, integers, floats, booleans)
	 * and recurses into nested arrays up to MAX_DATA_DEPTH levels.
	 *
	 * @param mixed $value The data parameter value.
	 * @return array Sanitized data array.
	 */
	public function sanitize_data_param( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		return self::sanitize_data_recursive( $value, 0 );
	}

	/**
	 * Recursively sanitizes data values with a depth limit.
	 *
	 * @param array $data  The data to sanitize.
	 * @param int   $depth Current nesting depth.
	 * @return array Sanitized data.
	 */
	private static function sanitize_data_recursive( $data, $depth ) {
		if ( $depth >= self::MAX_DATA_DEPTH ) {
			return array();
		}

		$sanitized = array();

		foreach ( $data as $key => $value ) {
			$key = sanitize_text_field( (string) $key );

			if ( is_array( $value ) ) {
				$sanitized[ $key ] = self::sanitize_data_recursive( $value, $depth + 1 );
			} elseif ( is_string( $value ) ) {
				$sanitized[ $key ] = sanitize_text_field( $value );
			} elseif ( is_int( $value ) || is_float( $value ) || is_bool( $value ) ) {
				$sanitized[ $key ] = $value;
			}
		}

		return $sanitized;
	}

	/**
	 * Checks if the current user has permission to read presence.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access, WP_Error otherwise.
	 */
	public function get_items_permissions_check( $request ) {
		$room = $request->get_param( 'room' );

		if ( ! wp_can_access_presence_room( $room ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to view presence in this room.', 'presence-api' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Retrieves presence entries for a room.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response Response object.
	 */
	public function get_items( $request ) {
		global $wpdb;

		$room     = $request->get_param( 'room' );
		$per_page = $request->get_param( 'per_page' );
		$page     = $request->get_param( 'page' );
		$offset   = ( $page - 1 ) * $per_page;

		$timeout = wp_presence_get_timeout( WP_PRESENCE_DEFAULT_TTL );
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - $timeout );

		// Get total count for pagination headers.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->presence} WHERE room = %s AND date_gmt > %s",
				$room,
				$cutoff
			)
		);

		// Fetch only the requested page.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT room, client_id, user_id, data, date_gmt FROM {$wpdb->presence} WHERE room = %s AND date_gmt > %s ORDER BY date_gmt DESC LIMIT %d OFFSET %d",
				$room,
				$cutoff,
				$per_page,
				$offset
			)
		);

		if ( ! $results ) {
			$results = array();
		}

		foreach ( $results as $row ) {
			$decoded   = json_decode( $row->data, true );
			$row->data = is_array( $decoded ) ? $decoded : array();
		}

		$data = array();
		foreach ( $results as $entry ) {
			$data[] = $this->prepare_item_for_response( $entry, $request )->get_data();
		}

		$response = rest_ensure_response( $data );

		$response->header( 'X-WP-Total', $total );
		$response->header( 'X-WP-TotalPages', (int) ceil( $total / $per_page ) );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Checks if the current user has permission to create a presence entry.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access, WP_Error otherwise.
	 */
	public function create_item_permissions_check( $request ) {
		$room = $request->get_param( 'room' );

		if ( ! wp_can_access_presence_room( $room ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to set presence in this room.', 'presence-api' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Creates or updates a presence entry.
	 *
	 * Validates that the client_id is not already claimed by a different user
	 * in the same room to prevent impersonation.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, WP_Error on failure.
	 */
	public function create_item( $request ) {
		global $wpdb;

		$room      = $request->get_param( 'room' );
		$client_id = $request->get_param( 'client_id' );
		$data      = $request->get_param( 'data' );

		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$current_user_id = get_current_user_id();

		// Enforce per-user entry limit (only count non-expired entries).
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - wp_presence_get_timeout( WP_PRESENCE_DEFAULT_TTL ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$user_entry_count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->presence} WHERE user_id = %d AND date_gmt > %s",
				$current_user_id,
				$cutoff
			)
		);

		if ( (int) $user_entry_count >= self::MAX_ENTRIES_PER_USER ) {
			return new WP_Error(
				'rest_presence_limit_exceeded',
				__( 'You have reached the maximum number of presence entries.', 'presence-api' ),
				array( 'status' => 429 )
			);
		}

		// Prevent overwriting another user's presence entry.
		//
		// Note: A narrow race window exists between the SELECT and INSERT below.
		// This is acceptable because the UNIQUE KEY on (room, client_id) prevents
		// duplicate entries, and Heartbeat re-establishes correct ownership within
		// seconds. Table-level locking is not warranted for this use case.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing_user_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->presence} WHERE room = %s AND client_id = %s",
				$room,
				$client_id
			)
		);

		if ( null !== $existing_user_id && (int) $existing_user_id !== $current_user_id ) {
			return new WP_Error(
				'rest_presence_client_id_conflict',
				__( 'This client_id is already in use by another user.', 'presence-api' ),
				array( 'status' => 409 )
			);
		}

		$result = wp_set_presence( $room, $client_id, $data, $current_user_id );

		if ( ! $result ) {
			return new WP_Error(
				'rest_presence_failed',
				__( 'Could not set presence.', 'presence-api' ),
				array( 'status' => 500 )
			);
		}

		$entry = (object) array(
			'room'      => $room,
			'client_id' => $client_id,
			'user_id'   => $current_user_id,
			'data'      => $data,
			'date_gmt'  => gmdate( 'Y-m-d H:i:s' ),
		);

		return rest_ensure_response( $this->prepare_item_for_response( $entry, $request )->get_data() );
	}

	/**
	 * Checks if the current user has permission to delete a presence entry.
	 *
	 * Users may only delete their own presence entries unless they have
	 * the 'manage_options' capability. Ownership is determined by the
	 * user_id column in the database, not by the client_id format.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access, WP_Error otherwise.
	 */
	public function delete_item_permissions_check( $request ) {
		global $wpdb;

		$room      = $request->get_param( 'room' );
		$client_id = $request->get_param( 'client_id' );

		if ( ! wp_can_access_presence_room( $room ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to remove presence in this room.', 'presence-api' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		// Admins can delete any entry.
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		// Look up the entry's owner.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$entry_user_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->presence} WHERE room = %s AND client_id = %s",
				$room,
				$client_id
			)
		);

		// Entry doesn't exist — allow the delete (it's a no-op).
		if ( null === $entry_user_id ) {
			return true;
		}

		if ( get_current_user_id() !== (int) $entry_user_id ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to remove another user\'s presence.', 'presence-api' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Deletes a presence entry.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response Response object.
	 */
	public function delete_item( $request ) {
		$room      = $request->get_param( 'room' );
		$client_id = $request->get_param( 'client_id' );

		wp_remove_presence( $room, $client_id );

		return rest_ensure_response(
			array(
				'deleted' => true,
				'room'    => $room,
			)
		);
	}

	/**
	 * Checks if the current user has permission to list rooms.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access, WP_Error otherwise.
	 */
	public function get_rooms_permissions_check( $request ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to view presence rooms.', 'presence-api' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Retrieves all active rooms with user counts and members.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response Response object.
	 */
	public function get_rooms( $request ) {
		$per_page = $request->get_param( 'per_page' );
		$page     = $request->get_param( 'page' );

		$rooms = wp_get_active_rooms();
		$total = count( $rooms );

		// Rooms are aggregated in PHP (GROUP BY + user hydration), so paginate the result.
		$paged_rooms = array_slice( $rooms, ( $page - 1 ) * $per_page, $per_page );

		$response = rest_ensure_response( $paged_rooms );

		$response->header( 'X-WP-Total', $total );
		$response->header( 'X-WP-TotalPages', (int) ceil( $total / $per_page ) );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Prepares a presence entry for the REST response.
	 *
	 * @param object          $item    Presence entry object.
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response Response object.
	 */
	public function prepare_item_for_response( $item, $request ) {
		$schema = $this->get_item_schema();
		$fields = $this->get_fields_for_response( $request );

		$data = array();

		if ( rest_is_field_included( 'room', $fields ) ) {
			$data['room'] = $item->room;
		}

		if ( rest_is_field_included( 'client_id', $fields ) ) {
			$data['client_id'] = $item->client_id;
		}

		if ( rest_is_field_included( 'user_id', $fields ) ) {
			$data['user_id'] = (int) $item->user_id;
		}

		if ( rest_is_field_included( 'data', $fields ) ) {
			$data['data'] = $item->data;
		}

		if ( rest_is_field_included( 'date_gmt', $fields ) ) {
			$data['date_gmt'] = $item->date_gmt;
		}

		$context = ! empty( $request['context'] ) ? $request['context'] : 'view';
		$data    = $this->add_additional_fields_to_object( $data, $request );
		$data    = $this->filter_response_by_context( $data, $context );

		return rest_ensure_response( $data );
	}

	/**
	 * Retrieves the presence entry schema, conforming to JSON Schema.
	 *
	 * @return array Item schema data.
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'presence',
			'type'       => 'object',
			'properties' => array(
				'room'      => array(
					'description' => __( 'The presence room identifier.', 'presence-api' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => false,
				),
				'client_id' => array(
					'description' => __( 'The client identifier within the room.', 'presence-api' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => false,
				),
				'user_id'   => array(
					'description' => __( 'The WordPress user ID associated with this presence entry.', 'presence-api' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'data'      => array(
					'description'          => __( 'Arbitrary presence state data.', 'presence-api' ),
					'type'                 => 'object',
					'context'              => array( 'view', 'edit' ),
					'additionalProperties' => true,
				),
				'date_gmt'  => array(
					'description' => __( 'The date the presence was last updated, in GMT.', 'presence-api' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}
}
