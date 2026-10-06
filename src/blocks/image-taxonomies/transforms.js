/**
 * WordPress dependencies
 */
import { createBlock } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import metadata from '../../../modules/metadata/blocks/image-taxonomies/block.json';

// XmpDisplayWidget::DEFAULT_TAXONOMIES: a widget saved without a list showed these.
export const WIDGET_DEFAULT_TAXONOMIES = 'photos_keywords, photos_camera, photos_lens, photos_city, photos_state, photos_country, photos_people';

export const parseList = ( list ) =>
	String( list || '' ).split( ',' ).map( ( name ) => name.trim() ).filter( Boolean );

/**
 * A placed "Display Taxonomies (PhotoPress)" widget, as the widgets block
 * editor holds it, to this block: the same taxonomies in the same order,
 * linked and labelled as the widget always was, and its title as a heading.
 */
export function fromWidget( { instance } ) {
	const raw = instance?.raw || {};
	const block = createBlock( metadata.name, {
		taxonomies: parseList( raw.taxonomies || WIDGET_DEFAULT_TAXONOMIES ),
		linkTerms: true,
		showLabels: true,
	} );

	return raw.title ? [ createBlock( 'core/heading', { content: raw.title, level: 2 } ), block ] : block;
}

export default {
	from: [
		{
			type: 'block',
			blocks: [ 'core/legacy-widget' ],
			isMatch: ( { idBase, instance } ) => idBase === 'xmpdisplaywidget' && !! instance?.raw,
			transform: fromWidget,
		},
	],
};
