import { beforeAll, afterAll, test, expect } from 'vitest';

/**
 * Internal dependencies
 */
import './stubs/block-supports';

/**
 * WordPress dependencies
 */
import { registerBlockType, unregisterBlockType, createBlock, serialize, parse } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import * as imageFilters from '../../src/blocks/image-filters';
import * as imageFilter from '../../src/blocks/image-filter';

const blocks = [ imageFilters, imageFilter ];

beforeAll( () => blocks.forEach( ( { name, settings } ) => registerBlockType( name, settings ) ) );

afterAll( () => blocks.forEach( ( { name } ) => unregisterBlockType( name ) ) );

test( 'saves its Image Filter blocks, in their order', () => {
	const saved = serialize( [
		createBlock( imageFilters.name, { showCounts: false }, [
			createBlock( imageFilter.name, { taxonomy: 'photos_keywords' } ),
			createBlock( imageFilter.name, { taxonomy: 'photos_people', label: 'Who' } ),
		] ),
	] );

	const [ block ] = parse( saved );
	expect( block.isValid ).toBe( true );
	expect( block.attributes.showCounts ).toBe( false );
	expect( block.innerBlocks.map( ( b ) => [ b.attributes.taxonomy, b.attributes.label ] ) ).toEqual( [
		[ 'photos_keywords', '' ],
		[ 'photos_people', 'Who' ],
	] );
} );

test( 'opens a block saved before it had Image Filter blocks', () => {
	const [ block ] = parse( '<!-- wp:photopress/image-filters {"hiddenTaxonomies":["photos_people"]} /-->' );
	expect( block.isValid ).toBe( true );
	expect( block.innerBlocks ).toEqual( [] );
} );
