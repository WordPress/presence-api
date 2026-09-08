'use strict';

// A hook, route, constant, CLI command, or browser global is a promise:
// once a site depends on one, renaming it breaks that site. This flags the pull
// request that adds one and names the ones with nothing written above them.
//
// It compares the full set of surfaces at each end rather than diff lines, so
// moving, reindenting, or renaming the function around a hook reads as no change.

// The plugin ships what is in these paths. `tests/` calls `apply_filters` and
// `do_action` constantly without publishing anything, and built assets under
// `assets/js/build/` duplicate the surfaces already counted in `src/`.
const SCANNED = [
	/^includes\//,
	/^assets\/js\//,
	/^src\//,
	/^(presence-api|uninstall)\.php$/,
];
const IGNORED = [ /^assets\/js\/build\//, /\/test\//, /\.test\.js$/ ];

// WordPress's own, which a plugin reads without ever owning. `ABSPATH` alone
// opens every file in the tree, so without this the surfaces that are the
// plugin's drown in guards that promise a site nothing. This is core's
// documented `wp-config.php` set plus the flags it defines at run time; a name
// core adds later belongs here too.
const CORE_CONSTANTS = new Set( [
	// Bootstrap, paths, and URLs.
	'ABSPATH',
	'WPINC',
	'WP_LANG_DIR',
	'WP_CONTENT_DIR',
	'WP_CONTENT_URL',
	'WP_PLUGIN_DIR',
	'WP_PLUGIN_URL',
	'WPMU_PLUGIN_DIR',
	'WPMU_PLUGIN_URL',
	'WP_TEMP_DIR',
	'WP_HOME',
	'WP_SITEURL',
	'UPLOADS',
	'TEMPLATEPATH',
	'STYLESHEETPATH',
	'WP_DEFAULT_THEME',
	'WP_USE_THEMES',
	'SHORTINIT',
	// Debugging and asset loading.
	'WP_DEBUG',
	'WP_DEBUG_LOG',
	'WP_DEBUG_DISPLAY',
	'SCRIPT_DEBUG',
	'SAVEQUERIES',
	'WP_DISABLE_FATAL_ERROR_HANDLER',
	'WP_START_TIMESTAMP',
	'WP_MEMORY_LIMIT',
	'WP_MAX_MEMORY_LIMIT',
	'CONCATENATE_SCRIPTS',
	'COMPRESS_SCRIPTS',
	'COMPRESS_CSS',
	'ENFORCE_GZIP',
	// Which kind of request this is.
	'DOING_AJAX',
	'DOING_CRON',
	'DOING_AUTOSAVE',
	'REST_REQUEST',
	'XMLRPC_REQUEST',
	'WP_ADMIN',
	'WP_BLOG_ADMIN',
	'WP_NETWORK_ADMIN',
	'WP_USER_ADMIN',
	'WP_CLI',
	'WP_INSTALLING',
	'WP_INSTALLING_NETWORK',
	'WP_SETUP_CONFIG',
	'WP_REPAIRING',
	'WP_ALLOW_REPAIR',
	'WP_SANDBOX_SCRAPING',
	'WP_UNINSTALL_PLUGIN',
	'WP_LOAD_IMPORTERS',
	'WP_IMPORTING',
	'IS_PROFILE_PAGE',
	'WP_RUN_CORE_TESTS',
	// Multisite.
	'MULTISITE',
	'WP_ALLOW_MULTISITE',
	'SUBDOMAIN_INSTALL',
	'VHOST',
	'SUNRISE',
	'DOMAIN_CURRENT_SITE',
	'PATH_CURRENT_SITE',
	'SITE_ID_CURRENT_SITE',
	'BLOG_ID_CURRENT_SITE',
	'NOBLOGREDIRECT',
	'UPLOADBLOGSDIR',
	'BLOGUPLOADDIR',
	'WPMU_ACCEL_REDIRECT',
	'WPMU_SENDFILE',
	// Cron.
	'DISABLE_WP_CRON',
	'ALTERNATE_WP_CRON',
	'WP_CRON_LOCK_TIMEOUT',
	// Updates and the filesystem API.
	'WP_AUTO_UPDATE_CORE',
	'AUTOMATIC_UPDATER_DISABLED',
	'CORE_UPGRADE_SKIP_NEW_BUNDLED',
	'DISALLOW_FILE_EDIT',
	'DISALLOW_FILE_MODS',
	'DISALLOW_UNFILTERED_HTML',
	'ALLOW_UNFILTERED_UPLOADS',
	'FS_METHOD',
	'FS_CHMOD_DIR',
	'FS_CHMOD_FILE',
	'FTP_BASE',
	'FTP_CONTENT_DIR',
	'FTP_PLUGIN_DIR',
	'FTP_HOST',
	'FTP_USER',
	'FTP_PASS',
	'FTP_SSL',
	'FTP_PUBKEY',
	'FTP_PRIKEY',
	// Content and editing.
	'EMPTY_TRASH_DAYS',
	'AUTOSAVE_INTERVAL',
	'WP_POST_REVISIONS',
	'MEDIA_TRASH',
	'IMAGE_EDIT_OVERWRITE',
	// Database.
	'DB_NAME',
	'DB_USER',
	'DB_PASSWORD',
	'DB_HOST',
	'DB_CHARSET',
	'DB_COLLATE',
	'CUSTOM_USER_TABLE',
	'CUSTOM_USER_META_TABLE',
	// Cookies, keys, and salts.
	'FORCE_SSL_ADMIN',
	'FORCE_SSL_LOGIN',
	'COOKIE_DOMAIN',
	'COOKIEPATH',
	'SITECOOKIEPATH',
	'ADMIN_COOKIE_PATH',
	'PLUGINS_COOKIE_PATH',
	'COOKIEHASH',
	'AUTH_COOKIE',
	'SECURE_AUTH_COOKIE',
	'LOGGED_IN_COOKIE',
	'TEST_COOKIE',
	'USER_COOKIE',
	'PASS_COOKIE',
	'RECOVERY_MODE_COOKIE',
	'RECOVERY_MODE_EMAIL',
	'AUTH_KEY',
	'SECURE_AUTH_KEY',
	'LOGGED_IN_KEY',
	'NONCE_KEY',
	'AUTH_SALT',
	'SECURE_AUTH_SALT',
	'LOGGED_IN_SALT',
	'NONCE_SALT',
	// Caching and environment.
	'WP_CACHE',
	'WP_CACHE_KEY_SALT',
	'WP_ENVIRONMENT_TYPE',
	'WP_DEVELOPMENT_MODE',
	'WP_LOCAL_DEV',
	// Localization.
	'WPLANG',
	'WP_LANG',
	// The time and byte spans core defines for everyone.
	'MINUTE_IN_SECONDS',
	'HOUR_IN_SECONDS',
	'DAY_IN_SECONDS',
	'WEEK_IN_SECONDS',
	'MONTH_IN_SECONDS',
	'YEAR_IN_SECONDS',
	'KB_IN_BYTES',
	'MB_IN_BYTES',
	'GB_IN_BYTES',
	'TB_IN_BYTES',
	'PB_IN_BYTES',
	'EB_IN_BYTES',
	'ZB_IN_BYTES',
	'YB_IN_BYTES',
] );

const PHP_RULES = [
	[
		/\b(apply_filters|do_action)(?:_ref_array|_deprecated)?\s*\(\s*(['"])(.*?)\2/g,
		( m ) =>
			`${ 'apply_filters' === m[ 1 ] ? 'filter' : 'action' }:${ m[ 3 ] }`,
	],
	// Every constant the plugin asks after, in either form a site can reach it:
	// the guarded define, where a value already in `wp-config.php` wins, and the
	// bare read of one only a site ever sets. Renaming either breaks that site.
	[
		/\bdefined\s*\(\s*(['"])([A-Z][A-Z0-9_]*)\1\s*\)/g,
		( m ) => `constant:${ m[ 2 ] }`,
	],
	// Routes are usually concatenated (`'/' . $this->rest_base . '/rooms'`), so
	// take the whole second argument rather than the first literal inside it.
	[
		/register_rest_route\s*\(\s*[^,]+,\s*([^,]+?)\s*,/g,
		( m ) => `rest:${ route( m[ 1 ] ) }`,
	],
	[ /WP_CLI::add_command\s*\(\s*(['"])(.*?)\1/g, ( m ) => `cli:${ m[ 2 ] }` ],
];

const JS_RULES = [
	[
		/\bwindow\.(wpPresence[\w$]*)\s*=(?!=)/g,
		( m ) => `js-global:window.${ m[ 1 ] }`,
	],
	[
		/\bwindow\.wp\.presence\.([\w$]+)\s*=(?!=)/g,
		( m ) => `js-global:wp.presence.${ m[ 1 ] }`,
	],
	[
		/\b(?:wp\.hooks\.)?(applyFilters|doAction)\s*\(\s*(['"`])(.*?)\2/g,
		( m ) =>
			`${ 'applyFilters' === m[ 1 ] ? 'js-filter' : 'js-action' }:${
				m[ 3 ]
			}`,
	],
];

// `@access private` in PHP per core's standards, `@private` in JS.
const PRIVATE = /@(?:private\b|access\s+private\b)/;

// The workflow greps for this literal to edit its own comment in place.
const MARKER = '<!-- presence-api:public-surface -->';

function isScanned( path ) {
	return (
		! IGNORED.some( ( re ) => re.test( path ) ) &&
		SCANNED.some( ( re ) => re.test( path ) )
	);
}

// `'/' . $this->rest_base . '/rooms'` reads back as `/$this->rest_base/rooms`.
function route( raw ) {
	return raw
		.replace( /['"]/g, '' )
		.replace( /\s*\.\s*/g, '' )
		.replace( /\s+/g, ' ' )
		.trim();
}

// The docblock directly above `index`, or an empty string when the nearest one
// belongs to something else. Only the opening of the statement may sit between
// them, which covers `$ttl = apply_filters( ... )` and `window.x = function () {}`
// while stopping a docblock from leaking onto whatever statement follows the one
// it describes.
function docblockAbove( source, index ) {
	const before = source.slice( 0, index );
	const close = before.lastIndexOf( '*/' );

	// A statement terminator or a brace between the two means the docblock
	// belongs to something else.
	if ( -1 === close || /[;{}]/.test( before.slice( close + 2 ) ) ) {
		return '';
	}

	const open = before.lastIndexOf( '/**', close );

	return -1 === open ? '' : before.slice( open, close );
}

// A docblock of nothing but tags is a signature, not a write-up: the bar is a
// sentence saying what the surface is for, which is what core's code reference
// renders. Documenting the hook where it fires beats a hand-kept list, which
// drifts the first time nobody updates it.
function describes( block ) {
	return block
		.replace( /^\/\*\*/, '' )
		.split( '\n' )
		.map( ( line ) => line.replace( /^\s*\*+\s*/, '' ).trim() )
		.some( ( line ) => '' !== line && ! line.startsWith( '@' ) );
}

// The constants a body of source defines outright. A `define()` with no
// `! defined()` guard anywhere leaves a site nothing to set, so the name is the
// plugin's own rather than a promise, however often the code reads it back.
// Sources are weighed together rather than one at a time: the file that defines
// a constant is rarely the file that reads it, and either half alone misreads
// the other. The CLI passes the whole ref.
function ownConstants( sources ) {
	const named = ( regex ) =>
		sources.flatMap( ( source ) =>
			[ ...source.matchAll( regex ) ].map( ( m ) => m[ 2 ] )
		);
	const own = new Set(
		named( /\bdefine\s*\(\s*(['"])([A-Z][A-Z0-9_]*)\1/g )
	);

	for ( const name of named(
		/!\s*defined\s*\(\s*(['"])([A-Z][A-Z0-9_]*)\1/g
	) ) {
		own.delete( name );
	}

	return own;
}

// Identifiers are `kind:name` and carry no path, so the same hook found at a new
// location is the same surface. Each one also reports whether the docblock above
// it describes it; a private marker in that docblock withdraws it entirely.
// `own` names the constants something else already defines outright, which only
// a caller holding the whole ref can know; alone, a file speaks for itself.
function findSurfaces( source, path, own = ownConstants( [ source ] ) ) {
	let rules = [];
	if ( path.endsWith( '.php' ) ) {
		rules = PHP_RULES;
	} else if ( path.endsWith( '.js' ) ) {
		rules = JS_RULES;
	}

	// A constant is only a promise if a site can set it.
	const reachable = ( id ) => {
		if ( ! id.startsWith( 'constant:' ) ) {
			return true;
		}

		const name = id.slice( 'constant:'.length );

		return ! CORE_CONSTANTS.has( name ) && ! own.has( name );
	};

	const found = rules.flatMap( ( [ regex, build ] ) =>
		[ ...source.matchAll( regex ) ]
			.map( ( m ) => ( {
				id: build( m ),
				block: docblockAbove( source, m.index ),
			} ) )
			.filter( ( { id } ) => ! id.endsWith( ':' ) && reachable( id ) )
	);

	// The marker withdraws the name, not the one occurrence carrying it: a
	// constant declared private and consulted again later is private at the read
	// too, and nothing is gained by making someone repeat the docblock.
	const withdrawn = new Set(
		found
			.filter( ( { block } ) => PRIVATE.test( block ) )
			.map( ( { id } ) => id )
	);

	return found
		.filter( ( { id } ) => ! withdrawn.has( id ) )
		.map( ( { id, block } ) => ( { id, documented: describes( block ) } ) );
}

// Additions only. A hook fired from two places is documented once and
// cross-referenced from the other, the way core does it, so one write-up
// anywhere at the head covers the surface.
function newSurfaces( base, head ) {
	const before = new Set( base.map( ( s ) => s.id ) );
	const fresh = new Map();

	for ( const { id, documented } of head ) {
		if ( ! before.has( id ) ) {
			fresh.set( id, documented || Boolean( fresh.get( id ) ) );
		}
	}

	return [ ...fresh ]
		.map( ( [ id, documented ] ) => ( { id, documented } ) )
		.sort( ( a, b ) => a.id.localeCompare( b.id ) );
}

// Kind first, so every line opens on the same kind of token instead of a name
// of arbitrary length, and what is left to do trails the name in italics so it
// reads as an aside rather than part of the surface.
function formatAudit( surfaces ) {
	return surfaces
		.map( ( { id, documented } ) => {
			const at = id.indexOf( ':' );

			return `- (${ id.slice( 0, at ) }) \`${ id.slice( at + 1 ) }\`${
				documented ? '' : ' - *requires documentation*'
			}`;
		} )
		.join( '\n' );
}

// An h3 heading, matching the Playground preview comment, so the two bots read
// the same way down a thread.
function formatComment( surfaces ) {
	return [
		MARKER,
		`### 🚩 New public surface${ 1 === surfaces.length ? '' : 's' }`,
		'',
		formatAudit( surfaces ),
		'',
	].join( '\n' );
}

module.exports = findSurfaces;
module.exports.findSurfaces = findSurfaces;
module.exports.newSurfaces = newSurfaces;
module.exports.formatAudit = formatAudit;
module.exports.formatComment = formatComment;
module.exports.isScanned = isScanned;
module.exports.ownConstants = ownConstants;
module.exports.MARKER = MARKER;

// CLI: `node detect-public-surface.js <base-ref> <head-ref>`. Reads both trees
// through git, never the working directory, so the head is never checked out.
// Writes `count` to $GITHUB_OUTPUT and the comment body to comment.md.
if ( require.main === module ) {
	const { execFileSync } = require( 'node:child_process' );
	const fs = require( 'node:fs' );

	const [ baseRef, headRef ] = process.argv.slice( 2 );

	if ( ! baseRef || ! headRef ) {
		console.error(
			'Usage: node detect-public-surface.js <base-ref> <head-ref>'
		);
		process.exit( 1 );
	}

	const git = ( args ) =>
		execFileSync( 'git', args, {
			encoding: 'utf8',
			maxBuffer: 64 * 1024 * 1024,
		} );

	const read = ( ref, path ) => {
		try {
			return git( [ 'show', `${ ref }:${ path }` ] );
		} catch {
			return '';
		}
	};

	const scan = ( ref ) => {
		const files = git( [ 'ls-tree', '-r', '--name-only', ref ] )
			.split( '\n' )
			.filter( isScanned )
			.map( ( path ) => ( { path, source: read( ref, path ) } ) );

		// One owned set for the whole ref. A constant defined outright in
		// `presence-api.php` is the plugin's own in every file that reads it, which
		// no file can tell on its own.
		const own = ownConstants( files.map( ( { source } ) => source ) );

		return files.flatMap( ( { path, source } ) =>
			findSurfaces( source, path, own )
		);
	};

	const surfaces = newSurfaces( scan( baseRef ), scan( headRef ) );

	if ( surfaces.length ) {
		console.log(
			`Found ${ surfaces.length } new public ${
				1 === surfaces.length ? 'surface' : 'surfaces'
			}:`
		);
		console.log( formatAudit( surfaces ) );
		fs.writeFileSync( 'comment.md', formatComment( surfaces ) );
	} else {
		console.log( 'No new public surfaces.' );
	}

	if ( process.env.GITHUB_OUTPUT ) {
		fs.appendFileSync(
			process.env.GITHUB_OUTPUT,
			`count=${ surfaces.length }\n`
		);
	}
}
