import { test, expect, vi } from 'vitest';

const registered = [];

vi.mock( '@wordpress/blocks', () => ( {
	registerBlockVariation: ( block, variation ) => registered.push( { block, ...variation } ),
} ) );

await import( '../../src/variations/gallery-layouts' );

const variation = ( name ) => registered.find( ( v ) => v.name === name );

test( 'each layout can be switched to, and back to the plain gallery', () => {
	const switchable = registered.filter( ( v ) => v.block === 'core/gallery' && v.scope.includes( 'transform' ) ).map( ( v ) => v.name );

	expect( switchable ).toEqual( [ 'photopress-masonry', 'photopress-rows', 'photopress-mosaic', 'photopress-columns' ] );
} );

test( 'the plain gallery clears the layout, is active without one, and is not in the inserter', () => {
	const plain = variation( 'photopress-columns' );

	expect( plain.scope ).toEqual( [ 'transform' ] );
	expect( 'photopressLayout' in plain.attributes ).toBe( true );
	expect( plain.attributes.photopressLayout ).toBeUndefined();

	// As core applies a variation: its attributes merged over the block's.
	const block = { photopressLayout: 'masonry', photopressSlideshow: true, columns: 3 };
	expect( { ...block, ...plain.attributes } ).toEqual( { photopressLayout: undefined, photopressSlideshow: true, columns: 3 } );

	expect( plain.isActive( {} ) ).toBe( true );
	expect( plain.isActive( { photopressLayout: 'rows' } ) ).toBe( false );
} );
