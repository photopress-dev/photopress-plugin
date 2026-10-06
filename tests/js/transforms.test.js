import { describe, test, expect, vi } from 'vitest';

/**
 * Internal dependencies
 */
import { toCoreGallery } from '../../src/blocks/gallery/transforms';

vi.mock( '@wordpress/blocks', () => ( {
	createBlock: ( name, attributes = {}, innerBlocks = [] ) => ( { name, attributes, innerBlocks } ),
} ) );

const images = [
	{ id: '15', url: 'https://example.test/a-1024x683.jpg', fullUrl: 'https://example.test/a.jpg', link: 'https://example.test/a/', alt: 'Lawn sign', caption: 'A caption' },
	{ id: '14', url: 'https://example.test/b.jpg', link: 'https://example.test/b/', alt: '', caption: '' },
];

describe( 'toCoreGallery', () => {
	test( 'masonry becomes the masonry variation with its settings', () => {
		const gallery = toCoreGallery( {
			images, galleryStyle: 'masonry', linkTo: 'attachment', sizeSlug: 'large', align: 'full',
			gutter: 20, columnWidth: 250, rowHeight: 300, linkToSlideshow: true, caption: 'Gallery',
		} );

		expect( gallery.name ).toBe( 'core/gallery' );
		expect( gallery.attributes ).toMatchObject( {
			photopressLayout: 'masonry',
			photopressColumnWidth: 250,
			photopressRowHeight: 300,
			photopressSlideshow: true,
			style: { spacing: { blockGap: '20px' } },
			linkTo: 'attachment',
			align: 'full',
			caption: 'Gallery',
		} );
		expect( gallery.attributes.columns ).toBeUndefined();
	} );

	test( 'each image becomes a core/image with its link', () => {
		const { innerBlocks } = toCoreGallery( { images, galleryStyle: 'rows', linkTo: 'attachment', sizeSlug: 'large' } );

		expect( innerBlocks ).toHaveLength( 2 );
		expect( innerBlocks[ 0 ] ).toEqual( {
			name: 'core/image',
			attributes: { id: 15, url: images[ 0 ].url, alt: 'Lawn sign', caption: 'A caption', sizeSlug: 'large', linkDestination: 'attachment', href: 'https://example.test/a/' },
			innerBlocks: [],
		} );
	} );

	test( 'media links go to the full-size file', () => {
		const { innerBlocks } = toCoreGallery( { images, linkTo: 'media' } );

		expect( innerBlocks.map( ( b ) => b.attributes.href ) ).toEqual( [ 'https://example.test/a.jpg', 'https://example.test/b.jpg' ] );
	} );

	test( 'the columns style becomes core columns and keeps its slideshow', () => {
		const { attributes } = toCoreGallery( { images, galleryStyle: 'columns', columns: 3, linkToSlideshow: true } );

		expect( attributes.photopressLayout ).toBeUndefined();
		expect( attributes.photopressSlideshow ).toBe( true );
		expect( attributes.columns ).toBe( 3 );
	} );

	test( 'hidden captions stay hidden, and the captions are kept', () => {
		const gallery = toCoreGallery( { images, galleryStyle: 'columns', columns: 3, showCaptions: false } );

		expect( gallery.attributes.photopressHideCaptions ).toBe( true );
		expect( gallery.innerBlocks[ 0 ].attributes.caption ).toBe( 'A caption' );
	} );

	test( 'captions are shown by default', () => {
		expect( toCoreGallery( { images } ).attributes.photopressHideCaptions ).toBe( false );
	} );

	test( 'no gutter leaves the block spacing to core', () => {
		expect( toCoreGallery( { images, galleryStyle: 'mosaic' } ).attributes.style ).toBeUndefined();
	} );
} );
