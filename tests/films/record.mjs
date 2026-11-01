// Films each feature for docs/media/: the admin on top with the debugger pinned, the presence table's rows below.
//   npm run films [-- <shot> ...]
import { chromium } from '@playwright/test';
import { execFileSync, spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const BASE = process.env.WP_BASE_URL || 'http://localhost:8888';
const ROOT = path.resolve(
	path.dirname( new URL( import.meta.url ).pathname ),
	'../..'
);
const MEDIA = path.join( ROOT, 'docs/media' );
const WORK = path.join( ROOT, 'artifacts/films' );
const W = 960;

const wp = ( ...args ) =>
	execFileSync( 'npx', [ 'wp-env', 'run', 'cli', 'wp', ...args ], {
		stdio: 'pipe',
	} )
		.toString()
		.trim();
const php = ( code ) => wp( 'eval', code );
const sleep = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );

// Hides what is not part of any story.
const DECLUTTER = `
	#wp-admin-bar-my-account, #wp-admin-bar-updates, #wp-admin-bar-comments, #wp-admin-bar-new-content,
	#wp-admin-bar-command-palette, #screen-meta-links, .update-plugins, .notice:not(.wp-presence-stale-notice),
	#footer-thankyou, #footer-upgrade, #welcome-panel, .column-categories, .column-tags, .column-comments,
	.column-date, .column-author, .check-column, .tablenav, .subsubsub, .search-box, .wp-list-table tfoot,
	#wp-admin-bar-presence-debug-scene, #wp-admin-bar-presence-debug-scene-step { display: none !important; }
`;
const ACTIVE_POSTS = `#dashboard-widgets .postbox:not(#presence_active_posts), #dashboard-widgets .empty-container { display: none !important; }
	#dashboard-widgets-wrap { max-width: 440px; }`;
const NARROW_LIST = '#wpbody-content .wrap { max-width: 440px; }';

/**
 * Each shot opens `left` as the admin, shows the rows of rooms starting with `rooms`, runs `setup` before filming, and runs `play` while it films.
 */
const SHOTS = {
	'post-locks': {
		left: '/wp-admin/edit.php',
		rooms: 'postType/',
		css: NARROW_LIST,
		posts: [
			'Spring volunteer schedule',
			'Accessibility audit notes',
			'Office move checklist',
		],
		dbHeight: 270,
		play: async ( { scene, reloadWhenPost } ) => {
			const run = scene( 'coming-back' );
			await reloadWhenPost( 'Product launch plan' );
			await run;
		},
	},
	'admin-screens': {
		left: '/wp-admin/',
		rooms: '',
		css: ACTIVE_POSTS,
		adminHeight: 420,
		dbHeight: 380,
		play: ( { scene } ) => scene( 'editing-together' ),
	},
	// The Editors column names an agent only in a tooltip, so this films Active Posts, which names it in text.
	'ai-agents': {
		left: '/wp-admin/',
		rooms: 'postType/',
		css: ACTIVE_POSTS,
		posts: [ 'Accessibility audit notes' ],
		dbHeight: 160,
		setup: async ( { person } ) => ( {
			agent: await person( 'Writing Assistant', 'editor', {
				agent: true,
			} ),
			post: wp(
				'post',
				'list',
				'--title=Accessibility audit notes',
				'--post_status=any',
				'--format=ids'
			),
		} ),
		play: async ( { agent, post } ) => {
			wp(
				'post',
				'update',
				post,
				'--post_content=Added alt text findings for the gallery pages.',
				`--user=${ agent.id }`
			);
			await sleep( 14000 );
		},
	},
	'stale-screen-notices': {
		left: '/wp-admin/options-general.php',
		rooms: 'admin/online',
		css: '.form-table tr:nth-child(n+4), p.submit { display: none !important; } #wpbody-content .wrap { max-width: 440px; }',
		dbHeight: 160,
		setup: async ( { person } ) => {
			wp( 'option', 'update', 'blogdescription', '' );
			return { theo: await person( 'Theo Okafor', 'administrator' ) };
		},
		play: async ( { theo } ) => {
			await theo.page.goto( `${ BASE }/wp-admin/options-general.php` );
			await sleep( 5000 );
			await theo.page.fill(
				'#blogdescription',
				'Notes from the volunteer team'
			);
			await theo.page.click( '#submit' );
			await sleep( 12000 );
		},
	},
	// The block editor, so Classic Editor is off for the take; the pattern sits below the debugger's menu so its notice shows.
	'synced-patterns': {
		left: ( { post } ) => `/wp-admin/post.php?post=${ post }&action=edit`,
		rooms: 'postType/wp_block',
		focus: '.interface-interface-skeleton__footer',
		canvasCss:
			'.is-root-container { max-width: 400px !important; margin-left: 32px !important; }',
		adminHeight: 560,
		dbHeight: 150,
		setup: async ( { person } ) => {
			wp( 'plugin', 'deactivate', 'classic-editor' );
			const prefs = JSON.stringify( {
				core: {
					fullscreenMode: false,
					isComplementaryAreaVisible: false,
				},
				'core/edit-post': { welcomeGuide: false },
			} );
			const pattern =
				wp(
					'post',
					'list',
					'--post_type=wp_block',
					'--title=Event details',
					'--format=ids'
				) ||
				wp(
					'post',
					'create',
					'--post_type=wp_block',
					'--post_status=publish',
					'--post_title=Event details',
					'--post_content=<!-- wp:paragraph --><p>Doors open at 6pm in the community hall.</p><!-- /wp:paragraph -->',
					'--porcelain'
				);
			const post =
				wp(
					'post',
					'list',
					'--title=Fall open house',
					'--post_status=any',
					'--format=ids'
				) ||
				wp(
					'post',
					'create',
					'--post_status=draft',
					'--post_title=Fall open house',
					`--post_content=<!-- wp:paragraph --><p>Everyone is welcome.</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Here is what to know before you come.</p><!-- /wp:paragraph --><!-- wp:block {"ref":${ pattern }} /-->`,
					'--porcelain'
				);
			wp(
				'user',
				'meta',
				'update',
				'1',
				'wp_persisted_preferences',
				prefs,
				'--format=json'
			);
			const priya = await person( 'Priya Raman', 'editor', { prefs } );
			return { pattern, post, priya };
		},
		play: async ( { priya, pattern } ) => {
			await sleep( 2000 );
			await priya.page.goto(
				`${ BASE }/wp-admin/post.php?post=${ pattern }&action=edit`
			);
			await sleep( 14000 );
			await priya.page.close();
			await sleep( 6000 );
		},
		teardown: () => wp( 'plugin', 'activate', 'classic-editor' ),
	},
};

