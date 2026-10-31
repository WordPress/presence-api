'use strict';

// Renders docs/ into build/showcase/docs/ in docs/manifest.json's order, the format developer.wordpress.org's handbooks sync from, with each Mermaid block drawn as a static SVG.

const fs = require( 'node:fs' );
const path = require( 'node:path' );
const MarkdownIt = require( 'markdown-it' );
const { chromium } = require( '@playwright/test' );

const SITE = 'https://wordpress.github.io/presence-api/';
const root = path.dirname( __dirname );
const docs = path.join( root, 'docs' );
const out = path.join( root, 'build', 'showcase' );
const manifest = JSON.parse(
	fs.readFileSync( path.join( docs, 'manifest.json' ), 'utf8' )
);

// docs/concepts/README.md becomes docs/concepts/, and docs/concepts/writers.md becomes docs/concepts/writers/.
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

const slugify = ( text ) =>
	text
		.toLowerCase()
		.replace( /`/g, '' )
		.replace( /[^a-z0-9]+/g, '-' )
		.replace( /^-|-$/g, '' );

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

// Gives each h2 and h3 an id to link to, and keeps the first paragraph as the page's description.
md.core.ruler.push( 'docs_meta', ( state ) => {
	const used = new Set();
	state.tokens.forEach( ( token, i ) => {
		const inline = state.tokens[ i + 1 ];
		if ( token.type === 'heading_open' && /^h[23]$/.test( token.tag ) ) {
			let id = slugify( inline.content );
			for ( let n = 2; used.has( id ); n++ ) {
				id = `${ slugify( inline.content ) }-${ n }`;
			}
			used.add( id );
			token.attrSet( 'id', id );
		}
		if ( token.type === 'paragraph_open' && ! state.env.description ) {
			state.env.description = inline.content
				.replace( /\[([^\]]+)\]\([^)]+\)/g, '$1' )
				.replace( /`/g, '' );
		}
	} );
} );

md.renderer.rules.heading_close = ( tokens, i, options, env, self ) => {
	const id = tokens[ i - 2 ].attrGet( 'id' );
	const anchor = id
		? `<a class="docs__anchor" href="#${ id }" aria-label="Link to this section">#</a>`
		: '';
	return anchor + self.renderToken( tokens, i, options );
};

// Leaves a placeholder for each diagram, drawn once every page is parsed.
const fence = md.renderer.rules.fence;
md.renderer.rules.fence = ( tokens, i, options, env, self ) => {
	const token = tokens[ i ];
	if ( token.info.trim() !== 'mermaid' ) {
		return fence( tokens, i, options, env, self );
	}
	if ( ! /^\s*accTitle:/m.test( token.content ) ) {
		throw new Error(
			`${ env.source } has a diagram without an accTitle, which screen readers announce.`
		);
	}
	env.diagrams.push( token.content );
	return `<!--diagram-${ env.diagrams.length - 1 }-->\n`;
};

async function drawDiagrams( diagrams ) {
	if ( ! diagrams.length ) {
		return [];
	}
	const font = fs
		.readFileSync( path.join( root, 'showcase/fonts/Inter-latin.woff2' ) )
		.toString( 'base64' );
	const browser = await chromium.launch();
	const page = await browser.newPage();
	// The page's own font, so the SVG's text is measured as it will be shown.
	await page.setContent(
		`<style>@font-face{font-family:Inter;src:url(data:font/woff2;base64,${ font })}body{font-family:Inter}</style><span>.</span>`
	);
	await page.addScriptTag( {
		path: require.resolve( 'mermaid/dist/mermaid.min.js' ),
	} );
	await page.evaluate( async () => {
		await document.fonts.ready;
		window.mermaid.initialize( {
			startOnLoad: false,
			securityLevel: 'strict',
			theme: 'base',
			fontFamily: 'Inter, sans-serif',
			flowchart: { htmlLabels: false, curve: 'basis' },
			themeVariables: {
				fontFamily: 'Inter, sans-serif',
				fontSize: '15px',
				primaryColor: '#eff2ff',
				primaryBorderColor: '#3858e9',
				primaryTextColor: '#1e1e1e',
				secondaryColor: '#f6f6f6',
				tertiaryColor: '#ffffff',
				lineColor: '#40464d',
				clusterBkg: '#f6f6f6',
				clusterBorder: '#d9d9d9',
				actorBkg: '#eff2ff',
				actorBorder: '#3858e9',
				noteBkgColor: '#f6f6f6',
				noteBorderColor: '#d9d9d9',
			},
		} );
	} );
	const svgs = [];
	for ( const [ n, code ] of diagrams.entries() ) {
		svgs.push(
			await page.evaluate(
				async ( [ id, text ] ) =>
					( await window.mermaid.render( id, text ) ).svg,
				[ `diagram-${ n }`, code ]
			)
		);
	}
	await browser.close();
	return svgs;
}

