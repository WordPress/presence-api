'use strict';

// Renders docs/ into build/showcase/docs/ in docs/manifest.json's order, the format developer.wordpress.org's handbooks sync from, with each Mermaid block drawn as a static SVG.

const fs = require( 'node:fs' );
const path = require( 'node:path' );
const MarkdownIt = require( 'markdown-it' );
const anchor = require( 'markdown-it-anchor' );
const GithubSlugger = require( 'github-slugger' ).default;
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

const pages = new Set( manifest.map( sourceOf ) );

// The showcase's own navigation, so the bar is the same on every page of the site.
const showcaseNav = fs
	.readFileSync( path.join( root, 'showcase/index.html' ), 'utf8' )
	.match( /\t<nav class="local-nav"[\s\S]*?<\/nav>/ )[ 0 ];
// Heading ids as GitHub makes them, so an anchor works on the site and on GitHub alike.
const md = new MarkdownIt( { html: true } ).use( anchor, {
	level: [ 2, 3 ],
	slugify: ( text ) => new GithubSlugger().slug( text ),
	uniqueSlugStartIndex: 1,
	tabIndex: false,
	permalink: anchor.permalink.linkInsideHeader( {
		class: 'docs__anchor',
		symbol: '#',
		placement: 'after',
		renderAttrs: () => ( { 'aria-label': 'Link to this section' } ),
	} ),
} );

// The sidebar's headings, each placed above the page that starts its group.
const GROUPS = {
	'rows-and-rooms': 'How it works',
	'admin-screens': 'Features',
	project: 'Next steps',
};
const REPO = 'https://github.com/WordPress/presence-api';

