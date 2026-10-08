<?php
/**
 * Demo seeder for the Presence API.
 *
 * Creates WordPress users with realistic names and seeds real presence
 * entries in the wp_presence table. Used by both the WP-CLI `demo`
 * command and the Playwright visual demo.
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * First and last name pools for demo users.
 *
 * 50 gender-neutral first names x 50 common last names = 2,500 unique
 * combinations. Names are paired using coprime offset arithmetic so
 * that every combination is unique without appending numbers.
 */
const WP_PRESENCE_DEMO_FIRST_NAMES = array(
	'Alex',
	'Jordan',
	'Sam',
	'Taylor',
	'Casey',
	'Morgan',
	'Riley',
	'Quinn',
	'Avery',
	'Blake',
	'Cameron',
	'Dakota',
	'Emery',
	'Finley',
	'Harper',
	'Jamie',
	'Kendall',
	'Logan',
	'Micah',
	'Noel',
	'Parker',
	'Reese',
	'Sage',
	'Tatum',
	'Val',
	'Wren',
	'Adrian',
	'Bailey',
	'Corey',
	'Drew',
	'Ellis',
	'Frankie',
	'Gray',
	'Hayden',
	'Indigo',
	'Jules',
	'Kit',
	'Lane',
	'Marlow',
	'Nico',
	'Oakley',
	'Peyton',
	'Remy',
	'Shay',
	'Toby',
	'Uma',
	'Vic',
	'Winter',
	'Xen',
	'Yael',
);

const WP_PRESENCE_DEMO_LAST_NAMES = array(
	'Smith',
	'Johnson',
	'Williams',
	'Brown',
	'Jones',
	'Garcia',
	'Miller',
	'Davis',
	'Rodriguez',
	'Martinez',
	'Hernandez',
	'Lopez',
	'Gonzalez',
	'Wilson',
	'Anderson',
	'Thomas',
	'Taylor',
	'Moore',
	'Jackson',
	'Martin',
	'Lee',
	'Perez',
	'Thompson',
	'White',
	'Harris',
	'Sanchez',
	'Clark',
	'Ramirez',
	'Lewis',
	'Robinson',
	'Walker',
	'Young',
	'Allen',
	'King',
	'Wright',
	'Scott',
	'Torres',
	'Nguyen',
	'Hill',
	'Flores',
	'Green',
	'Adams',
	'Nelson',
	'Baker',
	'Hall',
	'Rivera',
	'Campbell',
	'Mitchell',
	'Carter',
	'Roberts',
);

/**
 * Admin screens used when seeding presence entries, keyed to the page title a real ping would send.
 *
 * @var array
 */
const WP_PRESENCE_DEMO_SCREENS = array(
	'dashboard'       => 'Dashboard',
	'edit'            => 'Posts',
	'post'            => 'Edit Post',
	'page'            => 'Edit Page',
	'post-new'        => 'Add New Post',
	'upload'          => 'Media Library',
	'edit-comments'   => 'Comments',
	'themes'          => 'Themes',
	'plugins'         => 'Plugins',
	'users'           => 'Users',
	'profile'         => 'Profile',
	'tools'           => 'Tools',
	'options-general' => 'General Settings',
);

/**
 * How many demo users land on each screen, relative to the others, so most are writing and few are in settings.
 */
const WP_PRESENCE_DEMO_SCREEN_WEIGHTS = array(
	'dashboard'       => 2,
	'edit'            => 4,
	'post'            => 10,
	'page'            => 6,
	'post-new'        => 5,
	'upload'          => 6,
	'edit-comments'   => 6,
	'themes'          => 1,
	'plugins'         => 2,
	'users'           => 3,
	'profile'         => 3,
	'tools'           => 1,
	'options-general' => 2,
);

/**
 * How many editors each demo post draws, relative to the others, in WP_PRESENCE_DEMO_POSTS order.
 */
const WP_PRESENCE_DEMO_POST_WEIGHTS = array( 1, 1, 1, 1, 1 );

/**
 * Picks a key at random, in proportion to its weight.
 *
 * @since 0.11.0
 *
 * @param array $weights Weights keyed by the value to return.
 * @return int|string A key of `$weights`.
 */
