'use strict';

const test   = require( 'node:test' );
const assert = require( 'node:assert' );

const {
	BEGIN,
	END,
	filterContributors,
	escapePhp,
	renderContributors,
	replaceBlock,
} = require( './update-demo-contributors.js' );

test( 'filterContributors keeps users', () => {
	const result = filterContributors( [
		{ login: 'josephfusco', id: 1, type: 'User' },
		{ login: 'i-am-chitti', id: 2, type: 'User' },
	] );

	assert.deepStrictEqual( result.map( ( c ) => c.login ), [ 'josephfusco', 'i-am-chitti' ] );
} );

test( 'filterContributors drops bots by type', () => {
	const result = filterContributors( [
		{ login: 'Copilot', id: 1, type: 'Bot' },
		{ login: 'real-person', id: 2, type: 'User' },
	] );

	assert.deepStrictEqual( result.map( ( c ) => c.login ), [ 'real-person' ] );
} );

test( 'filterContributors drops bots by login suffix', () => {
	// dependabot[bot] and github-actions[bot] are typed as Bot, but older
	// app identities are not, so the suffix is a second line of defence.
	const result = filterContributors( [
		{ login: 'dependabot[bot]', id: 1, type: 'User' },
		{ login: 'github-actions[bot]', id: 2, type: 'User' },
		{ login: 'real-person', id: 3, type: 'User' },
	] );

	assert.deepStrictEqual( result.map( ( c ) => c.login ), [ 'real-person' ] );
} );

test( 'filterContributors honours the opt-out list, case-insensitively', () => {
	const result = filterContributors(
		[
			{ login: 'Shy', id: 1, type: 'User' },
			{ login: 'Willing', id: 2, type: 'User' },
		],
		[ 'shy' ]
	);

	assert.deepStrictEqual( result.map( ( c ) => c.login ), [ 'Willing' ] );
} );

test( 'filterContributors drops entries missing a login or id', () => {
	const result = filterContributors( [
		{ login: '', id: 1, type: 'User' },
		{ login: 'nobody', type: 'User' },
		null,
		{ login: 'somebody', id: 4, type: 'User' },
	] );

	assert.deepStrictEqual( result.map( ( c ) => c.login ), [ 'somebody' ] );
} );

test( 'escapePhp escapes quotes and backslashes', () => {
	assert.strictEqual( escapePhp( "Shea O'Brien" ), "Shea O\\'Brien" );
	assert.strictEqual( escapePhp( 'back\\slash' ), 'back\\\\slash' );
} );

test( 'escapePhp leaves non-ASCII names alone', () => {
	assert.strictEqual( escapePhp( 'José Núñez' ), 'José Núñez' );
} );

test( 'renderContributors produces parseable PHP', () => {
	const block = renderContributors( [
		{ login: 'josephfusco', name: 'Joe Fusco', id: 6676674 },
	] );

	assert.ok( block.startsWith( BEGIN ) );
	assert.ok( block.endsWith( END ) );
	assert.match( block, /const WP_PRESENCE_DEMO_CONTRIBUTORS = array\(/ );
	assert.match( block, /'login' => 'josephfusco',/ );
	assert.match( block, /'name'  => 'Joe Fusco',/ );
	assert.match( block, /'id'    => 6676674,/ );
} );

test( 'renderContributors emits an empty name rather than null', () => {
	const block = renderContributors( [ { login: 'anon', name: null, id: 7 } ] );

	assert.match( block, /'name'  => '',/ );
} );

test( 'renderContributors coerces the id to an integer', () => {
	const block = renderContributors( [ { login: 'anon', name: '', id: '42' } ] );

	assert.match( block, /'id'    => 42,/ );
} );

test( 'renderContributors emits an empty opt-out list by default', () => {
	const block = renderContributors( [ { login: 'a', name: 'A', id: 1 } ] );

	assert.match( block, /const WP_PRESENCE_DEMO_OPTOUT = array\(\);/ );
} );

test( 'renderContributors bakes the opt-out list in lowercased', () => {
	// The runtime lookup asks GitHub directly, so the opt-out only keeps
	// working if it survives into the generated file.
	const block = renderContributors( [ { login: 'a', name: 'A', id: 1 } ], [ 'Shy', 'Quiet' ] );

	assert.match( block, /const WP_PRESENCE_DEMO_OPTOUT = array\( 'shy', 'quiet' \);/ );
} );

test( 'replaceBlock swaps the block and preserves the surrounding file', () => {
	const source = `<?php\nbefore\n${ BEGIN }\nold\n${ END }\nafter\n`;
	const result = replaceBlock( source, `${ BEGIN }\nnew\n${ END }` );

	assert.strictEqual( result, `<?php\nbefore\n${ BEGIN }\nnew\n${ END }\nafter\n` );
} );

test( 'replaceBlock throws when the markers are missing', () => {
	assert.throws( () => replaceBlock( '<?php nothing here', 'x' ), /markers/ );
} );

test( 'replaceBlock throws when the markers are reversed', () => {
	assert.throws( () => replaceBlock( `${ END }\n${ BEGIN }`, 'x' ), /markers/ );
} );

test( 'the committed seeder still carries the markers', () => {
	const fs     = require( 'fs' );
	const path   = require( 'path' );
	const seeder = fs.readFileSync(
		path.join( __dirname, '..', '..', 'tests', 'e2e', 'demo-seeder.php' ),
		'utf8'
	);

	assert.ok( seeder.includes( BEGIN ), 'demo-seeder.php is missing the begin marker' );
	assert.ok( seeder.includes( END ), 'demo-seeder.php is missing the end marker' );
} );
