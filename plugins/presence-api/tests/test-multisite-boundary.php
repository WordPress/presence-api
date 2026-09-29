<?php
/**
 * Tests for the site boundary itself, as opposed to the network surfaces that
 * cross it deliberately.
 *
 * Presence was scoped by table prefix by construction until the network
 * summary arrived, so nothing asserted the boundary directly: the prefix made
 * it true. These hold that property in place now that a capability, rather
 * than the schema, is what keeps network reads apart from site reads.
 *
 * @package Presence_API
 *
 * @group presence
 * @group ms-required
 */
class WP_Test_Multisite_Boundary extends WP_Presence_Network_UnitTestCase {

	private static $editor_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id = $factory->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * An editor on one site has no role on a site they were never added to, so
	 * the same room resolves differently either side of a switch. user_can()
	 * is what carries the site context here; nothing in the room string does.
	 *
	 * @covers ::wp_can_access_presence_room
	 */
	public function test_admin_room_is_denied_on_a_site_the_user_is_not_a_member_of() {
		$site_b = $this->create_blog();

		$this->assertTrue(
			wp_can_access_presence_room( 'admin/online', self::$editor_id ),
			'An editor should reach the admin room on their own site.'
		);

		switch_to_blog( $site_b );
		$on_site_b = wp_can_access_presence_room( 'admin/online', self::$editor_id );
		restore_current_blog();

		$this->assertFalse( $on_site_b );
	}

	/**
	 * @covers ::wp_get_presence
	 */
	public function test_a_row_written_on_one_site_is_not_readable_from_another() {
		$site_b = $this->create_blog();

		$this->set_presence_on_site( $site_b, self::$editor_id );

		switch_to_blog( $site_b );
		$read_on_b = wp_get_presence( 'admin/online' );
		restore_current_blog();

		$read_on_a = wp_get_presence( 'admin/online' );

		$this->assertCount( 1, $read_on_b, 'The row should be readable on the site that wrote it.' );
		$this->assertSame( array(), $read_on_a );
	}
}
