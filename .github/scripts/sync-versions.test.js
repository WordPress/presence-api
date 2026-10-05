'use strict';

const { test } = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const { execFileSync } = require( 'node:child_process' );
const fs = require( 'node:fs' );
const os = require( 'node:os' );
const path = require( 'node:path' );

const SCRIPT = path.join(
	__dirname,
	'..',
	'..',
	'scripts',
	'sync-versions.sh'
);
const LINK =
	'([abc1234](https://github.com/WordPress/presence-api/commit/abc1234))';

/**
 * Runs the real script in a throwaway copy of the files it edits and returns readme.txt's changelog section.
 *
 * @param {string} changelog Contents of CHANGELOG.md.
 * @return {string} The rewritten changelog section.
 */
function changelogFor( changelog ) {
	const root = fs.mkdtempSync( path.join( os.tmpdir(), 'sync-versions-' ) );
	const plugin = path.join( root, 'plugins', 'presence-api' );
	const blueprints = path.join( plugin, '.wordpress-org', 'blueprints' );

	fs.mkdirSync( path.join( root, 'scripts' ), { recursive: true } );
	fs.mkdirSync( blueprints, { recursive: true } );
	fs.copyFileSync( SCRIPT, path.join( root, 'scripts', 'sync-versions.sh' ) );
	fs.writeFileSync(
		path.join( root, '.release-please-manifest.json' ),
		'{ ".": "9.9.9" }'
	);
	fs.writeFileSync( path.join( root, 'CHANGELOG.md' ), changelog );
	fs.writeFileSync(
		path.join( plugin, 'presence-api.php' ),
		"<?php\n/**\n * Plugin Name: Presence API\n * Version: 0.0.0\n */\ndefine( 'WP_PRESENCE_VERSION', '0.0.0' );\n"
	);
	fs.writeFileSync(
		path.join( plugin, 'readme.txt' ),
		'Stable tag: 0.0.0\n\n== Changelog ==\n\nold\n'
	);
	fs.writeFileSync(
		path.join( blueprints, 'blueprint.json' ),
		'{ "url": "https://raw.githubusercontent.com/WordPress/presence-api/v0.0.0/demo-seeder.php" }'
	);

	try {
		execFileSync(
			'bash',
			[ path.join( root, 'scripts', 'sync-versions.sh' ) ],
			{ stdio: 'pipe' }
		);

		return fs
			.readFileSync( path.join( plugin, 'readme.txt' ), 'utf8' )
			.split( '== Changelog ==' )[ 1 ];
	} finally {
		fs.rmSync( root, { recursive: true, force: true } );
	}
}

test( 'keeps the entries that reach a site and strips their commit links', () => {
	const section = changelogFor(
		`## [9.9.9]\n\n### Features\n\n* let a site switch off post locks ([#724](https://example.test/724)) ${ LINK }\n`
	);

	assert.match(
		section,
		/= 9\.9\.9 =\n\* Let a site switch off post locks \(\[#724\]\(https:\/\/example\.test\/724\)\)\.\n/
	);
	assert.doesNotMatch( section, /abc1234/ );
} );

test( 'strips the closes link release-please adds after the commit link', () => {
	const section = changelogFor(
		`## [9.9.9]\n\n### Bug Fixes\n\n* count people rather than rows ${ LINK }, closes [#134](https://example.test/134)\n`
	);

	assert.match( section, /\* Count people rather than rows\.\n/ );
} );

test( 'leaves out entries for the debug tools and for release, CI and test tooling', () => {
	const section = changelogFor(
		[
			'## [9.9.9]',
			'',
			'### Features',
			'',
			`* show client_id and expiry in the DB viewer ${ LINK }`,
			`* show each presence row's data in the debugger ${ LINK }`,
			`* check table availability in the CLI command and debug viewer ${ LINK }`,
			`* strip release-please's closes links from the readme changelog ${ LINK }`,
			`* run workflows on the release pull request's final commit ${ LINK }`,
			`* use correct REST route in PHPUnit tests ${ LINK }`,
			`* stop saving and serving presence colors ${ LINK }`,
			'',
		].join( '\n' )
	);

	assert.deepEqual( section.match( /^\* .*/gm ), [
		'* Stop saving and serving presence colors.',
	] );
} );

test( 'a release left with no entries still gets a line', () => {
	const section = changelogFor(
		`## [9.9.9]\n\n### Bug Fixes\n\n* translate Heartbeat debugger plurals and units ${ LINK }\n`
	);

	assert.match( section, /= 9\.9\.9 =\n\* Maintenance release\.\n/ );
} );
