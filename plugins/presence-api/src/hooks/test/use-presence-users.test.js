/**
 * External dependencies
 */
import { renderHook, waitFor } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import usePresenceUsers from '../use-presence-users';

jest.mock( '@wordpress/api-fetch' );
jest.mock( '@wordpress/data', () => ( {
	useSelect: jest.fn( ( mapSelect ) => {
		const select = () => ( {
			getCurrentUser: () => ( { id: 1 } ),
		} );
		return mapSelect( select );
	} ),
} ) );

let heartbeatTickCallback = null;

jest.mock( '../../utils/heartbeat-events', () => ( {
	onHeartbeatTick: jest.fn( ( callback ) => {
		heartbeatTickCallback = callback;
		return () => {
			heartbeatTickCallback = null;
		};
	} ),
	isHeartbeatAvailable: jest.fn( () => true ),
} ) );

describe( 'usePresenceUsers', () => {
	beforeEach( () => {
		global.window = {
			wp: {
				heartbeat: {},
			},
		};

		global.process = {
			env: {
				NODE_ENV: 'test',
			},
		};

		heartbeatTickCallback = null;
		apiFetch.mockClear();
	} );

	afterEach( () => {
		jest.clearAllMocks();
	} );

	it( 'should return empty state when room is null', () => {
		const { result } = renderHook( () => usePresenceUsers( null ) );

		expect( result.current.isPresent ).toBe( false );
		expect( result.current.isLoading ).toBe( false );
		expect( result.current.users ).toEqual( [] );
		expect( result.current.error ).toBe( null );
	} );

	it( 'should fetch presence data on mount', async () => {
		const mockData = [
			{
				user_id: 2,
				display_name: 'Alice',
				avatar_url: 'https://example.com/alice.jpg',
			},
			{
				user_id: 3,
				display_name: 'Bob',
				avatar_url: 'https://example.com/bob.jpg',
			},
		];

		apiFetch.mockResolvedValueOnce( mockData );

		const { result } = renderHook( () =>
			usePresenceUsers( 'postType/post:123' )
		);

		expect( result.current.isLoading ).toBe( true );

		await waitFor( () => {
			expect( result.current.isLoading ).toBe( false );
		} );

		expect( result.current.isPresent ).toBe( true );
		expect( result.current.users ).toHaveLength( 2 );
		expect( result.current.users[ 0 ] ).toEqual( {
			id: 2,
			displayName: 'Alice',
			avatarUrl: 'https://example.com/alice.jpg',
		} );
	} );

	it( 'should exclude current user by default', async () => {
		const mockData = [
			{
				user_id: 1,
				display_name: 'Current User',
				avatar_url: 'https://example.com/me.jpg',
			},
			{
				user_id: 2,
				display_name: 'Alice',
				avatar_url: 'https://example.com/alice.jpg',
			},
		];

		apiFetch.mockResolvedValueOnce( mockData );

		const { result } = renderHook( () =>
			usePresenceUsers( 'postType/post:123' )
		);

		await waitFor( () => {
			expect( result.current.isLoading ).toBe( false );
		} );

		expect( result.current.users ).toHaveLength( 1 );
		expect( result.current.users[ 0 ].id ).toBe( 2 );
	} );

	it( 'should include current user when includeSelf is true', async () => {
		const mockData = [
			{
				user_id: 1,
				display_name: 'Current User',
				avatar_url: 'https://example.com/me.jpg',
			},
			{
				user_id: 2,
				display_name: 'Alice',
				avatar_url: 'https://example.com/alice.jpg',
			},
		];

		apiFetch.mockResolvedValueOnce( mockData );

		const { result } = renderHook( () =>
			usePresenceUsers( 'postType/post:123', { includeSelf: true } )
		);

		await waitFor( () => {
			expect( result.current.isLoading ).toBe( false );
		} );

		expect( result.current.users ).toHaveLength( 2 );
	} );

	it( 'should deduplicate users by ID', async () => {
		const mockData = [
			{
				user_id: 2,
				display_name: 'Alice',
				avatar_url: 'https://example.com/alice.jpg',
			},
			{
				user_id: 2,
				display_name: 'Alice Duplicate',
				avatar_url: 'https://example.com/alice2.jpg',
			},
		];

		apiFetch.mockResolvedValueOnce( mockData );

		const { result } = renderHook( () =>
			usePresenceUsers( 'postType/post:123' )
		);

		await waitFor( () => {
			expect( result.current.isLoading ).toBe( false );
		} );

		expect( result.current.users ).toHaveLength( 1 );
		expect( result.current.users[ 0 ].displayName ).toBe( 'Alice' );
	} );

	it( 'should handle API errors gracefully', async () => {
		const mockError = new Error( 'Network error' );
		apiFetch.mockRejectedValueOnce( mockError );

		const { result } = renderHook( () =>
			usePresenceUsers( 'postType/post:123' )
		);

		await waitFor( () => {
			expect( result.current.isLoading ).toBe( false );
		} );

		expect( result.current.isPresent ).toBe( false );
		expect( result.current.users ).toEqual( [] );
		expect( result.current.error ).toBe( mockError );
	} );

	it( 'should refetch on heartbeat tick', async () => {
		const mockData1 = [
			{
				user_id: 2,
				display_name: 'Alice',
				avatar_url: 'https://example.com/alice.jpg',
			},
		];

		const mockData2 = [
			{
				user_id: 2,
				display_name: 'Alice',
				avatar_url: 'https://example.com/alice.jpg',
			},
			{
				user_id: 3,
				display_name: 'Bob',
				avatar_url: 'https://example.com/bob.jpg',
			},
		];

		apiFetch.mockResolvedValueOnce( mockData1 );

		const { result } = renderHook( () =>
			usePresenceUsers( 'postType/post:123' )
		);

		await waitFor( () => {
			expect( result.current.isLoading ).toBe( false );
		} );

		expect( result.current.users ).toHaveLength( 1 );

		apiFetch.mockResolvedValueOnce( mockData2 );
		heartbeatTickCallback();

		await waitFor( () => {
			expect( result.current.users ).toHaveLength( 2 );
		} );
	} );

	it( 'should not send duplicate requests during race condition', async () => {
		apiFetch.mockImplementation(
			() =>
				new Promise( ( resolve ) => {
					setTimeout( () => {
						resolve( [
							{
								user_id: 2,
								display_name: 'Alice',
								avatar_url: 'https://example.com/alice.jpg',
							},
						] );
					}, 100 );
				} )
		);

		renderHook( () => usePresenceUsers( 'postType/post:123' ) );

		heartbeatTickCallback();
		heartbeatTickCallback();
		heartbeatTickCallback();

		await waitFor(
			() => {
				expect( apiFetch ).toHaveBeenCalledTimes( 1 );
			},
			{ timeout: 200 }
		);
	} );

	it( 'should use custom fields parameter', async () => {
		const mockData = [
			{
				user_id: 2,
				display_name: 'Alice',
			},
		];

		apiFetch.mockResolvedValueOnce( mockData );

		renderHook( () =>
			usePresenceUsers( 'postType/post:123', {
				fields: 'user_id,display_name',
			} )
		);

		await waitFor( () => {
			expect( apiFetch ).toHaveBeenCalledWith(
				expect.objectContaining( {
					path: expect.stringContaining(
						'_fields=user_id%2Cdisplay_name'
					),
				} )
			);
		} );
	} );

	it( 'should clean up event listener on unmount', () => {
		const { unmount } = renderHook( () =>
			usePresenceUsers( 'postType/post:123' )
		);

		expect( heartbeatTickCallback ).not.toBe( null );

		unmount();

		expect( heartbeatTickCallback ).toBe( null );
	} );

	it( 'should provide fallback display name when missing', async () => {
		const mockData = [
			{
				user_id: 2,
				display_name: '',
				avatar_url: '',
			},
		];

		apiFetch.mockResolvedValueOnce( mockData );

		const { result } = renderHook( () =>
			usePresenceUsers( 'postType/post:123' )
		);

		await waitFor( () => {
			expect( result.current.isLoading ).toBe( false );
		} );

		expect( result.current.users[ 0 ].displayName ).toBe( 'User 2' );
	} );
} );
