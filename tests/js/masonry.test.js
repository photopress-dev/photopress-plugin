import { test, expect, afterEach, vi } from 'vitest';

/**
 * Internal dependencies
 */
import { createMasonry } from '../../src/shared/gallery-layout/masonry';

/**
 * A gallery of the given width and gap, with one image of each height and a
 * gallery caption. jsdom does no layout, so sizes and styles are supplied:
 * every image has a bottom margin of the gap, as the CSS gives it.
 */
function gallery( heights, { width = 620, gap = 20 } = {} ) {
	const figure = document.createElement( 'figure' );

	heights.forEach( ( height ) => {
		const item = document.createElement( 'figure' );
		item.className = 'wp-block-image';
		item.getBoundingClientRect = () => ( { height } );
		figure.appendChild( item );
	} );

	const caption = document.createElement( 'figcaption' );
	Object.defineProperty( caption, 'offsetHeight', { value: 40 } );
	figure.appendChild( caption );

	Object.defineProperty( figure, 'clientWidth', { value: width } );
	vi.spyOn( window, 'getComputedStyle' ).mockImplementation( ( el ) => ( el === figure ? { columnGap: gap + 'px' } : { marginTop: '0px', marginBottom: gap + 'px' } ) );

	return figure;
}

const positions = ( figure ) => Array.from( figure.querySelectorAll( '.wp-block-image' ) ).map( ( el ) => [ el.style.left, el.style.top ] );

afterEach( () => vi.restoreAllMocks() );

test( 'each image goes at the foot of the shortest column, the leftmost of equals', () => {
	// Two 300px columns and a 20px gutter in 620px.
	const figure = gallery( [ 400, 200, 100, 300 ] );

	createMasonry( figure, 300 );

	expect( positions( figure ) ).toEqual( [
		[ '0px', '0px' ],
		[ '320px', '0px' ],
		// Column 2 is shorter: 220 against 420, margins included.
		[ '320px', '220px' ],
		[ '320px', '340px' ],
	] );
	expect( figure.querySelector( '.wp-block-image' ).style.position ).toBe( 'absolute' );
} );

test( 'as many columns as fit the gallery, and one at most its width', () => {
	const three = gallery( [ 100, 100, 100, 100 ], { width: 1000 } );
	createMasonry( three, 300 );
	expect( positions( three ).map( ( [ left ] ) => left ) ).toEqual( [ '0px', '320px', '640px', '0px' ] );

	vi.restoreAllMocks();
	const narrow = gallery( [ 100, 100 ], { width: 250 } );
	createMasonry( narrow, 300 );
	expect( positions( narrow ) ).toEqual( [ [ '0px', '0px' ], [ '0px', '120px' ] ] );
} );

test( 'centers the columns in the gallery', () => {
	// Two 300px columns and a 20px gutter use 620 of 700px: 40px either side.
	const figure = gallery( [ 100, 100 ], { width: 700 } );

	createMasonry( figure, 300 );

	expect( figure.style.getPropertyValue( '--pp-masonry-offset' ) ).toBe( '40px' );
} );

test( 'the caption is placed below the columns and the gallery grows to fit', () => {
	const figure = gallery( [ 400, 200 ] );

	createMasonry( figure, 300 );

	const caption = figure.querySelector( 'figcaption' );
	expect( caption.style.position ).toBe( 'absolute' );
	// The tallest column, 400 and its 20px margin.
	expect( caption.style.top ).toBe( '420px' );
	expect( figure.style.height ).toBe( '460px' );
} );

test( 'laid out again, it gives the same places; a gallery with no width is left alone', () => {
	const figure = gallery( [ 400, 200, 100 ] );
	const masonry = createMasonry( figure, 300 );
	const first = positions( figure );

	masonry.layout();
	expect( positions( figure ) ).toEqual( first );
	expect( figure.style.height ).toBe( '460px' );

	vi.restoreAllMocks();
	const hidden = gallery( [ 100 ], { width: 0 } );
	createMasonry( hidden, 300 );
	expect( positions( hidden ) ).toEqual( [ [ '', '' ] ] );
} );

test( 'destroy undoes the layout', () => {
	const figure = gallery( [ 400, 200 ] );
	const masonry = createMasonry( figure, 300 );

	masonry.destroy();

	expect( positions( figure ) ).toEqual( [ [ '', '' ], [ '', '' ] ] );
	expect( figure.querySelector( '.wp-block-image' ).style.position ).toBe( '' );
	expect( figure.style.height ).toBe( '' );
	expect( figure.style.getPropertyValue( '--pp-masonry-offset' ) ).toBe( '' );
	expect( figure.querySelector( 'figcaption' ).style.position ).toBe( '' );
} );
