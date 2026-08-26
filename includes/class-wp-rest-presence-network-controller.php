<?php
/**
 * REST API: WP_REST_Presence_Network_Controller class
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core class used to read network-wide presence via the REST API.
 *
 * A site is the item here, not a presence entry: the summary table holds one
 * row per site, and resolving names and avatars is what costs.
 *
 * @see WP_REST_Controller
 */
class WP_REST_Presence_Network_Controller extends WP_REST_Controller {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = 'wp-presence/v1';
		$this->rest_base = 'presence/network';
	}

	/**
	 * Registers the routes for network presence.
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
						'per_page'       => array(
							'type'              => 'integer',
							'default'           => 50,
							'minimum'           => 1,
							'maximum'           => 100,
							'validate_callback' => 'rest_validate_request_arg',
							'sanitize_callback' => 'absint',
						),
						'page'           => array(
							'type'              => 'integer',
							'default'           => 1,
							'minimum'           => 1,
							'validate_callback' => 'rest_validate_request_arg',
							'sanitize_callback' => 'absint',
						),
						'users_per_site' => self::users_per_site_arg(),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<blog_id>[\d]+)',
			array(
				'args'   => array(
					'blog_id' => array(
						'description' => __( 'Unique identifier for the site.', 'presence-api' ),
						'type'        => 'integer',
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'users_per_site' => self::users_per_site_arg(),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Returns the shared definition of the per-site resolve cap.
	 *
	 * @return array Argument definition.
	 */
	private static function users_per_site_arg() {
		return array(
			'type'              => 'integer',
			'default'           => 0,
			'minimum'           => 0,
			'maximum'           => 100,
			'validate_callback' => 'rest_validate_request_arg',
			'sanitize_callback' => 'absint',
		);
	}

	/**
	 * Checks if the current user has permission to read network presence.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access, WP_Error otherwise.
	 */
	public function get_items_permissions_check( $request ) {
		if ( ! current_user_can( wp_presence_network_capability() ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to view network presence.', 'presence-api' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Checks if the current user has permission to read one site's presence.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access, WP_Error otherwise.
	 */
	public function get_item_permissions_check( $request ) {
		return $this->get_items_permissions_check( $request );
	}

	/**
	 * Retrieves the sites with users online, busiest first.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response Response object.
	 */
	public function get_items( $request ) {
		$per_page = $request->get_param( 'per_page' );
		$page     = $request->get_param( 'page' );

		$summary = wp_presence_get_network_summary(
			array(
				'sites'          => $per_page,
				'offset'         => ( $page - 1 ) * $per_page,
				'users_per_site' => $request->get_param( 'users_per_site' ),
			)
		);

		$data = array();

		foreach ( $summary['sites'] as $site ) {
			$data[] = $this->prepare_item_for_response( $site, $request )->get_data();
		}

		$response = rest_ensure_response( $data );

		// The user total has no standard header, and a caller after the headcount
		// alone would otherwise have to walk every page for it.
		$response->header( 'X-WP-Total', $summary['total_sites_online'] );
		$response->header( 'X-WP-TotalPages', (int) ceil( $summary['total_sites_online'] / $per_page ) );
		$response->header( 'X-WP-Presence-Users-Online', $summary['total_users_online'] );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Retrieves one site's presence.
	 *
	 * A site nobody is on answers with an empty user list, so only an unknown
	 * site is a 404 and a poll reads the same shape either way.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, WP_Error otherwise.
	 */
	public function get_item( $request ) {
		$blog_id = (int) $request->get_param( 'blog_id' );
		$site    = get_site( $blog_id );

		if ( ! $site ) {
			return new WP_Error(
				'rest_site_invalid_id',
				__( 'Invalid site ID.', 'presence-api' ),
				array( 'status' => 404 )
			);
		}

		$summary = wp_presence_get_network_summary(
			array(
				'blog_id'        => $blog_id,
				'users_per_site' => $request->get_param( 'users_per_site' ),
			)
		);

		$item = $summary['sites'] ? $summary['sites'][0] : self::empty_site( $site );

		$response = rest_ensure_response( $this->prepare_item_for_response( $item, $request )->get_data() );

		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Builds the item for a site nobody is online on.
	 *
	 * With no summary row to derive the URL from, this reads the site's own
	 * siteurl: one switch_to_blog(), and it reflects a mapped domain.
	 *
	 * @param WP_Site $site The site.
	 * @return array Item in the shape wp_presence_get_network_summary() returns.
	 */
	private static function empty_site( $site ) {
		return array(
			'blog_id'    => (int) $site->blog_id,
			'domain'     => $site->domain,
			'path'       => $site->path,
			'url'        => trailingslashit( get_site_url( (int) $site->blog_id ) ),
			'users'      => array(),
			'user_count' => 0,
		);
	}

	/**
	 * Prepares a site for the REST response.
	 *
	 * @param array           $item    Site entry from wp_presence_get_network_summary().
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response Response object.
	 */
	public function prepare_item_for_response( $item, $request ) {
		$fields = $this->get_fields_for_response( $request );

		$data = array();

		if ( rest_is_field_included( 'blog_id', $fields ) ) {
			$data['blog_id'] = (int) $item['blog_id'];
		}

		if ( rest_is_field_included( 'domain', $fields ) ) {
			$data['domain'] = $item['domain'];
		}

		if ( rest_is_field_included( 'path', $fields ) ) {
			$data['path'] = $item['path'];
		}

		if ( rest_is_field_included( 'url', $fields ) ) {
			$data['url'] = $item['url'];
		}

		if ( rest_is_field_included( 'user_count', $fields ) ) {
			$data['user_count'] = (int) $item['user_count'];
		}

		if ( rest_is_field_included( 'users', $fields ) ) {
			$data['users'] = $item['users'];
		}

		$context = ! empty( $request['context'] ) ? $request['context'] : 'view';
		$data    = $this->add_additional_fields_to_object( $data, $request );
		$data    = $this->filter_response_by_context( $data, $context );

		return rest_ensure_response( $data );
	}

	/**
	 * Retrieves the network presence site schema, conforming to JSON Schema.
	 *
	 * @return array Item schema data.
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'presence-network-site',
			'type'       => 'object',
			'properties' => array(
				'blog_id'    => array(
					'description' => __( 'The site ID.', 'presence-api' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'domain'     => array(
					'description' => __( 'The domain of the site.', 'presence-api' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'path'       => array(
					'description' => __( 'The path of the site.', 'presence-api' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'url'        => array(
					'description' => __( 'The URL of the site.', 'presence-api' ),
					'type'        => 'string',
					'format'      => 'uri',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'user_count' => array(
					'description' => __( 'How many users are online on the site, which the users list is capped below.', 'presence-api' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'users'      => array(
					'description' => __( 'The users online on the site.', 'presence-api' ),
					'type'        => 'array',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'user_id'      => array(
								'description' => __( 'The WordPress user ID.', 'presence-api' ),
								'type'        => 'integer',
							),
							'display_name' => array(
								'description' => __( 'The display name of the user.', 'presence-api' ),
								'type'        => 'string',
							),
							'avatar_url'   => array(
								'description' => __( 'The avatar URL of the user.', 'presence-api' ),
								'type'        => 'string',
								'format'      => 'uri',
							),
						),
					),
				),
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}
}
