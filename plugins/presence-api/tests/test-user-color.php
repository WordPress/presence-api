<?php
/**
 * Tests for the color each user wears on presence surfaces.
 *
 * @package Presence_API
 *
 * @group presence
 *
 * @covers ::wp_presence_get_user_color
 * @covers ::wp_presence_color_palette
 * @covers ::wp_presence_default_user_color
 * @covers ::wp_presence_entry_color
 * @covers ::wp_presence_assign_user_color
 */
class WP_Test_Presence_User_Color extends WP_Presence_UnitTestCase {

	/**
	 * Creates two editors whose IDs Gutenberg gives the same color.
	 *
	 * @return int[] The two user IDs.
	 */
	private function two_users_gutenberg_colors_alike() {
		$first = self::factory()->user->create( array( 'role' => 'editor' ) );
		do {
			$second = self::factory()->user->create( array( 'role' => 'editor' ) );
		} while ( 0 !== ( $second - $first ) % 7 );

		return array( $first, $second );
	}

	/**
	 * Puts a user in the admin room with the given state.
	 *
	 * @param int   $user_id User ID.
	 * @param array $state   Entry data.
	 */
	private function put_online( $user_id, $state = array() ) {
		wp_set_presence( wp_presence_admin_room(), 'user-' . $user_id, $state + array( 'screen' => 'dashboard' ), $user_id );
	}

	/**
	 * Without an entry, a user wears what Gutenberg's getAvatarBorderColor() gives them.
	 */
	public function test_the_default_matches_gutenberg() {
		$this->assertSame( '#6F42C1', wp_presence_get_user_color( 7 ) );
		$this->assertSame( '#D94145', wp_presence_get_user_color( 1 ) );
		$this->assertSame( '#D94145', wp_presence_get_user_color( 8 ) );
		$this->assertSame( '#00CFFF', wp_presence_get_user_color( 6 ) );
	}

	public function test_a_user_keeps_gutenbergs_color_when_nobody_else_wears_it() {
		list( $first ) = $this->two_users_gutenberg_colors_alike();

		$this->assertSame( wp_presence_default_user_color( $first ), wp_presence_assign_user_color( $first ) );
	}

	public function test_a_user_whose_color_is_taken_gets_one_nobody_wears() {
		list( $first, $second ) = $this->two_users_gutenberg_colors_alike();

		$this->put_online( $first, array( 'color' => wp_presence_assign_user_color( $first ) ) );
		$color = wp_presence_assign_user_color( $second );

		$this->assertNotSame( wp_presence_get_user_color( $first ), $color );
		$this->assertContains( $color, wp_presence_color_palette() );
	}

	/**
	 * A held color survives someone else taking the same one, so nobody changes color mid-session.
	 */
	public function test_a_held_color_is_kept() {
		list( $first, $second ) = $this->two_users_gutenberg_colors_alike();

		$this->put_online( $first, array( 'color' => '#FF35EE' ) );
		$this->put_online( $second, array( 'color' => '#FF35EE' ) );

		$this->assertSame( '#FF35EE', wp_presence_assign_user_color( $first ) );
		$this->assertSame( '#FF35EE', wp_presence_get_user_color( $first ) );
	}

	/**
	 * Entry data can come from a client, and the color ends up in inline CSS.
	 */
	public function test_a_color_outside_the_palette_is_ignored() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->put_online( $user_id, array( 'color' => 'red;background:url(x)' ) );

		$this->assertSame( wp_presence_default_user_color( $user_id ), wp_presence_get_user_color( $user_id ) );
	}

	/**
	 * @covers ::wp_presence_admin_heartbeat_received
	 */
	public function test_the_heartbeat_stores_the_color() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		wp_presence_admin_heartbeat_received( array(), array( 'presence-ping' => array( 'screen' => 'dashboard' ) ), 'dashboard' );

		$this->assertSame( wp_presence_default_user_color( $user_id ), wp_get_presence( wp_presence_admin_room() )[0]->data['color'] );
	}

	/**
	 * @covers WP_REST_Presence_Controller::prepare_item_for_response
	 */
	public function test_rest_entries_carry_the_color() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->put_online( $user_id, array( 'color' => '#879F11' ) );

		$entry = (object) array(
			'room'      => 'postType/post:1',
			'client_id' => 'client-1',
			'user_id'   => $user_id,
			'data'      => array(),
			'date_gmt'  => gmdate( 'Y-m-d H:i:s' ),
		);

		$data = ( new WP_REST_Presence_Controller() )->prepare_item_for_response( $entry, new WP_REST_Request( 'GET', '/wp-presence/v1/presence' ) )->get_data();

		$this->assertSame( '#879F11', $data['color'] );
	}
}