// The anchors GitHub gives the README's headings, so a link to one can be checked.
// GitHub's own #readme jumps to the README itself.
const readmeSlugger = new GithubSlugger();
const readmeAnchors = new Set( [ 'readme' ] );
for ( const [ , heading ] of fs
	.readFileSync( path.join( root, 'README.md' ), 'utf8' )
	.matchAll( /^#{1,6} (.+)$/gm ) ) {
	readmeAnchors.add( readmeSlugger.slug( heading ) );
}

// The public functions and hooks the generated reference lists, the only plugin names a page may use.
const documented = new Set(
	[
		...fs
			.readFileSync( path.join( docs, 'reference.md' ), 'utf8' )
			.matchAll( /^### `([\w.-]+?)(?:\(\))?`$/gm ),
	].map( ( [ , name ] ) => name )
);

// Points a link to another page at its built directory, and fails on one the manifest does not list.
md.renderer.rules.link_open = ( tokens, i, options, env, self ) => {
	const link = tokens[ i ].attrGet( 'href' );
	const fail = ( why ) => {
		throw new Error( `${ env.source } links to ${ link }, ${ why }.` );
	};
	if ( link?.startsWith( `${ REPO }/blob/main/` ) ) {
		const file = link
			.slice( `${ REPO }/blob/main/`.length )
			.split( '#' )[ 0 ];
		if ( ! fs.existsSync( path.join( root, file ) ) ) {
			fail( 'a file that does not exist' );
		}
	} else if ( link?.startsWith( `${ REPO }#` ) ) {
		if ( ! readmeAnchors.has( link.slice( REPO.length + 1 ) ) ) {
			fail( 'a README heading that does not exist' );
		}
	}
	if ( link && /^[^:#]+\.md(#.*)?$/.test( link ) ) {
		const [ file, hash = '' ] = link.split( '#' );
		const linked = path.resolve( path.dirname( env.source ), file );
		if ( ! pages.has( linked ) ) {
			throw new Error(
				`${ env.source } links to ${ file }, which docs/manifest.json does not list.`
			);
		}
		if ( hash ) {
			env.anchors.push( { page: linked, hash } );
		}
		tokens[ i ].attrSet(
			'href',
			href( env.dir, outputDir( linked ) ) + ( hash && `#${ hash }` )
		);
	}
	return self.renderToken( tokens, i, options );
};

// Shows a WordPress.org screenshot from the copy the site publishes, linked to its full size, and fails on one that does not exist.
const SCREENSHOTS = 'plugins/presence-api/.wordpress-org/';
md.renderer.rules.image = ( tokens, i, options, env, self ) => {
	const token = tokens[ i ];
	const file = path.relative(
		root,
		path.resolve( path.dirname( env.source ), token.attrGet( 'src' ) )
	);
	if (
		! file.startsWith( SCREENSHOTS ) ||
		! fs.existsSync( path.join( root, file ) )
	) {
		throw new Error(
			`${ env.source } shows ${ file }, which is not a WordPress.org screenshot in the repo.`
		);
	}
	token.attrSet(
		'src',
		`${ path.relative( env.dir, '.' ) }/images/${ path.basename( file ) }`
	);
	token.attrSet(
		'alt',
		self.renderInlineAsText( token.children, options, env )
	);
	token.attrSet( 'loading', 'lazy' );
	return `<a href="${ token.attrGet( 'src' ) }">${ self.renderToken(
		tokens,
		i,
		options
	) }</a>`;
};

// Keeps each heading's id for links to check, lists the h2s for the page's contents, and keeps the first paragraph as the page's description.
md.core.ruler.push( 'docs_meta', ( state ) => {
	state.tokens.forEach( ( token, i ) => {
		const inline = state.tokens[ i + 1 ];
		const id = token.type === 'heading_open' && token.attrGet( 'id' );
		if ( id ) {
			state.env.ids.push( id );
			if ( token.tag === 'h2' ) {
				state.env.headings.push( {
					id,
					html: escapeHtml( inline.content ).replace(
						/`([^`]+)`/g,
						'<code>$1</code>'
					),
				} );
			}
		}
		for ( const child of token.children || [] ) {
			const named =
				child.type === 'code_inline' &&
				child.content.match(
					/^(\w*presence\w*)\(|^(wp_presence_\w+|presence-api\.\w+)$/
				);
			const name = named && ( named[ 1 ] || named[ 2 ] );
			if (
				name &&
				! documented.has( name ) &&
				! state.env.source.endsWith( 'reference.md' )
			) {
				throw new Error(
					`${ state.env.source } names ${ name }, which docs/reference.md does not list.`
				);
			}
		}
		if ( token.type === 'paragraph_open' && ! state.env.description ) {
			state.env.description = inline.content
				.replace( /\[([^\]]+)\]\([^)]+\)/g, '$1' )
				.replace( /`/g, '' );
		}
	} );
} );

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
				edgeLabelBackground: '#ffffff',
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
		const env = {
			source,
			dir,
			diagrams: [],
			headings: [],
			ids: [],
			anchors: [],
		};
		const markdown = fs.readFileSync( source, 'utf8' );
		const body = md.render( markdown, env );
		// Minutes to read the prose at 200 words a minute, leaving out code and diagrams.
		const words = markdown
			.replace( /```[\s\S]*?```/g, '' )
			.split( /\s+/ )
			.filter( Boolean ).length;
		const offset = diagrams.length;
		diagrams.push( ...env.diagrams );
		return {
			entry,
			source,
			dir,
			description: env.description || '',
			headings: env.headings,
			ids: env.ids,
			anchors: env.anchors,
			minutes: Math.max( 1, Math.round( words / 200 ) ),
			body: body.replace(
				/<!--diagram-(\d+)-->/g,
				( match, n ) => `<!--diagram-${ offset + Number( n ) }-->`
			),
		};
	} );

	// Fails on a link to a heading another page no longer has.
	for ( const page of rendered ) {
		for ( const { page: linked, hash } of page.anchors ) {
			const target = rendered.find(
				( other ) => other.source === linked
			);
			if ( ! target.ids.includes( hash ) ) {
				throw new Error(
					`${ page.source } links to #${ hash }, which ${ path.relative(
						root,
						linked
					) } has no heading for.`
				);
			}
		}
	}

	const svgs = await drawDiagrams( diagrams );

	rendered.forEach(
		(
			{ entry, source, dir, description, minutes, headings, body },
			index
		) => {
			// The trail from the docs root to this page, as BreadcrumbList data for search engines.
			const trail = [];
			for (
				let crumb = entry;
				crumb;
				crumb = manifest.find( ( e ) => e.slug === crumb.parent )
			) {
				trail.unshift(
					rendered.find( ( page ) => page.entry === crumb )
				);
			}
			const structured = JSON.stringify( {
				'@context': 'https://schema.org',
				'@type': 'BreadcrumbList',
				itemListElement: trail.map( ( page, n ) => ( {
					'@type': 'ListItem',
					position: n + 1,
					name: page.entry.title,
					item: `${ SITE }${ page.dir }/`,
				} ) ),
			} );
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
			const sidebar = rendered
				.map( ( page ) => {
					const heading = GROUPS[ page.entry.slug ]
						? `</ul>\n\t\t\t<h2>${ GROUPS[ page.entry.slug ] }</h2>\n\t\t\t<ul>`
						: '';
					const current =
						page.entry === entry ? ' aria-current="page"' : '';
					return `${ heading }<li><a href="${ href(
						dir,
						page.dir
					) }"${ current }>${ escapeHtml(
						page.entry.parent ? page.entry.title : 'Overview'
					) }</a></li>`;
				} )
				.join( '\n\t\t\t\t' );
			// The page's own sections, left out where there are too few to need a guide.
			const toc =
				headings.length >= 3
					? `<nav class="docs-toc" aria-label="On this page">
		<h2>On this page</h2>
		<ul>
			${ headings
				.map(
					( heading ) =>
						`<li><a href="#${ heading.id }">${ heading.html }</a></li>`
				)
				.join( '\n\t\t\t' ) }
		</ul>
	</nav>
	`
					: '';
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
	<script type="application/ld+json">${ structured }</script>
	<link rel="icon" href="${ assets }/images/icon-256x256.png">
	<link rel="stylesheet" href="${ assets }/style.css">
	<link rel="stylesheet" href="${ assets }/docs.css">
	<script src="${ assets }/docs.js" defer></script>
</head>
<body>
	<a class="skip-link" href="#main">Skip to content</a>
${ showcaseNav
	.replace( 'href="#main"', `href="${ assets }/"` )
	.replace( 'href="docs/"', `href="${ href( dir, 'docs' ) }"` ) }
	<div class="docs-layout">
	<details class="docs-sidebar" open>
		<summary>Contents</summary>
		<nav aria-label="Docs">
			<ul>
				${ sidebar }
			</ul>
		</nav>
	</details>
	<main id="main" class="docs">
		${
			entry.parent
				? `<p class="docs__time">${ minutes } min read</p>\n\t\t`
				: ''
		}${ content.trim() }
			<nav class="docs__pager" aria-label="Previous and next">
${ pager }
			</nav>
			<p class="docs__edit"><a href="https://github.com/WordPress/presence-api/edit/main/${ path.relative(
				root,
				source
			) }">Edit this page on GitHub</a></p>
	</main>
	${ toc }</div>
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
						/\]\((?:\.\.\/)+(plugins\/presence-api\/\.wordpress-org\/[^)]+)\)/g,
						'](https://raw.githubusercontent.com/WordPress/presence-api/main/$1)'
					)
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
		}
	);

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
