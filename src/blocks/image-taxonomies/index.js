/**
 * WordPress dependencies
 */
import { tag as icon } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import metadata from '../../../modules/metadata/blocks/image-taxonomies/block.json';
import edit from './edit';
import transforms from './transforms';

const { name } = metadata;

export { metadata, name };

// Rendered on the server (modules/metadata/ImageTaxonomies.php); saves nothing
// but its attributes.
export const settings = {
	...metadata,
	icon,
	edit,
	save: () => null,
	transforms,
};
