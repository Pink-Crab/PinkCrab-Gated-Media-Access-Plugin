/**
 * The item-type vocabulary the product editor shows.
 *
 * The editor rendered the storage keys straight onto the screen — the tab
 * strip mapped over `[ 'group', 'post', 'file' ]` and printed each one, and
 * every stored row printed `part.type` in its left cell. Those are the strings
 * the row keys are built from ("group:12"), never labels, and they never went
 * through `__()` while every neighbouring string did.
 */

import { ITEM_TYPES, typeLabel, typeValues } from '../../assets/js/editor/item-types';

describe( 'the item types', () => {
	it( 'offers exactly the three the row keys are built from', () => {
		expect( typeValues() ).toEqual( [ 'group', 'post', 'file' ] );
	} );

	it( 'gives every type a label that is not its storage key', () => {
		ITEM_TYPES.forEach( ( type ) => {
			expect( typeof type.label ).toBe( 'string' );
			expect( type.label.length ).toBeGreaterThan( 0 );
			expect( type.label ).not.toBe( type.value );
		} );
	} );

	it( 'labels each type the way the rest of the admin names it', () => {
		expect( typeLabel( 'group' ) ).toBe( 'Group' );
		expect( typeLabel( 'post' ) ).toBe( 'Post' );
		expect( typeLabel( 'file' ) ).toBe( 'File' );
	} );

	it( 'falls back to the raw value for a type it does not know', () => {
		// A stored row could name anything; showing the key beats showing
		// nothing, but it must not crash the editor.
		expect( typeLabel( 'something-else' ) ).toBe( 'something-else' );
		expect( typeLabel( '' ) ).toBe( '' );
	} );
} );
