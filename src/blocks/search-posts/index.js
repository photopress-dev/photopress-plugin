/**
 * WordPress dependencies
 */
import { search as icon } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import metadata from '../../../modules/search/blocks/search-posts/block.json';
import edit from './edit';

const { name } = metadata;

export { metadata, name };

// Rendered on the server (modules/search/ImageSearch.php); saves only its
// settings.
export const settings = {
	...metadata,
	icon,
	edit,
	save: () => null,
};
