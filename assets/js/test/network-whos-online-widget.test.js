/**
 * Unit tests for the network Who's Online dashboard widget's focus handling.
 *
 * Each swap of #presence-network-widget-list would otherwise drop a keyboard
 * user back to the document body, so captureFocus()/restoreFocus() have to
 * find the equivalent spot in the new markup. These drive the real module
 * through heartbeat-tick events rather than reimplementing its logic.
 *
 * @package Presence_API
 */

require( '../avatar-stack' );

const I18N = {
	noUsersOnline: 'No users are currently online anywhere on the network.',
	notAggregated:
		'Presence is not aggregated across this network, so who is online cannot be shown.',
	sitesOnline: 'Sites with online users',
	moreSite: '+%d more site — view all',
	moreSites: '+%d more sites — view all',
};

function loadWidget( config ) {
	global.wp = { heartbeat: {} };
	window.wpPresenceNetworkWhosOnline = {
		i18n: I18N,
		viewAllUrl: '/wp-admin/network/sites.php',
		avatarMax: 3,
		...config,
	};

	let $;
	jest.isolateModules( () => {
		$ = require( 'jquery' );
		global.jQuery = $;
		require( '../network-whos-online-widget' );
	} );

	document.body.innerHTML =
		'<div id="presence-network-widget-list" aria-live="polite" tabindex="-1"></div>';

	return {
		$,
		container: document.getElementById( 'presence-network-widget-list' ),
	};
}

function tick( $, sites, overflow ) {
	$( document ).trigger( 'heartbeat-tick', [
		{
			'presence-network-widget': sites,
			'presence-network-widget-overflow': overflow || 0,
		},
	] );
}

describe( "Network Who's Online widget focus handling", () => {
	afterEach( () => {
		delete global.wp;
		delete global.jQuery;
		delete window.wpPresenceNetworkWhosOnline;
		document.body.innerHTML = '';
	} );

	it( "restores focus to the same site's link after a content-changing re-render", () => {
		const { $, container } = loadWidget();

		tick( $, [
			{
				blog_id: 1,
				url: 'https://a.example',
				domain: 'a.example',
				path: '/',
				user_count: 2,
				users: [],
			},
		] );
		container.querySelector( '[data-blog-id="1"] a' ).focus();

		tick( $, [
			{
				blog_id: 1,
				url: 'https://a.example',
				domain: 'a.example',
				path: '/',
				user_count: 3,
				users: [],
			},
		] );

		expect( document.activeElement ).toBe(
			container.querySelector( '[data-blog-id="1"] a' )
		);
	} );

	it( 'restores focus to the "more" link after a content-changing re-render', () => {
		const { $, container } = loadWidget();

		tick(
			$,
			[
				{
					blog_id: 1,
					url: 'https://a.example',
					domain: 'a.example',
					path: '/',
					user_count: 2,
					users: [],
				},
			],
			1
		);
		container.querySelector( '.presence-more-link' ).focus();

		tick(
			$,
			[
				{
					blog_id: 1,
					url: 'https://a.example',
					domain: 'a.example',
					path: '/',
					user_count: 3,
					users: [],
				},
			],
			1
		);

		expect( document.activeElement ).toBe(
			container.querySelector( '.presence-more-link' )
		);
	} );

	it( 'leaves focus alone when it is outside the widget', () => {
		const { $ } = loadWidget();
		const outside = document.createElement( 'button' );
		document.body.appendChild( outside );
		outside.focus();

		tick( $, [
			{
				blog_id: 1,
				url: 'https://a.example',
				domain: 'a.example',
				path: '/',
				user_count: 2,
				users: [],
			},
		] );

		expect( document.activeElement ).toBe( outside );
	} );

	it( 'falls back to the container when the focused site disappears', () => {
		const { $, container } = loadWidget();

		tick( $, [
			{
				blog_id: 1,
				url: 'https://a.example',
				domain: 'a.example',
				path: '/',
				user_count: 2,
				users: [],
			},
		] );
		container.querySelector( '[data-blog-id="1"] a' ).focus();

		tick( $, [
			{
				blog_id: 2,
				url: 'https://b.example',
				domain: 'b.example',
				path: '/',
				user_count: 1,
				users: [],
			},
		] );

		expect( document.activeElement ).toBe( container );
	} );
} );
