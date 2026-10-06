'use strict';

const { test } = require( 'node:test' );
const assert = require( 'node:assert/strict' );

const run = require( './check-issue-triage.js' );
const { missing, MARKER } = run;

const area = { name: '[Area] Admin UI' };
const type = { name: '[Type] Bug' };

function fakeGithub( issue, comments ) {
	const calls = [];
	const record =
		( name ) =>
		async ( args ) => {
			calls.push( [ name, args ] );
		};
	return {
		calls,
		paginate: async () => comments,
		rest: {
			issues: {
				get: async () => ( { data: issue } ),
				listComments: () => {},
				createComment: record( 'create' ),
				updateComment: record( 'update' ),
				deleteComment: record( 'delete' ),
			},
		},
	};
}

async function check( issue, comments = [] ) {
	// The payload is stale on purpose: the fetched issue is what counts.
	const github = fakeGithub( { state: 'open', ...issue }, comments );
	await run( {
		github,
		context: {
			repo: { owner: 'WordPress', repo: 'presence-api' },
			serverUrl: 'https://github.com',
			payload: { issue: { number: 7, labels: [], milestone: null } },
		},
		core: { info() {} },
	} );
	return github.calls.map( ( [ name ] ) => name );
}

test( 'names whichever of the area and milestone is missing', () => {
	assert.deepEqual( missing( { labels: [ type ], milestone: null } ), [
		'an `[Area]` label',
		'a milestone',
	] );
	assert.deepEqual(
		missing( { labels: [ area ], milestone: { title: 'n.e.x.t' } } ),
		[]
	);
} );

test( 'comments once, then edits that comment in place', async () => {
	const untriaged = { labels: [], milestone: null };
	assert.deepEqual( await check( untriaged ), [ 'create' ] );

	const old = { id: 1, body: `${ MARKER }\nstale` };
	assert.deepEqual( await check( untriaged, [ old ] ), [ 'update' ] );
} );

test( 'leaves an accurate comment alone', async () => {
	const issue = { labels: [ area ], milestone: null };
	const body = run.formatComment(
		missing( issue ),
		'https://github.com/WordPress/presence-api'
	);
	assert.deepEqual( await check( issue, [ { id: 1, body } ] ), [] );
} );

test( 'deletes the comment once triaged or closed', async () => {
	const comment = { id: 1, body: `${ MARKER }\nold` };
	assert.deepEqual(
		await check( { labels: [ area ], milestone: { title: 'x' } }, [
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
