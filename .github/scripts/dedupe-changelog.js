'use strict';

// Every merged pull request produces two commits that release-please parses:
// the branch commit, and the merge commit whose body GitHub fills with the
// pull request title (the `merge_commit_message: PR_TITLE` repository
// setting). Both carry the same conventional prefix, so both become changelog
// entries. This is a known release-please bug, googleapis/release-please#2476,
// and it has no fix and no config switch. Upstream's own workarounds are to
// squash-merge, which would cost contributors their git attribution, or to
// flip that repository setting, which needs admin. Neither is available here,
// so the duplicates get removed after the fact instead.
//
// Runs over CHANGELOG.md on the release pull request branch, from the
// sync-versions job in release-please.yml. Commit 921d838 is the same edit
// done by hand. The GitHub Release body is generated separately and keeps its
// duplicates; CHANGELOG.md is the canonical record, and WordPress.org reads
// readme.txt, so neither depends on that body.

// `* subject ([abc1234](https://github.com/.../commit/abc1234...))`
const SHA_LINK = /\s*\(\[[0-9a-f]{6,}\]\([^)]+\)\)/g;
// release-please appends `, closes [#141](...)` from a `Closes #141` footer.
// Only the branch commit carries the footer, never the merge commit, so the
// clause has to come off the key or the two entries never match.
const CLOSES = /,?\s+closes\s+.*$/i;
const ENTRY = /^\s*[*-]\s+\S/;
const HEADING = /^#{1,6}\s/;

// The text of an entry with everything that distinguishes the two copies of
// the same change removed, leaving the subject the conventional commit and the
// pull request title share.
function entryKey( line ) {
	return line.replace( SHA_LINK, '' ).replace( CLOSES, '' ).trim();
}

function dedupeChangelog( text ) {
	const out = [];
	// Scoped per heading, so the same subject under Features and under Bug
	// Fixes stays in both, and two releases that each fix the same thing keep
	// their own entry.
	let seen = new Map();

	for ( const line of text.split( '\n' ) ) {
		if ( HEADING.test( line ) ) {
			seen = new Map();
			out.push( line );
			continue;
		}

		if ( ! ENTRY.test( line ) ) {
			out.push( line );
			continue;
		}

		const key = entryKey( line );
		const previous = seen.get( key );

		if ( previous === undefined ) {
			seen.set( key, out.push( line ) - 1 );
			continue;
		}

		// Keep whichever copy says more. The branch commit's entry is the one
		// that carries the `closes` link, and it is always the second of the
		// pair, so keeping the first would drop the issue reference every time.
		if ( line.length > out[ previous ].length ) {
			out[ previous ] = line;
		}
	}

	return out.join( '\n' );
}

module.exports = dedupeChangelog;
module.exports.dedupeChangelog = dedupeChangelog;
module.exports.entryKey = entryKey;

// CLI: `node dedupe-changelog.js CHANGELOG.md`. Rewrites in place, and says
// nothing when there was nothing to remove so the workflow log stays quiet.
if ( require.main === module ) {
	const fs = require( 'node:fs' );
	const file = process.argv[ 2 ];

	if ( ! file ) {
		console.error( 'Usage: node dedupe-changelog.js <file>' );
		process.exit( 1 );
	}

	const original = fs.readFileSync( file, 'utf8' );
	const deduped = dedupeChangelog( original );

	if ( deduped !== original ) {
		fs.writeFileSync( file, deduped );
		const removed =
			original.split( '\n' ).length - deduped.split( '\n' ).length;
		console.log(
			`Removed ${ removed } duplicate changelog ${
				removed === 1 ? 'entry' : 'entries'
			} from ${ file }.`
		);
	}
}
