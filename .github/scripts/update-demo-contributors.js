/**
 * Regenerates the contributor list baked into tests/e2e/demo-seeder.php, so the
 * people who built the plugin are the people showing up in its demos.
 *
 * The committed list is the fallback, not the source of truth. The seeder asks
 * GitHub at runtime and only falls back to this when that request fails. What
 * the committed list uniquely provides is real names: the contributors endpoint
 * returns logins, and resolving those to names costs one request per person,
 * which is affordable here and not on every demo boot.
 *
 * Usage: node update-demo-contributors.js [seeder-path]
 * Env:   GH_TOKEN (or GITHUB_TOKEN), GITHUB_REPOSITORY
 */

'use strict';

const fs    = require( 'fs' );
const path  = require( 'path' );
const https = require( 'https' );

const BEGIN = '// BEGIN GENERATED CONTRIBUTORS';
const END   = '// END GENERATED CONTRIBUTORS';

/**
 * Hard cap on how many contributors get baked in.
 *
 * demo-seeder.php is downloaded by every Playground boot, and no demo seeds
 * more than a few dozen users, so there is nothing to gain past this.
 */
const MAX_CONTRIBUTORS = 60;

const OPTOUT_FILE = path.join( __dirname, '..', 'demo-contributors-optout.txt' );

/**
 * Drops bots and anyone who has opted out.
 *
 * The `type` field catches apps registered as bots; the `[bot]` suffix catches
 * the ones that are not, such as older GitHub Actions identities.
 *
 * @param {Array}  contributors Raw contributor objects from the API.
 * @param {Array}  optOut       Lowercased logins to exclude.
 * @return {Array} Filtered contributors.
 */
function filterContributors( contributors, optOut = [] ) {
	const excluded = new Set( optOut.map( ( login ) => login.toLowerCase() ) );

	return contributors.filter( ( c ) => {
		if ( ! c || ! c.login || ! c.id ) {
			return false;
		}
		if ( c.type && 'User' !== c.type ) {
			return false;
		}
		if ( c.login.toLowerCase().endsWith( '[bot]' ) ) {
			return false;
		}
		return ! excluded.has( c.login.toLowerCase() );
	} );
}

/**
 * Escapes a value for a single-quoted PHP string literal.
 *
 * Only the backslash and the single quote are special inside single quotes,
 * and both appear in real names.
 *
 * @param {string} value Raw value.
 * @return {string} Escaped value.
 */
