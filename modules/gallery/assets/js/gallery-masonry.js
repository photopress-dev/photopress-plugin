( function( $ ) {
	'use strict';

	$(window).load(function(){
		var container = $( '.photopress-gallery-masonry' );
		//container.imagesLoaded( function() {
			container.masonry( {
				itemSelector: '.photopress-gallery-item',
				transitionDuration: '0',
				percentPosition: false,
				columnWidth: '.grid-sizer',
				gutter: '.gutter-sizer',
				isFitWidth: true
			} );
			
			container.css({opacity: 1});
			$( document ).trigger('resize');
		//} );
	} );
}( jQuery ) );