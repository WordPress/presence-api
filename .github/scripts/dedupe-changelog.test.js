'use strict';

const { test } = require( 'node:test' );
const assert = require( 'node:assert/strict' );

const dedupeChangelog = require( './dedupe-changelog.js' );
const { entryKey } = dedupeChangelog;

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

const COMMIT = 'https://github.com/WordPress/presence-api/commit';
const ISSUE = 'https://github.com/WordPress/presence-api/issues';

function entry( subject, sha, closes ) {
	const link = `([${ sha }](${ COMMIT }/${ sha }))`;
	return closes
		? `* ${ subject } ${ link }, closes [#${ closes }](${ ISSUE }/${ closes })`
		: `* ${ subject } ${ link }`;
}

// ---------------------------------------------------------------------------
// entryKey
// ---------------------------------------------------------------------------

test( 'entryKey: strips the trailing commit link', () => {
	assert.equal(
		entryKey( entry( 'fix the thing', 'abc1234' ) ),
		'* fix the thing'
	);
} );

test( 'entryKey: strips the closes clause', () => {
	assert.equal(
		entryKey( entry( 'fix the thing', 'abc1234', 141 ) ),
		'* fix the thing'
	);
} );

test( 'entryKey: matches the same subject across different shas', () => {
	assert.equal(
		entryKey( entry( 'fix the thing', 'abc1234' ) ),
		entryKey( entry( 'fix the thing', 'def5678' ) )
	);
} );

test( 'entryKey: keeps different subjects distinct', () => {
	assert.notEqual(
		entryKey( entry( 'fix the thing', 'abc1234' ) ),
		entryKey( entry( 'fix the other thing', 'abc1234' ) )
	);
} );

// ---------------------------------------------------------------------------
// dedupeChangelog
// ---------------------------------------------------------------------------

test( 'dedupeChangelog: removes an entry duplicated by the merge commit', () => {
	const input = [
		'### Bug Fixes',
		'',
		entry( 'fix the thing', 'aaaaaaa' ),
		entry( 'fix the thing', 'bbbbbbb' ),
	].join( '\n' );

	assert.equal(
		dedupeChangelog( input ),
		[ '### Bug Fixes', '', entry( 'fix the thing', 'aaaaaaa' ) ].join(
			'\n'
		)
	);
} );

test( 'dedupeChangelog: keeps entries with different subjects', () => {
	const input = [
		'### Bug Fixes',
		'',
		entry( 'fix the thing', 'aaaaaaa' ),
		entry( 'fix the other thing', 'bbbbbbb' ),
	].join( '\n' );

	assert.equal( dedupeChangelog( input ), input );
} );

test( 'dedupeChangelog: keeps the copy carrying the closes link', () => {
	const input = [
		'### Bug Fixes',
		'',
		entry( 'fix the thing', 'aaaaaaa' ),
		entry( 'fix the thing', 'bbbbbbb', 141 ),
	].join( '\n' );

	assert.equal(
		dedupeChangelog( input ),
		[ '### Bug Fixes', '', entry( 'fix the thing', 'bbbbbbb', 141 ) ].join(
			'\n'
		)
	);
} );

test( 'dedupeChangelog: keeps the surviving entry in the original position', () => {
	const input = [
		'### Bug Fixes',
		'',
		entry( 'alpha', 'aaaaaaa' ),
		entry( 'alpha', 'bbbbbbb', 141 ),
		entry( 'beta', 'ccccccc' ),
	].join( '\n' );

	assert.equal(
		dedupeChangelog( input ),
		[
			'### Bug Fixes',
			'',
			entry( 'alpha', 'bbbbbbb', 141 ),
			entry( 'beta', 'ccccccc' ),
		].join( '\n' )
	);
} );

test( 'dedupeChangelog: scopes deduplication to a section', () => {
	const input = [
		'### Features',
		'',
		entry( 'support screens', 'aaaaaaa' ),
		'',
		'### Bug Fixes',
		'',
		entry( 'support screens', 'bbbbbbb' ),
	].join( '\n' );

	assert.equal( dedupeChangelog( input ), input );
} );

test( 'dedupeChangelog: scopes deduplication to a release', () => {
	const input = [
		'## [0.1.10](compare) (2026-07-27)',
		'',
		'### Bug Fixes',
		'',
		entry( 'fix the thing', 'aaaaaaa' ),
		'',
		'## [0.1.9](compare) (2026-07-26)',
		'',
		'### Bug Fixes',
		'',
		entry( 'fix the thing', 'bbbbbbb' ),
	].join( '\n' );

	assert.equal( dedupeChangelog( input ), input );
} );

test( 'dedupeChangelog: leaves already-clean text untouched', () => {
	const input = [
		'# Changelog',
		'',
		'### Bug Fixes',
		'',
		entry( 'fix the thing', 'aaaaaaa' ),
	].join( '\n' );
	assert.equal( dedupeChangelog( input ), input );
} );

test( 'dedupeChangelog: preserves blank lines and headings', () => {
	const input = [
		'# Changelog',
		'',
		'',
		'## [0.1.10](compare) (2026-07-27)',
		'',
		'',
	].join( '\n' );
	assert.equal( dedupeChangelog( input ), input );
} );

test( 'dedupeChangelog: handles empty input', () => {
	assert.equal( dedupeChangelog( '' ), '' );
} );

test( 'dedupeChangelog: ignores prose that is not a list entry', () => {
	const input = [
		'Some note about the thing.',
		'Some note about the thing.',
	].join( '\n' );
	assert.equal( dedupeChangelog( input ), input );
} );

test( 'dedupeChangelog: reproduces the manual cleanup in 921d838', () => {
	const input = [
		'## [0.1.10](https://github.com/WordPress/presence-api/compare/v0.1.9...v0.1.10) (2026-07-27)',
		'',
		'',
		'### Bug Fixes',
		'',
		entry(
			'credit every contributor in the release props comment',
			'd069193'
		),
		entry(
			'credit every contributor in the release props comment',
			'dd0f7b2'
		),
		entry(
			"move the admin/online write out of the Who's Online widget",
			'b7b500b',
			141
		),
		entry(
			'render release props in a code block like props-bot',
			'3a2ae99'
		),
		entry(
			'render release props in a code block like props-bot',
			'ac720d6'
		),
	].join( '\n' );

	assert.equal(
		dedupeChangelog( input ),
		[
			'## [0.1.10](https://github.com/WordPress/presence-api/compare/v0.1.9...v0.1.10) (2026-07-27)',
			'',
			'',
			'### Bug Fixes',
			'',
			entry(
				'credit every contributor in the release props comment',
				'd069193'
			),
			entry(
				"move the admin/online write out of the Who's Online widget",
				'b7b500b',
				141
			),
			entry(
				'render release props in a code block like props-bot',
				'3a2ae99'
			),
		].join( '\n' )
	);
} );

test( 'dedupeChangelog: leaves near-duplicate wording alone', () => {
	const input = [
		'### Bug Fixes',
		'',
		entry(
			'use 10up action ASSETS_DIR instead of separate assets workflow',
			'aaaaaaa'
		),
		entry(
			'use 10up action ASSETS_DIR, remove separate assets workflow',
			'bbbbbbb'
		),
	].join( '\n' );

	assert.equal( dedupeChangelog( input ), input );
} );
