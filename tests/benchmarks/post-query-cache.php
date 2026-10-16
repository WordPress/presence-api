<?php
/**
 * Plugin Name: Presence API post query cache measurement
 * Description: Counts how often posts last_changed moves, and how often cached post queries hit, with Presence API's post locks on and off.
 *
 * Copy this file into wp-content/mu-plugins on a site with a persistent object cache, leave it for a few
 * hours with Settings > Presence API > Post locks on and a few with it off, then run:
 *
 *   wp presence-measure post-query-cache
 *
 * Counters live in the object cache for two days, so nothing is written to the database. Delete the file
 * to stop.
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Seconds each hour's counters are kept.
 */
const PRESENCE_MEASURE_TTL = 2 * DAY_IN_SECONDS;

/**
 * Cache group the counters live in.
 */
const PRESENCE_MEASURE_GROUP = 'presence-measure';

/**
 * Adds one to a counter for the current hour and post lock setting.
 *
 * @param string $metric Counter name.
 */
function presence_measure_count( $metric ) {
	if ( ! wp_using_ext_object_cache() ) {
		return;
	}

	$locks = function_exists( 'wp_presence_feature_enabled' ) && wp_presence_feature_enabled( 'post-locks' ) ? 'on' : 'off';
	$key   = gmdate( 'Y-m-d H:00', time() ) . "|{$locks}|{$metric}";

	if ( false === wp_cache_incr( $key, 1, PRESENCE_MEASURE_GROUP ) ) {
		wp_cache_add( $key, 0, PRESENCE_MEASURE_GROUP, PRESENCE_MEASURE_TTL );
		wp_cache_incr( $key, 1, PRESENCE_MEASURE_GROUP );
	}

	$hour = substr( $key, 0, strrpos( $key, '|' ) );
	wp_cache_add( "{$hour}|first", time(), PRESENCE_MEASURE_GROUP, PRESENCE_MEASURE_TTL );
	wp_cache_set( "{$hour}|last", time(), PRESENCE_MEASURE_GROUP, PRESENCE_MEASURE_TTL );

	$hours = wp_cache_get( 'hours', PRESENCE_MEASURE_GROUP );
	if ( ! is_array( $hours ) || ! in_array( $hour, $hours, true ) ) {
		$hours   = is_array( $hours ) ? $hours : array();
		$hours[] = $hour;
		wp_cache_set( 'hours', array_slice( $hours, -96 ), PRESENCE_MEASURE_GROUP, PRESENCE_MEASURE_TTL );
	}
}

/**
 * The post meta key being written while core moves posts last_changed, or null outside a meta write.
 *
 * @param string|null $meta_key Optional. A key to remember, or false to forget it.
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
 * The SQL of cacheable post queries that have not run yet, from the first FROM on.
 *
 * A split query swaps the field list before it runs, so only what follows FROM is compared.
 *
 * @param string|null $add  Optional. A query to start watching.
 * @param string|null $seen Optional. SQL that just ran.
 * @return void
 */
function presence_measure_queries( $add = null, $seen = null ) {
	static $pending = array();

	if ( null !== $seen ) {
		$tail = strstr( $seen, ' FROM ' );
		$at   = false === $tail ? false : array_search( $tail, $pending, true );
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
	$pending = array();

	if ( null !== $add ) {
		$pending[] = $add;
	}
}

add_filter(
	'posts_pre_query',
	static function ( $posts, $query ) {
		global $wpdb;

		presence_measure_queries();

		if ( null !== $posts || empty( $query->query_vars['cache_results'] ) ) {
			return $posts;
		}

		// The same test WP_Query::get_posts() applies before it looks in the cache.
		$fields = preg_match( '/^\s*SELECT\s+(?:SQL_CALC_FOUND_ROWS\s+)?(?:DISTINCT\s+)?(.*?)\s+FROM\s/is', $query->request, $match ) ? trim( $match[1] ) : '';
		if ( str_contains( strtoupper( $query->request ), ' RAND(' ) || ! in_array( $fields, array( "{$wpdb->posts}.*", "{$wpdb->posts}.ID, {$wpdb->posts}.post_parent", "{$wpdb->posts}.ID" ), true ) ) {
			return $posts;
		}

		$tail = strstr( $query->request, ' FROM ' );
		if ( false !== $tail ) {
			presence_measure_queries( $tail );
		}

		return $posts;
	},
	PHP_INT_MAX,
	2
);

add_filter(
	'query',
	static function ( $sql ) {
		presence_measure_queries( null, $sql );
		return $sql;
	}
);

add_action(
	'shutdown',
	static function () {
		presence_measure_queries();
	}
);

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * Prints each hour's counters, split by whether post locks were on.
	 *
	 * ## EXAMPLES
	 *
	 *     wp presence-measure post-query-cache
	 */
	WP_CLI::add_command(
		'presence-measure post-query-cache',
		static function () {
			if ( ! wp_using_ext_object_cache() ) {
				WP_CLI::error( 'This site has no persistent object cache, so there is nothing to measure.' );
			}

			$hours = wp_cache_get( 'hours', PRESENCE_MEASURE_GROUP );
			if ( ! is_array( $hours ) || ! $hours ) {
				WP_CLI::error( 'Nothing counted yet.' );
			}

			$rows = array();
			foreach ( $hours as $hour ) {
				list( $time, $locks ) = explode( '|', $hour );

				$get  = static fn( $metric ) => (int) wp_cache_get( "{$hour}|{$metric}", PRESENCE_MEASURE_GROUP );
				$hits = $get( 'queries_hit' );
				$miss = $get( 'queries_miss' );

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

			WP_CLI\Utils\format_items( 'table', $rows, array_keys( $rows[0] ) );
		}
	);
}