function wp_presence_demo_pick( $weights ) {
	$roll = wp_rand( 1, array_sum( $weights ) );

	foreach ( $weights as $key => $weight ) {
		$roll -= $weight;
		if ( $roll <= 0 ) {
			return $key;
		}
	}

	return array_key_last( $weights );
}

/**
 * Returns the display name for a given demo user index.
 *
 * Uses coprime offset arithmetic to pair first and last names so that
 * every index up to 2,500 (50x50) produces a unique combination
 * without appending numbers.
 *
 * Deterministic: same index always produces the same name.
 *
 * @since 7.1.0
 *
 * @param int $index Zero-based user index.
 * @return array { 'first' => string, 'last' => string, 'display' => string }
 */
function wp_presence_demo_name( $index ) {
	$firsts      = WP_PRESENCE_DEMO_FIRST_NAMES;
	$lasts       = WP_PRESENCE_DEMO_LAST_NAMES;
	$first_count = count( $firsts );
	$last_count  = count( $lasts );

	// First name from column (index mod 50), last name from row + column
	// offset. Produces 2,500 unique pairs for 50x50 pools.
	$first = $firsts[ $index % $first_count ];
	$last  = $lasts[ ( (int) floor( $index / $first_count ) + ( $index % $first_count ) * 7 ) % $last_count ];

	return array(
		'first'   => $first,
		'last'    => $last,
		'display' => $first . ' ' . $last,
	);
}

/**
 * Demo post titles created for realistic Active Posts widget content.
 */
const WP_PRESENCE_DEMO_POSTS = array(
	'Live: Election Night Results',
	'City Council Votes on Transit Budget',
	'Storm Tracker: Coastal Flood Warnings',
	'Opinion: Keep the Libraries Open Late',
	'Weekend Arts Guide',
);

/**
 * Demo page titles, so editors show up on pages as well as posts.
 */
const WP_PRESENCE_DEMO_PAGES = array(
	'About',
	'Contact',
	'Events Calendar',
);

/**
 * Ensures demo posts exist and returns their IDs.
 *
 * @since 7.1.0
 * @since 0.11.0 Added the `$post_type` and `$titles` parameters.
 *
 * @param string   $post_type The post type to create.
 * @param string[] $titles    The titles to ensure.
 * @return array Array of post IDs.
 */
