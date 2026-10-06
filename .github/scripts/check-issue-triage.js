'use strict';

// CONTRIBUTING asks every open issue for an `[Area]` label and a milestone.
// This keeps one comment on an issue missing either, and deletes it once both
// are there.

const MARKER = '<!-- presence-api:issue-triage -->';

function missing( issue ) {
	const gaps = [];
	if (
		! issue.labels.some( ( l ) =>
			( l.name ?? l ).startsWith( '[Area]' )
		)
	) {
		gaps.push( 'an `[Area]` label' );
	}
	if ( ! issue.milestone ) {
		gaps.push( 'a milestone' );
	}
	return gaps;
}

function formatComment( gaps, repoUrl ) {
	return [
		MARKER,
		`This issue still needs ${ gaps.join( ' and ' ) }. See [Labels](${ repoUrl }/blob/main/.github/CONTRIBUTING.md#labels).`,
	].join( '\n' );
}

async function run( { github, context, core } ) {
	const { owner, repo } = context.repo;
	const issue = context.payload.issue;
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

	const body = formatComment(
		gaps,
		`${ context.serverUrl }/${ owner }/${ repo }`
	);
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
	} else {
		await github.rest.issues.createComment( {
			owner,
			repo,
			issue_number,
			body,
		} );
	}
}

module.exports = run;
module.exports.missing = missing;
module.exports.formatComment = formatComment;
module.exports.MARKER = MARKER;
