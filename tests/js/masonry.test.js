import { test, expect, beforeEach, afterEach, vi } from 'vitest';

/**
 * Internal dependencies
 */
import { createMasonry } from '../../src/shared/gallery-layout/masonry';

/**
 * Stands in for WordPress's masonry-layout: records its options and, on
 * layout(), sets the container height and the column metrics the real one
 * computes.
 */
class FakeMasonry {
	constructor( element, options ) {
		FakeMasonry.last = this;
		this.element = element;
		this.options = { ...options };
		this.handlers = {};
		this.layouts = 0;
	}
	on( event, handler ) {
		this.handlers[ event ] = handler;
	}
	reloadItems() {}
	layout() {
		this.layouts++;
		this.gutter = this.options.gutter;
		this.columnWidth = this.options.columnWidth + this.gutter;
		this.cols = Math.floor( ( this.element.clientWidth + this.gutter ) / this.columnWidth );
		this.element.style.height = '500px';
	}
	destroy() {
		this.destroyed = true;
	}
}

function gallery( width = 620, gap = 20 ) {
	const figure = document.createElement( 'figure' );
	figure.innerHTML = '<figure class="wp-block-image"></figure><figure class="wp-block-image"></figure><figcaption>Caption</figcaption>';
	Object.defineProperty( figure, 'clientWidth', { value: width } );
	Object.defineProperty( figure.querySelector( 'figcaption' ), 'offsetHeight', { value: 40 } );
	vi.spyOn( window, 'getComputedStyle' ).mockReturnValue( { columnGap: gap + 'px' } );
	return figure;
}

beforeEach( () => {
	window.Masonry = FakeMasonry;
} );

afterEach( () => {
	delete window.Masonry;
	vi.restoreAllMocks();
} );

test( 'without Masonry loaded it does nothing', () => {
	delete window.Masonry;

	expect( createMasonry( gallery(), 300 ) ).toBeNull();
} );

test( 'counts columns from the gallery itself and takes the gutter from the block gap', () => {
	createMasonry( gallery(), 300 );

	expect( FakeMasonry.last.options ).toMatchObject( {
		itemSelector: '.wp-block-image',
		columnWidth: 300,
		gutter: 20,
		// fitWidth counted columns from the parent and overflowed narrower galleries.
		fitWidth: false,
		transitionDuration: 0,
	} );
	expect( FakeMasonry.last.layouts ).toBe( 1 );
} );

test( 'centres the columns in the gallery', () => {
	// Two 300px columns and a 20px gutter use 620 of 700px: 40px either side.
	const figure = gallery( 700 );

	createMasonry( figure, 300 );

	expect( figure.style.getPropertyValue( '--pp-masonry-offset' ) ).toBe( '40px' );
} );

test( 'the caption is placed below the columns and the gallery grows to fit', () => {
	const figure = gallery();

	createMasonry( figure, 300 );

	const caption = figure.querySelector( 'figcaption' );
	expect( caption.style.position ).toBe( 'absolute' );
	expect( caption.style.top ).toBe( '500px' );
	expect( figure.style.height ).toBe( '540px' );
} );

test( 'destroy undoes the layout', () => {
	const figure = gallery();
	const masonry = createMasonry( figure, 300 );

	masonry.destroy();

	expect( FakeMasonry.last.destroyed ).toBe( true );
	expect( figure.style.getPropertyValue( '--pp-masonry-offset' ) ).toBe( '' );
	expect( figure.querySelector( 'figcaption' ).style.position ).toBe( '' );
} );
