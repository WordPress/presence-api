<?php
/**
 * Tests for the small presence helpers the admin surfaces share.
 *
 * @package Presence_API
 *
 * @group presence
 */
class WP_Test_Presence_Helpers extends WP_Presence_UnitTestCase {

	/**
	 * Editor counted as online.
	 *
	 * @var int
	 */
	protected static $editor_id;

	/**
	 * Second editor, used as another user's row.
	 *
	 * @var int
	 */
	protected static $other_editor_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id       = $factory->user->create( array( 'role' => 'editor' ) );
		self::$other_editor_id = $factory->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * A caller that named no window matches every stored row, so only the row's own expiry bounds it.
	 *
	 * @covers ::wp_presence_read_floor
	 */
	public function test_the_read_floor_matches_every_row_without_a_window() {
		$this->assertSame( '1000-01-01 00:00:00', wp_presence_read_floor( null ) );
	}

	/**
	 * A named window puts the floor that many seconds before now.
	 *
	 * @covers ::wp_presence_read_floor
	 */
	public function test_the_read_floor_sits_one_window_before_now() {
		$floor = strtotime( wp_presence_read_floor( 60 ) . ' UTC' );

		$this->assertEqualsWithDelta( time() - 60, $floor, 2 );
	}

	/**
	 * A signed-out request has no one to add.
	 *
	 * @covers ::wp_presence_with_current_user
	 */
	public function test_a_signed_out_request_adds_no_one() {
		wp_set_current_user( 0 );

		$entries = array( (object) array( 'user_id' => self::$other_editor_id ) );

		$this->assertSame( $entries, wp_presence_with_current_user( $entries ) );
	}

	/**
	 * The current user is online on the screen they are looking at, even before their own row is written.
	 *
	 * @covers ::wp_presence_with_current_user
	 */
	public function test_the_current_user_is_added_when_their_row_is_absent() {
		wp_set_current_user( self::$editor_id );

		$entries = wp_presence_with_current_user( array( (object) array( 'user_id' => self::$other_editor_id ) ) );

		$this->assertCount( 2, $entries );
		$this->assertSame( self::$editor_id, $entries[1]->user_id );
		$this->assertSame( array(), $entries[1]->data );
	}

	/**
	 * A row read back from the database carries a string user_id, which must still match the current user.
	 *
	 * @covers ::wp_presence_with_current_user
	 */
	public function test_the_current_user_is_not_added_twice() {
		wp_set_current_user( self::$editor_id );

		$entries = array( (object) array( 'user_id' => (string) self::$editor_id ) );

		$this->assertSame( $entries, wp_presence_with_current_user( $entries ) );
	}

	/**
	 * Several clients for one user count as one person online, and the viewer counts too.
	 *
	 * @covers ::wp_presence_online_user_ids
	 */
	public function test_online_user_ids_are_unique_and_include_the_current_user() {
		wp_set_current_user( self::$editor_id );

		$ids = wp_presence_online_user_ids(
			array(
				(object) array( 'user_id' => (string) self::$other_editor_id ),
				(object) array( 'user_id' => (string) self::$other_editor_id ),
			)
		);

		$this->assertSame( array( self::$other_editor_id, self::$editor_id ), $ids );
	}

	/**
	 * Only an agent gets the badge, so a person's name reads the same as before.
	 *
	 * @covers ::wp_presence_render_agent_badge
	 */
	public function test_only_an_agent_gets_the_badge() {
		$agent_id = self::$other_editor_id;

		add_filter(
			'wp_presence_is_agent_user',
			static function ( $is_agent, $user_id ) use ( $agent_id ) {
				return $agent_id === $user_id ? true : $is_agent;
			},
			10,
			2
		);

		$this->assertSame( '', wp_presence_render_agent_badge( self::$editor_id ) );
		$this->assertSame( ' <span class="presence-agent-badge">Agent</span>', wp_presence_render_agent_badge( $agent_id ) );
	}
}