function wp_presence_demo_ensure_posts( $post_type = 'post', $titles = WP_PRESENCE_DEMO_POSTS ) {
	$post_ids = array();

	foreach ( $titles as $title ) {
		$query = new WP_Query(
			array(
				'post_type'              => $post_type,
				'title'                  => $title,
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		if ( $query->have_posts() ) {
			$post_ids[] = $query->posts[0]->ID;
		} else {
			$post_id = wp_insert_post(
				array(
					'post_title'  => $title,
					'post_status' => 'draft',
					'post_type'   => $post_type,
				)
			);

			if ( $post_id && ! is_wp_error( $post_id ) ) {
				$post_ids[] = $post_id;
			}
		}
	}

	return $post_ids;
}

/**
 * Creates N demo users and seeds their presence entries.
 *
 * @since 7.1.0
 * @since 0.2.2 Added the `$offset` parameter.
 *
 * @param int $count  Number of users to create.
 * @param int $offset Index to start the username sequence at. Multisite users are
 *                    network-global, so each site needs its own slice.
 * @return array Array of created user IDs.
 */
function wp_presence_demo_seed( $count, $offset = 0 ) {
	$user_ids = array();
	$has_cli  = defined( 'WP_CLI' ) && WP_CLI;

	if ( $has_cli ) {
		$progress = WP_CLI\Utils\make_progress_bar(
			sprintf( 'Creating %d demo users', $count ),
			$count
		);
	}

	for ( $i = 0; $i < $count; $i++ ) {
		$username = 'presence-demo-' . ( $offset + $i + 1 );
		$user     = get_user_by( 'login', $username );

		if ( $user ) {
			$user_ids[] = $user->ID;
		} else {
			$name = wp_presence_demo_name( $offset + $i );

			$user_id = wp_insert_user(
				array(
					'user_login'   => $username,
					'user_email'   => $username . '@example.com',
					'user_pass'    => wp_generate_password(),
					'role'         => 'editor',
					'first_name'   => $name['first'],
					'last_name'    => $name['last'],
					'display_name' => $name['display'],
				)
			);

			if ( is_wp_error( $user_id ) ) {
				if ( $has_cli ) {
					WP_CLI::warning( $user_id->get_error_message() );
					$progress->tick();
				}
				continue;
			}

			$user_ids[] = $user_id;
		}

		if ( $has_cli ) {
			$progress->tick();
		}
	}

	if ( $has_cli ) {
		$progress->finish();
		WP_CLI::success( sprintf( '%d demo users ready.', count( $user_ids ) ) );
	}

	wp_presence_demo_refresh( $user_ids );

	return $user_ids;
}

/**
 * Seeds (or refreshes) presence entries for existing user IDs.
 *
 * @since 7.1.0
 *
 * @param array $user_ids Array of user IDs.
 */
function wp_presence_demo_refresh( $user_ids ) {
	$screens       = WP_PRESENCE_DEMO_SCREENS;
	$post_statuses = array( 'publish', 'draft', 'pending', 'private', 'future' );
	$has_cli       = defined( 'WP_CLI' ) && WP_CLI;

	// Ensure demo posts exist so editors are distributed across multiple posts.
	$real_posts = wp_presence_demo_ensure_posts();

	if ( empty( $real_posts ) ) {
		$real_posts = array( 1 );
	}

	$real_pages = wp_presence_demo_ensure_posts( 'page', WP_PRESENCE_DEMO_PAGES );

	if ( $has_cli ) {
		$progress = WP_CLI\Utils\make_progress_bar(
			sprintf( 'Seeding %d presence entries', count( $user_ids ) ),
			count( $user_ids )
		);
	}

	$first_user = true;

	foreach ( $user_ids as $uid ) {
		// Guarantee at least one user is editing a post for the Active Posts widget.
		$screen = $first_user ? 'post' : wp_presence_demo_pick( WP_PRESENCE_DEMO_SCREEN_WEIGHTS );
		$state  = array(
			'screen' => $screen,
			'title'  => $screens[ $screen ],
		);

		if ( in_array( $screen, array( 'post', 'page', 'post-new' ), true ) ) {
			$state['post_status'] = $post_statuses[ array_rand( $post_statuses ) ];
		}

		wp_set_presence( 'admin/online', 'user-' . $uid, $state, array( 'user_id' => $uid ) );

		if ( 'post' === $screen ) {
			$post_id = $real_posts[ wp_presence_demo_pick( array_slice( WP_PRESENCE_DEMO_POST_WEIGHTS, 0, count( $real_posts ) ) ) ];
		} elseif ( 'page' === $screen && $real_pages ) {
			$post_id = $real_pages[ array_rand( $real_pages ) ];
		} else {
			$post_id = 0;
		}

		if ( $post_id ) {
			wp_set_presence(
				'postType/' . $screen . ':' . $post_id,
				'editor-' . $uid,
				array(
					'action' => 'editing',
					'screen' => $screen,
				),
				array( 'user_id' => $uid )
			);
		}

		$first_user = false;

		if ( $has_cli ) {
			$progress->tick();
		}
	}

	if ( $has_cli ) {
		$progress->finish();
		$summary = wp_get_presence_summary();
		WP_CLI::success(
			sprintf(
				'%d users across %d rooms.',
				$summary['total_users'],
				count( $summary['by_prefix'] )
			)
		);
	}
}

/**
 * Post lock scenarios: title, status, and how many demo users are on it.
 */
const WP_PRESENCE_DEMO_LOCKS = array(
	array( 'Breaking: Water Main Closes Downtown Streets', 'draft', 1 ),
	array( 'Live Updates: Championship Parade', 'publish', 2 ),
	array( 'Investigation: Where the Road Repair Money Went', 'pending', 3 ),
	array( 'Tomorrow\'s Morning Briefing', 'future', 1 ),
);

/**
 * Locks a post for each scenario, held by the first of its demo users.
 *
 * @since 0.9.0
 */
function wp_presence_demo_seed_locks() {
	// presence-demo-1 is already editing a post from wp_presence_demo_seed().
	$user_index = 1;

	foreach ( WP_PRESENCE_DEMO_LOCKS as list( $title, $status, $people ) ) {
		$posts   = get_posts(
			array(
				'title'       => $title,
				'post_status' => 'any',
				'numberposts' => 1,
				'fields'      => 'ids',
			)
		);
		$post_id = $posts ? $posts[0] : wp_insert_post(
			array(
				'post_title'  => $title,
				'post_status' => $status,
				'post_author' => (int) username_exists( 'presence-demo-' . ( $user_index + 1 ) ),
				'post_date'   => 'future' === $status ? gmdate( 'Y-m-d H:i:s', strtotime( '+1 week' ) ) : '',
			)
		);

		for ( $i = 0; $i < $people; $i++ ) {
			$user = get_user_by( 'login', 'presence-demo-' . ( ++$user_index ) );
			if ( ! $user || ! $post_id ) {
				continue;
			}
			if ( 0 === $i ) {
				update_post_meta( $post_id, '_edit_lock', time() . ':' . $user->ID );
			}
			wp_set_presence( 'admin/online', 'user-' . $user->ID, array( 'screen' => 'post' ), array( 'user_id' => $user->ID ) );
			wp_set_presence( wp_presence_post_room( $post_id ), 'editor-' . $user->ID, wp_presence_editor_state( 'post', 0 === $i ), array( 'user_id' => $user->ID ) );
		}
	}
}

/**
 * Puts demo users in a post's block editor session, as Gutenberg Sync Engines' Presence backend stores them.
 *
 * @since 0.14.0
 *
 * @param int $post_id Optional. The post to join. Defaults to the first demo post.
 * @param int $count   How many demo users join. GSE turns away a room's fourth client, so two leaves room for the viewer.
 * @return int The post they are editing, or 0 when there is none.
 */
function wp_presence_demo_seed_collaborators( $post_id = 0, $count = 2 ) {
	if ( ! $post_id ) {
		$posts   = wp_presence_demo_ensure_posts();
		$post_id = $posts ? (int) $posts[0] : 0;
	}
	$room = $post_id ? wp_presence_post_room( $post_id ) : false;

	if ( ! $room ) {
		return 0;
	}

	for ( $i = 1; $i <= $count; $i++ ) {
		$user = get_user_by( 'login', 'presence-demo-' . $i );
		if ( ! $user ) {
			continue;
		}

		// GSE reads the number after its prefix as the Yjs client id, and drops rows older than 30 seconds.
		wp_set_presence(
			$room,
			'gse-' . ( 1000 + $user->ID ),
			array(
				'collaboratorInfo' => array(
					'id'          => $user->ID,
					'name'        => $user->display_name,
					'slug'        => $user->user_nicename,
					'avatar_urls' => rest_get_avatar_urls( $user ),
					'browserType' => 'Chrome',
					'enteredAt'   => strtotime( $user->user_registered . ' UTC' ) * 1000,
				),
			),
			array(
				'user_id'  => $user->ID,
				'date_gmt' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		wp_set_presence( 'admin/online', 'user-' . $user->ID, array( 'screen' => 'post' ), array( 'user_id' => $user->ID ) );
	}

	return $post_id;
}

/**
 * Removes all demo users and their presence entries.
 *
 * @since 7.1.0
 */
function wp_presence_demo_cleanup() {
	global $wpdb;

	$has_cli = defined( 'WP_CLI' ) && WP_CLI;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$user_ids = $wpdb->get_col(
		"SELECT ID FROM {$wpdb->users} WHERE user_login LIKE 'presence-demo-%'"
	);

	if ( empty( $user_ids ) ) {
		if ( $has_cli ) {
			WP_CLI::log( 'No demo users found.' );
		}
		return;
	}

	require_once ABSPATH . 'wp-admin/includes/user.php';

	if ( $has_cli ) {
		$progress = WP_CLI\Utils\make_progress_bar(
			sprintf( 'Removing %d demo users', count( $user_ids ) ),
			count( $user_ids )
		);
	}

	foreach ( $user_ids as $uid ) {
		wp_remove_user_presence( (int) $uid );
		wp_delete_user( (int) $uid );
		if ( $has_cli ) {
			$progress->tick();
		}
	}

	if ( $has_cli ) {
		$progress->finish();
		WP_CLI::success( sprintf( '%d demo users removed.', count( $user_ids ) ) );
	}
}
