/**
 * External dependencies
 */
import filter from 'lodash/filter';

/**
 * Internal dependencies
 */
import metadata from './block.json';
//import { GalleryTransforms } from '../../components/block-gallery/shared';

/**
 * WordPress dependencies
 */
import { createBlock } from '@wordpress/blocks';

const transforms = {
	from: [
		{
			type: 'block',
			blocks: [
				
				'core/gallery'
			],
			transform: ( attributes ) => createBlock( metadata.name, attributes ),
		}
		
	],
	
	to: ( function() {
		return [
			
			'core/gallery',
		].map( ( x ) => {
			return {
				type: 'block',
				blocks: [ x ],
				transform: ( attributes ) => createBlock( x, {
					...attributes,
				} ),
			};
		} );
	}() ),
};

export default transforms;