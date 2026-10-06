<?php
/**
 * Fails when the calls from features to private functions outside them differ from scripts/private-calls.txt, so that list can only shrink.
 *
 *   php scripts/check-private-calls.php [--update]
 */

$root     = dirname( __DIR__ );
$baseline = __DIR__ . '/private-calls.txt';

$files = array();
foreach ( glob( $root . '/plugins/*', GLOB_ONLYDIR ) as $plugin ) {
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $plugin, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		$path = substr( $file->getPathname(), strlen( $root ) + 1 );
		if ( 'php' === $file->getExtension() && ! preg_match( '#/(tests|vendor|node_modules)/#', $path ) ) {
			$files[ $path ] = array();
			foreach ( token_get_all( file_get_contents( $file->getPathname() ) ) as $token ) {
				$token = (array) $token + array( 1 => $token );
				if ( ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT ), true ) ) {
					$files[ $path ][] = $token;
				}
			}
		}
	}
}

// The API itself; every other file is a feature, and each bundled plugin counts as one.
$api = array(
	'plugins/presence-api/presence-api.php',
	'plugins/presence-api/uninstall.php',
	'plugins/presence-api/includes/avatar-stack.php',
	'plugins/presence-api/includes/cron.php',
	'plugins/presence-api/includes/default-filters.php',
	'plugins/presence-api/includes/features.php',
	'plugins/presence-api/includes/heartbeat.php',
	'plugins/presence-api/includes/lifecycle.php',
	'plugins/presence-api/includes/ms-default-filters.php',
	'plugins/presence-api/includes/network-functions.php',
	'plugins/presence-api/includes/plugin.php',
	'plugins/presence-api/includes/presence.php',
	'plugins/presence-api/includes/privacy.php',
	'plugins/presence-api/includes/rest-api.php',
	'plugins/presence-api/includes/rest-api/',
	'plugins/presence-api/includes/schema.php',
	'plugins/presence-api/includes/settings.php',
	'plugins/presence-api/includes/site-health.php',
);

$part_of = static function ( $path ) use ( $api ) {
	foreach ( $api as $prefix ) {
		if ( str_starts_with( $path, $prefix ) ) {
			return 'api';
		}
	}
	return str_starts_with( $path, 'plugins/presence-api/' ) ? $path : dirname( $path );
};

// Top-level functions whose docblock says @access private.
$private = array();
foreach ( $files as $path => $tokens ) {
	$doc   = '';
	$depth = 0;
	foreach ( $tokens as $i => $token ) {
		if ( in_array( $token[0], array( '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) {
			++$depth;
		} elseif ( '}' === $token[0] ) {
			--$depth;
		} elseif ( T_DOC_COMMENT === $token[0] ) {
			$doc = $token[1];
		} elseif ( T_FUNCTION === $token[0] && 0 === $depth && T_STRING === $tokens[ $i + 1 ][0] && str_contains( $doc, '@access private' ) ) {
			$private[ strtolower( $tokens[ $i + 1 ][1] ) ] = $path;
		}
		if ( in_array( $token[0], array( T_FUNCTION, ';' ), true ) ) {
			$doc = '';
		}
	}
}

// Calls to them from a feature other than the one defining them.
$calls = array();
foreach ( $files as $path => $tokens ) {
	$part = $part_of( $path );
	if ( 'api' === $part ) {
		continue;
	}
	foreach ( $tokens as $i => $token ) {
		if ( ! in_array( $token[0], array( T_STRING, T_NAME_FULLY_QUALIFIED ), true ) || '(' !== ( $tokens[ $i + 1 ][0] ?? null ) ) {
			continue;
		}
		$name    = ltrim( $token[1], '\\' );
		$defined = $private[ strtolower( $name ) ] ?? null;
		$before  = $tokens[ $i - 1 ][0] ?? null;
		if ( $defined && $part_of( $defined ) !== $part && ! in_array( $before, array( T_FUNCTION, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW ), true ) ) {
			$calls[ "$path $name()" ] = true;
		}
	}
}

$actual = array_keys( $calls );
sort( $actual );

if ( in_array( '--update', $argv, true ) ) {
	file_put_contents( $baseline, implode( "\n", $actual ) . "\n" );
	exit( 0 );
}

$expected = file( $baseline, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
$added    = array_diff( $actual, $expected );
$removed  = array_diff( $expected, $actual );

if ( $added ) {
	fwrite( STDERR, "New private calls; use a public function or list them in scripts/private-calls.txt:\n  " . implode( "\n  ", $added ) . "\n" );
}
if ( $removed ) {
	fwrite( STDERR, "No longer called; delete from scripts/private-calls.txt:\n  " . implode( "\n  ", $removed ) . "\n" );
}
if ( $added || $removed ) {
	exit( 1 );
}

echo count( $actual ) . " private calls, all in scripts/private-calls.txt.\n";
