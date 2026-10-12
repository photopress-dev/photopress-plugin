/**
 * Internal dependencies
 */

import edit from './edit.js';
import icon from './icon.js';
import metadata from './block.json';

 
/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { createBlock } from '@wordpress/blocks';

//  Import CSS.
import './style.scss';
import './editor.scss';

/**
 * Block constants
 */
const { name, category, postFeedType, attributes } = metadata;

const settings = {
	/* translators: block name */
	title: __( 'Child Pages', 'photopress' ),
	/* translators: block description */
	description: __( 'Display child pages. Superseded by Child Pages Gallery.', 'photopress' ),
	icon,
	keywords: [
		'photopress',
		/* translators: block keyword */
		__( 'child pages', 'photopress' )
		
	],
	// Superseded by Child Pages Gallery (a core/gallery variation): still
	// shown where it was used, no longer offered, and turned into one by
	// its transform.
	supports: {
		align: [ 'wide', 'full' ],
		html: false,
		inserter: false,
	},
	transforms: {
		to: [
			{
				type: 'block',
				blocks: [ 'core/gallery' ],
				transform: ( { align, columns, imageSize, imageCrop } ) => createBlock( 'core/gallery', {
					photopressSource: 'children',
					sizeSlug: imageSize || 'large',
					align,
					columns,
					imageCrop: false !== imageCrop,
				} ),
			},
		],
	},
	attributes,
	edit,
	save() {
		return null;
	},
};

export { name, category, settings };