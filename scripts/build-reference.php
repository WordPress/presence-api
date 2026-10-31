<?php
/**
 * Writes docs/reference.md from the docblocks of Presence API's public functions and documented hooks, or with --check fails when it is out of date.
 *
 *   php scripts/build-reference.php [--check]
 */

$root   = dirname( __DIR__ );
$plugin = 'plugins/presence-api';
$target = $root . '/docs/reference.md';
$blob   = 'https://github.com/WordPress/presence-api/blob/main/';

// The API itself, as scripts/check-private-calls.php lists it; every other file is a feature, whose functions are internal.
$api = array( 'presence-api.php', 'includes/avatar-stack.php', 'includes/cron.php', 'includes/features.php', 'includes/heartbeat.php', 'includes/lifecycle.php', 'includes/network-functions.php', 'includes/plugin.php', 'includes/presence.php', 'includes/privacy.php', 'includes/rest-api.php', 'includes/schema.php', 'includes/settings.php', 'includes/site-health.php' );

$php = array();
$js  = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( "$root/$plugin", FilesystemIterator::SKIP_DOTS ) ) as $file ) {
	$path = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
	if ( preg_match( '#/(tests?|vendor|node_modules|build)/#', $path ) ) {
		continue;
	}
	if ( 'php' === $file->getExtension() ) {
		$php[ $path ] = file_get_contents( $file->getPathname() );
	} elseif ( 'js' === $file->getExtension() ) {
		$js[ $path ] = file_get_contents( $file->getPathname() );
	}
}
ksort( $php );
ksort( $js );

// The summary, the first @since, and each @param, with an $args array's @type keys nested under it.
$parse = static function ( $doc ) {
	$lines   = array_map( static fn( $line ) => preg_replace( '#^\s*/?\*+/?\s?#', '', $line ), explode( "\n", $doc ) );
	$text    = trim( implode( "\n", $lines ) );
	$summary = preg_match( '/^(.*?)(?:\n\s*\n|\n@|$)/s', $text, $m ) ? preg_replace( '/\s+/', ' ', trim( $m[1] ) ) : '';
	$since   = preg_match( '/@since\s+(\S+)/', $text, $m ) ? $m[1] : '';
	$params  = array();
	foreach ( $lines as $line ) {
		if ( preg_match( '/^@param\s+(\S+)\s+(\$\S+)\s*(.*)$/', trim( $line ), $m ) ) {
			$params[] = array( $m[2], $m[1], '{' === trim( $m[3] ) ? '' : trim( $m[3] ), array() );
		} elseif ( $params && preg_match( '/^\s*@type\s+(\S+)\s+(\$\S+)\s*(.*)$/', $line, $m ) ) {
			$params[ count( $params ) - 1 ][3][] = array( $m[2], $m[1], trim( $m[3] ) );
		} elseif ( $params && preg_match( '/^\s+Optional\.\s/', $line ) && '' === $params[ count( $params ) - 1 ][2] ) {
			$params[ count( $params ) - 1 ][2] = trim( $line );
		}
	}
	return array(
		'summary' => $summary,
		'since'   => $since,
		'params'  => $params,
	);
};

