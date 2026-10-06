'use strict';

const RELEASE_BRANCH = 'release-please--branches--main--components--';
const SCENES_PATH = 'plugins/presence-scenes/';
const NEXT = 'n.e.x.t';

// A pull request counts toward Presence Scenes only when every file it touches is under that plugin, mirroring Presence API's `exclude-paths`.
const PACKAGES = [
	{
		component: 'presence-api',
		milestone: '',
		tag: 'v',
		owns: ( files ) => files.some( ( f ) => ! f.startsWith( SCENES_PATH ) ),
	},
	{
		component: 'presence-scenes',
		milestone: 'Presence Scenes ',
		tag: 'presence-scenes-v',
		owns: ( files ) =>
			files.length > 0 &&
			files.every( ( f ) => f.startsWith( SCENES_PATH ) ),
	},
];

function versionAfter( prefix, name ) {
	const rest = name.startsWith( prefix ) ? name.slice( prefix.length ) : '';
	return /^\d+\.\d+\.\d+$/.test( rest ) ? rest : null;
}

function findCutoff( releases, pkg ) {
	return releases
		.filter(
			( r ) =>
				! r.draft &&
				r.published_at &&
				versionAfter( pkg.tag, r.tag_name )
		)
		.map( ( r ) => r.published_at )
		.sort()
		.pop();
}

// Search would be one filter shorter, but its index lags a merge by seconds.
const MERGED_QUERY = `
	query ( $owner: String!, $repo: String! ) {
		repository( owner: $owner, name: $repo ) {
			pullRequests(
				states: MERGED
				baseRefName: "main"
				first: 100
				orderBy: { field: UPDATED_AT, direction: DESC }
			) {
				nodes {
					number
					mergedAt
					headRefName
					milestone { title }
					files( first: 100 ) { nodes { path } }
					closingIssuesReferences( first: 20 ) {
						nodes { number milestone { title } }
					}
				}
			}
		}
	}
`;

// This package's pull requests merged since the cutoff, and the issues they closed.
async function releaseItems( { github, owner, repo, pkg, cutoff } ) {
	const { repository } = await github.graphql( MERGED_QUERY, {
		owner,
		repo,
	} );
	return repository.pullRequests.nodes
		.filter(
			( pr ) =>
				pr.mergedAt >= cutoff &&
				! pr.headRefName.startsWith( RELEASE_BRANCH ) &&
				pkg.owns( pr.files.nodes.map( ( f ) => f.path ) )
		)
		.flatMap( ( pr ) => [ pr, ...pr.closingIssuesReferences.nodes ] );
}

// Items move only out of n.e.x.t or no milestone, so theme milestones keep theirs.
async function run( { github, context, core } ) {
	const { owner, repo } = context.repo;

	const [ milestones, releases, openPRs ] = await Promise.all( [
		github.paginate( github.rest.issues.listMilestones, {
			owner,
			repo,
			state: 'open',
			per_page: 100,
		} ),
		github.paginate( github.rest.repos.listReleases, {
			owner,
			repo,
			per_page: 100,
		} ),
		github.paginate( github.rest.pulls.list, {
			owner,
			repo,
			state: 'open',
			base: 'main',
			per_page: 100,
		} ),
	] );
	const published = new Set(
		releases.filter( ( r ) => ! r.draft ).map( ( r ) => r.tag_name )
	);

	for ( const pkg of PACKAGES ) {
		const own = milestones.filter( ( m ) =>
			versionAfter( pkg.milestone, m.title )
		);
		const isReleased = ( m ) =>
			published.has( pkg.tag + versionAfter( pkg.milestone, m.title ) );

		for ( const m of own.filter( isReleased ) ) {
			await github.rest.issues.updateMilestone( {
				owner,
				repo,
				milestone_number: m.number,
				state: 'closed',
			} );
			core.info( `Closed milestone ${ m.title }.` );
		}

		const releasePR = openPRs.find(
			( pr ) => pr.head.ref === RELEASE_BRANCH + pkg.component
		);
		const version = releasePR?.title.match( /(\d+\.\d+\.\d+)$/ )?.[ 1 ];
		if ( ! version ) {
			continue;
		}

		const title = pkg.milestone + version;
		const previous = own.find(
			( m ) => m.number === releasePR.milestone?.number
		);
		let milestone = own.find( ( m ) => m.title === title );

		if ( ! milestone && previous ) {
			// The release PR's version moved, say from a patch to a minor.
			( { data: milestone } = await github.rest.issues.updateMilestone( {
				owner,
				repo,
				milestone_number: previous.number,
				title,
			} ) );
			core.info( `Renamed milestone ${ previous.title } to ${ title }.` );
		} else if ( ! milestone ) {
			( { data: milestone } = await github.rest.issues.createMilestone( {
				owner,
				repo,
				title,
			} ) );
			core.info( `Created milestone ${ title }.` );
		}

		const cutoff = findCutoff( releases, pkg );
		const items = [
			releasePR,
			...( cutoff
				? await releaseItems( { github, owner, repo, pkg, cutoff } )
				: [] ),
		];

		for ( const item of items ) {
			if ( item.milestone && item.milestone.title !== NEXT ) {
				continue;
			}
			await github.rest.issues.update( {
				owner,
				repo,
				issue_number: item.number,
				milestone: milestone.number,
			} );
			core.info( `Moved #${ item.number } into ${ title }.` );
		}
	}
}

module.exports = run;
module.exports.PACKAGES = PACKAGES;
module.exports.findCutoff = findCutoff;
