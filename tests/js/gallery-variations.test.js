import { test, expect, vi } from 'vitest';

const registered = [];

vi.mock( '@wordpress/blocks', () => ( {
	registerBlockVariation: ( block, variation ) => registered.push( { block, ...variation } ),
} ) );

const { captionClassOf, captionStyleOf, childPagesSummaryOf, isImageArchiveTemplate, sourceFor, summaryOf, templateSlugOf } = await import( '../../src/variations/gallery-layouts' );

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

test( 'Taxonomy Gallery is inserted in Masonry, and only it is active for a query gallery, whatever its layout', () => {
	const query = variation( 'photopress-taxonomy-gallery' );

	expect( query.scope ).toEqual( [ 'inserter' ] );
	expect( query.attributes ).toMatchObject( { photopressSource: 'query', photopressLayout: 'masonry', linkTo: 'attachment' } );

	for ( const source of [ 'query', 'archive' ] ) {
		const gallery = { photopressSource: source, photopressLayout: 'mosaic' };
		expect( registered.filter( ( v ) => v.block === 'core/gallery' && v.isActive( gallery ) ).map( ( v ) => v.name ) ).toEqual( [ 'photopress-taxonomy-gallery' ] );
	}

	// A gallery of its own images is not one.
	expect( query.isActive( { photopressLayout: 'mosaic' } ) ).toBe( false );
	expect( variation( 'photopress-mosaic' ).isActive( { photopressLayout: 'mosaic' } ) ).toBe( true );
} );

test( 'on an image taxonomy archive or search template the page\'s own images; anywhere else, those chosen', () => {
	const taxonomies = [ 'photos_keywords', 'pp_genre' ];

	expect( templateSlugOf( 'wp_template', 'twentytwentyfive//taxonomy-attachment' ) ).toBe( 'taxonomy-attachment' );
	expect( templateSlugOf( 'page', 12 ) ).toBe( '' );

	for ( const slug of [ 'taxonomy-attachment', 'taxonomy-photos_keywords', 'taxonomy-pp_genre-portrait', 'search', 'search-images' ] ) {
		expect( isImageArchiveTemplate( slug, taxonomies ), slug ).toBe( true );
		expect( sourceFor( slug, taxonomies ) ).toBe( 'archive' );
	}

	for ( const slug of [ '', 'archive', 'taxonomy', 'taxonomy-category', 'taxonomy-photos_keywordsx', 'single' ] ) {
		expect( sourceFor( slug, taxonomies ), slug ).toBe( 'query' );
	}
} );

test( 'a sentence says what it shows', () => {
	expect( summaryOf( { photopressSource: 'archive', photopressLayout: 'masonry' } ) ).toBe( 'The images of the archive or search results being viewed, in the Masonry layout, with links to more pages of them.' );
	expect( summaryOf( { photopressSource: 'query', photopressLayout: 'mosaic', photopressCount: 8, photopressTaxonomy: 'photos_keywords', photopressTerm: 3, photopressOrder: 'rand' }, { taxonomy: 'Keywords', term: 'portrait' } ) )
		.toBe( '8 images in Keywords: “portrait”, in random order, in the Mosaic layout.' );
	expect( summaryOf( { photopressSource: 'query' } ) ).toBe( 'The 12 latest images, newest first, in the Default (columns) layout.' );
} );

test( 'Child Pages Gallery is inserted in Masonry, and only it is active for a gallery of child pages', () => {
	const children = variation( 'photopress-child-pages-gallery' );

	expect( children.scope ).toEqual( [ 'inserter' ] );
	expect( children.attributes ).toMatchObject( { photopressSource: 'children', photopressLayout: 'masonry' } );

	for ( const layout of [ 'masonry', 'rows', 'mosaic', undefined ] ) {
		const attributes = { photopressSource: 'children', photopressLayout: layout };
		const active = registered.filter( ( v ) => v.block === 'core/gallery' && v.isActive( attributes ) ).map( ( v ) => v.name );

		expect( active ).toEqual( [ 'photopress-child-pages-gallery' ] );
	}
} );

test( 'a sentence says which child pages it shows', () => {
	const attributes = { photopressSource: 'children', photopressLayout: 'rows' };

	expect( childPagesSummaryOf( attributes ) ).toBe( 'The published pages under the page being viewed, each linked to it with its title, in the Rows layout.' );
	expect( childPagesSummaryOf( attributes, { total: 0, shown: 0 } ) ).toMatch( /^This page has no published pages under it yet/ );
	expect( childPagesSummaryOf( attributes, { total: 5, shown: 4 } ) ).toMatch( /^4 of the 5 pages under this one, each linked to it with its title, in the Rows layout\./ );
} );

test( 'a gallery’s caption style: its own, else hidden for Hide captions, else the Gallery tab’s', () => {
	expect( captionStyleOf( {} ) ).toBe( 'theme' );
	expect( captionStyleOf( { photopressCaptionStyle: 'below' } ) ).toBe( 'below' );
	expect( captionStyleOf( { photopressHideCaptions: true } ) ).toBe( 'hidden' );
	expect( captionStyleOf( { photopressCaptionStyle: 'theme', photopressHideCaptions: true } ) ).toBe( 'theme' );

	window.photopressCaptionStyle = 'hover';
	expect( captionStyleOf( {} ) ).toBe( 'hover' );
	expect( captionStyleOf( { photopressCaptionStyle: 'tooltip' } ) ).toBe( 'hover' );
	window.photopressCaptionStyle = 'sideways';
	expect( captionStyleOf( {} ) ).toBe( 'theme' );
	delete window.photopressCaptionStyle;
} );

test( 'the class each caption style adds, as gallery.php adds it', () => {
	expect( captionClassOf( {} ) ).toBe( '' );
	expect( captionClassOf( { photopressCaptionStyle: 'below' } ) ).toBe( 'photopress-captions-below' );
	expect( captionClassOf( { photopressCaptionStyle: 'hover' } ) ).toBe( 'photopress-captions-hover' );
	expect( captionClassOf( { photopressCaptionStyle: 'hidden' } ) ).toBe( 'photopress-hide-captions' );
	expect( captionClassOf( { photopressHideCaptions: true } ) ).toBe( 'photopress-hide-captions' );
} );
