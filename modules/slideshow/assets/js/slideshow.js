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
	
	this.thumbnailCarousel = null;
	// default options
	this.options = {
		isLoaded: false,
		showDetails: true,										// show slide details
		detail_components: {									// detail components to be displayed
			title: true, 
			caption: true,
			description: false
		},
		gallerySelector: '.photopress-gallery',
		clickStart: true, 										// delay start of slideshow until something is clicked.
		clickStartSelector: '.photopress-gallery-item', 		// DOM element to start the slideshow
		detail_position: 'bottom',
		thumbnail_height: 120,
		thumbnailCarousel: {
			
			loop: true,
			autoWidth: true,
			center: true,
			margin:10,
			slideBy: 1,
			dots: false
		}
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
photopress.slideshow.prototype = {
	
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
		
		// render if the click start is disabled.
		if ( this.getOption( 'clickStart' ) ) {
			
			// register click start handler
			var selector = this.getOption( 'clickStartSelector') ;
			
			jQuery(document).on('click', selector, function() {
				
				that.showLightbox();
			});
			
		} else {
			
			this.render();
		}
	
		// Keypress event handlers
		// these just fire the click event on the prev/next elements.
		jQuery(document).keydown( function( e ) {
			
			switch( e.which ) {
		        
		        case 27: // esc
		        	e.preventDefault(); // prevent the default action (scroll / move caret)
		        	jQuery( '.lightbox .lightbox__close' ).click();
					break;
		
		        default: 
		        	return; // exit this handler is needed for other keypress handlers
		    }
		     
		});
		
		jQuery(document).on('click', '.lightbox .lightbox__close', function(){
    	
			that.hideLightbox();
		});
		
	},
	
	showSlide: function ( img ) {
		
		jQuery('.panels .center').html('<div class="loader-circle"></div>');
		var i = new Image;
		i.srcset = jQuery(img).attr('srcset');
		
		// calculate the img tags resposive "sizes" attribute: 
		// window height - thumbnails container height * aspect ratio of image.
		let aspectratio = jQuery(img).data('aspectratio');
		let vh = window.innerHeight;
		let thumbsHeight = jQuery('.thumbnails').outerHeight(true);
		i.sizes = `calc((${vh}px - ${thumbsHeight}px) * ${aspectratio})`;
		
		// fade in class
		jQuery(i).addClass('fade-in');
		
		// add data-id from the gallery image, just in case...
		jQuery(i).attr('data-id', jQuery(img).attr('data-id'));
		
		//load handler that once image is loaded will insert it into the DOM
		jQuery(i).on('load', function() {
			
			//jQuery('.panels .center').html('<div class="main-image"></div>');
			jQuery('.panels .center').html(i);
		});
		
		// load the src of the image.
		i.src = jQuery(img).attr('data-full-url');
		
	},
	
	/**
	 * Hides the lightbox in the DOM
	 */
	hideLightbox: function () {
		
		jQuery( '.lightbox' ).css('opacity','0');
		jQuery( '.lightbox' ).css({'z-index': '-100'});
		// re-enable scrolling of the body content
		jQuery( 'body' ).css('overflow', 'auto');
		// fire hidden event in case anyone is listening
		jQuery( '.lightbox').trigger('pp-slideshow-closed');
		
	},
	
	/**
	 * Reveals the lightbox in the DOM
	 */
	showLightbox: function() {
		
		var that = this;
		
		jQuery( '.lightbox' ).show('slow', function() {
			
			var img = jQuery( '.owl-item.center' ).find('img');
			that.showSlide( img );
			
			if ( ! that.getOption('isLoaded') ) {
				
				that.render();
			}
			
			jQuery( '.lightbox' ).css('opacity', '1');
			jQuery( '.lightbox' ).css('z-index', '99999');
			// remove scroll bar for body of document
			jQuery( 'body' ).css('overflow', 'hidden');
			// fire reveals event in case anyone is listening
			jQuery( '.lightbox').trigger('pp-slideshow-opened');
			
		});
		
		
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
		
		var that = this;
		var count;
		var containerWidth = jQuery('.thumbnails').outerWidth();
		var itemsWidth = 0;
		//load images from the gallery
		jQuery( that.getOption( 'gallerySelector' ) ).find('img').each( function( i ) {
			
			// clone the image
			var ni = jQuery(this).clone();
			// add thumnail class
			jQuery(ni).addClass('thumbnail');
			// add data position attribute
			jQuery(ni).attr('data-position', i + 1);
			// append it to the thumbnail 
			jQuery('.thumbnail-list').append( '<div class="thumbnail-item item">' + ni[0].outerHTML + "</div>" );
			count = i;
			itemsWidth = itemsWidth + jQuery('.thumbnail-item').last().width();
		});	
		
		console.log(itemsWidth);
		console.log(containerWidth);
		if (itemsWidth < containerWidth) {
			
			// double the slides to create a wrap around effect in the carousel.
			jQuery( that.getOption( 'gallerySelector' ) ).find('img').each( function( i ) {
				
				// clone the image
				var ni = jQuery(this).clone();
				// add thumnail class
				jQuery(ni).addClass('thumbnail');
				// add data position attribute
				jQuery(ni).attr('data-position', i + count + 1);
				// append it to the thumbnail 
				jQuery('.thumbnail-list').append( '<div class="thumbnail-item item">' + ni[0].outerHTML + "</div>" );
				count = i;
				
			});	
		}
		
	
		//initialize the thumbnail carousel
		this.initThumbnailCarousel( that.getOption('thumbnailCarousel') );
		
		//show first slide
		var img = jQuery( '.owl-item.center' ).find('img');
		that.showSlide( img );
		
		// hook left arrow icon handler	
		jQuery( document ).on( 'click', '.nav-control.left', function(e) {
		
			that.scrollToPreviousSlide();
			
			var img = jQuery( '.owl-item.center' ).find('img');
			that.showSlide( img );
			
		
		});
		
		// hook right arrow icon handler
		jQuery( document ).on( 'click', '.nav-control.right', function(e) {
					
			that.scrollToNextSlide();
			
			var img = jQuery( '.owl-item.center' ).find('img');
			that.showSlide( img );
		
		});
		
		// hook arrow key handlers
		jQuery(document).on('keydown', function( event ) { //attach event listener
		   
		    if( event.keyCode == 37) {
			    
		        jQuery('.nav-control.left').click();
		        		    }
		    if(event.keyCode == 39) {
			   
		        jQuery('.nav-control.right').click();
		    }
		});
		
		// hook handler for clicking on image directly.
		jQuery( document ).on( 'click', '.thumbnail-item', function(e) {
			
			let position = that.getSlidePosition(e.target);
			
			that.scrollToSlide( position );
		
			that.showSlide( jQuery( e.target ) );
		
		});
		
		// set the loaded flag so that we do not render again.
		this.options.isLoaded = true;
	},
	
	getSlidePosition: function( el ) {
		
		return jQuery( el ).attr('data-position') - 1;
	},
	
	scrollToSlide: function( index ) {
		
		this.thumbnailCarousel.trigger("to.owl.carousel", [ index, 300, true]);
	},
	
	scrollToNextSlide: function() {
		
		this.thumbnailCarousel.trigger('next.owl');
	},
	
	scrollToPreviousSlide: function() {
		
		this.thumbnailCarousel.trigger('prev.owl');
		
	},
	
	initThumbnailCarousel: function( options ) {
		
		this.thumbnailCarousel = jQuery(".thumbnail-list").owlCarousel( options );
	}
	
};



jQuery(window).load(function() {
	
	new photopress.slideshow();	
});


