/**
 * Front end of the PhotoPress masonry and mosaic layouts on core/gallery.
 * Enqueued by modules/gallery/gallery.php only on pages that have one.
 *
 * Each gallery has already been laid out by the inline script printed after
 * it (src/frontend/gallery-layouts-inline.js), before the page was first
 * painted. This lays it out the same way again, and keeps it laid out as the
 * gallery's width changes. Rows are laid out by CSS alone.
 */

/**
 * Internal dependencies
 */
import { createMasonry } from '../shared/gallery-layout/masonry.js';
import { justify } from '../shared/gallery-layout/justify.js';

/**
 * Runs `layout` now, and again whenever the gallery's width changes (its
 * height is the layout's own doing), at most once a frame.
 */
function keepLaidOut( figure, layout ) {
	let width = null;
	let frame = null;

	const schedule = () => {
		if ( frame === null ) {
			frame = requestAnimationFrame( () => {
				frame = null;
				layout();
				// Shown, if the inline script could not lay it out.
				figure.classList.add( 'photopress-laid-out' );
			} );
		}
	};

	new ResizeObserver( () => {
		if ( figure.clientWidth !== width ) {
			width = figure.clientWidth;
			schedule();
		}
	} ).observe( figure );

	return schedule;
}

function init() {
	document.querySelectorAll( '.wp-block-gallery.photopress-layout-mosaic' ).forEach( ( figure ) => {
		const rowHeight = parseFloat( figure.dataset.ppRowHeight ) || 300;

		// The aspect ratios are in the markup, so this does not wait for images.
		keepLaidOut( figure, () => justify( figure, rowHeight ) );
	} );

	document.querySelectorAll( '.wp-block-gallery.photopress-layout-masonry' ).forEach( ( figure ) => {
		const masonry = createMasonry( figure, parseFloat( figure.dataset.ppColumnWidth ) || 300 );
		const schedule = keepLaidOut( figure, masonry.layout );

		// An image without width and height attributes has its height only
		// once it loads.
		figure.querySelectorAll( 'img' ).forEach( ( img ) => {
			if ( ! img.complete ) {
				img.addEventListener( 'load', schedule );
			}
		} );
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
