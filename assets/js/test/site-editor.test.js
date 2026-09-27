/**
 * Unit tests for the Site Editor's editor ping in presence-ping.js.
 *
 * @package Presence_API
 */

let listeners;

/**
 * Loads presence-ping.js on the Site Editor with the given open entity.
 *
 * @param {string}        search   The page's query string.
 * @param {number|string} id       The ID core/editor reports.
 * @param {Object}        [record] The entity record core returns for that ID.
 */
function loadSiteEditor( search, id, record ) {
	listeners = {};
	const $ = ( subject ) => {
		if ( typeof subject === 'function' ) {
			subject();
			return;
		}
		return {
			on( eventName, handler ) {
				( listeners[ eventName ] = listeners[ eventName ] || [] ).push(
					handler
				);
			},
		};
	};
	const stores = {
		'core/editor': {
			getCurrentPostType: () => 'wp_template',
			getCurrentPostId: () => id,
		},
		core: { getEntityRecord: () => record },
	};

	global.wp = {
		hooks: { applyFilters: ( name, value ) => value, doAction() {} },
		heartbeat: { interval: () => 15, connectNow() {} },
		data: { select: ( store ) => stores[ store ] },
	};
	window.history.replaceState(
		null,
		'',
		'/wp-admin/site-editor.php' + search
	);
	window.pagenow = 'site-editor';
	window.wpPresenceConfig = {};
	window.wpPresenceCreateTabCoordinator = () => ( { isLeader: () => true } );
	global.jQuery = $;

	jest.isolateModules( () => require( '../presence-ping' ) );
}

function editorPing() {
	const data = {};
	listeners[ 'heartbeat-send' ].forEach( ( handler ) =>
		handler( { type: 'heartbeat-send' }, data )
	);
	return data[ 'presence-editor-ping' ];
}

describe( 'Site Editor editor ping', () => {
	afterEach( () => {
		delete global.wp;
		delete global.jQuery;
	} );

	it( 'joins a saved template by its post ID', () => {
		loadSiteEditor(
			'?p=%2Fwp_template%2Ftheme%2F%2Fhome&canvas=edit',
			'theme//home',
			{ wp_id: 42 }
		);

		expect( editorPing() ).toEqual( { post_id: 42 } );
	} );

	it( 'stays out of any room while browsing, before the canvas opens', () => {
		loadSiteEditor( '?p=%2Fwp_template', 7 );

		expect( editorPing() ).toBeUndefined();
	} );
} );
