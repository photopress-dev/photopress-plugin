/**
 * WordPress dependencies
 */
import { createBlock } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import metadata from '../../../modules/gallery/blocks/gallery-slideshow/block.json';

/**
 * A Jetpack Slideshow set up by the "Slideshow Source: Gallery" plugin, to
 * this block. Its paa* attributes only exist while that plugin is active, so
 * convert before deactivating it.
 */
export function fromJetpackSlideshow( attributes ) {
	return createBlock( metadata.name, {
		galleryAnchor: attributes.paaSourceGalleryAnchor,
		sizeSlug: attributes.sizeSlug || 'large',
		autoplay: !! attributes.autoplay,
		delay: Number( attributes.delay ) || 3,
		effect: attributes.effect === 'fade' ? 'fade' : 'slide',
		maxHeightOffset: Number( attributes.paaMaxHeightOffsetPx ) || 0,
		galleryNavigation: attributes.paaGalleryNavEnabled !== false,
		scrollBehavior: attributes.paaScrollBehavior === 'smooth' ? 'smooth' : 'instant',
		align: attributes.align,
	} );
}

export default {
	from: [
		{
			type: 'block',
			blocks: [ 'jetpack/slideshow' ],
			isMatch: ( attributes ) => !! attributes.paaSourceGalleryAnchor,
			transform: fromJetpackSlideshow,
		},
	],
};
