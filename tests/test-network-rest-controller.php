<?php
/**
 * Tests for the network presence REST controller.
 *
 * @package Presence_API
 *
 * @group presence
 * @group ms-required
 *
 * @covers WP_REST_Presence_Network_Controller
 */
class WP_Test_Network_Presence_REST_Controller extends WP_Presence_Network_UnitTestCase {

	private static $editor_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id = $factory->user->create( array( 'role' => 'editor' ) );
	}

	private function get( $route, array $params = array() ) {
		$request = new WP_REST_Request( 'GET', $route );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	public function test_the_collection_reports_every_site_with_someone_online() {
		$this->become_network_admin();

		$busy  = $this->create_blog();
		$quiet = $this->create_blog();

		$this->set_network_summary_row( $busy, self::factory()->user->create_many( 2 ) );
		$this->set_network_summary_row( $quiet, array( self::$editor_id ) );

		$response = $this->get( '/wp-presence/v1/presence/network' );
		$data     = $response->get_data();
		$headers  = $response->get_headers();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $busy, $quiet ), wp_list_pluck( $data, 'blog_id' ), 'Busiest site first.' );
		$this->assertSame( 2, $data[0]['user_count'] );
		$this->assertCount( 2, $data[0]['users'] );
		$this->assertArrayHasKey( 'avatar_url', $data[0]['users'][0] );
		$this->assertSame( 2, $headers['X-WP-Total'] );
		$this->assertSame( 3, $headers['X-WP-Presence-Users-Online'] );
		$this->assertSame( 'no-store', $headers['Cache-Control'] );
	}

	public function test_the_collection_paginates_over_sites() {
		$this->become_network_admin();

		$busy  = $this->create_blog();
		$quiet = $this->create_blog();

		$this->set_network_summary_row( $busy, self::factory()->user->create_many( 2 ) );
		$this->set_network_summary_row( $quiet, array( self::$editor_id ) );

		$response = $this->get(
			'/wp-presence/v1/presence/network',
			array(
				'per_page' => 1,
				'page'     => 2,
			)
		);

		$headers = $response->get_headers();

		$this->assertSame( array( $quiet ), wp_list_pluck( $response->get_data(), 'blog_id' ) );

		// Network-wide, so a caller can tell the page is a page.
		$this->assertSame( 2, $headers['X-WP-Total'] );
		$this->assertSame( 2, $headers['X-WP-TotalPages'] );
	}

	public function test_users_per_site_caps_what_is_resolved_without_capping_the_count() {
		$this->become_network_admin();

		$blog_id = $this->create_blog();
		$this->set_network_summary_row( $blog_id, self::factory()->user->create_many( 3 ) );

		$response = $this->get( '/wp-presence/v1/presence/network', array( 'users_per_site' => 1 ) );
		$data     = $response->get_data();

		$this->assertCount( 1, $data[0]['users'] );
		$this->assertSame( 3, $data[0]['user_count'] );
	}

	public function test_the_collection_is_closed_to_users_without_the_network_capability() {
		wp_set_current_user( self::$editor_id );

		$response = $this->get( '/wp-presence/v1/presence/network' );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->get_data()['code'] );
	}

	/**
	 * The route has to read the filter rather than hard-code the default.
	 */
	public function test_the_route_honours_the_filtered_network_capability() {
		wp_set_current_user( self::$editor_id );

		add_filter( 'wp_presence_network_capability', fn() => 'edit_posts' );

		$this->assertSame( 200, $this->get( '/wp-presence/v1/presence/network' )->get_status() );
	}

	public function test_a_single_site_reports_who_is_on_it() {
		$this->become_network_admin();

		$wanted = $this->create_blog();
		$other  = $this->create_blog();

		$this->set_network_summary_row( $wanted, array( self::$editor_id ) );
		$this->set_network_summary_row( $other, self::factory()->user->create_many( 2 ) );

		$response = $this->get( '/wp-presence/v1/presence/network/' . $wanted );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $wanted, $data['blog_id'] );
		$this->assertSame( 1, $data['user_count'] );
		$this->assertSame( self::$editor_id, $data['users'][0]['user_id'] );
	}

	/**
	 * An empty site is a 200; a 404 is reserved for a site that does not exist.
	 */
	public function test_a_site_nobody_is_on_answers_with_an_empty_user_list() {
		$this->become_network_admin();

		$blog_id = $this->create_blog();

		$response = $this->get( '/wp-presence/v1/presence/network/' . $blog_id );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $blog_id, $data['blog_id'] );
		$this->assertSame( array(), $data['users'] );
		$this->assertSame( 0, $data['user_count'] );
		$this->assertSame( trailingslashit( get_site_url( $blog_id ) ), $data['url'] );
	}

	public function test_a_site_that_does_not_exist_is_a_404() {
		$this->become_network_admin();

		$response = $this->get( '/wp-presence/v1/presence/network/999999' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'rest_site_invalid_id', $response->get_data()['code'] );
	}

	public function test_a_single_site_is_closed_to_users_without_the_network_capability() {
		$blog_id = $this->create_blog();

		wp_set_current_user( self::$editor_id );

		$response = $this->get( '/wp-presence/v1/presence/network/' . $blog_id );

		$this->assertSame( 403, $response->get_status() );
	}
}
