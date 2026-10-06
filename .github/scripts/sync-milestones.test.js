'use strict';

const { test } = require( 'node:test' );
const assert = require( 'node:assert/strict' );

const run = require( './sync-milestones.js' );
const { PACKAGES, findCutoff } = run;

const [ API, SCENES ] = PACKAGES;
const context = { repo: { owner: 'WordPress', repo: 'presence-api' } };
const core = { info: () => {} };

function releasePR( number, component, title ) {
	return {
		number,
		title,
		milestone: null,
		head: {
			ref: `release-please--branches--main--components--${ component }`,
		},
	};
}

function mergedPR( number, files, { milestone = null, closes = [] } = {} ) {
	return {
		number,
		mergedAt: '2026-10-06T12:00:00Z',
		headRefName: `feat/${ number }`,
		milestone: milestone && { title: milestone },
		files: { nodes: files.map( ( path ) => ( { path } ) ) },
		closingIssuesReferences: { nodes: closes },
	};
}

// Records every write as [ method, params ] so a test can assert on the calls.
function buildGithub( {
	milestones = [],
	releases = [],
	openPRs = [],
	merged = [],
} ) {
	const writes = [];
	const write = ( method ) => async ( params ) => {
		writes.push( [ method, params ] );
		return { data: { number: params.milestone_number ?? 100, ...params } };
	};
	const lists = new Map();
	const github = {
		rest: {
			issues: {
				listMilestones: 'milestones',
				updateMilestone: write( 'updateMilestone' ),
				createMilestone: write( 'createMilestone' ),
				update: write( 'update' ),
			},
			repos: { listReleases: 'releases' },
			pulls: { list: 'pulls' },
		},
		paginate: async ( fn ) => lists.get( fn ),
		graphql: async () => ( {
			repository: { pullRequests: { nodes: merged } },
		} ),
	};
	lists.set( 'milestones', milestones );
	lists.set( 'releases', releases );
	lists.set( 'pulls', openPRs );
	return { github, writes };
}

const published = ( tag_name, published_at = '2026-10-06T01:49:41Z' ) => ( {
	tag_name,
	published_at,
	draft: false,
} );

test( 'findCutoff: reads only its own package tags, skipping drafts', () => {
	const releases = [
		published( 'v0.15.0', '2026-10-06T01:00:00Z' ),
		published( 'presence-scenes-v0.1.1', '2026-10-07T00:00:00Z' ),
		published( 'preview-pr-12', '2026-10-08T00:00:00Z' ),
		{
			tag_name: 'v0.16.0',
			published_at: '2026-10-09T00:00:00Z',
			draft: true,
		},
	];
	assert.equal( findCutoff( releases, API ), '2026-10-06T01:00:00Z' );
	assert.equal( findCutoff( releases, SCENES ), '2026-10-07T00:00:00Z' );
} );

test( 'creates the release milestone and files each PR under its own package', async () => {
	const { github, writes } = buildGithub( {
		releases: [
			published( 'v0.15.0' ),
			published( 'presence-scenes-v0.1.1' ),
		],
		openPRs: [
			releasePR( 759, 'presence-api', 'chore(main): release 0.16.0' ),
			releasePR(
				761,
				'presence-scenes',
				'chore(main): release presence-scenes 0.1.2'
			),
		],
		merged: [
			mergedPR( 1, [ 'plugins/presence-scenes/library/a.json' ] ),
			mergedPR( 2, [ 'plugins/presence-scenes/a.php', 'README.md' ] ),
			{
				...mergedPR( 3, [ 'README.md' ] ),
				mergedAt: '2026-10-01T00:00:00Z',
			},
			{
				...mergedPR( 4, [ '.release-please-manifest.json' ] ),
				headRefName:
					'release-please--branches--main--components--presence-scenes',
			},
		],
	} );

	await run( { github, context, core } );

	assert.deepEqual(
		writes
			.filter( ( [ m ] ) => m === 'createMilestone' )
			.map( ( [ , p ] ) => p.title ),
		[ '0.16.0', 'Presence Scenes 0.1.2' ]
	);
	assert.deepEqual(
		writes
			.filter( ( [ m ] ) => m === 'update' )
			.map( ( [ , p ] ) => p.issue_number ),
		[ 759, 2, 761, 1 ]
	);
} );

test( 'moves closed issues out of n.e.x.t but leaves theme milestones alone', async () => {
	const { github, writes } = buildGithub( {
		milestones: [ { number: 31, title: '0.16.0' } ],
		releases: [ published( 'v0.15.0' ) ],
		openPRs: [
			releasePR( 759, 'presence-api', 'chore(main): release 0.16.0' ),
		],
		merged: [
			mergedPR( 3, [ 'README.md' ], {
				milestone: 'Public API freeze',
				closes: [
					{ number: 10, milestone: { title: 'n.e.x.t' } },
					{ number: 11, milestone: { title: 'Public API freeze' } },
				],
			} ),
		],
	} );

	await run( { github, context, core } );

	assert.deepEqual(
		writes.map( ( [ m, p ] ) => [ m, p.issue_number, p.milestone ] ),
		[
			[ 'update', 759, 31 ],
			[ 'update', 10, 31 ],
		]
	);
} );

test( 'renames the release PR milestone when the version moves, and closes released ones', async () => {
	const pr = releasePR( 759, 'presence-api', 'chore(main): release 0.16.0' );
	pr.milestone = { number: 31, title: '0.15.1' };
	const { github, writes } = buildGithub( {
		milestones: [
			{ number: 23, title: '0.15.0' },
			{ number: 30, title: '1.0.0' },
			{ number: 31, title: '0.15.1' },
		],
		releases: [ published( 'v0.15.0' ) ],
		openPRs: [ pr ],
	} );

	await run( { github, context, core } );

	assert.deepEqual( writes.slice( 0, 2 ), [
		[
			'updateMilestone',
			{
				owner: 'WordPress',
				repo: 'presence-api',
				milestone_number: 23,
				state: 'closed',
			},
		],
		[
			'updateMilestone',
			{
				owner: 'WordPress',
				repo: 'presence-api',
				milestone_number: 31,
				title: '0.16.0',
			},
		],
	] );
} );
