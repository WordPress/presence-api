<?php
/**
 * Lists every call from a feature to an `@access private` function defined
 * outside it, and fails unless the list matches scripts/private-calls.txt
 * exactly. A new call fails until it is added there;
 * a removed call fails until its line is deleted, so the list only shrinks.
 *
 * Called from .github/workflows/phpcs.yml. Also runnable locally:
 *
 *   php scripts/check-private-calls.php           # compare
 *   php scripts/check-private-calls.php --update  # rewrite the list
 */

$root     = dirname( __DIR__ );
$baseline = __DIR__ . '/private-calls.txt';

$files = array();
foreach ( glob( $root . '/plugins/*', GLOB_ONLYDIR ) as $plugin ) {
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $plugin, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		$path = substr( $file->getPathname(), strlen( $root ) + 1 );
		if ( 'php' === $file->getExtension() && ! preg_match( '#/(tests|vendor|node_modules)/#', $path ) ) {
			$files[ $path ] = token_get_all( file_get_contents( $file->getPathname() ) );
		}
	}
}

// The Presence API itself; any other file is a feature, and a plugin directory is one feature.
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

$skip = array( T_WHITESPACE, T_COMMENT, T_ATTRIBUTE );

// Pass 1: top-level and namespaced functions whose docblock says @access private.
$private = array();
foreach ( $files as $path => $tokens ) {
	$doc   = '';
	$depth = 0;
	foreach ( $tokens as $i => $token ) {
		if ( '{' === $token || ( is_array( $token ) && in_array( $token[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
			++$depth;
		} elseif ( '}' === $token ) {
			--$depth;
		} elseif ( is_array( $token ) && T_DOC_COMMENT === $token[0] ) {
			$doc = $token[1];
		} elseif ( is_array( $token ) && T_FUNCTION === $token[0] && 0 === $depth ) {
			for ( $j = $i + 1; is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], $skip, true ); $j++ );
			if ( is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] && str_contains( $doc, '@access private' ) ) {
				$private[ strtolower( $tokens[ $j ][1] ) ] = $path;
			}
			$doc = '';
		} elseif ( ';' === $token ) {
			$doc = '';
		}
	}
}

// Pass 2: calls to those functions from a feature other than the one defining it.
$calls = array();
foreach ( $files as $path => $tokens ) {
	$part = $part_of( $path );
	if ( 'api' === $part ) {
		continue;
	}
	foreach ( $tokens as $i => $token ) {
		if ( ! is_array( $token ) || ! in_array( $token[0], array( T_STRING, T_NAME_FULLY_QUALIFIED ), true ) ) {
			continue;
		}
		$name = ltrim( $token[1], '\\' );
		if ( ! isset( $private[ strtolower( $name ) ] ) || $part_of( $private[ strtolower( $name ) ] ) === $part ) {
			continue;
		}
		for ( $j = $i + 1; isset( $tokens[ $j ] ) && is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], $skip, true ); $j++ );
		for ( $k = $i - 1; $k >= 0 && is_array( $tokens[ $k ] ) && in_array( $tokens[ $k ][0], $skip, true ); $k-- );
		$before = is_array( $tokens[ $k ] ) ? $tokens[ $k ][0] : $tokens[ $k ];
		if ( '(' === ( $tokens[ $j ] ?? null ) && ! in_array( $before, array( T_FUNCTION, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW ), true ) ) {
			$calls[ "$path $name()" ] = true;
		}
	}
}

$actual = array_keys( $calls );
sort( $actual );

if ( in_array( '--update', $argv, true ) ) {
	file_put_contents( $baseline, implode( "\n", $actual ) . "\n" );
	echo 'Wrote ' . count( $actual ) . " calls to scripts/private-calls.txt.\n";
	exit( 0 );
}

$expected = file( $baseline, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ?: array();
$added    = array_diff( $actual, $expected );
$removed  = array_diff( $expected, $actual );

if ( $added ) {
	fwrite( STDERR, "New calls to a private function outside the feature. Use a public function, or add the line to scripts/private-calls.txt:\n  " . implode( "\n  ", $added ) . "\n" );
}
if ( $removed ) {
	fwrite( STDERR, "No longer called. Delete these lines from scripts/private-calls.txt:\n  " . implode( "\n  ", $removed ) . "\n" );
}
if ( $added || $removed ) {
	exit( 1 );
}

echo count( $actual ) . " private calls from features, all listed in scripts/private-calls.txt.\n";
