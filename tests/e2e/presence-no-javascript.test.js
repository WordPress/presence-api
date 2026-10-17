/**
 * Presence API — No JavaScript E2E Tests
 *
 * Loads each screen the plugin touches with JavaScript off, where presence comes only from the server-side write on each page load (#823).
 *
 * Run from plugin root:
 *   npx playwright test --config tests/e2e/playwright.config.js tests/e2e/presence-no-javascript.test.js
 *
 * @package WordPress
 */
import { test as base, expect } from '@wordpress/e2e-test-utils-playwright';
import { execSync } from 'node:child_process';

const BASE_URL = ( process.env.WP_BASE_URL || 'http://localhost:8888' ).replace(
	/\/$/,
	''
);

const TEST_USERS = [
	{
		username: 'presencetestnojs',
		email: 'presencetestnojs@example.com',
		firstName: 'NoJS',
		lastName: 'Editor',
		password: 'password',
		roles: [ 'editor' ],
	},
];

const test = base.extend( {
	testUsers: [
		async ( { requestUtils }, use ) => {
			const users = [];
			for ( const user of TEST_USERS ) {
				const { id } = await requestUtils.createUser( user );
				users.push( { ...user, id } );
			}
			await use( users );
			await requestUtils.deleteAllUsers();
		},
		{ scope: 'test' },
	],
} );

function wpEval( phpExpression ) {
	execSync(
		`npx wp-env run cli wp eval ${ JSON.stringify( phpExpression ) }`,
		{ stdio: 'pipe', timeout: 30_000 }
	);
}

/**
 * Logs a user in from a second context that also has JavaScript off.
 *
 * @param {import('@playwright/test').Browser}   browser Browser to open the context in.
 * @param {{username: string, password: string}} user    User to log in.
 * @return {Promise<import('@playwright/test').BrowserContext>} The logged-in context.
 */
async function loginWithoutJavaScript( browser, user ) {
	const context = await browser.newContext( {
		baseURL: BASE_URL,
		javaScriptEnabled: false,
	} );

	await context.request.post( `${ BASE_URL }/wp-login.php`, {
		form: {
			log: user.username,
			pwd: user.password,
			'wp-submit': 'Log In',
			testcookie: '1',
		},
	} );

	return context;
}

test.describe( 'Presence without JavaScript', () => {
	test.use( { javaScriptEnabled: false } );

	test.afterEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
	} );

	test( 'the admin bar, Editors column, Active Posts widget and Online view show who is here', async ( {
		admin,
		page,
		requestUtils,
		testUsers,
	} ) => {
		const [ editor ] = testUsers;
		const post = await requestUtils.createPost( {
			title: 'No JavaScript Post',
			status: 'draft',
		} );
		wpEval(
			`wp_set_presence( wp_presence_admin_room(), 'user-${ editor.id }', array( 'screen' => 'post', 'title' => 'Edit Post' ), array( 'user_id' => ${ editor.id } ) );` +
				`wp_set_presence( 'postType/post:${ post.id }', 'editor-${ editor.id }', array( 'action' => 'editing', 'screen' => 'post' ), array( 'user_id' => ${ editor.id } ) );`
		);

		await admin.visitAdminPage( 'edit.php' );
		const bar = page.locator( '#wp-admin-bar-presence-online' );

		// Core's admin bar opens its menus on CSS hover when there is no script.
		await bar.hover();
		await expect( bar.locator( '.ab-sub-wrapper' ).first() ).toBeVisible();
		await expect( bar ).toContainText( 'NoJS Editor' );

		await expect(
			page.locator( `#post-${ post.id } .column-presence_editors img` )
		).toHaveAttribute( 'alt', 'NoJS Editor' );

		await admin.visitAdminPage( '/' );
		await expect( page.locator( '#presence_active_posts' ) ).toContainText(
			'No JavaScript Post'
		);

		await admin.visitAdminPage( 'users.php' );
		await page.locator( '.subsubsub .presence_online a' ).click();
		await expect( page.locator( '#the-list' ) ).toContainText(
			editor.username
		);
	} );

	test( 'a post lock stops a second editor', async ( {
		admin,
		browser,
		page,
		requestUtils,
		testUsers,
	} ) => {
		const post = await requestUtils.createPost( {
			title: 'No JavaScript Locked Post',
			status: 'draft',
		} );

		// wp-env loads the Classic Editor plugin, so this is core's classic form, which takes the lock on load.
		await admin.visitAdminPage(
			'post.php',
			`post=${ post.id }&action=edit`
		);
		await expect( page.locator( '#title' ) ).toHaveValue(
			'No JavaScript Locked Post'
		);

		const context = await loginWithoutJavaScript( browser, testUsers[ 0 ] );
		try {
			const second = await context.newPage();
			await second.goto(
				`/wp-admin/post.php?post=${ post.id }&action=edit`
			);

			await expect(
				second.locator( '#post-lock-dialog .notification-dialog' )
			).toBeVisible();
			await expect( second.locator( '#post-lock-dialog' ) ).toContainText(
				'admin is currently editing this post'
			);
		} finally {
			await context.close();
		}
	} );

	test( 'the recording switch on Settings > General saves', async ( {
		admin,
		page,
	} ) => {
		const recording = page.locator( '#wp_presence_recording' );
		const save = page.getByRole( 'button', { name: 'Save Changes' } );

		await admin.visitAdminPage( 'options-general.php' );
		await expect( recording ).toBeChecked();

		try {
			await recording.uncheck();
			await save.click();

			await expect( recording ).not.toBeChecked();
		} finally {
			// Other specs need recording on, so it goes back on however this one ends.
			await admin.visitAdminPage( 'options-general.php' );
			await recording.check();
			await save.click();
			await expect( recording ).toBeChecked();
		}
	} );

	test( 'a feature switch on Settings > Presence API saves', async ( {
		admin,
		page,
	} ) => {
		const postLocks = page.locator( '#wp_presence_features_post-locks' );
		const save = page.getByRole( 'button', { name: 'Save Changes' } );

		await admin.visitAdminPage(
			'options-general.php',
			'page=presence-api'
		);
		await expect( postLocks ).toBeChecked();

		try {
			await postLocks.uncheck();
			await save.click();

			await expect( postLocks ).not.toBeChecked();
		} finally {
			// Other specs rely on post locks, so the switch goes back on however this one ends.
			await admin.visitAdminPage(
				'options-general.php',
				'page=presence-api'
			);
			await postLocks.check();
			await save.click();
			await expect( postLocks ).toBeChecked();
		}
	} );
} );
