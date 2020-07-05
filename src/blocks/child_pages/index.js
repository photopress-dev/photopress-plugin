/**
 * Internal dependencies
 */

import edit from './edit.js';
import icon from './icon.js';
import metadata from './block.json';
import transforms from './transforms';
 
/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

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
	description: __( 'Display child pages.', 'photopress' ),
	icon,
	keywords: [
		'photopress',
		/* translators: block keyword */
		__( 'child pages', 'photopress' )
		
	],
	supports: {
		align: [ 'wide', 'full' ],
		html: false,
	},
	attributes,
	transforms,
	edit,
	save() {
		return null;
	},
};

export { name, category, settings };