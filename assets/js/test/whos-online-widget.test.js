/**
 * Unit tests for the Who's Online dashboard widget's focus handling.
 *
 * Each swap of #presence-whos-online-list would otherwise drop a keyboard
 * user back to the document body, so captureFocus()/restoreFocus() have to
 * find the equivalent spot in the new markup. These drive the real module
 * through heartbeat-tick events rather than reimplementing its logic.
 *
 * @package Presence_API
 */

require( '../avatar-stack' );

const I18N = {
	noUsersOnline: 'No users are online.',
	onlineNow: 'Online now',
	usersOnline: 'Users currently online',
	additionalUsers: 'Additional online users',
	showLess: 'Show less',
	moreCount: '+%d more',
	moreCountLink: '+%d more — view all users',
	secondsAgo: '%d seconds ago',
	minutesAgo: '%d min ago',
	hourAgo: '%d hour ago',
	hoursAgo: '%d hours ago',
};

function isoNow() {
	return new Date().toISOString().slice( 0, 19 );
}

function loadWidget( config ) {
	global.wp = { heartbeat: {} };
	window.wpPresenceWhosOnline = {
		screenLabels: {},
		screenUrls: { dashboard: '/wp-admin/', posts: '/wp-admin/edit.php' },
		idleThreshold: 300,
		overflowThreshold: 10,
		usersUrl: '',
		avatarMax: 3,
		maxRows: 5,
		i18n: I18N,
		...config,
	};

	let $;
	jest.isolateModules( () => {
		$ = require( 'jquery' );
		global.jQuery = $;
		require( '../whos-online-widget' );
	} );

	document.body.innerHTML =
		'<div id="presence-whos-online-list" aria-live="polite" tabindex="-1"></div>';

	return {
		$,
		container: document.getElementById( 'presence-whos-online-list' ),
	};
}

function tick( $, entries ) {
	$( document ).trigger( 'heartbeat-tick', [
		{ 'presence-online': entries },
	] );
}

describe( "Who's Online widget focus handling", () => {
	beforeEach( () => {
		jest.useFakeTimers();
	} );

	afterEach( () => {
		jest.clearAllTimers();
		jest.useRealTimers();
		delete global.wp;
		delete global.jQuery;
		delete window.wpPresenceWhosOnline;
		document.body.innerHTML = '';
	} );

	it( "restores focus to the same user's row after a content-changing re-render", () => {
		const { $, container } = loadWidget();

		tick( $, [
			{
				user_id: 1,
				display_name: 'Alice',
				screen: 'dashboard',
				date_gmt: isoNow(),
			},
		] );
		container.querySelector( '[data-user-id="1"] a' ).focus();

		tick( $, [
			{
				user_id: 1,
				display_name: 'Alice',
				screen: 'posts',
				date_gmt: isoNow(),
			},
		] );

		expect( container.contains( document.activeElement ) ).toBe( true );
		expect( document.activeElement.getAttribute( 'href' ) ).toBe(
			'/wp-admin/edit.php'
		);
	} );

	it( 'restores focus to the overflow toggle after a content-changing re-render', () => {
		const { $, container } = loadWidget( { maxRows: 1 } );

		tick( $, [
			{
				user_id: 1,
				display_name: 'Alice',
				screen: 'dashboard',
				date_gmt: isoNow(),
			},
			{
				user_id: 2,
				display_name: 'Bob',
				screen: 'dashboard',
				date_gmt: isoNow(),
			},
		] );
		container.querySelector( '[data-action="expand"]' ).focus();

		tick( $, [
			{
				user_id: 1,
				display_name: 'Alice',
				screen: 'posts',
				date_gmt: isoNow(),
			},
			{
				user_id: 2,
				display_name: 'Bob',
				screen: 'dashboard',
				date_gmt: isoNow(),
			},
		] );

		expect( document.activeElement ).toBe(
			container.querySelector( '[data-action="expand"]' )
		);
	} );

	it( 'leaves focus alone when it is outside the widget', () => {
		const { $ } = loadWidget();
		const outside = document.createElement( 'button' );
		document.body.appendChild( outside );
		outside.focus();

		tick( $, [
			{
				user_id: 1,
				display_name: 'Alice',
				screen: 'dashboard',
				date_gmt: isoNow(),
			},
		] );

		expect( document.activeElement ).toBe( outside );
	} );

	it( 'falls back to the container when the focused row disappears', () => {
		const { $, container } = loadWidget();

		tick( $, [
			{
				user_id: 1,
				display_name: 'Alice',
				screen: 'dashboard',
				date_gmt: isoNow(),
			},
		] );
		container.querySelector( '[data-user-id="1"] a' ).focus();

		tick( $, [
			{
				user_id: 2,
				display_name: 'Bob',
				screen: 'dashboard',
				date_gmt: isoNow(),
			},
		] );

		expect( document.activeElement ).toBe( container );
	} );
} );
