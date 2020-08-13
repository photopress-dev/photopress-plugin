

( function( $ ) {
	'use strict';

	const container = $( '.photopress-gallery-masonry' );

	$( document ).ready( function() {
		//container.imagesLoaded().done( function() {
			container.masonry( {
				itemSelector: '.photopress-gallery-item',
				transitionDuration: '0',
				percentPosition: false,
				columnWidth: '.grid-sizer',
				gutter: '.gutter-sizer',
				isFitWidth: true
			} );
			
			container.css({opacity: 1});
		//} );
	} );
}( jQuery ) );