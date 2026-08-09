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
 * The people who have contributed to this repository.
 *
 * Demo users are sampled from this list first, so contributors run into
 * themselves in the demo they helped build, with their own avatars.
 *
 * This is the fallback, not the source of truth. wp_presence_demo_contributors()
 * asks GitHub who has contributed and only falls back to this list when that
 * request fails. A release can sit for a long time, and the WordPress.org
 * blueprint pins this file to a release tag, so a purely baked list would go on
 * showing the same cast long after it stopped being accurate.
 *
 * What it is still good for: real names. The contributors endpoint returns
 * logins, not names, and resolving those would cost one request per person on
 * every boot. Names are matched in from here by GitHub ID.
 *
 * Generated. Do not edit by hand. Refreshed on every release PR by the
 * sync-versions job in .github/workflows/release-please.yml, or on demand:
 *
 *     GH_TOKEN=$( gh auth token ) node .github/scripts/update-demo-contributors.js
 */
// BEGIN GENERATED CONTRIBUTORS
const WP_PRESENCE_DEMO_CONTRIBUTORS = array(
	array(
		'login' => 'josephfusco',
		'name'  => 'Joe Fusco',
		'id'    => 6676674,
	),
	array(
		'login' => 'i-am-chitti',
		'name'  => 'Deepak Kumar',
		'id'    => 60139930,
	),
	array(
		'login' => 'Intenzi',
		'name'  => 'Ritvik Bhutani',
		'id'    => 62426443,
	),
	array(
		'login' => 'iqbal-web',
		'name'  => 'Iqbal Hossain',
		'id'    => 71224856,
	),
	array(
		'login' => 'aldorizona10-glitch',
		'name'  => 'Aldo Rizona',
		'id'    => 250669805,
	),
	array(
		'login' => 'AshishJii',
		'name'  => 'Ashish Verma',
		'id'    => 88656163,
	),
	array(
		'login' => 'asllanmaciel',
		'name'  => 'Asllan Maciel',
		'id'    => 397983,
	),
);

/**
 * Lowercased GitHub logins that have asked not to appear in the demos.
 *
 * Baked in from .github/demo-contributors-optout.txt so that the opt-out is
 * still honoured when the contributor list comes back live from GitHub, which
 * knows nothing about that file.
 */
const WP_PRESENCE_DEMO_OPTOUT = array();
// END GENERATED CONTRIBUTORS

/**
 * First and last name pools for demo users.
 *
 * Filler. Used once the contributor sample above is exhausted, so that
 * `wp_presence_demo_seed( 40 )` still produces 40 distinct people.
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
 * Admin screen slugs used when seeding presence entries.
 *
 * @var array
 */
const WP_PRESENCE_DEMO_SCREENS = array(
	'dashboard',
	'edit',
	'post',
	'post-new',
	'upload',
	'edit-comments',
	'themes',
	'plugins',
	'users',
	'profile',
	'tools',
	'options-general',
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
 * Asks GitHub who has contributed to the repository.
 *
 * One request, not one per person. Cached for twelve hours so a persistent
 * install does not re-ask on every seed, though a Playground boot starts with
 * an empty database and always pays for it once.
 *
 * Returns an empty array on any failure, including a throttled response.
 * Unauthenticated callers get sixty requests an hour, and the caller treats
 * empty as "use the baked list" rather than as "nobody contributed", so a
 * throttle costs freshness and never an empty demo.
 *
 * @since 7.1.0
 *
 * @return array List of array{login: string, id: int}.
 */
function wp_presence_demo_fetch_contributors() {
	$cached = get_transient( 'wp_presence_demo_contributors' );

	if ( is_array( $cached ) ) {
		return $cached;
	}

	$response = wp_remote_get(
		'https://api.github.com/repos/WordPress/presence-api/contributors?per_page=100',
		array(
			'timeout' => 5,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'presence-api-demo-seeder',
			),
		)
	);

	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		// Cache the miss briefly too. Without this, a rate-limited demo re-asks
		// on every boot and stays rate limited.
		set_transient( 'wp_presence_demo_contributors', array(), 5 * MINUTE_IN_SECONDS );
		return array();
	}

	$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( ! is_array( $decoded ) ) {
		return array();
	}

	$contributors = array();

	foreach ( $decoded as $entry ) {
		if ( empty( $entry['login'] ) || empty( $entry['id'] ) ) {
			continue;
		}

		// Bots are typed, except for the older app identities that are not,
		// which is what the suffix catches.
		if ( ! empty( $entry['type'] ) && 'User' !== $entry['type'] ) {
			continue;
		}

		if ( str_ends_with( strtolower( $entry['login'] ), '[bot]' ) ) {
			continue;
		}

		if ( in_array( strtolower( $entry['login'] ), WP_PRESENCE_DEMO_OPTOUT, true ) ) {
			continue;
		}

		$contributors[] = array(
			'login' => (string) $entry['login'],
			'id'    => (int) $entry['id'],
		);
	}

	set_transient( 'wp_presence_demo_contributors', $contributors, 12 * HOUR_IN_SECONDS );

	return $contributors;
}

