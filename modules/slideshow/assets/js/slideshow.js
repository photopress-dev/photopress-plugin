/**
 * Where the lightbox's script was before PhotoPress 1.10. A page cached by
 * an earlier version still asks for it, after jQuery and Owl Carousel; it
 * now loads the lightbox from dist/lightbox.build.js (src/frontend/lightbox.js),
 * which needs neither.
 */
( function () {
	var script = document.currentScript;

	if ( ! script || window.photopressLightbox ) {
		return;
	}

	var lightbox = document.createElement( 'script' );
	lightbox.src = script.src.replace( /modules\/slideshow\/assets\/js\/slideshow\.js.*$/, 'dist/lightbox.build.js' );
	document.head.appendChild( lightbox );
} )();
