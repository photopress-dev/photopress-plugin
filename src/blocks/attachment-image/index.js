/**
 * WordPress dependencies
 */
import { image as icon } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import metadata from '../../../modules/gallery/blocks/attachment-image/block.json';
import edit from './edit';
import './style.scss';

const { name } = metadata;

export { metadata, name };

// Rendered on the server (modules/gallery/ImageTemplates.php); saves only
// its settings.
export const settings = {
	...metadata,
	icon,
	edit,
	save: () => null,
};
