/**
 * PhotoPress Gallery Slideshow
 *
 * Turns a WordPress Gallery into a slideshow
 */

/**
 * Concrete slideshow class
 */
photopress.slideshow = function( selector, options ) {
	
	// set the viewport height
	this.setViewportHeight();
	
	// add window resize handler so that we can make the main slide image 
	// responsive to changes in viewport height.This is necessary because the flexbox
	// height is set explicitly using a css calc and will not shrink on its own unless 
	// we tell the CSS that the viewport height has changed. 
	window.onresize = this.setViewportHeight;
	
	// apply instance specific options
	if ( options ) {
		
		var o;
		
		for( o in options ) {
			
			if ( options[ o ] != null) {
				this.options[ o ] = options[ o ];
			}
		}
	}
	
	// initialize the slideshow
	this.init();
	
};

/**
 * Abscract Slideshow Class
 */
photopress.slideshow.prototype = {
	
	thumbnails: {
		containerWidth: 0,
		count: 0,
		carousel: null,
		totalWidth: 0
	},
	viewportHeight: null,
	slideViewCount: 0,
	options: {
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
		thumbnailHeight: 150,
		thumbnailCarousel: {
			
			loop: true,
			autoWidth: true,
			center: true,
			margin:10,
			slideBy: 1,
			dots: false
		}
	},
	
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
	},
	
	displaySlideLoader: function() {
		
		jQuery('.panels .center').html('<div class="loader-circle"></div>');	
	},
	
	showSlide: function ( img ) {
		
		this.displaySlideLoader();
	
		var i = new Image;
			
		// calculate the img tags responsive "sizes" attribute: 
		// window height - thumbnails container height * aspect ratio of image.
		let aspectratio = jQuery(img).data('aspectratio');
		let vh = this.viewportHeight;
		let thumbsHeight = jQuery('.thumbnails').outerHeight(true);
		
		var bodyStyles = window.getComputedStyle(document.body);
		thumbsHeight = bodyStyles.getPropertyValue('--pp-slideshow-thumbnails-total-height'); //get
		i.sizes = `calc( ( var(--vh) - ${thumbsHeight} ) * ${aspectratio} )`;
		
		// fade in class
		jQuery(i).addClass('fade-in');
		
		// add data-id from the gallery image, just in case...
		jQuery(i).attr('data-id', jQuery(img).attr('data-id'));
		
		let caption = this.getCaptionFromGalleryItem( jQuery(img).data('id') );
		//load handler that once image is loaded will insert it into the DOM
		jQuery(i).on('load', function() {
			//jQuery('.panels .center').html('<div class="main-image"></div>');
			jQuery('.panels .center').html(i);
			
			if (caption) {
				jQuery('.panels .center').append(`<div class="caption">${caption}</div>`);
			}
		});
		
		// load the src of the image.
		let srcset= jQuery(img).attr('srcset');
		i.srcset = srcset;
		i.src = jQuery(img).attr('data-full-url');
		
	},
	
	setViewportHeight: function() {
		
		// set class variable
		this.viewportHeight = window.innerHeight;
		// set value as CSS variable for use in calculated styles
		document.documentElement.style.setProperty('--vh', `${this.viewportHeight}px`);
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
		
		this.slideViewCount++;
	},
	
	getViewCount: function() {
		
		return this.slideViewCount;
	},
	
	generateThumbnailImages: function() {
		
		var that = this;
		
		jQuery( that.getOption( 'gallerySelector' ) ).find('img').each( function( i ) {
			
			// clone the image
			var ni = jQuery(this).clone();
			
			// add thumnail class
			jQuery(ni).addClass('thumbnail');
			
			// add data position attribute
			jQuery(ni).attr('data-position', i + 1);
			
			// append it to the thumbnail 
			jQuery('.thumbnail-list').append( '<div class="thumbnail-item item">' + ni[0].outerHTML + "</div>" );
			
			// update totle width of thumbnails.
			aspectRatio = jQuery(ni).attr('data-aspectRatio');
			that.thumbnails.totalWidth += that.getOption('thumbnailHeight') *  aspectRatio;
			
			// update thumbnail count
			that.thumbnails.count++;
		});

	},
	
	getCaptionFromGalleryItem: function( id ) {
		
		var that = this;
		
		let caption = jQuery( that.getOption( 'gallerySelector' ) + ' .photopress-gallery-item[data-id=' + id + ']').find('figcaption').html();	
		
		if ( typeof caption !== 'undefined' && caption !== '' && caption !== null && caption.length > 0 ) {
			
			return caption;
		}
	},
	
	/**
	 * Renders the Slideshow
	 */
	render: function () {
		
		this.thumbnails.containerWidth = jQuery('.thumbnails').outerWidth();
		
		// Clone gallery images for thumbnail carousel
		this.generateThumbnailImages();
		
		// clone a second set of thumbnails if there aren't enough to fill the entire container.
		// the carousel library should handle this but it does not, so better safe than sorry.		
		if (this.thumbnails.totalWidth > this.thumbnails.containerWidth / 2 && this.thumbnails.totalWidth < this.thumbnails.containerWidth ) {
			
			//console.log('starting second cloning pass');
			
			this.generateThumbnailImages();
		}
		
		// disable the loop/wrap-around on the carousel if there are too few slides
		if (this.thumbnails.totalWidth < this.thumbnails.containerWidth / 2 ) {
			
			this.options.thumbnailCarousel.loop = false;
		}
	
		// initialize the thumbnail carousel
		this.initThumbnailCarousel( this.getOption('thumbnailCarousel') );
		
		// show first slide
		var img = this.getCurrentSlide();
		
		// set right caption class
		
		this.showSlide( img );
		
		this.registerHandlers();
				
		// set the loaded flag so that we do not render again if lightbox is 
		// closed and then re-opened.
		this.options.isLoaded = true;
	},
	
	/**
	 * Registers Slideshow Event handlers
	 */
	registerHandlers: function() {
		
		var that = this;
		
		// left arrow icon handler	
		jQuery( document ).on( 'click', '.nav-control.left', function(e) {
		
			that.scrollToPreviousSlide();
			
			that.showSlide( that.getCurrentSlide() );
			
		});
		
		// right arrow icon handler
		jQuery( document ).on( 'click', '.nav-control.right', function(e) {
					
			that.scrollToNextSlide();
			
			that.showSlide( that.getCurrentSlide() );
		
		});
		
		// handler for clicking on image directly.
		jQuery( document ).on( 'click', '.thumbnail-item', function(e) {
			
			let position = that.getSlidePosition(e.target);
			
			that.scrollToSlide( position );
		
			that.showSlide( jQuery( e.target ) );
		
		});

		
		// Keypress event handlers
		// these just fire the click event on the prev/next elements.
		jQuery(document).keydown( function( e ) {
			
			switch( e.which ) {
		        
		        case 27: // esc
		        	e.preventDefault(); // prevent the default action (scroll / move caret)
		        	jQuery( '.lightbox .lightbox__close' ).click();
					break;
				
				case 37: // left arrow key
		        	e.preventDefault(); // prevent the default action (scroll / move caret)
		        	jQuery('.nav-control.left').click();
					break;
					
				case 39: // right arrow key
		        	e.preventDefault(); // prevent the default action (scroll / move caret)
		        	jQuery('.nav-control.right').click();
					break;
					
		        default: 
		        	return; // exit this handler is needed for other keypress handlers
		    }
		     
		});
		
		// close lightbox control
		jQuery(document).on('click', '.lightbox .lightbox__close', function(){
    	
			that.hideLightbox();
		});

	},
	
	// uses img data-position attr which is set on each thumbnail during
	// the cloning process.
	getSlidePosition: function( el ) {
		
		return jQuery( el ).attr('data-position') - 1;
	},
	
	// Carousel specific implementation
	scrollToSlide: function( index ) {
		
		this.thumbnails.carousel.trigger("to.owl.carousel", [ index, 300, true]);
	},
	
	// Carousel specific implementation
	scrollToNextSlide: function() {
		
		this.thumbnails.carousel.trigger('next.owl');
	},
	
	// Carousel specific implementation
	scrollToPreviousSlide: function() {
		
		this.thumbnails.carousel.trigger('prev.owl');
		
	},
	
	// Carousel specific implementation
	initThumbnailCarousel: function( options ) {
		
		this.thumbnails.carousel = jQuery(".thumbnail-list").owlCarousel( options );
	},
	
	// Carousel specific implementation
	getCurrentSlide: function() {
		
		return jQuery( '.owl-item.center' ).find('img');
	}
		
};



jQuery(window).load(function() {
	
	new photopress.slideshow();	
});
