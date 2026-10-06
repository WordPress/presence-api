'use strict';

// Keeps one comment on an open issue a day old and missing a `[Type]`, `[Area]` or milestone, deleted once it has all three.

const MARKER = '<!-- presence-api:issue-triage -->';
const GRACE_MS = 24 * 60 * 60 * 1000;

function missing( issue ) {
	const gaps = [];
	if ( ! issue.labels.some( ( l ) => l.name.startsWith( '[Type]' ) ) ) {
		gaps.push( 'a `[Type]` label' );
	}
	if ( ! issue.labels.some( ( l ) => l.name.startsWith( '[Area]' ) ) ) {
		gaps.push( 'an `[Area]` label' );
	}
	if ( ! issue.milestone ) {
		gaps.push( 'a milestone' );
	}
	return gaps;
}

function formatComment( gaps, repoUrl ) {
	const labels = `${ repoUrl }/blob/main/.github/CONTRIBUTING.md#labels`;
	const list =
		gaps.length > 1
			? `${ gaps.slice( 0, -1 ).join( ', ' ) } and ${ gaps.at( -1 ) }`
			: gaps[ 0 ];
	return `${ MARKER }\nThis issue still needs ${ list }. See [Labels](${ labels }).`;
}

// New comments wait for the daily sweep so a maintainer has a day to triage first; events only edit or delete one.
async function triage( { github, owner, repo, issue, repoUrl, now, core } ) {
	const issue_number = issue.number;
	const comments = await github.paginate( github.rest.issues.listComments, {
		owner,
		repo,
		issue_number,
		per_page: 100,
	} );
	const existing = comments.find( ( c ) => c.body.startsWith( MARKER ) );
	const gaps = issue.state === 'open' ? missing( issue ) : [];

	if ( gaps.length === 0 ) {
		if ( existing ) {
			await github.rest.issues.deleteComment( {
				owner,
				repo,
				comment_id: existing.id,
			} );
		}
		core.info( `#${ issue_number } is triaged.` );
		return;
	}

	const body = formatComment( gaps, repoUrl );
	if ( existing?.body === body ) {
		return;
	}
	if ( existing ) {
		await github.rest.issues.updateComment( {
			owner,
			repo,
			comment_id: existing.id,
			body,
		} );
	} else if ( now && now - Date.parse( issue.created_at ) > GRACE_MS ) {
		await github.rest.issues.createComment( {
			owner,
			repo,
			issue_number,
			body,
		} );
	}
}

async function run( { github, context, core } ) {
	const { owner, repo } = context.repo;
	const repoUrl = `${ context.serverUrl }/${ owner }/${ repo }`;

	if ( ! context.payload.issue ) {
		const issues = await github.paginate( github.rest.issues.listForRepo, {
			owner,
			repo,
			state: 'open',
			per_page: 100,
		} );
		const now = Date.now();
		for ( const issue of issues.filter( ( i ) => ! i.pull_request ) ) {
			await triage( { github, owner, repo, issue, repoUrl, now, core } );
		}
		return;
	}

	// A queued run's payload is from before the labels added since.
	const { data: issue } = await github.rest.issues.get( {
		owner,
		repo,
		issue_number: context.payload.issue.number,
	} );
	await triage( { github, owner, repo, issue, repoUrl, now: null, core } );
}

module.exports = run;
module.exports.missing = missing;
module.exports.formatComment = formatComment;
module.exports.MARKER = MARKER;
