'use strict';

const { test } = require( 'node:test' );
const assert = require( 'node:assert/strict' );

const run = require( './check-issue-triage.js' );
const { missing, MARKER } = run;

const area = { name: '[Area] Admin UI' };
const type = { name: '[Type] Bug' };

function fakeGithub( issue, comments ) {
	const calls = [];
	const record = ( name ) => async ( args ) => {
		calls.push( [ name, args ] );
	};
	return {
		calls,
		paginate: async ( method ) =>
			method === 'listForRepo' ? [ issue ] : comments,
		rest: {
			issues: {
				listForRepo: 'listForRepo',
				get: async () => ( { data: issue } ),
				listComments: () => {},
				createComment: record( 'create' ),
				updateComment: record( 'update' ),
				deleteComment: record( 'delete' ),
			},
		},
	};
}

const DAY_OLD = new Date( Date.now() - 25 * 60 * 60 * 1000 ).toISOString();

// The event payload is stale on purpose: the fetched issue is what counts.
async function check( issue, comments = [], { sweep = false } = {} ) {
	const github = fakeGithub(
		{ number: 7, state: 'open', created_at: DAY_OLD, ...issue },
		comments
	);
	await run( {
		github,
		context: {
			repo: { owner: 'WordPress', repo: 'presence-api' },
			serverUrl: 'https://github.com',
			payload: sweep
				? {}
				: { issue: { number: 7, labels: [], milestone: null } },
		},
		core: { info() {} },
	} );
	return github.calls.map( ( [ name ] ) => name );
}

test( 'names whichever of the type, area and milestone is missing', () => {
	assert.deepEqual( missing( { labels: [], milestone: null } ), [
		'a `[Type]` label',
		'an `[Area]` label',
		'a milestone',
	] );
	assert.deepEqual(
		missing( { labels: [ area ], milestone: { title: 'n.e.x.t' } } ),
		[ 'a `[Type]` label' ]
	);
	assert.deepEqual(
		missing( { labels: [ type, area ], milestone: { title: 'n.e.x.t' } } ),
		[]
	);
} );

test( 'only the sweep comments, and only on an issue a day old', async () => {
	const untriaged = { labels: [], milestone: null };
	assert.deepEqual( await check( untriaged ), [] );
	assert.deepEqual( await check( untriaged, [], { sweep: true } ), [
		'create',
	] );
	assert.deepEqual(
		await check(
			{ ...untriaged, created_at: new Date().toISOString() },
			[],
			{ sweep: true }
		),
		[]
	);

	const old = { id: 1, body: `${ MARKER }\nstale` };
	assert.deepEqual( await check( untriaged, [ old ] ), [ 'update' ] );
} );

test( 'leaves an accurate comment alone', async () => {
	const issue = { labels: [ type, area ], milestone: null };
	const body = run.formatComment(
		missing( issue ),
		'https://github.com/WordPress/presence-api'
	);
	assert.deepEqual( await check( issue, [ { id: 1, body } ] ), [] );
} );

test( 'deletes the comment once triaged or closed', async () => {
	const comment = { id: 1, body: `${ MARKER }\nold` };
	assert.deepEqual(
		await check( { labels: [ type, area ], milestone: { title: 'x' } }, [
			comment,
		] ),
		[ 'delete' ]
	);
	assert.deepEqual(
		await check( { state: 'closed', labels: [], milestone: null }, [
			comment,
		] ),
		[ 'delete' ]
	);
} );
