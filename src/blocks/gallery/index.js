/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { gallery as icon } from '@wordpress/icons';

/**
 * Internal dependencies
 */
//import deprecated from './deprecated';
import edit from './edit';
import metadata from './block.json';
import save from './save';
import transforms from './transforms';


//  Import CSS.
import './style.scss';
import './editor.scss';

//import './theme.scss';

const { name, category, attributes } = metadata;

export { metadata, category, name };

export const settings = {
	title: __( 'PhotoPress Gallery' ),
	description: __( 'Display multiple images in a rich gallery.' ),
	icon,
	keywords: [ __( 'images' ), __( 'photos' ), __( 'photopress' )  ],
	example: {
		attributes: {
			columns: 2,
			images: [
				{
					url:
						'https://s.w.org/images/core/5.3/Glacial_lakes%2C_Bhutan.jpg',
				},
				{
					url:
						'https://s.w.org/images/core/5.3/Sediment_off_the_Yucatan_Peninsula.jpg',
				},
			],
		},
	},
	supports: {
		align: [ 'wide', 'full' ],
		html: false,
	},
	attributes,
	transforms,
	edit,
	save,
	//deprecated,
};
