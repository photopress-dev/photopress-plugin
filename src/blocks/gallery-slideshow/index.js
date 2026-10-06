/**
 * WordPress dependencies
 */
import { stack as icon } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import metadata from '../../../modules/gallery/blocks/gallery-slideshow/block.json';
import edit from './edit';
import transforms from './transforms';
import './style.scss';

const { name } = metadata;

export { metadata, name };

// Rendered on the server (modules/gallery/GallerySlideshow.php) from the
// gallery's current images; saves only its settings.
export const settings = {
	...metadata,
	icon,
	edit,
	save: () => null,
	transforms,
};
