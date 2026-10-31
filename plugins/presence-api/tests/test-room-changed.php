<?php
/**
 * Tests for the room-changed notice a post's title or status change sends.
 *
 * @package Presence_API
 *
 * @group presence
 *
 * @covers ::wp_presence_room_changed
 * @covers ::wp_presence_on_post_title_or_status_change
 */
class WP_Test_Presence_Room_Changed extends WP_UnitTestCase {

	/**
	 * Rooms the action fired for.
	 *
	 * @var string[]
	 */
	private $rooms = array();

	public function set_up() {
		parent::set_up();
		$this->rooms = array();
		add_action( 'wp_presence_room_changed', array( $this, 'record' ) );
	}

	public function tear_down() {
		remove_action( 'wp_presence_room_changed', array( $this, 'record' ) );
		parent::tear_down();
	}

	/**
	 * Records a room the action fired for.
	 *
	 * @param string $room The room identifier.
	 */
	public function record( $room ) {
		$this->rooms[] = $room;
	}

	public function test_a_title_or_status_change_fires_for_the_post_room_and_the_admin_room_only_while_someone_is_in_it() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => 'Before',
				'post_status' => 'draft',
			)
		);
		$room    = wp_presence_post_room( $post_id );

		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Renamed while empty',
			)
		);
		$this->assertSame( array(), $this->rooms, 'an empty room has nobody to tell' );

		wp_set_presence( $room, 'editor-' . $user_id, array( 'action' => 'editing' ), array( 'user_id' => $user_id ) );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Body only',
			)
		);
		$this->assertSame( array(), $this->rooms, 'a change presence does not show fires nothing' );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_title'  => 'After',
				'post_status' => 'publish',
			)
		);
		$this->assertSame( array( $room, wp_presence_admin_room() ), $this->rooms );
	}
}
