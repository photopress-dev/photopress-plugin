import { test, expect } from 'vitest';

/**
 * Internal dependencies
 */
import { filterTemplate, offeredTaxonomies } from '../../src/blocks/image-filters/template';

const taxonomies = [
	{ slug: 'photos_people', name: 'People', photopress_public: true },
	{ slug: 'pp_client', name: 'Clients', photopress_public: false },
	{ slug: 'photos_keywords', name: 'Keywords' },
];

test( 'offers the image taxonomies visitors see', () => {
	expect( offeredTaxonomies( taxonomies ).map( ( t ) => t.slug ) ).toEqual( [ 'photos_people', 'photos_keywords' ] );
	expect( offeredTaxonomies( null ) ).toEqual( [] );
} );

test( 'starts with a filter per taxonomy offered, in their order, but those the block hid', () => {
	expect( filterTemplate( taxonomies ) ).toEqual( [
		[ 'photopress/image-filter', { taxonomy: 'photos_people' } ],
		[ 'photopress/image-filter', { taxonomy: 'photos_keywords' } ],
	] );
	expect( filterTemplate( taxonomies, [ 'photos_people' ] ) ).toEqual( [ [ 'photopress/image-filter', { taxonomy: 'photos_keywords' } ] ] );
} );
