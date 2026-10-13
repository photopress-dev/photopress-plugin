/**
 * The slideshows on the page, from one script: each Gallery Slideshow block
 * (modules/gallery/GallerySlideshow.php), and the full-screen slideshow a
 * gallery opens (src/frontend/lightbox.js). Both are the slideshow of
 * src/shared/slideshow.js.
 */

import { createSlideshow } from '../shared/slideshow';
import { startLightbox } from './lightbox';

export { createSlideshow, attachmentIdOf } from '../shared/slideshow';

export function init( doc = document ) {
	doc.querySelectorAll( '.wp-block-photopress-gallery-slideshow' ).forEach( ( root ) => {
		if ( ! root.photopressSlideshow && root.querySelector( '.photopress-gallery-slideshow__slide' ) ) {
			root.photopressSlideshow = createSlideshow( root );
		}
	} );
	startLightbox( doc );
}

if ( typeof document !== 'undefined' ) {
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', () => init() );
	} else {
		init();
	}
}
