/* eslint-disable import/no-extraneous-dependencies */
/**
 * Unit tests for the Heartbeat debugger admin bar i18n and pluralization.
 *
 * @package Presence_API
 */

const i18n = require( '@wordpress/i18n' );

describe( 'debugger admin bar i18n', () => {
	const domain = 'presence-api';

	afterEach( () => {
		// Reset locale data for the domain to English defaults.
		i18n.resetLocaleData( undefined, domain );
	} );

	function formatSeconds( count ) {
		return i18n.sprintf(
			/* translators: %s: Number of seconds. */
			i18n._n( '%ss', '%ss', count, 'presence-api' ),
			count
		);
	}

	describe( 'English defaults', () => {
		it( 'renders singular and plural values correctly', () => {
			expect( formatSeconds( 1 ) ).toBe( '1s' );
			expect( formatSeconds( 0 ) ).toBe( '0s' );
			expect( formatSeconds( 2 ) ).toBe( '2s' );
			expect( formatSeconds( 15 ) ).toBe( '15s' );
			expect( formatSeconds( 120 ) ).toBe( '120s' );
		} );
	} );

	describe( 'Multi-plural locales (more than 2 plural forms)', () => {
		it( 'supports Russian plural forms without using count === 1', () => {
			i18n.setLocaleData(
				{
					'': {
						domain,
						lang: 'ru',
						plural_forms:
							'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);',
					},
					'%ss': [ '%s с', '%s с.', '%s сек.' ],
				},
				domain
			);

			expect( formatSeconds( 1 ) ).toBe( '1 с' );
			expect( formatSeconds( 21 ) ).toBe( '21 с' );
			expect( formatSeconds( 2 ) ).toBe( '2 с.' );
			expect( formatSeconds( 4 ) ).toBe( '4 с.' );
			expect( formatSeconds( 22 ) ).toBe( '22 с.' );
			expect( formatSeconds( 5 ) ).toBe( '5 сек.' );
			expect( formatSeconds( 11 ) ).toBe( '11 сек.' );
			expect( formatSeconds( 0 ) ).toBe( '0 сек.' );
		} );

		it( 'supports Polish plural forms without using count === 1', () => {
			i18n.setLocaleData(
				{
					'': {
						domain,
						lang: 'pl',
						plural_forms:
							'nplurals=3; plural=(n==1 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);',
					},
					'%ss': [ '%s sekunda', '%s sekundy', '%s sekund' ],
				},
				domain
			);

			expect( formatSeconds( 1 ) ).toBe( '1 sekunda' );
			expect( formatSeconds( 2 ) ).toBe( '2 sekundy' );
			expect( formatSeconds( 3 ) ).toBe( '3 sekundy' );
			expect( formatSeconds( 4 ) ).toBe( '4 sekundy' );
			expect( formatSeconds( 22 ) ).toBe( '22 sekundy' );
			expect( formatSeconds( 0 ) ).toBe( '0 sekund' );
			expect( formatSeconds( 5 ) ).toBe( '5 sekund' );
			expect( formatSeconds( 12 ) ).toBe( '12 sekund' );
		} );
	} );

	describe( 'DOM updates with formatSeconds', () => {
		let container;

		beforeEach( () => {
			container = document.createElement( 'div' );
			container.id = 'wp-admin-bar-presence-debug';
			container.innerHTML = `
				<span class="presence-debug-countdown"></span>
				<div class="presence-debug-row">
					<span class="presence-debug-value" data-presence-debug="interval"></span>
				</div>
				<div class="presence-debug-row">
					<span class="presence-debug-value" data-presence-debug="ttl" data-presence-debug-seconds="150"></span>
				</div>
				<div class="presence-debug-row">
					<span class="presence-debug-value" data-presence-debug-age="5"></span>
				</div>
			`;
			document.body.appendChild( container );
		} );

		afterEach( () => {
			container.remove();
		} );

		it( 'updates countdown, interval, ttl, and client age correctly in English', () => {
			const countdown = container.querySelector(
				'.presence-debug-countdown'
			);
			const interval = container.querySelector(
				'[data-presence-debug="interval"]'
			);
			const ttl = container.querySelector(
				'[data-presence-debug-seconds]'
			);
			const age = container.querySelector( '[data-presence-debug-age]' );

			countdown.textContent = formatSeconds( 1 );
			expect( countdown.textContent ).toBe( '1s' );

			countdown.textContent = formatSeconds( 15 );
			expect( countdown.textContent ).toBe( '15s' );

			interval.textContent = formatSeconds( 15 );
			expect( interval.textContent ).toBe( '15s' );

			ttl.textContent = formatSeconds(
				+ttl.dataset.presenceDebugSeconds
			);
			expect( ttl.textContent ).toBe( '150s' );

			age.textContent = formatSeconds( +age.dataset.presenceDebugAge );
			expect( age.textContent ).toBe( '5s' );
		} );

		it( 'updates countdown, interval, ttl, and client age correctly in translated locale', () => {
			i18n.setLocaleData(
				{
					'': {
						domain,
						lang: 'ru',
						plural_forms:
							'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);',
					},
					'%ss': [ '%s с', '%s с.', '%s сек.' ],
				},
				domain
			);

			const countdown = container.querySelector(
				'.presence-debug-countdown'
			);
			const age = container.querySelector( '[data-presence-debug-age]' );

			countdown.textContent = formatSeconds( 1 );
			expect( countdown.textContent ).toBe( '1 с' );

			countdown.textContent = formatSeconds( 2 );
			expect( countdown.textContent ).toBe( '2 с.' );

			countdown.textContent = formatSeconds( 15 );
			expect( countdown.textContent ).toBe( '15 сек.' );

			age.textContent = formatSeconds( 22 );
			expect( age.textContent ).toBe( '22 с.' );
		} );
	} );
} );