$functions = array();
$hooks     = array();
foreach ( $php as $path => $source ) {
	$tokens = array_values( array_filter( PhpToken::tokenize( $source ), static fn( $t ) => ! $t->is( array( T_WHITESPACE, T_COMMENT ) ) ) );
	$doc    = '';
	$depth  = 0;
	$guard  = array();
	foreach ( $tokens as $i => $token ) {
		if ( $token->is( array( '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ) ) ) {
			++$depth;
		} elseif ( $token->is( '}' ) ) {
			--$depth;
			unset( $guard[ $depth ] );
		} elseif ( $token->is( T_DOC_COMMENT ) ) {
			$doc = $token->text;
		} elseif ( $token->is( T_STRING ) && 'function_exists' === $token->text ) {
			$guard[ $depth ] = true;
		} elseif ( $token->is( T_FUNCTION ) && $tokens[ $i + 1 ]->is( T_STRING ) && 1 === $depth && isset( $guard[0] ) && in_array( substr( $path, strlen( $plugin ) + 1 ), $api, true ) ) {
			$name = $tokens[ $i + 1 ]->text;
			if ( $doc && ! str_contains( $doc, '@access private' ) && str_starts_with( $name, 'wp_' ) ) {
				for ( $j = $i + 2, $open = 0; $j < count( $tokens ); $j++ ) {
					$open += $tokens[ $j ]->is( '(' ) ? 1 : ( $tokens[ $j ]->is( ')' ) ? -1 : 0 );
					if ( 0 === $open ) {
						break;
					}
				}
				$signature = preg_replace( '/\s+/', ' ', substr( $source, $tokens[ $i + 1 ]->pos, $tokens[ $j ]->pos + 1 - $tokens[ $i + 1 ]->pos ) );
				$functions[ $name ] = array( $path, $token->line, $signature ) + $parse( $doc );
			}
		} elseif ( $token->is( T_STRING ) && in_array( $token->text, array( 'do_action', 'apply_filters', 'do_action_ref_array' ), true ) && $tokens[ $i + 1 ]->is( '(' ) && $tokens[ $i + 2 ]->is( T_CONSTANT_ENCAPSED_STRING ) && $doc ) {
			$name = trim( $tokens[ $i + 2 ]->text, '\'"' );
			if ( ! isset( $hooks[ $name ] ) && ! str_contains( $doc, '@access private' ) ) {
				$hooks[ $name ] = array( $path, $token->line, 'apply_filters' === $token->text ? 'Filter' : 'Action' ) + $parse( $doc );
			}
		}
		if ( $token->is( array( T_FUNCTION, ';' ) ) ) {
			$doc = '';
		}
	}
}

// Documented wp.hooks calls, with any line comments between the docblock and the call.
$js_hooks = array();
foreach ( $js as $path => $source ) {
	preg_match_all( '#/\*\*((?:(?!\*/).)*)\*/\s*(?://[^\n]*\n\s*)*(?:return\s+)?wp\.hooks\.(doAction|applyFilters|addAction)\(\s*\'(presence-api\.[^\']+)\'#s', $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
	foreach ( $matches as $match ) {
		$name = $match[3][0];
		$type = array(
			'doAction'     => 'Action',
			'applyFilters' => 'Filter',
			'addAction'    => 'Action to fire',
		)[ $match[2][0] ];
		if ( ! isset( $js_hooks[ $name ] ) ) {
			$js_hooks[ $name ] = array( $path, substr_count( substr( $source, 0, $match[0][1] ), "\n" ) + 1, $type ) + $parse( $match[1][0] );
		}
	}
}

// A function the plugin hooks somewhere is a callback, not something to call.
$callbacks = array();
foreach ( $php as $source ) {
	preg_match_all( "/add_(?:action|filter)\\(\\s*'[^']+',\\s*'(wp_[a-z0-9_]+)'/", $source, $matches );
	$callbacks += array_flip( $matches[1] );
}
$functions = array_diff_key( $functions, $callbacks );

ksort( $functions );
ksort( $hooks );
ksort( $js_hooks );

$entry = static function ( $heading, $item, $lead ) use ( $blob ) {
	$out = array( "### `$heading`", '', $lead, '', $item['summary'] );
	if ( $item['params'] ) {
		$out[] = '';
		foreach ( $item['params'] as $param ) {
			$out[] = rtrim( "- `{$param[0]}` (`{$param[1]}`): {$param[2]}" );
			foreach ( $param[3] as $key ) {
				$out[] = "  - `'" . ltrim( $key[0], '$' ) . "'` (`{$key[1]}`): {$key[2]}";
			}
		}
	}
	$out[] = '';
	$out[] = trim( ( $item['since'] ? "Since {$item['since']}. " : '' ) . "Source: [`{$item[0]}`]($blob{$item[0]})" );
	$out[] = '';
	return implode( "\n", $out );
};

$doc   = array( '# Reference', '', 'Every public function and documented hook in Presence API, generated from their docblocks by `scripts/build-reference.php`.', '' );
$doc[] = '## PHP functions';
$doc[] = '';
foreach ( $functions as $name => $item ) {
	$doc[] = $entry( "$name()", $item, "`{$item[2]}`" );
}
$doc[] = '## PHP hooks';
$doc[] = '';
foreach ( $hooks as $name => $item ) {
	$doc[] = $entry( $name, $item, "{$item[2]}." );
}
$doc[] = '## JavaScript hooks';
$doc[] = '';
foreach ( $js_hooks as $name => $item ) {
	$doc[] = $entry( $name, $item, "{$item[2]}." );
}
$doc[] = 'REST routes and WP-CLI commands are listed in the [README](https://github.com/WordPress/presence-api#rest-api).';
$markdown = implode( "\n", $doc ) . "\n";

if ( in_array( '--check', $argv, true ) ) {
	if ( ! file_exists( $target ) || file_get_contents( $target ) !== $markdown ) {
		fwrite( STDERR, "docs/reference.md is out of date; regenerate it with php scripts/build-reference.php.\n" );
		exit( 1 );
	}
	echo count( $functions ) . ' functions and ' . ( count( $hooks ) + count( $js_hooks ) ) . " hooks, all in docs/reference.md.\n";
	exit( 0 );
}

file_put_contents( $target, $markdown );
