<?php
/**
 * Demo seeder for the Presence API.
 *
 * Creates WordPress users with realistic names and seeds real presence
 * entries in the wp_presence table. Used by both the WP-CLI `demo`
 * command and the Playwright visual demo.
 *
 * @package Presence_API
 * @since 7.1.0
 */

/**
 * First and last name pools for demo users.
 *
 * 50 gender-neutral first names x 50 common last names = 2,500 unique
 * combinations. Names are paired using coprime offset arithmetic so
 * that every combination is unique without appending numbers.
 */
const WP_PRESENCE_DEMO_FIRST_NAMES = array(
	'Alex',      'Jordan',    'Sam',       'Taylor',    'Casey',
	'Morgan',    'Riley',     'Quinn',     'Avery',     'Blake',
	'Cameron',   'Dakota',    'Emery',     'Finley',    'Harper',
	'Jamie',     'Kendall',   'Logan',     'Micah',     'Noel',
	'Parker',    'Reese',     'Sage',      'Tatum',     'Val',
	'Wren',      'Adrian',    'Bailey',    'Corey',     'Drew',
	'Ellis',     'Frankie',   'Gray',      'Hayden',    'Indigo',
	'Jules',     'Kit',       'Lane',      'Marlow',    'Nico',
	'Oakley',    'Peyton',    'Remy',      'Shay',      'Toby',
	'Uma',       'Vic',       'Winter',    'Xen',       'Yael',
);

const WP_PRESENCE_DEMO_LAST_NAMES = array(
	'Smith',     'Johnson',   'Williams',  'Brown',     'Jones',
	'Garcia',    'Miller',    'Davis',     'Rodriguez', 'Martinez',
	'Hernandez', 'Lopez',     'Gonzalez',  'Wilson',    'Anderson',
	'Thomas',    'Taylor',    'Moore',     'Jackson',   'Martin',
	'Lee',       'Perez',     'Thompson',  'White',     'Harris',
	'Sanchez',   'Clark',     'Ramirez',   'Lewis',     'Robinson',
	'Walker',    'Young',     'Allen',     'King',      'Wright',
	'Scott',     'Torres',    'Nguyen',    'Hill',      'Flores',
	'Green',     'Adams',     'Nelson',    'Baker',     'Hall',
	'Rivera',    'Campbell',  'Mitchell',  'Carter',    'Roberts',
);

/**
 * Admin screen slugs used when seeding presence entries.
 *
 * @var array
 */
const WP_PRESENCE_DEMO_SCREENS = array(
	'dashboard', 'edit', 'post', 'post-new', 'upload',
	'edit-comments', 'themes', 'plugins', 'users',
	'profile', 'tools', 'options-general',
);

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
	'Q3 Product Launch Announcement',
	'How to Migrate to the New Theme',
	'Weekly Team Standup Notes',
	'Accessibility Audit Findings',
	'Site Redesign: Homepage Wireframes',
);

/**
 * Ensures demo posts exist and returns their IDs.
 *
 * @since 7.1.0
 *
 * @return array Array of post IDs.
 */
function wp_presence_demo_ensure_posts() {
	$post_ids = array();

	foreach ( WP_PRESENCE_DEMO_POSTS as $title ) {
		$existing = get_page_by_title( $title, OBJECT, 'post' );

		if ( $existing ) {
			$post_ids[] = $existing->ID;
		} else {
			$post_id = wp_insert_post( array(
				'post_title'  => $title,
				'post_status' => 'draft',
				'post_type'   => 'post',
			) );

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
 *
 * @param int $count Number of users to create.
 * @return array Array of created user IDs.
 */
function wp_presence_demo_seed( $count ) {
	$user_ids = array();
	$has_cli  = defined( 'WP_CLI' ) && WP_CLI;

	if ( $has_cli ) {
		$progress = WP_CLI\Utils\make_progress_bar(
			sprintf( 'Creating %d demo users', $count ),
			$count
		);
	}

	for ( $i = 0; $i < $count; $i++ ) {
		$username = 'presence-demo-' . ( $i + 1 );
		$user     = get_user_by( 'login', $username );

		if ( $user ) {
			$user_ids[] = $user->ID;
		} else {
			$name = wp_presence_demo_name( $i );

			$user_id = wp_insert_user( array(
				'user_login'   => $username,
				'user_email'   => $username . '@example.com',
				'user_pass'    => wp_generate_password(),
				'role'         => 'editor',
				'first_name'   => $name['first'],
				'last_name'    => $name['last'],
				'display_name' => $name['display'],
			) );

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

	if ( $has_cli ) {
		$progress = WP_CLI\Utils\make_progress_bar(
			sprintf( 'Seeding %d presence entries', count( $user_ids ) ),
			count( $user_ids )
		);
	}

	foreach ( $user_ids as $uid ) {
		$screen = $screens[ array_rand( $screens ) ];
		$state  = array( 'screen' => $screen );

		if ( in_array( $screen, array( 'post', 'post-new' ), true ) ) {
			$state['post_status'] = $post_statuses[ array_rand( $post_statuses ) ];
		}

		wp_set_presence( 'admin/online', 'user-' . $uid, $state, $uid );

		if ( 'post' === $screen ) {
			$post_id = $real_posts[ array_rand( $real_posts ) ];
			wp_set_presence(
				'postType/post:' . $post_id,
				'editor-' . $uid,
				array( 'action' => 'editing', 'screen' => 'post' ),
				$uid
			);
		}

		if ( $has_cli ) {
			$progress->tick();
		}
	}

	if ( $has_cli ) {
		$progress->finish();
		$summary = wp_get_presence_summary();
		WP_CLI::success( sprintf(
			'%d users across %d rooms.',
			$summary['total_users'],
			count( $summary['by_prefix'] )
		) );
	}
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
