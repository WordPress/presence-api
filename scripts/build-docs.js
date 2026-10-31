'use strict';

// Renders docs/ into build/showcase/docs/ in docs/manifest.json's order, the format developer.wordpress.org's handbooks sync from.

const fs = require( 'node:fs' );
const path = require( 'node:path' );
const MarkdownIt = require( 'markdown-it' );

const root = path.dirname( __dirname );
const docs = path.join( root, 'docs' );
const out = path.join( root, 'build', 'showcase' );
const manifest = JSON.parse(
	fs.readFileSync( path.join( docs, 'manifest.json' ), 'utf8' )
);

// docs/learn/README.md becomes docs/learn/, and docs/learn/the-row.md becomes docs/learn/the-row/.
function outputDir( source ) {
	const relative = path.relative( root, source ).replace( /\.md$/, '' );
	return path.basename( relative ) === 'README'
		? path.dirname( relative )
		: relative;
}

function sourceOf( entry ) {
	return path.join(
		docs,
		entry.markdown_source.replace( /^\.\.\/docs\//, '' )
	);
}

function href( fromDir, toDir ) {
	return ( path.relative( fromDir, toDir ) || '.' ) + '/';
}

const escapeHtml = ( text ) =>
	text.replace( /[&<>"]/g, ( c ) => `&#${ c.charCodeAt( 0 ) };` );

const pages = new Set( manifest.map( sourceOf ) );
const md = new MarkdownIt( { html: true } );

// Points a link to another page at its built directory, and fails on one the manifest does not list.
md.renderer.rules.link_open = ( tokens, i, options, env, self ) => {
	const link = tokens[ i ].attrGet( 'href' );
	if ( link && /^[^:#]+\.md(#.*)?$/.test( link ) ) {
		const [ file, hash = '' ] = link.split( '#' );
		const linked = path.resolve( path.dirname( env.source ), file );
		if ( ! pages.has( linked ) ) {
			throw new Error(
				`${ env.source } links to ${ file }, which docs/manifest.json does not list.`
			);
		}
		tokens[ i ].attrSet(
			'href',
			href( env.dir, outputDir( linked ) ) + ( hash && `#${ hash }` )
		);
	}
	return self.renderToken( tokens, i, options );
};

for ( const entry of manifest ) {
	const source = sourceOf( entry );
	const dir = outputDir( source );

	const siblings = entry.parent
		? manifest.filter( ( e ) => e.parent === entry.parent )
		: [];
	const index = siblings.indexOf( entry );
	const pager = [
		[ siblings[ index - 1 ], 'Previous' ],
		[ siblings[ index + 1 ], 'Next' ],
	]
		.filter( ( [ sibling ] ) => sibling )
		.map(
			( [ sibling, label ] ) =>
				`<a href="${ href( dir, outputDir( sourceOf( sibling ) ) ) }">${ label }: ${ escapeHtml( sibling.title ) }</a>`
		)
		.join( '\n\t\t\t' );
	const section = manifest.find( ( e ) => e.slug === entry.parent );
	const nav = pager
		? `<nav class="docs__pager" aria-label="${ escapeHtml( section.title ) }">\n\t\t\t${ pager }\n\t\t</nav>\n\t\t`
		: '';

	const assets = path.relative( dir, '.' ) || '.';
	const home = href( dir, 'docs' );
	const html = `<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>${ escapeHtml( entry.title ) } | Presence API</title>
	<link rel="icon" href="${ assets }/images/icon-256x256.png">
	<link rel="stylesheet" href="${ assets }/style.css">
	<link rel="stylesheet" href="${ assets }/docs.css">
</head>
<body>
	<a class="skip-link" href="#main">Skip to content</a>
	<nav class="local-nav" aria-label="Presence API">
		<a class="local-nav__title" href="${ assets }/">Presence API</a>
		<ul>
			<li><a href="${ home }">Docs</a></li>
			<li><a href="https://github.com/WordPress/presence-api">GitHub</a></li>
		</ul>
	</nav>
	<main id="main" class="docs">
		${ md.render( fs.readFileSync( source, 'utf8' ), { source, dir } ).trim() }
		${ nav }<p class="docs__edit"><a href="https://github.com/WordPress/presence-api/edit/main/${ path.relative( root, source ) }">Edit this page on GitHub</a></p>
	</main>
</body>
</html>
`;

	fs.mkdirSync( path.join( out, dir ), { recursive: true } );
	fs.writeFileSync( path.join( out, dir, 'index.html' ), html );
}
