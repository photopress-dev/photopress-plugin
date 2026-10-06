/**
 * Front end of the PhotoPress masonry and mosaic layouts on core/gallery.
 * Enqueued by modules/gallery/gallery.php only on pages that contain one.
 */

/**
 * Internal dependencies
 */
import { createMasonry } from '../shared/gallery-layout/masonry.js';
import { justify } from '../shared/gallery-layout/justify.js';

function init() {
	document.querySelectorAll( '.wp-block-gallery.photopress-layout-mosaic' ).forEach( ( figure ) => {
		const rowHeight = parseFloat( figure.dataset.ppRowHeight ) || 300;
		let frame = null;

		// The aspect ratios are in the markup, so this does not wait for images.
		new ResizeObserver( () => {
			if ( frame === null ) {
				frame = requestAnimationFrame( () => {
					frame = null;
					justify( figure, rowHeight );
				} );
			}
		} ).observe( figure );
	} );

	document.querySelectorAll( '.wp-block-gallery.photopress-layout-masonry' ).forEach( ( figure ) => {
		const masonry = createMasonry( figure, parseFloat( figure.dataset.ppColumnWidth ) || 300 );

		if ( ! masonry ) {
			return;
		}

		// Lay out again as each image arrives; lazy-loaded images have no
		// height until then.
		figure.querySelectorAll( 'img' ).forEach( ( img ) => {
			if ( ! img.complete ) {
				img.addEventListener( 'load', masonry.layout );
			}
		} );
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