/**
 * The contributors to draw demo users from.
 *
 * Live membership from GitHub, with real names matched in from the baked list
 * by GitHub ID. Someone who has contributed since the list was last generated
 * appears immediately under their login and picks up their display name at the
 * next regeneration.
 *
 * @since 7.1.0
 *
 * @return array List of array{login: string, name: string, id: int}.
 */
function wp_presence_demo_contributors() {
	$baked = WP_PRESENCE_DEMO_CONTRIBUTORS;
	$live  = wp_presence_demo_fetch_contributors();

	if ( ! $live ) {
		return $baked;
	}

	$names = array();
	foreach ( $baked as $contributor ) {
		$names[ $contributor['id'] ] = $contributor['name'];
	}

	$contributors = array();

	foreach ( $live as $contributor ) {
		$contributors[] = array(
			'login' => $contributor['login'],
			'name'  => isset( $names[ $contributor['id'] ] ) ? $names[ $contributor['id'] ] : '',
			'id'    => $contributor['id'],
		);
	}

	return $contributors;
}

/**
 * Builds the list of people to seed.
 *
 * Contributors are shuffled and sampled, then synthetic names fill whatever is
 * left over. Sampled rather than taken in commit order so that the five-user
 * demo is not the same five faces on every boot; two people looking at the
 * demo side by side should see different rooms.
 *
 * Deliberately not deterministic. The Playwright suite captures screenshot
 * artifacts rather than comparing against baselines, so a different cast each
 * run costs nothing there and is the entire point here.
 *
 * @since 7.1.0
 *
 * @param int $count Number of identities to build.
 * @return array List of {
 *     @type string $first     First name.
 *     @type string $last      Last name. Empty for a one-word name.
 *     @type string $display   Display name.
 *     @type string $login     GitHub login, or '' for a synthetic name.
 *     @type int    $github_id GitHub user ID, or 0 for a synthetic name.
 * }
 */
function wp_presence_demo_identities( $count ) {
	$contributors = wp_presence_demo_contributors();
	shuffle( $contributors );

	$identities = array();

	foreach ( array_slice( $contributors, 0, $count ) as $contributor ) {
		$display = '' !== $contributor['name'] ? $contributor['name'] : $contributor['login'];

		// first_name and last_name feed WordPress's name-format dropdown. A
		// mononym or a three-part name should not be forced into two fields,
		// so only split on the first space and accept an empty last name.
		$parts = explode( ' ', $display, 2 );

		$identities[] = array(
			'first'     => $parts[0],
			'last'      => isset( $parts[1] ) ? $parts[1] : '',
			'display'   => $display,
			'login'     => $contributor['login'],
			'github_id' => (int) $contributor['id'],
		);
	}

	for ( $i = 0; count( $identities ) < $count; $i++ ) {
		$name = wp_presence_demo_name( $i );

		$identities[] = array(
			'first'     => $name['first'],
			'last'      => $name['last'],
			'display'   => $name['display'],
			'login'     => '',
			'github_id' => 0,
		);
	}

	return $identities;
}

/**
 * Builds the avatar URL for a GitHub user ID.
 *
 * GitHub resizes server-side, so each widget gets an image at the size it
 * actually renders instead of one 460px original scaled down twenty times.
 *
 * @since 7.1.0
 *
 * @param int $github_id GitHub user ID.
 * @param int $size      Requested size in pixels.
 * @return string
 */
function wp_presence_demo_avatar_url( $github_id, $size = 96 ) {
	return sprintf(
		'https://avatars.githubusercontent.com/u/%d?v=4&s=%d',
		(int) $github_id,
		max( 1, (int) $size )
	);
}

/**
 * Installs the mu-plugin that maps demo users to their GitHub avatars.
 *
 * The seeder itself only runs for the length of one seeding request. Avatars
 * have to resolve on every subsequent page load, and every avatar in the
 * plugin goes through get_avatar_url() or get_avatar(), so a single
 * pre_get_avatar_data filter in an mu-plugin covers all of them.
 *
 * Written from here rather than added as another blueprint step so that all
 * four blueprints, wp-env, and the Playwright suite pick it up without
 * changing anything. Removed again by wp_presence_demo_cleanup().
 *
 * @since 7.1.0
 *
 * @return bool True when the mu-plugin is in place.
 */
function wp_presence_demo_install_avatar_filter() {
	if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
		return false;
	}

	if ( ! is_dir( WPMU_PLUGIN_DIR ) && ! wp_mkdir_p( WPMU_PLUGIN_DIR ) ) {
		return false;
	}

	$source = <<<'PHP'
<?php
/**
 * Plugin Name: Presence Demo Avatars
 *
 * Serves GitHub avatars for demo users seeded by demo-seeder.php. Installed
 * by wp_presence_demo_install_avatar_filter() and removed by
 * wp_presence_demo_cleanup(). Not part of the Presence API.
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves an avatar identifier to a demo user's GitHub ID.
 *
 * @param mixed $id_or_email User ID, WP_User, WP_Post, WP_Comment, or email.
 * @return int GitHub user ID, or 0 when this is not a demo contributor.
 */
