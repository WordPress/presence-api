/**
 * Reads the release-props sticky comment on the open release PR and adds any
 * new contributor usernames to the Contributors line in readme.txt.
 *
 * Usage: node sync-contributors.js <readme-path>
 * Env:   GH_TOKEN, GITHUB_REPOSITORY, PR_NUMBER
 */

'use strict';

const fs = require( 'fs' );
const https = require( 'https' );

const { MARKER, parsePropsNames } = require( './aggregate-props.js' );

const readmeFile = process.argv[ 2 ] || 'readme.txt';
const prNumber = process.env.PR_NUMBER;
const token = process.env.GH_TOKEN || process.env.GITHUB_TOKEN;
const repo = process.env.GITHUB_REPOSITORY;

if ( ! prNumber || ! token || ! repo ) {
	console.log(
		'Missing PR_NUMBER, GH_TOKEN, or GITHUB_REPOSITORY — skipping.'
	);
	process.exit( 0 );
}

function apiGet( path ) {
	return new Promise( ( resolve, reject ) => {
		https
			.get(
				`https://api.github.com${ path }`,
				{
					headers: {
						Authorization: `Bearer ${ token }`,
						Accept: 'application/vnd.github+json',
						'User-Agent': 'presence-api-sync-contributors',
					},
				},
				( res ) => {
					let data = '';
					res.on( 'data', ( d ) => ( data += d ) );
					res.on( 'end', () => resolve( JSON.parse( data ) ) );
				}
			)
			.on( 'error', reject );
	} );
}

async function main() {
	const comments = await apiGet(
		`/repos/${ repo }/issues/${ prNumber }/comments?per_page=100`
	);

	const propsComment = [ ...comments ]
		.reverse()
		.find( ( c ) => c.body && c.body.includes( MARKER ) );

	if ( ! propsComment ) {
		console.log(
			'No release-props comment found — skipping contributor sync.'
		);
		return;
	}

	const incoming = parsePropsNames( propsComment.body );
	if ( ! incoming.length ) {
		console.log( 'Props line is empty — skipping.' );
		return;
	}

	const readme = fs.readFileSync( readmeFile, 'utf8' );
	const match = readme.match( /^Contributors: (.+)$/m );
	if ( ! match ) {
		console.log( 'Contributors line not found in readme — skipping.' );
		return;
	}

	const existing = match[ 1 ].split( ',' ).map( ( n ) => n.trim() );
	const toAdd = incoming.filter( ( n ) => ! existing.includes( n ) );

	if ( ! toAdd.length ) {
		console.log( 'No new contributors to add.' );
		return;
	}

	const updated = readme.replace(
		/^Contributors: .+$/m,
		`Contributors: ${ [ ...existing, ...toAdd ].join( ', ' ) }`
	);

	fs.writeFileSync( readmeFile, updated );
	console.log( `Added contributors: ${ toAdd.join( ', ' ) }` );
}

main().catch( ( err ) => {
	console.error( err );
	process.exit( 1 );
} );
