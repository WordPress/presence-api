'use strict';

const { test } = require( 'node:test' );
const assert = require( 'node:assert/strict' );

const {
	parseCodeowners,
	latestActivity,
	decide,
} = require( './needs-reply.js' );

const owners = parseCodeowners( '# Owners\n* @josephfusco @i-am-chitti\n' );
const user = ( login, type = 'User' ) => ( { login, type } );
const HOUR = 60 * 60 * 1000;
const now = Date.parse( '2026-10-06T12:00:00Z' );
const ago = ( hours ) => new Date( now - hours * HOUR ).toISOString();
const item = { user: user( 'contributor' ), created_at: ago( 100 ) };

test( 'reads every handle in CODEOWNERS', () => {
	assert.deepEqual( [ ...owners ], [ 'josephfusco', 'i-am-chitti' ] );
} );

test( 'takes the newest human event, counting the opening', () => {
	assert.equal( latestActivity( item, [] ).user.login, 'contributor' );

	const latest = latestActivity( item, [
		{
			event: 'reviewed',
			user: user( 'josephfusco' ),
			submitted_at: ago( 50 ),
		},
		{
			event: 'line-commented',
			comments: [
				{ user: user( 'contributor' ), created_at: ago( 49 ) },
			],
		},
		{
			event: 'labeled',
			actor: user( 'josephfusco' ),
			created_at: ago( 1 ),
		},
		{
			event: 'commented',
			actor: user( 'github-actions[bot]', 'Bot' ),
			created_at: ago( 1 ),
		},
	] );

	assert.deepEqual( latest, { user: user( 'contributor' ), at: ago( 49 ) } );
} );

test( 'adds after 48 hours of an outside contributor waiting', () => {
	const latest = ( hours ) => ( {
		user: user( 'contributor' ),
		at: ago( hours ),
	} );

	assert.equal(
		decide( { latest: latest( 49 ), owners, labeled: false, now } ),
		'add'
	);
	assert.equal(
		decide( { latest: latest( 47 ), owners, labeled: false, now } ),
		null
	);
} );

test( 'removes once a code owner answers, whatever the case of the login', () => {
	const latest = { user: user( 'JosephFusco' ), at: ago( 1 ) };

	assert.equal( decide( { latest, owners, labeled: true, now } ), 'remove' );
	assert.equal( decide( { latest, owners, labeled: false, now } ), null );
} );
