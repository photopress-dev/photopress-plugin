/**
 * WordPress dependencies
 */
import { InnerBlocks } from '@wordpress/block-editor';
import { filter as icon } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import metadata from '../../../modules/search/blocks/image-filters/block.json';
import edit from './edit';

const { name } = metadata;

export { metadata, name };

// Rendered on the server (modules/search/ImageFilters.php); saves its
// settings and its Image Filter blocks.
export const settings = {
	...metadata,
	icon,
	edit,
	save: () => <InnerBlocks.Content />,
};
