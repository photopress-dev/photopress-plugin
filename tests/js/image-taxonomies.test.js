import { test, expect, vi } from 'vitest';

/**
 * Internal dependencies
 */
import transforms, { fromExifWidget, fromWidget, parseList } from '../../src/blocks/image-taxonomies/transforms';

vi.mock( '@wordpress/blocks', () => ( {
	createBlock: ( name, attributes = {}, innerBlocks = [] ) => ( { name, attributes, innerBlocks } ),
} ) );

const [ widgetTransform, exifTransform ] = transforms.from;

test( 'matches only a placed PhotoPress taxonomy widget with its settings', () => {
	expect( widgetTransform.blocks ).toEqual( [ 'core/legacy-widget' ] );
	expect( widgetTransform.isMatch( { idBase: 'xmpdisplaywidget', instance: { raw: { taxonomies: 'photos_city' } } } ) ).toBe( true );
	expect( widgetTransform.isMatch( { idBase: 'xmpdisplaywidget', instance: {} } ) ).toBe( false );
	expect( widgetTransform.isMatch( { idBase: 'text', instance: { raw: {} } } ) ).toBe( false );
} );

test( 'a widget becomes a heading and the block, with its taxonomies in order', () => {
	const result = fromWidget( { instance: { raw: { title: 'Image Details', taxonomies: 'photos_keywords, photos_people' } } } );

	expect( result ).toEqual( [
		{ name: 'core/heading', attributes: { content: 'Image Details', level: 2 }, innerBlocks: [] },
		{ name: 'photopress/image-taxonomies', attributes: { taxonomies: [ 'photos_keywords', 'photos_people' ], linkTerms: true, showLabels: true }, innerBlocks: [] },
	] );
} );

test( 'a widget without a title or list gets the widget defaults', () => {
	const block = fromWidget( { instance: { raw: { title: '', taxonomies: '' } } } );

	expect( block.name ).toBe( 'photopress/image-taxonomies' );
	expect( block.attributes.taxonomies ).toEqual( parseList( 'photos_keywords, photos_camera, photos_lens, photos_city, photos_state, photos_country, photos_people' ) );
} );

test( 'matches only a placed PhotoPress Exif widget with its settings', () => {
	expect( exifTransform.blocks ).toEqual( [ 'core/legacy-widget' ] );
	expect( exifTransform.isMatch( { idBase: 'exifdisplaywidget', instance: { raw: { keys: 'iso' } } } ) ).toBe( true );
	expect( exifTransform.isMatch( { idBase: 'exifdisplaywidget', instance: {} } ) ).toBe( false );
	expect( exifTransform.isMatch( { idBase: 'xmpdisplaywidget', instance: { raw: {} } } ) ).toBe( false );
} );

test( 'an Exif widget becomes the block with its details as exif rows, unlinked', () => {
	expect( fromExifWidget( { instance: { raw: { title: 'Exposure', keys: 'Aperture, shutter_speed' } } } ) ).toEqual( [
		{ name: 'core/heading', attributes: { content: 'Exposure', level: 2 }, innerBlocks: [] },
		{ name: 'photopress/image-taxonomies', attributes: { taxonomies: [ 'exif:aperture', 'exif:shutter_speed' ], linkTerms: false, showLabels: true }, innerBlocks: [] },
	] );

	// Saved without a list: the widget's own default.
	expect( fromExifWidget( { instance: { raw: {} } } ).attributes.taxonomies ).toEqual( [ 'exif:iso', 'exif:aperture', 'exif:camera' ] );
} );