function escapePhp( value ) {
	return String( value ).replace( /\\/g, '\\\\' ).replace( /'/g, "\\'" );
}

/**
 * Renders the generated PHP block, markers included.
 *
 * @param {Array}  contributors Objects with login, name, and id.
 * @param {Array}  optOut       Logins that asked not to appear.
 * @return {string} PHP source.
 */
function renderContributors( contributors, optOut = [] ) {
	const entries = contributors.map( ( c ) => {
		return [
			'\tarray(',
			`\t\t'login' => '${ escapePhp( c.login ) }',`,
			`\t\t'name'  => '${ escapePhp( c.name || '' ) }',`,
			`\t\t'id'    => ${ parseInt( c.id, 10 ) },`,
			'\t),',
		].join( '\n' );
	} );

	// Lowercased, because the seeder compares against a lowercased login. The
	// opt-out has to survive into the generated file: the runtime lookup asks
	// GitHub directly, and GitHub has never heard of the opt-out file.
	const excluded = optOut.map( ( login ) => `'${ escapePhp( login.toLowerCase() ) }'` );

	return [
		BEGIN,
		'const WP_PRESENCE_DEMO_CONTRIBUTORS = array(',
		...entries,
		');',
		'',
		'/**',
		' * Lowercased GitHub logins that have asked not to appear in the demos.',
		' *',
		' * Baked in from .github/demo-contributors-optout.txt so that the opt-out is',
		' * still honoured when the contributor list comes back live from GitHub, which',
		' * knows nothing about that file.',
		' */',
		excluded.length
			? `const WP_PRESENCE_DEMO_OPTOUT = array( ${ excluded.join( ', ' ) } );`
			: 'const WP_PRESENCE_DEMO_OPTOUT = array();',
		END,
	].join( '\n' );
}

/**
 * Swaps the generated block into the seeder source.
 *
 * @param {string} source Full seeder file contents.
 * @param {string} block  Replacement block, markers included.
 * @return {string} Updated source.
 * @throws {Error} When the markers are missing or out of order.
 */
function replaceBlock( source, block ) {
	const start = source.indexOf( BEGIN );
	const stop  = source.indexOf( END );

	if ( -1 === start || -1 === stop || stop < start ) {
		throw new Error( `Could not find the ${ BEGIN } / ${ END } markers.` );
	}

	return source.slice( 0, start ) + block + source.slice( stop + END.length );
}

/**
 * Reads the opt-out list, if there is one.
 *
 * Contributors who would rather not appear in a public demo add their login
 * here. The file is optional.
 *
 * @param {string} file Path to the opt-out file.
 * @return {Array} Logins.
 */
function readOptOut( file ) {
	if ( ! fs.existsSync( file ) ) {
		return [];
	}

	return fs
		.readFileSync( file, 'utf8' )
		.split( '\n' )
		.map( ( line ) => line.replace( /#.*$/, '' ).trim() )
		.filter( Boolean );
}

/**
 * Performs a GET against the GitHub API.
 *
 * @param {string} apiPath Path beginning with a slash.
 * @param {string} token   API token.
 * @return {Promise<Object|Array>} Parsed response body.
 */
function apiGet( apiPath, token ) {
	return new Promise( ( resolve, reject ) => {
		https
			.get(
				`https://api.github.com${ apiPath }`,
				{
					headers: {
						Authorization: `Bearer ${ token }`,
						Accept:        'application/vnd.github+json',
						'User-Agent':  'presence-api-update-demo-contributors',
					},
				},
				( res ) => {
					let data = '';
					res.on( 'data', ( d ) => ( data += d ) );
					res.on( 'end', () => {
						if ( 200 !== res.statusCode ) {
							reject(
								new Error( `GET ${ apiPath } returned ${ res.statusCode }: ${ data.slice( 0, 200 ) }` )
							);
							return;
						}
						try {
							resolve( JSON.parse( data ) );
						} catch ( err ) {
							reject( err );
						}
					} );
				}
			)
			.on( 'error', reject );
	} );
}

async function main() {
	const seederFile = process.argv[ 2 ] || path.join( __dirname, '..', '..', 'tests', 'e2e', 'demo-seeder.php' );
	const token      = process.env.GH_TOKEN || process.env.GITHUB_TOKEN;
	const repo       = process.env.GITHUB_REPOSITORY || 'WordPress/presence-api';

	if ( ! token ) {
		console.log( 'Missing GH_TOKEN — skipping.' );
		process.exit( 0 );
	}

	const collected = [];
	for ( let page = 1; page <= 5; page++ ) {
		const batch = await apiGet( `/repos/${ repo }/contributors?per_page=100&page=${ page }`, token );
		if ( ! Array.isArray( batch ) || ! batch.length ) {
			break;
		}
		collected.push( ...batch );
		if ( batch.length < 100 ) {
			break;
		}
	}

	const optOut = readOptOut( OPTOUT_FILE );
	const humans = filterContributors( collected, optOut );

	if ( ! humans.length ) {
		console.log( 'No human contributors returned — refusing to write an empty list.' );
		process.exit( 1 );
	}

	if ( humans.length > MAX_CONTRIBUTORS ) {
		console.log( `Capping ${ humans.length } contributors at ${ MAX_CONTRIBUTORS }.` );
	}

	const top = humans.slice( 0, MAX_CONTRIBUTORS );

	// The contributors endpoint does not carry real names, so each profile
	// needs its own request. A profile that fails to load falls back to the
	// login rather than failing the run.
	const resolved = [];
	for ( const contributor of top ) {
		let name = '';
		try {
			const profile = await apiGet( `/users/${ encodeURIComponent( contributor.login ) }`, token );
			name = profile && profile.name ? profile.name.trim() : '';
		} catch ( err ) {
			console.log( `Could not read the profile for ${ contributor.login }: ${ err.message }` );
		}
		resolved.push( { login: contributor.login, name, id: contributor.id } );
	}

	const source  = fs.readFileSync( seederFile, 'utf8' );
	const updated = replaceBlock( source, renderContributors( resolved, optOut ) );

	if ( updated === source ) {
		console.log( 'Contributor list is already up to date.' );
		return;
	}

	fs.writeFileSync( seederFile, updated );
	console.log( `Wrote ${ resolved.length } contributors to ${ seederFile }.` );
}

module.exports = { BEGIN, END, filterContributors, escapePhp, renderContributors, replaceBlock, readOptOut };

if ( require.main === module ) {
	main().catch( ( err ) => {
		console.error( err );
		process.exit( 1 );
	} );
}
