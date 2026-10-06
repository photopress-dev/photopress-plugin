import { test, expect, afterEach, vi } from 'vitest';

/**
 * Internal dependencies
 */
import { justify, release } from '../../src/shared/gallery-layout/justify';

/**
 * A gallery figure of the given width and gap, with one image item per ratio.
 * jsdom does no layout, so width and computed style are supplied.
 */
function gallery( ratios, { width = 620, gap = 16 } = {} ) {
	const figure = document.createElement( 'figure' );

	ratios.forEach( ( ratio ) => {
		const item = document.createElement( 'figure' );
		item.className = 'wp-block-image';
		if ( ratio ) {
			item.style.setProperty( '--pp-ar', String( ratio ) );
		}
		figure.appendChild( item );
	} );

	// The gallery caption is not an image and is left alone.
	const caption = document.createElement( 'figcaption' );
	figure.appendChild( caption );

	Object.defineProperty( figure, 'clientWidth', { value: width } );
	vi.spyOn( window, 'getComputedStyle' ).mockReturnValue( { paddingLeft: '0px', paddingRight: '0px', columnGap: gap + 'px' } );

	return figure;
}

const sizes = ( figure ) => Array.from( figure.querySelectorAll( '.wp-block-image' ) ).map( ( el ) => [ parseInt( el.style.width, 10 ), parseInt( el.style.height, 10 ) ] );

afterEach( () => vi.restoreAllMocks() );

test( 'a full row spans the gallery exactly, at a height that fits its images', () => {
	const figure = gallery( [ 1.5, 1.5, 0.75 ] );

	justify( figure, 300 );

	const [ a, b, c ] = sizes( figure );
	// Ratios 1.5 + 1.5 at 300px tall are 900px: wider than 620, so the row is
	// those two at (620 - 16) / 3 = 201px.
	expect( a[ 1 ] ).toBe( 201 );
	expect( b[ 1 ] ).toBe( 201 );
	expect( a[ 0 ] + 16 + b[ 0 ] ).toBe( 620 );
	// The last row is not full and stays at the target height.
	expect( c ).toEqual( [ 225, 300 ] );
} );

test( 'a very wide image gets a row of its own, scaled down, not cropped', () => {
	const figure = gallery( [ 5.66 ] );

	justify( figure, 300 );

	expect( sizes( figure ) ).toEqual( [ [ 620, 109 ] ] );
} );

test( 'items without a ratio yet are laid out as squares', () => {
	const figure = gallery( [ null ] );

	justify( figure, 300 );

	expect( sizes( figure ) ).toEqual( [ [ 300, 300 ] ] );
} );

test( 'only image items are sized', () => {
	const figure = gallery( [ 1 ] );

	justify( figure, 300 );

	expect( figure.querySelector( 'figcaption' ).getAttribute( 'style' ) ).toBeNull();
} );

test( 'a gallery with no width yet is left alone', () => {
	const figure = gallery( [ 1 ], { width: 0 } );

	justify( figure, 300 );

	expect( sizes( figure ) ).toEqual( [ [ NaN, NaN ] ] );
} );

test( 'release removes the sizes', () => {
	const figure = gallery( [ 1.5, 1.5 ] );

	justify( figure, 300 );
	release( figure );

	figure.querySelectorAll( '.wp-block-image' ).forEach( ( el ) => {
		expect( el.style.width ).toBe( '' );
		expect( el.style.height ).toBe( '' );
		expect( el.style.flex ).toBe( '' );
	} );
} );
