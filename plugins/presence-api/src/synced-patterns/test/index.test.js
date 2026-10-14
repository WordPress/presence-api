/**
 * External dependencies
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { addFilter } from '@wordpress/hooks';

// The module reads usePresenceUsers from window.wp.presence as it loads, so it has to exist before the import.
const { usePresenceUsers } = vi.hoisted( () => {
	const hook = vi.fn();
	window.wp = { presence: { usePresenceUsers: hook } };
	return { usePresenceUsers: hook };
} );

vi.mock( '@wordpress/hooks', () => ( { addFilter: vi.fn() } ) );

// The real Notice pulls in rich-text, which fails to load under jsdom.
vi.mock( '@wordpress/components', () => ( {
	Notice: ( { status, isDismissible, children } ) => (
		<div
			role="status"
			data-status={ status }
			data-dismissible={ String( isDismissible ) }
		>
			{ children }
		</div>
	),
} ) );

/**
 * Internal dependencies
 */
import '../index';

// Read at load, since Vitest clears call history before each test.
const [ hookName, namespace, withPatternEditors ] = addFilter.mock.calls[ 0 ];
const BlockEdit = withPatternEditors( ( { name } ) => (
	<p data-testid="block-edit">{ name }</p>
) );

const users = ( ...names ) =>
	names.map( ( displayName ) => ( { displayName } ) );

describe( 'synced pattern editors notice', () => {
	beforeEach( () => {
		usePresenceUsers.mockReset();
		usePresenceUsers.mockReturnValue( { users: [] } );
	} );

	afterEach( () => {
		cleanup();
	} );

	it( 'filters editor.BlockEdit', () => {
		expect( hookName ).toBe( 'editor.BlockEdit' );
		expect( namespace ).toBe( 'presence-api/synced-pattern-editors' );
	} );

	it( 'reads the room of the referenced pattern', () => {
		render( <BlockEdit name="core/block" attributes={ { ref: 42 } } /> );

		expect( usePresenceUsers ).toHaveBeenCalledWith(
			'postType/wp_block:42'
		);
	} );

	it( 'shows no notice while no one else is editing the pattern', () => {
		render( <BlockEdit name="core/block" attributes={ { ref: 42 } } /> );

		expect( screen.queryByRole( 'status' ) ).toBeNull();
		expect( screen.getByTestId( 'block-edit' ).textContent ).toBe(
			'core/block'
		);
	} );

	it.each( [
		[ users( 'Ana' ), 'Ana is editing this pattern.' ],
		[
			users( 'Ana', 'Bo' ),
			'Ana and 1 other person are editing this pattern.',
		],
		[
			users( 'Ana', 'Bo', 'Cy' ),
			'Ana and 2 other people are editing this pattern.',
		],
	] )( 'names the editors (%#)', ( editors, message ) => {
		usePresenceUsers.mockReturnValue( { users: editors } );

		render( <BlockEdit name="core/block" attributes={ { ref: 42 } } /> );

		const notice = screen.getByRole( 'status' );
		expect( notice.textContent ).toBe( message );
		expect( notice.dataset.status ).toBe( 'warning' );
		expect( notice.dataset.dismissible ).toBe( 'false' );
		expect( screen.getByTestId( 'block-edit' ) ).toBeTruthy();
	} );

	it.each( [
		[ 'another block', 'core/paragraph', { ref: 42 } ],
		[ 'a pattern block without a ref', 'core/block', {} ],
	] )( 'leaves %s alone', ( _label, name, attributes ) => {
		usePresenceUsers.mockReturnValue( { users: users( 'Ana' ) } );

		render( <BlockEdit name={ name } attributes={ attributes } /> );

		expect( usePresenceUsers ).not.toHaveBeenCalled();
		expect( screen.queryByRole( 'status' ) ).toBeNull();
		expect( screen.getByTestId( 'block-edit' ).textContent ).toBe( name );
	} );
} );
