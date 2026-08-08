<?php
/**
 * Tests for the admin bar presence node.
 *
 * @package Presence_API
 *
 * @group presence
 *
 * @covers ::wp_presence_admin_bar_node
 */
class WP_Test_Presence_Admin_Bar extends WP_UnitTestCase {

	private static $editor_id;
	private static $contributor_id;
	private static $post_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id      = $factory->user->create( array( 'role' => 'editor' ) );
		self::$contributor_id = $factory->user->create( array( 'role' => 'contributor' ) );
		self::$post_id        = $factory->post->create(
			array(
				'post_title'  => 'Secret Draft',
				'post_status' => 'draft',
				'post_author' => self::$editor_id,
			)
		);
	}

	public function set_up() {
		parent::set_up();
		// Only loaded on requests that actually render the bar.
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
	}

	public function tear_down() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "TRUNCATE TABLE {$wpdb->presence}" );
		parent::tear_down();
	}

	/**
	 * Puts the editor online on the post editing screen for a given post.
	 *
	 * @param int $post_id The post the editor is working on.
	 */
	private function put_editor_on_post( $post_id ) {
		wp_set_presence(
			'admin/online',
			'user-' . self::$editor_id,
			array( 'screen' => 'post' ),
			self::$editor_id
		);
		wp_set_presence(
			wp_presence_post_room( $post_id ),
			'lock-' . self::$editor_id,
			array(),
			self::$editor_id
		);
	}

	/**
	 * Renders the node and returns every node title and link as one string.
	 *
	 * @return string Concatenated node titles and hrefs.
	 */
	private function render_node_markup() {
		$bar = new WP_Admin_Bar();
		wp_presence_admin_bar_node( $bar );

		$markup = '';
		foreach ( $bar->get_nodes() as $node ) {
			$markup .= $node->title;
			if ( is_string( $node->href ) ) {
				$markup .= $node->href;
			}
		}

		return $markup;
	}

	/**
	 * The menu labels each online user with the post they are editing. A
	 * contributor has `edit_posts`, which is all the node itself requires, but
	 * must not learn the title of a draft they cannot edit.
	 */
	public function test_hides_post_titles_the_user_cannot_edit() {
		$this->put_editor_on_post( self::$post_id );

		wp_set_current_user( self::$contributor_id );
		$markup = $this->render_node_markup();

		$this->assertStringNotContainsString( 'Secret Draft', $markup );
		$this->assertStringNotContainsString( 'post=' . self::$post_id, $markup );
	}

	/**
	 * The user is still listed, only the post they are on is withheld.
	 */
	public function test_still_lists_the_user_without_the_post_title() {
		$this->put_editor_on_post( self::$post_id );

		wp_set_current_user( self::$contributor_id );
		$markup = $this->render_node_markup();

		$editor = get_userdata( self::$editor_id );
		$this->assertStringContainsString( $editor->display_name, $markup );
	}

	/**
	 * A user who can edit the post still sees its title.
	 */
	public function test_shows_post_titles_the_user_can_edit() {
		$this->put_editor_on_post( self::$post_id );

		$other_editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $other_editor_id );
		$markup = $this->render_node_markup();

		$this->assertStringContainsString( 'Secret Draft', $markup );
	}
}
