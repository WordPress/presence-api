<?php
/**
 * Plugin Name: Presence API post query cache measurement
 * Description: Counts how often posts last_changed moves, and how often cached post queries hit, with Presence API's post locks on and off.
 *
 * Drop it into wp-content/mu-plugins on a site with a persistent object cache, leave Settings > Presence API >
 * Post locks on for a few hours and off for a few, then run `wp presence-measure post-query-cache`.
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const PRESENCE_MEASURE_TTL   = 2 * DAY_IN_SECONDS;
const PRESENCE_MEASURE_GROUP = 'presence-measure';

/**
 * Adds one to a counter in memory, or with no metric writes the request's counts to the object cache.
 *
 * @param string|null $metric Optional. Counter name.
 */
function presence_measure_count( $metric = null ) {
	static $counts = array(), $since = 0;

	if ( null !== $metric ) {
		$counts[ $metric ] = ( $counts[ $metric ] ?? 0 ) + 1;
		$since             = $since ? $since : time();

		// A long WP-CLI or cron run writes once a minute rather than only when it ends.
		if ( time() - $since < MINUTE_IN_SECONDS ) {
			return;
		}
	}

	if ( ! $counts || ! wp_using_ext_object_cache() ) {
		return;
	}

	$locks  = function_exists( 'wp_presence_feature_enabled' ) && wp_presence_feature_enabled( 'post-locks' ) ? 'on' : 'off';
	$bucket = gmdate( 'Y-m-d H:00' ) . "|{$locks}";

	foreach ( $counts as $name => $count ) {
		if ( false === wp_cache_incr( "{$bucket}|{$name}", $count, PRESENCE_MEASURE_GROUP ) ) {
			wp_cache_add( "{$bucket}|{$name}", 0, PRESENCE_MEASURE_GROUP, PRESENCE_MEASURE_TTL );
			wp_cache_incr( "{$bucket}|{$name}", $count, PRESENCE_MEASURE_GROUP );
		}
	}
	wp_cache_add( "{$bucket}|first", $since, PRESENCE_MEASURE_GROUP, PRESENCE_MEASURE_TTL );
	wp_cache_set( "{$bucket}|last", time(), PRESENCE_MEASURE_GROUP, PRESENCE_MEASURE_TTL );

	$counts = array();
	$since  = 0;
}

/**
 * The post meta key being written while core moves posts last_changed, or null outside a meta write.
 *
 * @param string|false|null $meta_key Optional. A key to remember, or false to forget it.
 * @return string|null
 */
function presence_measure_meta_key( $meta_key = null ) {
	static $current = null;

	if ( false === $meta_key ) {
		$current = null;
	} elseif ( null !== $meta_key ) {
		$current = $meta_key;
	}

	return $current;
}

// Core moves posts last_changed from these at priority 10, so the key is known on either side.
foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $presence_measure_hook ) {
	add_action(
		$presence_measure_hook,
		static function ( $meta_ids, $object_id, $meta_key ) {
			presence_measure_meta_key( (string) $meta_key );
		},
		9,
		3
	);
	add_action(
		$presence_measure_hook,
		static function () {
			presence_measure_meta_key( false );
		},
		11
	);
}

add_action(
	'wp_cache_set_last_changed',
	static function ( $group ) {
		if ( 'posts' === $group ) {
			presence_measure_count( '_edit_lock' === presence_measure_meta_key() ? 'moves_lock' : 'moves_other' );
		}
	}
);

/**
 * Returns a query's SQL from its first FROM on, which a split query keeps when it swaps the field list.
 *
 * @param string $sql SQL.
 * @return string
 */
function presence_measure_tail( $sql ) {
	return (string) preg_replace( '/^.*?\bFROM\b/is', '', $sql, 1 );
}

/**
 * Tracks cacheable post queries between WP_Query's cache lookup and the SQL a miss runs.
 *
 * @param string $action 'watch' a query, note SQL that 'ran', or 'settle' what is waiting as hits.
 * @param string $sql    Optional. The query's SQL.
 */
