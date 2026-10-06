/**
 * WordPress dependencies
 */
import { createBlock } from '@wordpress/blocks';

/**
 * Converts a legacy PhotoPress gallery to core/gallery with the matching
 * PhotoPress layout (src/variations/gallery-layouts.js). The columns style
 * becomes core's own column layout.
 */
export function toCoreGallery( attributes ) {
	const {
		images = [],
		galleryStyle,
		columns,
		imageCrop,
		linkTo = 'none',
		sizeSlug,
		caption,
		align,
		gutter,
		columnWidth,
		rowHeight,
		linkToSlideshow,
	} = attributes;

	const layout = [ 'masonry', 'rows', 'mosaic' ].includes( galleryStyle ) ? galleryStyle : undefined;

	const innerBlocks = images.map( ( image ) => createBlock( 'core/image', {
		id: image.id ? parseInt( image.id, 10 ) : undefined,
		url: image.url,
		alt: image.alt || '',
		caption: image.caption,
		sizeSlug,
		linkDestination: linkTo,
		href: { media: image.fullUrl || image.url, attachment: image.link }[ linkTo ],
	} ) );

	return createBlock( 'core/gallery', {
		align,
		caption,
		columns: layout ? undefined : columns,
		imageCrop,
		linkTo,
		sizeSlug,
		style: gutter !== undefined ? { spacing: { blockGap: gutter + 'px' } } : undefined,
		photopressLayout: layout,
		photopressColumnWidth: columnWidth,
		photopressRowHeight: rowHeight,
		photopressSlideshow: layout ? !! linkToSlideshow : undefined,
	}, innerBlocks );
}

const transforms = {
	// No transforms into this block: new galleries use the core/gallery
	// layouts. This one stays only so that existing galleries keep working.
	to: [
		{
			type: 'block',
			blocks: [ 'core/gallery' ],
			transform: toCoreGallery,
		},
	],
};

export default transforms;
