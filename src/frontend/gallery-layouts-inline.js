/**
 * Lays out a masonry or mosaic gallery as soon as its markup is parsed, so
 * that the browser first paints it laid out, rather than stacked in one
 * column, or in rows not yet justified, until the page's scripts run.
 * modules/gallery/gallery.php prints this inline, once, before the first
 * such gallery, and after each a call:
 *
 *   photopressLayout( document.currentScript.previousElementSibling )
 *
 * Each gallery is hidden until it is laid out here (src/variations/style.scss).
 * A gallery whose CSS has not applied yet (a stylesheet printed after it) is
 * left to the front-end script (src/frontend/gallery-layouts.js), which lays
 * galleries out again the same way, shows them, and keeps them laid out on
 * resize.
 */

/**
 * Internal dependencies
 */
import { createMasonry } from '../shared/gallery-layout/masonry.js';
import { justify } from '../shared/gallery-layout/justify.js';

window.photopressLayout = ( figure ) => {
	if ( ! figure || ! figure.matches( '.wp-block-gallery.photopress-layout' ) ) {
		return;
	}

	const style = window.getComputedStyle( figure );

	if ( figure.matches( '.photopress-layout-masonry' ) && style.position === 'relative' ) {
		createMasonry( figure, parseFloat( figure.dataset.ppColumnWidth ) || 300 );
	} else if ( figure.matches( '.photopress-layout-mosaic' ) && style.flexWrap === 'wrap' ) {
		justify( figure, parseFloat( figure.dataset.ppRowHeight ) || 300 );
	} else {
		return;
	}

	// Shown now that it is laid out (see src/variations/style.scss).
	figure.classList.add( 'photopress-laid-out' );
};