function presence_measure_queries( $action, $sql = '' ) {
	static $pending = array();

	if ( 'ran' === $action ) {
		$at = array_search( presence_measure_tail( $sql ), $pending, true );
		if ( false !== $at ) {
			unset( $pending[ $at ] );
			presence_measure_count( 'queries_miss' );
		}
		return;
	}

	// Anything still waiting never ran its SQL, so it came from the cache.
	foreach ( $pending as $unused ) {
		presence_measure_count( 'queries_hit' );
	}
	$pending = 'watch' === $action ? array( presence_measure_tail( $sql ) ) : array();
}

add_filter(
	'posts_pre_query',
	static function ( $posts, $query ) {
		global $wpdb;

		presence_measure_queries( 'settle' );

		if ( null !== $posts || empty( $query->query_vars['cache_results'] ) ) {
			return $posts;
		}

		// The same test WP_Query::get_posts() applies before it looks in the cache.
		$fields = preg_match( '/^\s*SELECT\s+(?:SQL_CALC_FOUND_ROWS\s+)?(?:DISTINCT\s+)?(.*?)\s+FROM\s/is', $query->request, $match ) ? trim( $match[1] ) : '';
		if ( str_contains( strtoupper( $query->request ), ' RAND(' ) || ! in_array( $fields, array( "{$wpdb->posts}.*", "{$wpdb->posts}.ID, {$wpdb->posts}.post_parent", "{$wpdb->posts}.ID" ), true ) ) {
			return $posts;
		}

		presence_measure_queries( 'watch', $query->request );

		return $posts;
	},
	PHP_INT_MAX,
	2
);

// First, so the SQL is compared before another plugin rewrites it.
add_filter(
	'query',
	static function ( $sql ) {
		presence_measure_queries( 'ran', $sql );
		return $sql;
	},
	PHP_INT_MIN
);

add_action(
	'shutdown',
	static function () {
		presence_measure_queries( 'settle' );
		presence_measure_count();
	},
	PHP_INT_MAX
);

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * Prints each hour's counters, split by whether post locks were on.
	 */
	WP_CLI::add_command(
		'presence-measure post-query-cache',
		static function () {
			if ( ! wp_using_ext_object_cache() ) {
				WP_CLI::error( 'This site has no persistent object cache, so there is nothing to measure.' );
			}

			$rows = array();
			for ( $ago = PRESENCE_MEASURE_TTL / HOUR_IN_SECONDS; $ago >= 0; $ago-- ) {
				$time = gmdate( 'Y-m-d H:00', time() - $ago * HOUR_IN_SECONDS );

				foreach ( array( 'off', 'on' ) as $locks ) {
					$values = wp_cache_get_multiple( array_map( static fn( $metric ) => "{$time}|{$locks}|{$metric}", array( 'first', 'last', 'moves_lock', 'moves_other', 'queries_hit', 'queries_miss' ) ), PRESENCE_MEASURE_GROUP );
					if ( ! array_filter( $values ) ) {
						continue;
					}

					$get     = static fn( $metric ) => (int) $values[ "{$time}|{$locks}|{$metric}" ];
					$hits    = $get( 'queries_hit' );
					$miss    = $get( 'queries_miss' );
					$minutes = max( 1, ( $get( 'last' ) - $get( 'first' ) ) / MINUTE_IN_SECONDS );
					$moves   = $get( 'moves_lock' ) + $get( 'moves_other' );

					$rows[] = array(
						'hour (UTC)'              => $time,
						'post locks'              => $locks,
						'minutes'                 => round( $minutes ),
						'moves from lock refresh' => $get( 'moves_lock' ),
						'other moves'             => $get( 'moves_other' ),
						'moves per hour'          => round( 60 * $moves / $minutes ),
						'cacheable queries'       => $hits + $miss,
						'hit rate'                => $hits + $miss ? round( 100 * $hits / ( $hits + $miss ), 1 ) . '%' : '-',
					);
				}
			}

			if ( ! $rows ) {
				WP_CLI::error( 'Nothing counted yet.' );
			}

			WP_CLI\Utils\format_items( 'table', $rows, array_keys( $rows[0] ) );
		}
	);
}