function presence_demo_github_id_for( $id_or_email ) {
	$user_id = 0;

	if ( is_numeric( $id_or_email ) ) {
		$user_id = (int) $id_or_email;
	} elseif ( $id_or_email instanceof WP_User ) {
		$user_id = (int) $id_or_email->ID;
	} elseif ( $id_or_email instanceof WP_Post ) {
		$user_id = (int) $id_or_email->post_author;
	} elseif ( $id_or_email instanceof WP_Comment ) {
		$user_id = (int) $id_or_email->user_id;
	} elseif ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
		$user    = get_user_by( 'email', $id_or_email );
		$user_id = $user ? (int) $user->ID : 0;
	}

	if ( ! $user_id ) {
		return 0;
	}

	return (int) get_user_meta( $user_id, 'presence_demo_github_id', true );
}

/**
 * Short-circuits avatar resolution for demo users.
 *
 * Setting a url on the args array is what stops get_avatar_data() from
 * continuing on to Gravatar, which would return the mystery person for these
 * users because their addresses are @example.com.
 *
 * @param array $args        Avatar arguments, with size already normalized.
 * @param mixed $id_or_email Avatar identifier.
 * @return array
 */
function presence_demo_avatar_data( $args, $id_or_email ) {
	if ( ! empty( $args['force_default'] ) ) {
		return $args;
	}

	$github_id = presence_demo_github_id_for( $id_or_email );

	if ( ! $github_id ) {
		return $args;
	}

	$size = isset( $args['size'] ) ? (int) $args['size'] : 96;

	$args['url']          = sprintf( 'https://avatars.githubusercontent.com/u/%d?v=4&s=%d', $github_id, max( 1, $size ) );
	$args['found_avatar'] = true;

	return $args;
}
add_filter( 'pre_get_avatar_data', 'presence_demo_avatar_data', 10, 2 );

PHP;

	$target = WPMU_PLUGIN_DIR . '/presence-demo-avatars.php';

	if ( file_exists( $target ) && $source === file_get_contents( $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return true;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	return false !== file_put_contents( $target, $source );
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
		$query = new WP_Query(
			array(
				'post_type'              => 'post',
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
					'post_type'   => 'post',
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

	wp_presence_demo_install_avatar_filter();

	$identities = wp_presence_demo_identities( $count );

	for ( $i = 0; $i < $count; $i++ ) {
		$username = 'presence-demo-' . ( $i + 1 );
		$identity = $identities[ $i ];
		$user     = get_user_by( 'login', $username );

		if ( $user ) {
			$user_id = (int) $user->ID;

			// Re-apply the identity rather than skipping. The contributor list
			// grows, so presence-demo-3 is not always the same person, and a
			// persistent environment would otherwise keep showing whoever held
			// that slot the first time it was seeded.
			if ( $user->display_name !== $identity['display'] ) {
				wp_update_user(
					array(
						'ID'           => $user_id,
						'first_name'   => $identity['first'],
						'last_name'    => $identity['last'],
						'display_name' => $identity['display'],
					)
				);
			}

			$user_ids[] = $user_id;
		} else {
			$user_id = wp_insert_user(
				array(
					'user_login'   => $username,
					'user_email'   => $username . '@example.com',
					'user_pass'    => wp_generate_password(),
					'role'         => 'editor',
					'first_name'   => $identity['first'],
					'last_name'    => $identity['last'],
					'display_name' => $identity['display'],
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

		// The avatar mu-plugin reads these. Synthetic users get the meta
		// cleared so they fall through to the Gravatar default.
		if ( $identity['github_id'] ) {
			update_user_meta( $user_id, 'presence_demo_github_id', $identity['github_id'] );
			update_user_meta( $user_id, 'presence_demo_github_login', $identity['login'] );
		} else {
			delete_user_meta( $user_id, 'presence_demo_github_id' );
			delete_user_meta( $user_id, 'presence_demo_github_login' );
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

	$first_user = true;

	foreach ( $user_ids as $uid ) {
		// Guarantee at least one user is editing a post for the Active Posts widget.
		$screen = $first_user ? 'post' : $screens[ array_rand( $screens ) ];
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
				array(
					'action' => 'editing',
					'screen' => 'post',
				),
				$uid
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
 * Removes all demo users and their presence entries.
 *
 * @since 7.1.0
 */
function wp_presence_demo_cleanup() {
	global $wpdb;

	$has_cli = defined( 'WP_CLI' ) && WP_CLI;

	if ( defined( 'WPMU_PLUGIN_DIR' ) && file_exists( WPMU_PLUGIN_DIR . '/presence-demo-avatars.php' ) ) {
		wp_delete_file( WPMU_PLUGIN_DIR . '/presence-demo-avatars.php' );
	}

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
