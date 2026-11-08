/**
 * WordPress dependencies
 */
import { tag as icon } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import metadata from '../../../modules/search/blocks/image-filter/block.json';
import edit from './edit';

const { name } = metadata;

export { metadata, name };

// Rendered on the server (ImageFilters::renderFilter()), with a variation
// per taxonomy from the server (ImageFilters::variations()); saves only its
// settings.
export const settings = {
	...metadata,
	icon,
	edit,
	save: () => null,
};
