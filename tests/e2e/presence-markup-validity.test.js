/**
 * Presence API — Markup Validity E2E Tests
 *
 * Runs the markup the plugin adds to each screen through html-validate's
 * standards rules. Most of it is built as strings in PHP, such as the admin
 * bar menu, the Editors column's avatar stack and the widgets, so a mistake
 * there shows up in no lint (#824).
 *
 * Only the plugin's own fragments are checked. Whole pages also carry core's
 * markup, which the plugin can't fix, such as the admin bar's role="group"
 * rows and list tables' colspans.
 *
 * Run from plugin root:
 *   npx playwright test --config tests/e2e/playwright.config.js tests/e2e/presence-markup-validity.test.js
 *
 * @package WordPress
 */
import { test as base, expect } from '@wordpress/e2e-test-utils-playwright';
import { HtmlValidate } from 'html-validate';
import { execSync } from 'node:child_process';

const TEST_USERS = [
	{
		username: 'presencetestmarkupa',
		email: 'presencetestmarkupa@example.com',
		firstName: 'Markup',
		lastName: 'A',
		password: 'password',
		roles: [ 'editor' ],
	},
	{
		username: 'presencetestmarkupb',
		email: 'presencetestmarkupb@example.com',
		firstName: 'Markup',
		lastName: 'B',
		password: 'password',
		roles: [ 'editor' ],
	},
];

// The standards preset, plus the two conformance errors the Nu Html Checker
// raises that the preset leaves to its accessibility rules.
const htmlValidate = new HtmlValidate( {
	extends: [ 'html-validate:standard' ],
	rules: {
		'aria-label-misuse': 'error',
		'wcag/h37': 'error',
	},
} );

const test = base.extend( {
	testUsers: [
		async ( { requestUtils }, use ) => {
			const users = [];
			for ( const user of TEST_USERS ) {
				users.push( await requestUtils.createUser( user ) );
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
 * Seeds each user as online in the admin room and editing the post.
 *
 * @param {Array<{id: number}>} users  Users to seed.
 * @param {number}              postId Post they are editing.
 */
function seedEditors( users, postId ) {
	for ( const { id } of users ) {
		wpEval(
			`wp_set_presence( wp_presence_admin_room(), 'user-${ id }', array( 'screen' => 'post', 'title' => 'Edit Post' ), array( 'user_id' => ${ id } ) );` +
				`wp_set_presence( 'postType/post:${ postId }', 'editor-${ id }', array( 'action' => 'editing', 'screen' => 'post' ), array( 'user_id' => ${ id } ) );`
		);
	}
}

/**
 * Validates every element matching a selector on its own.
 *
 * @param {import('@playwright/test').Page} page     Page to read.
 * @param {string}                          selector Plugin-owned fragment.
 * @return {Promise<string[]>} One line per problem, empty when the markup is valid.
 */
async function markupErrors( page, selector ) {
	const fragments = await page
		.locator( selector )
		.evaluateAll( ( elements ) => elements.map( ( el ) => el.outerHTML ) );

	if ( ! fragments.length ) {
		return [ `${ selector } is not on the page` ];
	}

	const errors = [];
	for ( const fragment of fragments ) {
		const report = await htmlValidate.validateString( fragment );
		for ( const result of report.results ) {
			for ( const message of result.messages ) {
				errors.push(
					`${ selector }: ${ message.ruleId }: ${ message.message } in ${ fragment }`
				);
			}
		}
	}

	return errors;
}

test.describe( 'Presence Markup Validity', () => {
	test.afterEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
	} );

	test( 'the admin bar menu, Editors column and Active Posts widget are valid', async ( {
		admin,
		page,
		requestUtils,
		testUsers,
	} ) => {
		const post = await requestUtils.createPost( {
			title: 'Markup Validity Post',
			status: 'draft',
		} );
		seedEditors( testUsers, post.id );

		await admin.visitAdminPage( 'edit.php' );
		await expect(
			page.locator( '#wp-admin-bar-presence-online' )
		).toContainText( 'Markup A' );
		await expect(
			page.locator( `#post-${ post.id } .column-presence_editors img` )
		).toHaveCount( testUsers.length );
		expect(
			await markupErrors( page, '#wp-admin-bar-presence-online' )
		).toEqual( [] );
		expect(
			await markupErrors(
				page,
				`#post-${ post.id } .column-presence_editors`
			)
		).toEqual( [] );

		await admin.visitAdminPage( '/' );
		await expect( page.locator( '#presence_active_posts' ) ).toContainText(
			'Markup Validity Post'
		);
		expect( await markupErrors( page, '#presence_active_posts' ) ).toEqual(
			[]
		);
	} );

	test( 'the Online view on Users is valid', async ( {
		admin,
		page,
		requestUtils,
		testUsers,
	} ) => {
		const post = await requestUtils.createPost( {
			title: 'Markup Validity Users Post',
			status: 'draft',
		} );
		seedEditors( testUsers, post.id );

		await admin.visitAdminPage( 'users.php' );
		expect(
			await markupErrors( page, '.subsubsub .presence_online' )
		).toEqual( [] );

		await page.locator( '.subsubsub .presence_online a' ).click();
		expect(
			await markupErrors( page, '.subsubsub .presence_online' )
		).toEqual( [] );
	} );

	test( 'the feature settings page is valid', async ( { admin, page } ) => {
		await admin.visitAdminPage(
			'options-general.php',
			'page=presence-api'
		);
		expect( await markupErrors( page, '#wpbody-content .wrap' ) ).toEqual(
			[]
		);
	} );

	test( 'the stale-screen notice is valid', async ( {
		admin,
		page,
		testUsers,
	} ) => {
		await admin.visitAdminPage( 'options-general.php' );
		await page.waitForFunction(
			() =>
				typeof wp !== 'undefined' &&
				wp.heartbeat &&
				wp.heartbeat.connectNow
		);

		wpEval(
			`wp_presence_bump_screen_revision( 'options/general', ${ testUsers[ 0 ].id } );`
		);

		const notice = page.locator( '.wp-presence-stale-notice' );
		await expect
			.poll(
				async () => {
					await page.evaluate( () => wp.heartbeat.connectNow() );
					return notice.count();
				},
				{ timeout: 30_000 }
			)
			.toBe( 1 );

		expect(
			await markupErrors( page, '.wp-presence-stale-notice' )
		).toEqual( [] );
	} );
} );
