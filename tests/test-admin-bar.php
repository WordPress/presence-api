<?php
/**
 * Tests for the admin bar presence node.
 *
 * @package Presence_API
 *
 * @group presence
 *
 * @covers ::wp_presence_admin_bar_node
 * @covers ::wp_presence_admin_bar_assets
 * @covers ::wp_presence_admin_bar_node_markup
 * @covers ::wp_presence_admin_bar_heartbeat_received
 * @covers ::wp_presence_screen_object_id
 */
class WP_Test_Presence_Admin_Bar extends WP_Presence_UnitTestCase {

	private static $editor_id;
	private static $post_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$editor_id = $factory->user->create( array( 'role' => 'editor' ) );
		self::$post_id   = $factory->post->create(
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
		unset( $GLOBALS['pagenow'] );
		// is_admin_bar_showing() memoizes into this global, so removing the
		// filter that set it is not enough to undo it.
		$GLOBALS['show_admin_bar'] = null;
		// is_admin() reads $current_screen, which outlives the test that set it.
		set_current_screen( 'dashboard' );
		parent::tear_down();
	}

	/**
	 * Lets the current user see where everyone else is, which takes list_users.
	 */
	private function let_current_user_list_users() {
		add_filter(
			'user_has_cap',
			static function ( $allcaps ) {
				$allcaps['list_users'] = true;
				return $allcaps;
			}
		);
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
	 * Renders the node and returns the admin bar's nodes, keyed by id.
	 *
	 * @return array Node objects.
	 */
	private function render_nodes() {
		$bar = new WP_Admin_Bar();
		wp_presence_admin_bar_node( $bar );

		return $bar->get_nodes() ?? array();
	}

	/**
	 * Puts a user online on a given screen.
	 *
	 * @param string $screen The screen slug to record.
	 * @param array  $data   Extra presence data to record alongside it.
	 * @return int The new user's ID.
	 */
	private function put_user_on_screen( $screen, $data = array() ) {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		wp_set_presence(
			'admin/online',
			'user-' . $user_id,
			array_merge( array( 'screen' => $screen ), $data ),
			$user_id
		);

		return $user_id;
	}

	/**
	 * Backdates a user's entry so it falls outside the TTL window.
	 *
	 * @param int $user_id     The user whose entry to backdate.
	 * @param int $seconds_ago How far in the past to date the entry.
	 */
	private function age_entry( $user_id, $seconds_ago ) {
		global $wpdb;

		$wpdb->update(
			$wpdb->presence,
			array( 'date_gmt' => gmdate( 'Y-m-d H:i:s', time() - $seconds_ago ) ),
			array( 'client_id' => 'user-' . $user_id ),
			array( '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Alone, the node still shows, so the bar does not shift when someone arrives.
	 */
	public function test_the_node_stays_when_you_are_alone() {
		wp_set_current_user( self::$editor_id );
		wp_set_presence( 'admin/online', 'user-' . self::$editor_id, array( 'screen' => 'dashboard' ), self::$editor_id );

		$nodes = $this->render_nodes();

		$this->assertStringContainsString( '>1 online<', $nodes['presence-online']->title );
		$this->assertStringContainsString( '<span class="screen-reader-text">1 user online</span>', $nodes['presence-online']->title );
	}

	public function test_no_indicator_for_a_user_without_edit_posts() {
		$this->put_user_on_screen( 'dashboard' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( array(), $this->render_nodes() );
	}

	/**
	 * Puts the current request on an admin screen so presence recorded against
	 * the same slug counts as "on this page".
	 *
	 * @param string $pagenow The admin file being requested.
	 * @param string $base    The screen base to register it under.
	 */
	private function view_admin_page( $pagenow, $base ) {
		set_current_screen( $base );
		$GLOBALS['pagenow'] = $pagenow;
	}

	public function test_people_on_this_page_come_first_with_faces() {
		$this->view_admin_page( 'upload.php', 'upload' );

		$here      = get_userdata( $this->put_user_on_screen( 'upload' ) );
		$elsewhere = $this->put_user_on_screen( 'edit-comments', array( 'title' => 'Comments' ) );

		wp_set_current_user( self::$editor_id );
		$nodes = $this->render_nodes();

		$this->assertStringContainsString( 'outline-color:', $nodes[ 'presence-user-' . $here->ID ]->title );
		// Without view_presence_location, everyone else is a name with no place or link.
		$this->assertSame( 'presence-elsewhere', $nodes[ 'presence-user-' . $elsewhere ]->parent );
		$this->assertSame( esc_html( get_userdata( $elsewhere )->display_name ), $nodes[ 'presence-user-' . $elsewhere ]->title );
		$this->assertFalse( $nodes[ 'presence-user-' . $elsewhere ]->href );
		// The faces are the others on this page; you are already in My Account.
		$this->assertStringContainsString( 'alt="' . esc_attr( $here->display_name ) . '"', $nodes['presence-online']->title );
		$this->assertStringNotContainsString(
			'alt="' . esc_attr( get_userdata( self::$editor_id )->display_name ) . '"',
			$nodes['presence-online']->title
		);
	}

	/**
	 * Returns the place heading a person is listed under, or null.
	 *
	 * @param array $nodes   Rendered nodes.
	 * @param int   $user_id The person.
	 * @return object|null
	 */
	private function place_of( $nodes, $user_id ) {
		$place = null;
		foreach ( wp_list_filter( $nodes, array( 'parent' => 'presence-elsewhere' ) ) as $id => $node ) {
			if ( 'presence-user-' . $user_id === $id ) {
				return $place;
			}
			$place = 0 === strpos( $id, 'presence-place-' ) ? $node : $place;
		}
		return null;
	}

	/**
	 * @dataProvider data_elsewhere_links
	 */
	public function test_people_elsewhere_link_to_where_they_are( $screen, $path ) {
		$user_id = $this->put_user_on_screen( $screen, array( 'title' => 'Somewhere' ) );

		wp_set_current_user( self::$editor_id );
		$this->let_current_user_list_users();
		$nodes = $this->render_nodes();

		if ( '../wp-login' === $screen ) {
			$this->assertFalse( $nodes[ 'presence-user-' . $user_id ]->href );
			$this->assertNull( $this->place_of( $nodes, $user_id ) );
			return;
		}
		if ( null === $path ) {
			$this->assertFalse( $this->place_of( $nodes, $user_id )->href );
			$this->assertSame( 'Somewhere', $this->place_of( $nodes, $user_id )->title, 'A screen each person has to themselves is never counted as one place.' );
			return;
		}
		$this->assertArrayNotHasKey( 'presence-user-' . $user_id, $nodes, 'A shared screen folds its people into its own row.' );
		$row = $nodes['presence-place-0'];
		$this->assertSame( admin_url( $path ), $row->href );
		$this->assertStringContainsString( '>Somewhere</span>', $row->title );
		$this->assertStringContainsString( '<span class="screen-reader-text">, ' . esc_html( get_userdata( $user_id )->display_name ) . '</span>', $row->title );
	}

	public function data_elsewhere_links() {
		return array(
			'core screen'      => array( 'edit-comments', 'edit-comments.php' ),
			'post type list'   => array( 'edit-page', 'edit.php?post_type=page' ),
			'taxonomy list'    => array( 'edit-category', 'edit-tags.php?taxonomy=category' ),
			'plugin page'      => array( 'settings_page_presence-api', 'admin.php?page=presence-api' ),
			'needs an ID'      => array( 'user-edit', null ),
			'their own profile' => array( 'profile', null ),
			'a new post'       => array( 'post-new', null ),
			'forged path'      => array( '../wp-login', null ),
		);
	}

	public function test_posts_being_edited_come_first_then_the_busiest_places() {
		$this->put_editor_on_post( self::$post_id );
		$hidden = $this->put_user_on_screen( '../wp-login' );
		$untitled = $this->put_user_on_screen( 'edit-comments' );
		$alone  = $this->put_user_on_screen( 'edit-comments', array( 'title' => 'Comments' ) );
		$this->put_user_on_screen( 'users', array( 'title' => 'Users' ) );
		$this->put_user_on_screen( 'users', array( 'title' => 'Users' ) );
		$own = array(
			$this->put_user_on_screen( 'profile', array( 'title' => 'Profile' ) ),
			$this->put_user_on_screen( 'profile', array( 'title' => 'Profile' ) ),
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$nodes = wp_list_filter( $this->render_nodes(), array( 'parent' => 'presence-elsewhere' ) );
		$rows  = array_map(
			fn( $node ) => 0 === strpos( $node->id, 'presence-place-' ) ? wp_strip_all_tags( preg_replace( '/<span class="presence-bar-crowd".*/s', '', $node->title ) ) : (int) substr( $node->id, 14 ),
			array_values( $nodes )
		);

		$this->assertSame( array( 'Secret Draft', self::$editor_id, 'Users', 'Comments', 'Profile' ), array_slice( $rows, 0, 5 ) );
		$this->assertEqualSets( $own, array_slice( $rows, 5, 2 ) );
		$this->assertEqualSets( array( $untitled, $hidden ), array_slice( $rows, 7 ) );
		$this->assertStringContainsString( '<span class="presence-bar-crowd" aria-hidden="true">2</span>', $nodes['presence-place-2']->title );
		$this->assertStringContainsString( esc_html( get_userdata( $alone )->display_name ), $nodes['presence-place-3']->title );
	}

	public function test_a_crowded_screen_names_only_a_few() {
		for ( $i = 0; $i < 12; $i++ ) {
			$this->put_user_on_screen( 'users', array( 'title' => 'Users' ) );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$row = $this->render_nodes()['presence-place-0'];

		$this->assertStringContainsString( '<span class="presence-bar-crowd" aria-hidden="true">12</span>', $row->title );
		$this->assertStringContainsString( ', and 2 others</span>', $row->title );
		$this->assertStringEndsWith( ', and 2 others', $row->meta['title'] );
	}

	public function test_someone_editing_a_post_links_to_it() {
		$this->put_editor_on_post( self::$post_id );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$place = $this->place_of( $this->render_nodes(), self::$editor_id );

		$this->assertSame( get_edit_post_link( self::$post_id, 'raw' ), $place->href );
		$this->assertSame( 'Secret Draft', $place->title );
	}

	public function test_someone_editing_an_untitled_draft_is_on_no_title() {
		$this->put_editor_on_post( self::factory()->post->create( array( 'post_title' => '', 'post_status' => 'draft', 'post_author' => self::$editor_id ) ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertSame( '(no title)', $this->place_of( $this->render_nodes(), self::$editor_id )->title );
	}

	/**
	 * @dataProvider data_objects_being_edited
	 */
	public function test_someone_editing_a_comment_user_or_term_links_to_it( $screen, $object, $title ) {
		$object_id = 'comment' === $screen ? self::factory()->comment->create() : ( 'user-edit' === $screen ? self::$editor_id : self::factory()->category->create( array( 'name' => 'Recipes' ) ) );
		$this->put_user_on_screen( $screen, array( 'title' => 'Somewhere', 'object_id' => $object_id ) );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		// Only a super admin can edit other users on a network.
		grant_super_admin( $admin_id );
		wp_set_current_user( $admin_id );
		$row = $this->render_nodes()['presence-place-0'];

		$this->assertSame( call_user_func( $object, $object_id ), $row->href );
		$this->assertStringContainsString( '>' . $title . '</span>', $row->title );
	}

	public function data_objects_being_edited() {
		return array(
			'comment' => array( 'comment', fn( $id ) => get_edit_comment_link( $id, 'url' ), 'Somewhere' ),
			'user'    => array( 'user-edit', 'get_edit_user_link', 'Somewhere' ),
			'term'    => array( 'edit-category', fn( $id ) => get_edit_term_link( $id, 'category' ), 'Recipes' ),
		);
	}

	public function test_more_comments_and_terms_being_edited_cost_no_more_queries() {
		global $wpdb;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$queries = array();
		for ( $i = 0; $i < 2; $i++ ) {
			for ( $j = 0; $j < 3; $j++ ) {
				$this->put_user_on_screen( 'comment', array( 'object_id' => self::factory()->comment->create( array( 'comment_post_ID' => self::factory()->post->create() ) ) ) );
				$this->put_user_on_screen( 'edit-category', array( 'object_id' => self::factory()->category->create() ) );
			}
			wp_cache_flush();
			$before = $wpdb->num_queries;
			$this->render_nodes();
			$queries[] = $wpdb->num_queries - $before;
		}

		$this->assertSame( $queries[0], $queries[1] );
	}

	public function test_a_user_or_term_you_cannot_edit_is_not_named_or_linked() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$on_user  = $this->put_user_on_screen( 'user-edit', array( 'title' => 'Edit User Admin', 'object_id' => $admin_id ) );
		$on_term  = $this->put_user_on_screen( 'edit-category', array( 'title' => 'Edit Category', 'object_id' => self::factory()->category->create() ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$this->let_current_user_list_users();
		$nodes = $this->render_nodes();

		$this->assertSame( 'Edit User', $this->place_of( $nodes, $on_user )->title );
		$this->assertFalse( $this->place_of( $nodes, $on_user )->href );
		$this->assertSame( 'Edit Category', $this->place_of( $nodes, $on_term )->title );
		$this->assertFalse( $this->place_of( $nodes, $on_term )->href );
	}

	public function test_people_on_this_page_show_when_they_are_idle() {
		$this->view_admin_page( 'upload.php', 'upload' );

		$active = $this->put_user_on_screen( 'upload' );
		$idle   = $this->put_user_on_screen( 'upload' );
		$this->age_entry( $idle, wp_presence_idle_threshold() + 1 );

		wp_set_current_user( self::$editor_id );
		$nodes = $this->render_nodes();

		$this->assertStringNotContainsString( 'Idle', $nodes[ 'presence-user-' . $active ]->title );
		$this->assertStringContainsString( '>Idle</span>', $nodes[ 'presence-user-' . $idle ]->title );
	}

	/**
	 * Every post editor shares one screen ID, so the post decides who is on this page.
	 */
	public function test_editors_of_another_post_are_not_on_this_page() {
		$other_post = self::factory()->post->create( array( 'post_author' => self::$editor_id ) );
		$this->view_admin_page( 'post.php', 'post' );
		$this->put_editor_on_post( self::$post_id );

		$me = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $me );
		wp_set_presence( 'admin/online', 'user-' . $me, array( 'screen' => 'post' ), $me );
		wp_set_presence( wp_presence_post_room( $other_post ), 'lock-' . $me, array(), $me );

		$this->assertSame( 'presence-elsewhere', $this->render_nodes()[ 'presence-user-' . self::$editor_id ]->parent );

		wp_set_presence( wp_presence_post_room( self::$post_id ), 'lock-' . $me, array(), $me );
		wp_remove_presence( wp_presence_post_room( $other_post ), 'lock-' . $me );

		$this->assertSame( 'presence-online', $this->render_nodes()[ 'presence-user-' . self::$editor_id ]->parent );
	}

	/**
	 * A term's editor shares its screen ID with the list of terms.
	 */
	public function test_people_on_the_term_list_or_another_term_are_not_on_this_term() {
		$term_id = self::factory()->category->create();
		$this->view_admin_page( 'term.php', 'edit-category' );

		$on_list  = $this->put_user_on_screen( 'edit-category' );
		$on_other = $this->put_user_on_screen( 'edit-category', array( 'object_id' => $term_id + 1 ) );
		$on_term  = $this->put_user_on_screen( 'edit-category', array( 'object_id' => $term_id ) );

		wp_set_current_user( self::$editor_id );
		wp_set_presence( 'admin/online', 'user-' . self::$editor_id, array( 'screen' => 'edit-category', 'object_id' => $term_id ), self::$editor_id );
		$nodes = $this->render_nodes();

		$this->assertSame( 'presence-online', $nodes[ 'presence-user-' . $on_term ]->parent );
		$this->assertSame( 'presence-elsewhere', $nodes[ 'presence-user-' . $on_list ]->parent );
		$this->assertSame( 'presence-elsewhere', $nodes[ 'presence-user-' . $on_other ]->parent );
	}

	public function test_readers_of_another_page_are_not_on_this_page() {
		$there = $this->put_user_on_screen( 'front', array( 'post_id' => self::$post_id + 1 ) );
		$here  = $this->put_user_on_screen( 'front', array( 'post_id' => self::$post_id ) );

		wp_set_current_user( self::$editor_id );
		wp_set_presence( 'admin/online', 'user-' . self::$editor_id, array( 'screen' => 'front', 'post_id' => self::$post_id ), self::$editor_id );
		$nodes = $this->render_nodes();

		$this->assertSame( 'presence-online', $nodes[ 'presence-user-' . $here ]->parent );
		$this->assertSame( 'presence-elsewhere', $nodes[ 'presence-user-' . $there ]->parent );
	}

	public function test_rows_are_not_links() {
		$this->view_admin_page( 'upload.php', 'upload' );
		$user_id = $this->put_user_on_screen( 'upload' );

		wp_set_current_user( self::$editor_id );
		$row = $this->render_nodes()[ 'presence-user-' . $user_id ];

		$this->assertFalse( $row->href );
		$this->assertArrayNotHasKey( 'tabindex', $row->meta );
	}

	/**
	 * The count agrees with the users list and the network Sites column, all of
	 * which count everyone present rather than everyone but you.
	 */
	public function test_the_count_includes_you() {
		$this->put_user_on_screen( 'dashboard' );
		$this->put_user_on_screen( 'edit' );

		wp_set_current_user( self::$editor_id );
		wp_set_presence( 'admin/online', 'user-' . self::$editor_id, array( 'screen' => 'dashboard' ), self::$editor_id );

		$nodes = $this->render_nodes();

		$this->assertStringContainsString( '3 online', $nodes['presence-online']->title );
		$this->assertStringContainsString( '<span class="screen-reader-text">3 users online</span>', $nodes['presence-online']->title );
	}

	/**
	 * Your own row ages past the TTL while you are still on the screen, so the
	 * count has to add you back by identity.
	 */
	public function test_the_count_includes_you_when_your_own_row_has_expired() {
		$this->put_user_on_screen( 'dashboard' );

		wp_set_current_user( self::$editor_id );
		wp_set_presence( 'admin/online', 'user-' . self::$editor_id, array( 'screen' => 'dashboard' ), self::$editor_id );
		$this->age_entry( self::$editor_id, WP_PRESENCE_DEFAULT_TTL + 1 );

		$nodes = $this->render_nodes();

		$this->assertStringContainsString( '2 online', $nodes['presence-online']->title );
	}

	/**
	 * The three surfaces that report a number have to report the same one. Each
	 * assembles its own set, so a fix to any single one can drift from the rest.
	 *
	 * @covers ::wp_presence_users_views
	 */
	public function test_every_surface_reports_the_same_number_when_your_row_is_absent() {
		$this->put_user_on_screen( 'dashboard' );
		$this->put_user_on_screen( 'edit' );

		wp_set_current_user( self::$editor_id );

		$nodes = $this->render_nodes();
		$views = wp_presence_users_views( array() );

		$this->assertStringContainsString( '3 online', $nodes['presence-online']->title );
		$this->assertStringContainsString( '(3)', $views['presence_online'] );
	}

	/**
	 * The Posts list reports edit-post as window.pagenow, not its filename.
	 */
	public function test_the_posts_list_groups_by_its_screen_id() {
		$this->view_admin_page( 'edit.php', 'edit-post' );

		$user_id = $this->put_user_on_screen( 'edit-post' );

		wp_set_current_user( self::$editor_id );
		$nodes = $this->render_nodes();

		$this->assertArrayHasKey( 'presence-user-' . $user_id, $nodes );
	}

	public function test_people_on_this_page_never_share_a_ring_color() {
		$this->view_admin_page( 'upload.php', 'upload' );

		$first  = $this->put_user_on_screen( 'upload', array( 'color' => '#6F42C1' ) );
		$second = $this->put_user_on_screen( 'upload', array( 'color' => '#6F42C1' ) );

		wp_set_current_user( self::$editor_id );
		$nodes = $this->render_nodes();

		$this->assertStringContainsString( 'outline-color:#6F42C1', $nodes[ 'presence-user-' . $first ]->title );
		$this->assertStringNotContainsString( 'outline-color:#6F42C1', $nodes[ 'presence-user-' . $second ]->title );
	}

	public function test_a_place_is_never_left_without_anyone_under_it() {
		$this->view_admin_page( 'upload.php', 'upload' );

		for ( $i = 0; $i < 99; $i++ ) {
			$this->put_user_on_screen( 'upload' );
		}
		$this->put_editor_on_post( self::$post_id );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$nodes = $this->render_nodes();

		$this->assertEmpty( wp_list_filter( $nodes, array( 'parent' => 'presence-elsewhere' ) ) );
		$this->assertArrayHasKey( 'presence-more', $nodes );
	}

	/**
	 * The cap bounds the markup every Heartbeat tick resends.
	 */
	public function test_this_page_is_capped_at_a_hundred() {
		$this->view_admin_page( 'upload.php', 'upload' );

		for ( $i = 0; $i < 101; $i++ ) {
			$this->put_user_on_screen( 'upload' );
		}
		$this->put_user_on_screen( 'users', array( 'title' => 'Users' ) );

		wp_set_current_user( self::$editor_id );
		$this->assertSame( '2 more', $this->render_nodes()['presence-more']->title, 'Without the Online list to open, the row says how many it leaves out.' );

		$this->let_current_user_list_users();
		$nodes = $this->render_nodes();

		$this->assertCount( 100, array_filter( $nodes, fn( $n ) => 0 === strpos( $n->id, 'presence-user-' ) ) );
		$this->assertEmpty( wp_list_filter( $nodes, array( 'parent' => 'presence-elsewhere' ) ) );
		$this->assertSame( 'See everyone online', $nodes['presence-more']->title );
		$this->assertSame( $nodes['presence-online']->href, $nodes['presence-more']->href );
		$this->assertSame( 8, substr_count( $nodes['presence-online']->title, '<img' ) );
		$this->assertSame( wp_nonce_url( admin_url( 'users.php?presence_status=online' ), 'presence_online_filter' ), $nodes['presence-online']->href );
	}

	/**
	 * A presence row outlives the user record it points at when an account is
	 * deleted mid-session, and every list here has to survive that.
	 */
	public function test_an_entry_for_a_user_who_no_longer_exists_is_skipped() {
		$this->view_admin_page( 'upload.php', 'upload' );

		wp_set_presence( 'admin/online', 'user-999901', array( 'screen' => 'upload' ), 999901 );
		wp_set_presence( 'admin/online', 'user-999902', array( 'screen' => 'plugins' ), 999902 );
		$real = get_userdata( $this->put_user_on_screen( 'upload' ) );

		wp_set_current_user( self::$editor_id );
		$nodes = $this->render_nodes();

		$this->assertArrayNotHasKey( 'presence-user-999901', $nodes );
		$this->assertArrayNotHasKey( 'presence-user-999902', $nodes );
		$this->assertArrayHasKey( 'presence-user-' . $real->ID, $nodes );
	}

	public function test_the_indicator_styles_load_for_a_user_who_can_see_it() {
		wp_set_current_user( self::$editor_id );
		add_filter( 'show_admin_bar', '__return_true' );

		wp_presence_admin_bar_assets();

		$this->assertTrue( wp_style_is( 'presence-admin-bar', 'enqueued' ) );
		$this->assertStringContainsString(
			'#wp-admin-bar-presence-online',
			implode( '', (array) wp_styles()->get_data( 'presence-admin-bar', 'after' ) )
		);

		remove_filter( 'show_admin_bar', '__return_true' );
	}

	public function test_the_indicator_styles_stay_off_when_the_bar_is_hidden() {
		wp_deregister_style( 'presence-admin-bar' );
		$wp_styles        = wp_styles();
		$wp_styles->queue = array();
		$wp_styles->done  = array();

		wp_set_current_user( self::$editor_id );
		add_filter( 'show_admin_bar', '__return_false' );

		wp_presence_admin_bar_assets();

		$this->assertFalse( wp_style_is( 'presence-admin-bar', 'enqueued' ) );

		remove_filter( 'show_admin_bar', '__return_false' );
	}

	/**
	 * WP_Admin_Bar renders a node without a link as a div, outside the tab order unless given a tabindex.
	 */
	public function test_the_menu_opens_by_keyboard_without_list_users() {
		wp_set_current_user( self::$editor_id );
		$node = $this->render_nodes()['presence-online'];

		$this->assertFalse( $node->href );
		$this->assertSame( 0, $node->meta['tabindex'] );
	}

	/**
	 * The heartbeat runs from admin-ajax.php, so the node groups by the screen the ping reports.
	 */
	public function test_the_heartbeat_sends_the_node_for_the_screen_it_came_from() {
		$here = get_userdata( $this->put_user_on_screen( 'upload' ) );
		$this->put_user_on_screen( 'edit-comments' );

		wp_set_current_user( self::$editor_id );
		$response = wp_presence_admin_bar_heartbeat_received(
			array(),
			array(
				'presence-ping'      => array(
					'screen' => 'upload',
					'token'  => wp_create_nonce( 'wp_presence_screen_upload' ),
				),
				'presence-admin-bar' => 1,
			)
		);

		$this->assertStringStartsWith( "<li role='group' id='wp-admin-bar-presence-online'", $response['presence-admin-bar'] );
		$this->assertStringContainsString( 'id=\'wp-admin-bar-presence-user-' . $here->ID . '\'', $response['presence-admin-bar'] );
		$this->assertStringContainsString( '3 online', $response['presence-admin-bar'] );
	}

	/**
	 * Grouping by a screen the page was not served for would say who is on it.
	 */
	public function test_the_heartbeat_sends_no_node_for_a_screen_it_cannot_prove() {
		wp_set_current_user( self::$editor_id );
		$response = wp_presence_admin_bar_heartbeat_received(
			array(),
			array(
				'presence-ping'      => array(
					'screen' => 'users',
					'token'  => wp_create_nonce( 'wp_presence_screen_upload' ),
				),
				'presence-admin-bar' => 1,
			)
		);

		$this->assertArrayNotHasKey( 'presence-admin-bar', $response );
	}

	public function test_a_refreshed_heartbeat_nonce_brings_a_fresh_screen_token() {
		wp_set_current_user( self::$editor_id );
		$fresh = apply_filters( 'wp_refresh_nonces', array(), array(), 'upload' )['presence-screen-token'];

		$this->assertSame( 'upload', $fresh['screen'] );
		$this->assertSame( 1, wp_verify_nonce( $fresh['token'], 'wp_presence_screen_upload' ) );
	}

	public function test_the_heartbeat_sends_no_node_unless_the_page_has_one() {
		wp_set_current_user( self::$editor_id );
		$response = wp_presence_admin_bar_heartbeat_received(
			array(),
			array( 'presence-ping' => array( 'screen' => 'upload' ) )
		);

		$this->assertArrayNotHasKey( 'presence-admin-bar', $response );
	}
}
