/**
 * PhotoPress Gallery Slideshow
 *
 * Turns a WordPress Gallery into a slideshow
 */

/**
 * Concrete slideshow class
 */
photopress.slideshow = function( selector, options ) {
	
	// Per-instance copies; the prototype's objects would be shared and mutated.
	this.options = jQuery.extend( true, {}, this.options );
	this.thumbnails = jQuery.extend( {}, this.thumbnails );
	
	this.options.selector = selector ? selector : this.options.selector;
	// apply instance specific options
	if ( options ) {
		
		var o;
		
		for( o in options ) {
			
			if ( options[ o ] != null) {
				this.options[ o ] = options[ o ];
			}
		}
	}
	
	var dom_options = [
		'thumbnailHeight', 
		'showThumbnails', 
		'showCaptions', 
		'detail_position',  
		'showTitleInCaption',
		'showDescriptionInCaption',
		'showAttachmentLink',
		'attachmentLinkText',
		'linkTo'
	];
	
	// load overrides from dom data attributes
	var that = this;
	dom_options.forEach( function (opt) {
		
		let dom_opt = jQuery( that.options.selector ).data( opt.toLowerCase() );
		
		// Options the markup does not set keep their defaults.
		if ( typeof dom_opt === 'undefined' ) {
			return;
		}
		
		if ( that.isValidJson( dom_opt ) ) {
			
			dom_opt = JSON.parse(dom_opt);
		}
				
		that.options[ opt ] = dom_opt;
	});
	
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
	viewportWidth: null,
	slideViewCount: 0,
	totalGalleryImages: 0,
	carouselNotWideEnough: false,
	isLoaded: false,
	isRendering: false,
	isOpen: false,
	gallery: null,			// the gallery the slideshow was built from
	slideRequest: 0,		// incremented per showSlide, so stale image loads are ignored
	options: {
		selector: '.photopress-slideshow',
		showDetails: true,										// show slide details
		showTitleInCaption: false,
		showDescriptionInCaption: false,
		showCaptions: true,
		showAttachmentLink: false,
		attachmentLinkText: 'Read More...',
		gallerySelector: '.photopress-has-slideshow',				// galleries with the slideshow turned on
		clickStart: true, 										// delay start of slideshow until something is clicked.
		clickStartSelector: '.photopress-has-slideshow .photopress-gallery-item', 		// DOM element to start the slideshow
		detail_position: 'bottom',
		thumbnailHeight: 120,
		showThumbnails: true,
		linkTo: 'attachment',
		thumbnailCarousel: {
			

			loop: true,
			autoWidth: true,
			center: true,
			margin:10,
			slideBy: 1,
			dots: false,
			startPosition: 0 									// the id of the image to start the slideshow on

/*
			wrapAround: true,
			setGallerySize: false,
			pageDots: false,
			imagesLoaded: true,
			prevNextButtons: false,
			initialIndex: 0
*/
			
			
		}
	},
	
	/**
	 * Helper method for getting option values
	 */
	getOption: function ( key ) {
		
		if ( this.options.hasOwnProperty( key ) ) {
		
			return this.options[key];
		}
	},
	
	isValidJson: function ( str ) {
		
	    try {
	        JSON.parse(str);
	    } catch (e) {
	        return false;
	    }
	    return true;
	},

	init: function() {
		
		// set the viewport height
		this.setViewportDimensions();
		
		// add window resize handler so that we can make the main slide image 
		// responsive to changes in viewport height.This is necessary because the flexbox
		// height is set explicitly using a css calc and will not shrink on its own unless 
		// we tell the CSS that the viewport height has changed. 
		jQuery( window ).on( 'resize orientationchange', this.setViewportDimensions.bind( this ) );
		
		this.registerHandlers();
		
		var that = this;
		
		// render if the click start is disabled.
		if ( this.getOption( 'clickStart' ) ) {
			
			// register click start handler
			var selector = this.getOption( 'clickStartSelector') ;
			
			jQuery(document).on('click', selector, function(e) {
				
				// intercept the click event
				e.preventDefault();
				
				let item = jQuery( this );
				let gallery = item.closest( that.getOption( 'gallerySelector' ) );
				
				// The position of the clicked item within its own gallery. The
				// click may land on a caption or a link rather than the image.
				let i = parseInt( item.find( 'img' ).first().data( 'position' ), 10 );
				
				if ( isNaN( i ) ) {
					i = gallery.find( '.photopress-gallery-item' ).index( item );
				}
				
				// A different gallery than last time: rebuild from that one.
				if ( ! that.gallery || that.gallery[0] !== gallery[0] ) {
					that.useGallery( gallery );
				}
				
				// display the lightbox and show the slide that was clicked on
				that.showLightbox( i );
				
			});
			
		} else {
			
			this.useGallery( jQuery( this.getOption( 'gallerySelector' ) ).first() );
			// show the lightbox and display the first slide.
			this.showLightbox( this.getStartPosition() );
		}		
	},
	
	/**
	 * Points the slideshow at a gallery, discarding slides and thumbnails
	 * built from another one.
	 */
	useGallery: function( gallery ) {
		
		if ( this.thumbnails.carousel ) {
			this.thumbnails.carousel.trigger( 'destroy.owl.carousel' );
		}
		
		jQuery( this.options.selector ).empty();
		
		this.gallery = gallery;
		this.isLoaded = false;
		this.thumbnails = { containerWidth: 0, count: 0, carousel: null, totalWidth: 0 };
		this.totalGalleryImages = gallery.find( '.photopress-gallery-item img' ).length;
	},
	
	displaySlideLoader: function() {
		
		jQuery('.panels .center').html('<div class="loader-circle"></div>');	
	},
	
	showSlide: function ( img ) {
		
		this.displaySlideLoader();
	
		var that = this;
		var request = ++this.slideRequest;
		var i = new Image;
			
		// calculate the img tags responsive "sizes" attribute: 
		// window height - thumbnails container height * aspect ratio of image.
		let aspectratio = jQuery(img).data('aspectratio');
		//let vh = this.viewportHeight;
		let thumbsHeight = jQuery('.thumbnails').outerHeight(true);
		
		var bodyStyles = window.getComputedStyle(document.body);
		thumbsHeight = bodyStyles.getPropertyValue('--pp-slideshow-thumbnails-total-height'); //get
		let vh = bodyStyles.getPropertyValue('--vh');
		let vw = this.viewportWidth;
		if ( this.viewportHeight <= this.viewportWidth ) {
			//i.sizes = `calc( ( var(--vh) - ${thumbsHeight} ) * ${aspectratio} )`;
			i.sizes = `calc( ( ${vh} - ${thumbsHeight} ) * ${aspectratio} )`;
		} else {
			
			i.sizes = `${vw}px`;
		}
		// fade in class
		jQuery(i).addClass('fade-in');
		
		// add data-id from the gallery image, just in case...
		jQuery(i).attr('data-id', jQuery(img).attr('data-id'));
		
		
				
		// fetch slide info from the gallery item
		let galleryItemId = jQuery(img).data('id');
		let caption = this.getCaptionFromGalleryItem( galleryItemId );
		let title = this.getDataFromGalleryItem( galleryItemId, 'image-title' );
		let description = this.getDataFromGalleryItem( galleryItemId, 'image-description' );
		let attachmentLink = this.getDataFromGalleryItem( galleryItemId, 'attachment-url' );
		
		let slideInfoPosition = this.getOption('detail_position'); 
		let showTitleInCaption = this.getOption('showTitleInCaption');
		let showCaptions = this.getOption('showCaptions');
		let showDescriptionInCaption = this.getOption('showDescriptionInCaption');
		let showAttachmentLink = this.getOption('showAttachmentLink');
		let attachmentLinkText = this.getOption('attachmentLinkText');
		
		//load handler that once image is loaded will insert it into the DOM
		jQuery(i).on('error', function() {
			
			if ( request === that.slideRequest ) {
				jQuery('.panels .center').html('<div class="slide-error">This image could not be loaded.</div>');
			}
		});
		
		jQuery(i).on('load', function() {
			
			// A later slide was requested while this one was loading.
			if ( request !== that.slideRequest ) {
				return;
			}
			
			//jQuery('.panels .center').html('<div class="main-image"></div>');
			jQuery('.panels .center').html(i);
			
			jQuery('.panels .center').append(`<div class="slide-info"></div>`);
			
			if ( slideInfoPosition === 'right' ) {
			
				jQuery( '.center' ).addClass('info-right');
			}
			
			if (title && showTitleInCaption ) {
				jQuery('.slide-info').append(`<div class="info title">${title}</div>`);
			}
			
			if (caption && showCaptions) {
				jQuery('.slide-info').append(`<div class="info caption">${caption}</div>`);
			}
			
			if ( description && showDescriptionInCaption ) {
				jQuery('.slide-info').append(`<div class="info description">${description}</div>`);
			}
			
			if ( showAttachmentLink ) {
				
				 
				jQuery('<div class="info attachment-link"></div>')
					.append( jQuery('<a></a>').attr( 'href', attachmentLink ).text( attachmentLinkText ) )
					.appendTo('.slide-info');
			}

		});
		
		// load the src of the image.
		let srcset= jQuery(img).attr('srcset');
		i.srcset = srcset;
		i.src = jQuery(img).attr('data-orig-file');
		
	},
	
	getThumbnailHeight: function() {
		
		var bodyStyles = window.getComputedStyle(document.body);
		
		let th = bodyStyles.getPropertyValue('--pp-slideshow-thumbnail-height'); //get
		
		return th;
	},
	
	setViewportDimensions: function() {
		
		// set class variable
		this.viewportHeight = window.innerHeight;
		this.viewportWidth = window.innerWidth;
		// set value as CSS variable for use in calculated styles
		document.documentElement.style.setProperty('--vh', `${this.viewportHeight}px`);
		//alert('height: ' + this.viewportHeight + ' ' + document.documentElement.clientHeight );
	},
	
	/**
	 * Hides the lightbox in the DOM
	 */
	hideLightbox: function () {
		
		this.isOpen = false;
		jQuery( '.lightbox' ).css('opacity','0');
		jQuery( '.lightbox' ).css({'z-index': '-100'});
		// display:none, or the hidden full-screen lightbox still paints over
		// the page background.
		jQuery( '.lightbox' ).hide();
		// re-enable scrolling of the body content
		jQuery( 'body' ).css('overflow', '');
		// fire hidden event in case anyone is listening
		jQuery( '.lightbox').trigger('pp-slideshow-closed');
	},
	
	/**
	 * Reveals the lightbox in the DOM
	 *
	 * @var i int the index of the slide to show.
	 */
	showLightbox: function( i ) {

		var that = this;
		
		this.isOpen = true;
		
		jQuery( '.lightbox' ).show('slow', function() {
			
			// render the slideshow for the first time.
			if ( ! that.isLoaded ) {
				
				// A second click while the first render is still loading
				// thumbnails must not render again.
				if ( ! that.isRendering ) {
					that.render( i );
				}
				
			} else {
				
				// position the carousel and then show slide that was clicked on
					
				that.scrollToSlide( i );
				that.showSlide( that.getCurrentSlide() );
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
		
		that.gallery.find( '.photopress-gallery-item img' ).each( function( i ) {
			
			// clone the image
			var ni = jQuery(this).clone();
			
			// add thumnail class
			jQuery(ni).addClass('thumbnail');
			
			// add data position attribute
			jQuery(ni).attr('data-position', i + 1);
			
			// Missing values would make the width NaN, and the loop in render()
			// that adds thumbnails until they are wide enough would never end.
			let aspectRatio = parseFloat( jQuery(ni).attr('data-aspectratio') )
				|| ( this.naturalWidth && this.naturalHeight ? this.naturalWidth / this.naturalHeight : 1 );
			
			let thumbnailHeight = parseInt( that.getOption('thumbnailHeight'), 10 ) || 120;
			
			let thumbnailWidth = Math.round( thumbnailHeight * aspectRatio );
			
			// add data sizes attribute
			jQuery(ni).attr('sizes', `${thumbnailWidth}px`);
			
			// necessary to avoid causing the lazyload lib to force loading the src.
			jQuery(ni).attr('src', '');
			
			jQuery(ni).attr('width', thumbnailWidth);
			jQuery(ni).attr('height', thumbnailHeight );
						
			// update thumbnail count
			that.thumbnails.count++;
			//console.log(that.thumbnails.count);
			
			// update total width of thumbnails.
			that.thumbnails.totalWidth = that.thumbnails.totalWidth + thumbnailWidth;
			//console.log(that.thumbnails.totalWidth);
			// append it to the thumbnail 
			jQuery('.thumbnail-list').append( '<div class="thumbnail-item item">' + ni[0].outerHTML + "</div>" );
						
		});

		// if there are so few slides that thy don't even reach half way acrosos the container
		if (that.thumbnails.count == that.totalGalleryImages && that.thumbnails.totalWidth < that.thumbnails.containerWidth / 2 ) {
			//console.log('stopping thumb generation short.');
			
			//shrink the thumbnail container
			jQuery('.thumbnails').css({
				
				'width': that.thumbnails.totalWidth +'px'
			});
			
			// stop the thumbnail generation process by returing false
			return false;
				
		}
		
		// stop generating once we have double the width of the container
		// Extra duplicate thumbnails are needed becuase the carousel libraries don't 
		// handle looping well and show gaps etc.
		
		//console.log('total width', that.thumbnails.totalWidth);
		//console.log('container width', that.thumbnails.containerWidth);
		
		if (that.thumbnails.totalWidth > that.thumbnails.containerWidth * 2 ) {
			//console.log('thumb generation complete.');
			// stops the do loop
			return false;
		}
		
		return true;


	},
	
	getCaptionFromGalleryItem: function( id ) {
		
		return this.getDataFromGalleryItem( id, 'caption' );
	},
	
	getDataFromGalleryItem: function( id, key ) {
		
		var that = this;
		
		let value = that.gallery.find( '.photopress-gallery-item[data-id="' + id + '"]' ).find('img').data( key );	
		
		// .data() turns a caption such as "2024" into a number.
		if ( typeof value !== 'undefined' && value !== null && String( value ).length > 0 ) {
			
			return String( value );
		}
	},
	
	getDescriptionFromGalleryItem: function( id ) {
		
		
	},
	
	/**
	 * Renders the Slideshow
	 */
	render: function ( i ) {
		
		var that = this;
		
		this.isRendering = true;
		
		// create inner dom scaffolding
		let o = '';
		
		o += '<div class="panels">';
				
			o +='<div class="nav-control left"><i class="arrow left"></i></div>';
			o +='<div class="center"><div class="loader-circle"></div></div>';
			o +='<div class="nav-control right"><i class="arrow right"></i></div>';
			
		o += '</div>';
		
		o += '<div class="thumbnails"><div class="thumbnail-list owl-carousel"></div></div>';
				
		jQuery( that.options.selector ).append( o );
		
		// A press on the left or right half of the slide goes back or forward,
		// as in the Gallery Slideshow block (src/shared/press-navigation.js).
		if ( photopress.pressNavigation ) {
			
			photopress.pressNavigation( jQuery( that.options.selector ).find( '.panels' )[0], {
				prev: that.previous.bind( that ),
				next: that.next.bind( that ),
				ignore: 'a'
			} );
		}
		
		if (! this.getOption( 'showThumbnails' ) ) {
			
			//center the flex container items as thumbs no longer need ot be pined to the bottom.
			jQuery('.photopress-slideshow').css( { 'justify-content':'center' } );
			
			// hide the container
			jQuery('.photopress-slideshow .thumbnails').css({'opacity':'0', 'height':0});
			jQuery('.panels .center').css({'padding':'20px'});
			
			// reset css variable to 0
			document.documentElement.style.setProperty('--pp-slideshow-thumbnails-total-height', '0px');
		}
		
		this.thumbnails.containerWidth = jQuery('.thumbnails').outerWidth();
			
		// clone a second set of thumbnails if there aren't enough to fill the entire container.
		// the carousel library should handle this but it does not, so better safe than sorry.		
		
		// Capped in case the widths never reach the target.
		for ( let pass = 0; pass < 20; pass++ ) {
			
			if ( ! this.generateThumbnailImages() ) {
				
				break;
			}
		}
		
		// try to wait for thumbnails to load...
		jQuery('.thumbnail-list').imagesLoaded( function( instance ) {
			
			setTimeout(function() {
				
				// initialize the carousel once all the thumbnails have loaded
				that.initCarousel( i );	
				
			}, 700)
			
		});
	},
	
	/**
	 * Initialize the carousel
	 *
	 * @var i int the starting slide postion
	 */
	initCarousel: function( i ) {
	
		var that = this;
		// initialize the thumbnail carousel
		
		// set the start position of the carousel
		that.setStartPosition( i );
		
		this.initThumbnailCarousel( this.getOption('thumbnailCarousel'), function() {
			
			// show first slide
			var img = that.getCurrentSlide();
			// show the start slide
			that.showSlide( img );
					
			// set the loaded flag so that we do not render again if lightbox is 
			// closed and then re-opened.
			that.isLoaded = true;
			that.isRendering = false;

		});
	},
	
	/**
	 * Registers Slideshow Event handlers
	 */
	registerHandlers: function() {
		
		var that = this;
		
		// left arrow icon handler	
		jQuery( document ).on( 'click', '.nav-control.left', function(e) {
		
			that.previous();
		});
		
		// right arrow icon handler
		jQuery( document ).on( 'click', '.nav-control.right', function(e) {
					
			that.next();
		});
		
		// handler for clicking on image directly.
		jQuery( document ).on( 'click', '.thumbnail-item', function(e) {
			
			let position = that.getSlidePosition(e.target);
			
			that.scrollToSlide( position );
		
			that.showSlide( jQuery( e.target ) );
		
		});

		
		// Keypress event handlers
		// these just fire the click event on the prev/next elements.
		jQuery(document).on( 'keydown', function( e ) {
			
			// Leave the keys alone while the lightbox is closed, and in fields.
			if ( ! that.isOpen || jQuery( e.target ).is( 'input, textarea, select, [contenteditable]' ) ) {
				return;
			}
			
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
		jQuery(document).on('click', '.lightbox .lightbox__close', function( e ){
    	
			e.preventDefault();
			that.hideLightbox();
		});

	},
	
	previous: function() {
		
		if ( ! this.isLoaded ) {
			return;
		}
		
		this.scrollToPreviousSlide();
		this.showSlide( this.getCurrentSlide() );
	},
	
	next: function() {
		
		if ( ! this.isLoaded ) {
			return;
		}
		
		this.scrollToNextSlide();
		this.showSlide( this.getCurrentSlide() );
	},
	
	getSlideImgById: function( id ) {
		
		return jQuery( '.thumbnail[data-id=' + id + ']' );
	},
	
	// uses img data-position attr which is set on each thumbnail during
	// the cloning process.
	getSlidePosition: function( el ) {
		
		return jQuery( el ).attr('data-position') - 1;
	},
	
	// Carousel specific implementation
	scrollToSlide: function( index ) {
		
		this.thumbnails.carousel.trigger("to.owl.carousel", [ index, 300, true]);
		//this.thumbnails.carousel.select( index, this.options.thumbnailCarousel.wrapAround);
	},
	
	// Carousel specific implementation
	scrollToNextSlide: function() {
		
		this.thumbnails.carousel.trigger('next.owl');
		//this.thumbnails.carousel.next();
	},
	
	// Carousel specific implementation
	scrollToPreviousSlide: function() {
		
		this.thumbnails.carousel.trigger('prev.owl');
		//this.thumbnails.carousel.previous();
		
	},
	
	// Carousel specific implementation
	initThumbnailCarousel: function( options, callback ) {
		
		this.thumbnails.carousel = jQuery(".thumbnail-list").owlCarousel( options );
		//this.thumbnails.carousel = new Flickity(".thumbnail-list", options );
		
		if (callback) {
			
			callback();
		}
	},
	
	// Carousel specific implementation
	getCurrentSlide: function() {
	
		return jQuery( '.owl-item.center' ).find('img');
		//return jQuery( '.is-selected' ).find('img');
	},
	
	// Carousel specific implementation
	setStartPosition: function( index ) {
		
		this.options.thumbnailCarousel.startPosition = index;
	},
	
	getStartPosition: function() {
		
		var thumbnailCarousel = this.getOption('thumbnailCarousel');
		return thumbnailCarousel.startPosition;
	}
		
};



jQuery( function() {
	
	// if slideshow contain is present
	if ( document.getElementById('lightbox-gallery') ) {
		new photopress.slideshow();	
	}
});
