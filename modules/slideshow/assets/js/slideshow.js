/**
 * PhotoPress Gallery Slideshow
 *
 * Turns a WordPress Gallery into a slideshow
 */

/**
 * Concrete slideshow class
 */
photopress.slideshow = function( selector, options ) {
	
	// set ids and selectors
	this.dom_id = 'photopress-slideshow' ;
	
	// keeps track of how many slides have been viewed
	this.slide_view_counter = 0;
	
	// initial right cpation height
	this.right_caption_height;
	
	// default options
	this.options = {
	
		showDetails: true,										// show slide details
		detail_components: {									// detail components to be displayed
			title: true, 
			caption: true,
			description: false
		},		
		lightbox: true,											// display slideshow in lightbox mode
		clickStart: false, 										// delay start of slideshow until something is clicked.
		clickStartSelector: '.gallery-icon', 					// DOM element to start the slideshow
		detail_position: 'bottom',
		thumbnail_height: 120,
		mobile_width_breakpoint: 900								// Position of the slide details relative to image. e.g. bottom || right		
		
	};
	
	// apply instance specific options
	if ( options ) {
		
		var o;
		
		for( o in options ) {
			
			if ( options[ o ] != null) {
				this.options[ o ] = options[ o ];
			}
		}
	}
	
	// setup DOM access to the WordPress Gallery
	this.gallery = selector;
	
	// placeholder for an image resizer
	this.resizer = {};
	
	// initialize the slideshow
	this.init();
	
};

/**
 * Abscract Slideshow Class
 */
photopress.gallery.slideshow.prototype = {
	
	/**
	 * Helper method for getting option values
	 */
	getOption: function( key ) {
		
		if ( this.options.hasOwnProperty( key ) ) {
		
			return this.options[key];
		}
	},

	init: function() {
		
		var that = this;
		
		
		//  once document is ready
		jQuery( document ).ready( function() {
			
			// REDNER LIGHTBOX
			that.render();	
			

		   	
		   	jQuery( document ).on( 'click', '.main_image_container .next', function() {
        	
        		// needed?
	        	if ( jQuery( this ).hasClass( "pending" ) ) {
	        		return;
	        	}
				
				// find the target slide to show
				var target = jQuery( '.makeMeScrollable img.current' ).next();
				
				if ( ! jQuery(target).length) {
	         	
	         	   target = jQuery( '.makeMeScrollable img:first-child' );
			 	}
			 	
			 	// trigger a click on the target
			 	target.click();
			
			});
		
			// prev next click event handlers.
			jQuery(document).on('click', '.main_image_container .prev', function(){
	        	
	        	// needed?
	        	if ( jQuery(this).hasClass("pending") ) {
	        		return;
	        	}
				
				// find the target slide to show
				var target = jQuery('.makeMeScrollable img.current').prev();
				
				if ( ! jQuery(target).length) {
	         	
	         	   target = jQuery('.makeMeScrollable img:last-child');
			 	}
			 	
			 	// trigger click on the target
			 	target.click();
			
			});
		
			// Keypress event handlers
			// these just fire the click event on the prev/next elements.
			jQuery(document).keydown( function( e ) {
				
				switch( e.which ) {
				
			        case 37: // left arrow
			        	e.preventDefault(); // prevent the default action (scroll / move caret)
			        	jQuery( '.makeMeScrollable img.current' ).prev().click();
						break;
			
			        case 39: // right arrow
			        	e.preventDefault(); // prevent the default action (scroll / move caret)
			        	jQuery( '.makeMeScrollable img.current' ).next().click();
			        break;
			        
			        case 27: // esc
			        	e.preventDefault(); // prevent the default action (scroll / move caret)
			        	jQuery( '.photopress_gallery_slideshow .lightbox_close' ).click();
						break;
			
			        default: 
			        	return; // exit this handler is needed for other keypress handlers
			    }
			     
			});
			
			jQuery(document).on('click', '.photopress_gallery_slideshow .lightbox_close', function(){
        	
				that.hideLightbox();
			});

			
		});
			
	},
	
	/**
	 * Hides the lightbox in the DOM
	 */
	hideLightbox: function () {
		
		jQuery( '.photopress_gallery_slideshow' ).css('opacity','0');
		jQuery( '.photopress_gallery_slideshow' ).css({'z-index': '-100'});
		// re-enable scrolling of the body content
		jQuery( 'body' ).css('overflow', 'auto');
		// fire hidden event in case anyone is listening
		jQuery( '.photopress_gallery_slideshow').trigger('slideshow-hidden');
		
	},
	
	/**
	 * Reveals the lightbox in the DOM
	 */
	showLightbox: function() {
		
		jQuery( '.photopress_gallery_slideshow' ).css('opacity', '1');
		jQuery( '.photopress_gallery_slideshow' ).css('z-index', '99999');
		// remove scroll bar for body of document
		jQuery( 'body' ).css('overflow', 'hidden');
		// fire reveals event in case anyone is listening
		jQuery( '.photopress_gallery_slideshow').trigger('slideshow-revealed');
		
	},
	
	/**
	 * Increment the view coounter
	 */
	incrementViewCounter: function() {
		
		this.slide_view_counter++;
	},
	
	getViewCount: function() {
		
		return this.slide_view_counter;
	},
	
	/**
	 * Main slideshow render method
	 */
	render: function () {
		
	},
	
};