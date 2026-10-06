/**
 * Labels open threads where someone outside CODEOWNERS has waited 48 hours for
 * a reply, and unlabels them once a code owner answers.
 *
 * Usage: node needs-reply.js
 * Env:   GH_TOKEN, GITHUB_REPOSITORY
 */

'use strict';

const LABEL = 'Needs Reply';
const WAIT_MS = 48 * 60 * 60 * 1000;

// Every `@handle` in the file. Teams (`@org/team`) would need an API call to
// expand, and the file names none.
function parseCodeowners( text ) {
	const owners = new Set();

	for ( const line of text.split( '\n' ) ) {
		const content = line.replace( /#.*/, '' );

		for ( const [ , handle ] of content.matchAll( /@([\w-]+)(?!\/)\b/g ) ) {
			owners.add( handle.toLowerCase() );
		}
	}

	return owners;
}

const isBot = ( user ) =>
	! user || 'Bot' === user.type || user.login.endsWith( '[bot]' );

// The opening counts, so a thread nobody has answered is caught too.
function latestActivity( item, timeline ) {
	const events = [ { user: item.user, at: item.created_at } ];

	for ( const event of timeline ) {
		switch ( event.event ) {
			case 'commented':
			case 'review_requested':
				events.push( {
					user: event.actor || event.user,
					at: event.created_at,
				} );
				break;
			case 'reviewed':
				events.push( { user: event.user, at: event.submitted_at } );
				break;
			case 'line-commented':
				for ( const comment of event.comments || [] ) {
					events.push( {
						user: comment.user,
						at: comment.created_at,
					} );
				}
				break;
		}
	}

	return events
		.filter( ( e ) => e.at && ! isBot( e.user ) )
		.reduce(
			( latest, e ) =>
				! latest || Date.parse( e.at ) > Date.parse( latest.at )
					? e
					: latest,
			null
		);
}

// 'add', 'remove', or null to leave the label alone.
function decide( { latest, owners, labeled, now } ) {
	if ( ! latest ) {
		return null;
	}

	if ( owners.has( latest.user.login.toLowerCase() ) ) {
		return labeled ? 'remove' : null;
	}

	return ! labeled && now - Date.parse( latest.at ) > WAIT_MS ? 'add' : null;
}

module.exports = { LABEL, parseCodeowners, latestActivity, decide };

if ( require.main === module ) {
	const fs = require( 'node:fs' );

	const token = process.env.GH_TOKEN || process.env.GITHUB_TOKEN;
	const repo = process.env.GITHUB_REPOSITORY;

	if ( ! token || ! repo ) {
		console.error( 'Missing GH_TOKEN or GITHUB_REPOSITORY.' );
		process.exit( 1 );
	}

	// `allowed` lists statuses that mean the work is already done.
	const api = async ( path, init = {}, allowed = [] ) => {
		const res = await fetch( `https://api.github.com${ path }`, {
			...init,
			headers: {
				Authorization: `Bearer ${ token }`,
				Accept: 'application/vnd.github+json',
				'User-Agent': 'presence-api-needs-reply',
			},
		} );

		if ( ! res.ok && ! allowed.includes( res.status ) ) {
			throw new Error( `${ res.status } from ${ path }` );
		}

		return res.json().catch( () => null );
	};

	const all = async ( path ) => {
		const items = [];

		for ( let page = 1; ; page++ ) {
			const sep = path.includes( '?' ) ? '&' : '?';
			const body = await api(
				`${ path }${ sep }per_page=100&page=${ page }`
			);

			items.push( ...body );

			if ( body.length < 100 ) {
				return items;
			}
		}
	};

	( async () => {
		const owners = parseCodeowners(
			fs.readFileSync( '.github/CODEOWNERS', 'utf8' )
		);
		const now = Date.now();
		let labelExists = false;

		for ( const item of await all(
			`/repos/${ repo }/issues?state=open`
		) ) {
			// A draft is still being written, not waiting on anyone.
			if ( item.draft ) {
				continue;
			}

			const timeline = await all(
				`/repos/${ repo }/issues/${ item.number }/timeline`
			);
			const action = decide( {
				latest: latestActivity( item, timeline ),
				owners,
				labeled: item.labels.some( ( l ) => LABEL === l.name ),
				now,
			} );

			if ( 'add' === action ) {
				if ( ! labelExists ) {
					await api(
						`/repos/${ repo }/labels`,
						{
							method: 'POST',
							body: JSON.stringify( {
								name: LABEL,
								color: 'F2994A',
								description:
									'Someone outside the maintainers has waited over 48 hours for an answer',
							} ),
						},
						[ 422 ]
					);
					labelExists = true;
				}

				await api( `/repos/${ repo }/issues/${ item.number }/labels`, {
					method: 'POST',
					body: JSON.stringify( { labels: [ LABEL ] } ),
				} );
				console.log( `#${ item.number }: added ${ LABEL }` );
			} else if ( 'remove' === action ) {
				await api(
					`/repos/${ repo }/issues/${
						item.number
					}/labels/${ encodeURIComponent( LABEL ) }`,
					{ method: 'DELETE' },
					[ 404 ]
				);
				console.log( `#${ item.number }: removed ${ LABEL }` );
			}
		}
	} )().catch( ( error ) => {
		console.error( error );
		process.exit( 1 );
	} );
}
