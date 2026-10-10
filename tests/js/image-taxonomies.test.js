import { test, expect, vi } from 'vitest';

/**
 * Internal dependencies
 */
import transforms, { fromWidget, parseList } from '../../src/blocks/image-taxonomies/transforms';

vi.mock( '@wordpress/blocks', () => ( {
	createBlock: ( name, attributes = {}, innerBlocks = [] ) => ( { name, attributes, innerBlocks } ),
} ) );

const [ widgetTransform ] = transforms.from;

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