async function build() {
	const diagrams = [];
	const rendered = manifest.map( ( entry ) => {
		const source = sourceOf( entry );
		const dir = outputDir( source );
		const env = { source, dir, diagrams: [] };
		const body = md.render( fs.readFileSync( source, 'utf8' ), env );
		const offset = diagrams.length;
		diagrams.push( ...env.diagrams );
		return {
			entry,
			source,
			dir,
			description: env.description || '',
			body: body.replace(
				/<!--diagram-(\d+)-->/g,
				( match, n ) => `<!--diagram-${ offset + Number( n ) }-->`
			),
		};
	} );

	const svgs = await drawDiagrams( diagrams );

	rendered.forEach( ( { entry, source, dir, description, body }, index ) => {
		const pager = [
			[ rendered[ index - 1 ], 'Previous' ],
			[ rendered[ index + 1 ], 'Next' ],
		]
			.filter( ( [ page ] ) => page )
			.map(
				( [ page, label ] ) =>
					`<a href="${ href( dir, page.dir ) }"><span>${ label }</span> ${ escapeHtml(
						page.entry.title
					) }</a>`
			)
			.join( '\n' );
		const assets = path.relative( dir, '.' ) || '.';
		const url = `${ SITE }${ dir }/`;
		const content = body.replace(
			/<!--diagram-(\d+)-->/g,
			( match, n ) =>
				`<figure class="docs__diagram">${ svgs[ Number( n ) ] }</figure>`
		);
		const html = `<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>${ escapeHtml( entry.title ) } | Presence API</title>
	<meta name="description" content="${ escapeHtml( description ) }">
	<link rel="canonical" href="${ url }">
	<link rel="alternate" type="text/markdown" href="index.md">
	<meta property="og:type" content="article">
	<meta property="og:title" content="${ escapeHtml( entry.title ) }">
	<meta property="og:description" content="${ escapeHtml( description ) }">
	<meta property="og:url" content="${ url }">
	<meta property="og:image" content="${ SITE }og.png">
	<meta name="twitter:card" content="summary_large_image">
	<link rel="icon" href="${ assets }/images/icon-256x256.png">
	<link rel="stylesheet" href="${ assets }/style.css">
	<link rel="stylesheet" href="${ assets }/docs.css">
</head>
<body>
	<a class="skip-link" href="#main">Skip to content</a>
	<nav class="local-nav" aria-label="Presence API">
		<a class="local-nav__title" href="${ assets }/">Presence API</a>
		<ul>
			<li><a href="${ href( dir, 'docs' ) }">Docs</a></li>
			<li><a href="https://github.com/WordPress/presence-api">GitHub</a></li>
		</ul>
	</nav>
	<main id="main" class="docs">
			${ content.trim() }
			<nav class="docs__pager" aria-label="Previous and next">
${ pager }
			</nav>
			<p class="docs__edit"><a href="https://github.com/WordPress/presence-api/edit/main/${ path.relative(
				root,
				source
			) }">Edit this page on GitHub</a></p>
	</main>
</body>
</html>
`;
		fs.mkdirSync( path.join( out, dir ), { recursive: true } );
		fs.writeFileSync( path.join( out, dir, 'index.html' ), html );
		// The markdown copy links to the other copies by full URL, so it reads correctly wherever it is fetched from.
		fs.writeFileSync(
			path.join( out, dir, 'index.md' ),
			fs
				.readFileSync( source, 'utf8' )
				.replace(
					/\]\(([^):#]+)\.md(#[^)]*)?\)/g,
					( match, file, hash = '' ) =>
						`](${ SITE }${ outputDir(
							path.resolve(
								path.dirname( source ),
								`${ file }.md`
							)
						) }/index.md${ hash })`
				)
		);
	} );

	// An index for language models, in the llms.txt format, pointing at each page's markdown.
	const [ home, ...rest ] = rendered;
	const llms = [
		`# ${ home.entry.title }`,
		'',
		`> ${ home.description }`,
		'',
		'## Docs',
		'',
		...rest.map(
			( page ) =>
				`- [${ page.entry.title }](${ SITE }${ page.dir }/index.md): ${ page.description }`
		),
		'',
		'## Reference',
		'',
		'- [README](https://raw.githubusercontent.com/WordPress/presence-api/main/README.md): every function, hook, REST route and WP-CLI command',
		'',
	].join( '\n' );
	fs.writeFileSync( path.join( out, 'llms.txt' ), llms );
}

build().catch( ( error ) => {
	process.exitCode = 1;
	// eslint-disable-next-line no-console
	console.error( error );
} );