// Installs the dressing as a must-use plugin for the take, so no environment needs to know about films.
const dressing = fs
	.readFileSync( path.join( ROOT, 'tests/films/dressing.php' ) )
	.toString( 'base64' );
const dress = () =>
	php(
		`wp_mkdir_p( WPMU_PLUGIN_DIR ); file_put_contents( WPMU_PLUGIN_DIR . "/presence-films.php", base64_decode( "${ dressing }" ) );`
	);
const undress = () =>
	php( 'wp_delete_file( WPMU_PLUGIN_DIR . "/presence-films.php" );' );

async function film( name ) {
	const shot = SHOTS[ name ];
	const work = path.join( WORK, name );
	fs.rmSync( work, { recursive: true, force: true } );
	fs.mkdirSync( path.join( work, 'frames' ), { recursive: true } );

	wp( 'presence', 'scene', 'stop' );
	php( 'global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->presence}" );' );
	for ( const title of shot.posts || [] ) {
		if (
			! wp(
				'post',
				'list',
				`--title=${ title }`,
				'--post_status=any',
				'--format=ids'
			)
		) {
			wp(
				'post',
				'create',
				`--post_title=${ title }`,
				'--post_status=draft'
			);
		}
	}

	const ADMIN = shot.adminHeight || 440;
	const H = ADMIN + ( shot.dbHeight || 270 ) + 2;
	const browser = await chromium.launch();
	const people = [];

	const login = async ( context, user, password ) => {
		const page = await context.newPage();
		await page.goto( `${ BASE }/wp-login.php` );
		await page.fill( '#user_login', user );
		await page.fill( '#user_pass', password );
		await Promise.all( [
			page.waitForNavigation(),
			page.click( '#wp-submit' ),
		] );
		return page;
	};

	// Another person, in a browser of their own that is not filmed, removed when the take ends.
	const person = async (
		displayName,
		role,
		{ agent = false, prefs } = {}
	) => {
		const login_ = `films-${ displayName.toLowerCase().replace( /\W+/g, '-' ) }`;
		const id = wp(
			'user',
			'create',
			login_,
			`${ login_ }@example.org`,
			`--role=${ role }`,
			`--display_name=${ displayName }`,
			'--user_pass=password',
			'--porcelain'
		);
		people.push( id );
		if ( prefs ) {
			wp(
				'user',
				'meta',
				'update',
				id,
				'wp_persisted_preferences',
				prefs,
				'--format=json'
			);
		}
		if ( agent ) {
			wp( 'user', 'meta', 'update', id, '_presence_films_agent', '1' );
			return { id };
		}
		const context = await browser.newContext();
		return { id, page: await login( context, login_, 'password' ) };
	};

	try {
		const context = await browser.newContext( {
			viewport: { width: W, height: H },
			deviceScaleFactor: 2,
		} );
		await context.addInitScript( () =>
			localStorage.setItem( 'presence-debug-pinned', '1' )
		);
		const page = await login( context, 'admin', 'password' );
		const state = shot.setup ? await shot.setup( { person } ) : {};
		const left =
			typeof shot.left === 'function' ? shot.left( state ) : shot.left;

		// The DB viewer's nonce belongs to this login, so it comes from the debugger's own link.
		await page.goto( `${ BASE }/wp-admin/` );
		const db = await page.$eval( 'a.presence-debug-table', ( a ) => {
			const url = new URL( a.href );
			url.searchParams.delete( 'room' );
			return url.href;
		} );

		await page.route( `${ BASE }/__films`, ( route ) =>
			route.fulfill( {
				contentType: 'text/html',
				body: `<!doctype html><style>
					html, body { margin: 0; height: 100%; overflow: hidden; background: #1e1e1e; }
					body { display: flex; flex-direction: column; gap: 2px; }
					iframe { border: 0; width: 100%; background: #fff; }
					#admin { height: ${ ADMIN }px; } #db { flex: 1; }
				</style>
				<iframe id="admin" src="${ BASE }${ left }"></iframe>
				<iframe id="db" src="${ db }"></iframe>`,
			} )
		);
		await page.goto( `${ BASE }/__films` );
		const admin = await ( await page.$( '#admin' ) ).contentFrame();
		const viewer = await ( await page.$( '#db' ) ).contentFrame();
		await admin.waitForLoadState( 'load' );
		await viewer.waitForLoadState( 'load' );

		const dressAdmin = async () => {
			await admin.addStyleTag( {
				content: DECLUTTER + ( shot.css || '' ),
			} );
			// Folds the menu, and asks for a fresh answer every two seconds through the plugin's own action instead of waiting for a Heartbeat tick.
			await admin.evaluate( () => {
				document.body.classList.add( 'folded' );
				setInterval(
					() =>
						window.wp?.hooks?.doAction(
							'presence-api.roomChanged'
						),
					2000
				);
			} );
			// Gives the admin focus, or Heartbeat treats it as a tab in the background.
			await admin.click( shot.focus || '#wpbody-content h1' );
			if ( shot.canvasCss ) {
				const canvas = await (
					await admin.waitForSelector(
						'iframe[name="editor-canvas"]'
					)
				).contentFrame();
				await canvas.waitForSelector( '.is-root-container' );
				await canvas.addStyleTag( { content: shot.canvasCss } );
			}
			await page.mouse.move( W - 1, H - 1 );
		};
		await dressAdmin();

		await viewer.addStyleTag( {
			content:
				'body { font-size: 15px; padding: 8px 16px; } th { font-size: 12px; } td, th { padding: 6px 8px; }',
		} );
		// Polls every second instead of five, keeps the shot's rooms in a steady order, and fills in each row's age and expiry.
		await viewer.evaluate( ( rooms ) => {
			// Stops the viewer's own timers, which would repaint every room in write order between these refreshes.
			const last = setInterval( () => {} );
			for ( let id = 0; id <= last; id++ ) {
				clearInterval( id );
			}
			const refresh = () =>
				fetch( window.location.href, { cache: 'no-store' } )
					.then( ( response ) => response.text() )
					.then( ( html ) => {
						const next = new window.DOMParser().parseFromString(
							html,
							'text/html'
						);
						const rows = [
							...next.querySelectorAll( 'tr[data-ts]' ),
						]
							.filter( ( tr ) =>
								tr.cells[ 0 ].textContent.startsWith( rooms )
							)
							.sort( ( a, b ) =>
								(
									a.cells[ 0 ].textContent +
									a.cells[ 2 ].textContent
								).localeCompare(
									b.cells[ 0 ].textContent +
										b.cells[ 2 ].textContent
								)
							);
						const now = Date.now();
						for ( const tr of rows ) {
							const age = Math.max(
								0,
								Math.round( ( now - tr.dataset.ts ) / 1000 )
							);
							tr.querySelector( '.age' ).textContent =
								`${ age }s`;
							tr.querySelector( '.expires' ).textContent =
								`${ Math.round( ( tr.dataset.expires - now ) / 1000 ) }s`;
							tr.className = 'is-stale';
							if ( age < 30 ) {
								tr.className = age < 5 ? 'is-new' : 'is-fresh';
							}
						}
						if ( ! document.querySelector( 'tbody' ) ) {
							next.querySelectorAll( 'script' ).forEach( ( s ) =>
								s.remove()
							);
							document.body.replaceChildren(
								...next.body.childNodes
							);
						}
						document
							.querySelector( 'tbody' )
							?.replaceChildren( ...rows );
						const table = document.querySelector( 'table' );
						if ( table ) {
							table.style.display = rows.length ? '' : 'none';
						}
						document.querySelector( '.empty' ).style.display =
							rows.length ? 'none' : '';
					} )
					.catch( () => {} );
			refresh();
			setInterval( refresh, 1000 );
		}, shot.rooms );
		await page.mouse.move( W - 1, H - 1 );
		await page.waitForTimeout( 2500 );

		const cdp = await context.newCDPSession( page );
		const frames = [];
		cdp.on( 'Page.screencastFrame', ( { data, metadata, sessionId } ) => {
			const file = path.join(
				work,
				'frames',
				`${ String( frames.length ).padStart( 5, '0' ) }.jpg`
			);
			fs.writeFileSync( file, Buffer.from( data, 'base64' ) );
			frames.push( { file, t: metadata.timestamp } );
			cdp.send( 'Page.screencastFrameAck', { sessionId } ).catch(
				() => {}
			);
		} );
		await cdp.send( 'Page.startScreencast', {
			format: 'jpeg',
			quality: 92,
			maxWidth: W * 2,
			maxHeight: H * 2,
		} );
		await sleep( 2000 );

		await shot.play( {
			...state,
			scene: ( scene ) =>
				new Promise( ( resolve ) =>
					spawn(
						'npx',
						[
							'wp-env',
							'run',
							'cli',
							'wp',
							'presence',
							'scene',
							'run',
							scene,
						],
						{ stdio: 'ignore' }
					).on( 'exit', resolve )
				),
			// A list only shows a post that existed when it loaded, so it reloads once the post does.
			reloadWhenPost: async ( title ) => {
				while (
					! wp(
						'post',
						'list',
						`--title=${ title }`,
						'--post_status=any',
						'--format=ids'
					)
				) {
					await sleep( 200 );
				}
				await admin.goto( `${ BASE }${ left }` );
				await dressAdmin();
			},
		} );
		await sleep( 3000 );
		await cdp.send( 'Page.stopScreencast' );
		const end = Date.now() / 1000;

		// Each frame shows until the next arrives, so the film keeps the real timing.
		const list = frames
			.map(
				( frame, i ) =>
					`file '${ frame.file }'\nduration ${ ( ( frames[ i + 1 ]?.t ?? end ) - frame.t ).toFixed( 3 ) }`
			)
			.join( '\n' );
		fs.writeFileSync(
			path.join( work, 'frames.txt' ),
			`${ list }\nfile '${ frames.at( -1 ).file }'\n`
		);
		fs.mkdirSync( MEDIA, { recursive: true } );
		execFileSync( 'ffmpeg', [
			'-y',
			'-loglevel',
			'error',
			'-f',
			'concat',
			'-safe',
			'0',
			'-i',
			path.join( work, 'frames.txt' ),
			'-vf',
			'scale=1440:-2:flags=lanczos,fps=30,format=yuv420p',
			'-c:v',
			'libx264',
			'-preset',
			'slow',
			'-crf',
			'22',
			'-movflags',
			'+faststart',
			'-an',
			path.join( MEDIA, `${ name }.mp4` ),
		] );
		process.stdout.write(
			`${ name }: ${ frames.length } frames, docs/media/${ name }.mp4\n`
		);
	} finally {
		await browser.close();
		shot.teardown?.();
		for ( const id of people ) {
			wp( 'user', 'delete', id, '--yes', '--reassign=1' );
		}
	}
}

const names = process.argv.slice( 2 ).length
	? process.argv.slice( 2 )
	: Object.keys( SHOTS );
for ( const name of names ) {
	if ( ! SHOTS[ name ] ) {
		throw new Error(
			`Unknown shot ${ name }; one of ${ Object.keys( SHOTS ).join( ', ' ) }.`
		);
	}
}
// Without the built scripts the block editor pieces never load, and a film would show them doing nothing.
if (
	php( 'echo is_dir( WP_PRESENCE_PLUGIN_DIR . "build" ) ? 1 : 0;' ) !== '1'
) {
	throw new Error(
		'The plugin has no build/ directory; run npm run build first.'
	);
}
dress();
try {
	for ( const name of names ) {
		await film( name );
	}
} finally {
	undress();
}
