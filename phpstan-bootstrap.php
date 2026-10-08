<?php
/**
 * Constants and functions PHPStan can't resolve by parsing presence-api.php alone.
 *
 * @package Presence_API
 */

define( 'WP_PRESENCE_PLUGIN_DIR', __DIR__ . '/plugins/presence-api/' );
define( 'WP_PRESENCE_PLUGIN_URL', 'https://example.com/wp-content/plugins/presence-api/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

// wordpress-stubs skips cache-compat.php, the only place core defines these.
function wp_cache_get_salted( $cache_key, $group, $salt ) {}
function wp_cache_set_salted( $cache_key, $data, $group, $salt, $expire = 0 ) {}
