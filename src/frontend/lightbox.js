/**
 * The full-screen slideshow (the lightbox) of galleries with the slideshow
 * on. Markup: modules/slideshow/slideshow.php (printLightbox); styles:
 * src/shared/styles/slideshow.scss.
 *
 * No jQuery and no carousel library: the thumbnails are a row that scrolls,
 * with the current one brought to the middle.
 *
 * What it shows of each image comes from the gallery's own markup (its
 * srcset, width and height, and caption), and from the data-* attributes
 * the metadata module adds to slideshow galleries: the full-size file, the
 * shape, and the media library's caption, title, description and page.
 */
import { pressNavigation } from '../shared/press-navigation';

const GALLERY = '.photopress-has-slideshow';
const ITEM = '.photopress-gallery-item';

/** The settings, as printLightbox() gives them, and their defaults. */
const DEFAULTS = {
	thumbnailheight: 120,
	showthumbnails: true,
	showcaptions: true,
	detail_position: 'bottom',
	showtitleincaption: false,
	showdescriptionincaption: false,
	showattachmentlink: false,
	attachmentlinktext: 'Read More...',
};

/** A setting from the slideshow's data-* attribute: true is "1", false is "". */
function setting( root, key ) {
	const value = root.getAttribute( `data-${ key }` );
	if ( value === null ) {
		return DEFAULTS[ key ];
	}
	try {
		return JSON.parse( value );
	} catch ( e ) {
		return value;
	}
}

/** The widths in an image's srcset, and their files, largest first. */
function candidates( img ) {
	return ( img.getAttribute( 'srcset' ) || '' )
		.split( ',' )
		.map( ( part ) => part.trim().split( /\s+/ ) )
		.filter( ( [ url, w ] ) => url && /^\d+w$/.test( w || '' ) )
		.map( ( [ url, w ] ) => ( { url, width: parseInt( w, 10 ) } ) )
		.sort( ( a, b ) => b.width - a.width );
}

/** Width / height of an image, from what the page says of it, or 0. */
function ratioOf( img ) {
	const fromData = parseFloat( img.dataset.aspectratio );
	if ( fromData ) {
		return fromData;
	}
	const width = parseInt( img.getAttribute( 'width' ), 10 );
	const height = parseInt( img.getAttribute( 'height' ), 10 );
	if ( width && height ) {
		return width / height;
	}
	return img.naturalWidth && img.naturalHeight ? img.naturalWidth / img.naturalHeight : 0;
}

/** The width of the full image: the largest in its srcset, or the one the gallery shows. */
function fullWidthOf( img ) {
	const [ largest ] = candidates( img );
	return largest ? largest.width : img.naturalWidth || 0;
}

/** The full image's file: the metadata module's, a legacy gallery's, or the largest in the srcset. */
function fullFileOf( img ) {
	const [ largest ] = candidates( img );
	return img.dataset.origFile || img.dataset.fullUrl || ( largest && largest.url ) || img.currentSrc || img.src;
}

function element( tag, className, html ) {
	const el = document.createElement( tag );
	if ( className ) {
		el.className = className;
	}
	if ( html !== undefined ) {
		el.innerHTML = html;
	}
	return el;
}

class Lightbox {
	constructor( root ) {
		this.root = root;
		this.box = root.closest( '.lightbox' ) || root;
		this.gallery = null;
		this.images = [];
		this.index = 0;
		this.request = 0;
		this.isOpen = false;

		this.setViewport();
		window.addEventListener( 'resize', () => this.setViewport() );
		window.addEventListener( 'orientationchange', () => this.setViewport() );

		document.addEventListener( 'click', ( event ) => this.clicked( event ) );
		document.addEventListener( 'keydown', ( event ) => this.key( event ) );
	}

	/** The --vh the slide's height is worked out from: the window's, as it changes. */
	setViewport() {
		this.viewportWidth = window.innerWidth;
		this.viewportHeight = window.innerHeight;
		document.documentElement.style.setProperty( '--vh', `${ this.viewportHeight }px` );
	}

	clicked( event ) {
		const target = event.target instanceof Element ? event.target : null;

		if ( ! target ) {
			return;
		}

		if ( target.closest( '.lightbox__close' ) ) {
			event.preventDefault();
			this.close();
			return;
		}

		if ( this.isOpen ) {
			if ( target.closest( '.nav-control.left' ) ) {
				this.previous();
			} else if ( target.closest( '.nav-control.right' ) ) {
				this.next();
			} else if ( target.closest( '.thumbnail-item' ) ) {
				this.show( parseInt( target.closest( '.thumbnail-item' ).dataset.position, 10 ) );
			}
			return;
		}

		// A page rendered by PhotoPress 1.6 or earlier, served from a page
		// cache after an update: its galleries lack the class that marks a
		// slideshow gallery. 1.6 opened the lightbox from every PhotoPress
		// gallery on the page, so do that. Looked for on a click, once the
		// page and anything changing it have run.
		if ( ! document.querySelector( GALLERY ) ) {
			document.querySelectorAll( '.photopress-gallery' ).forEach( ( gallery ) => gallery.classList.add( 'photopress-has-slideshow' ) );
		}

		const item = target.closest( `${ GALLERY } ${ ITEM }` );

		if ( ! item ) {
			return;
		}

		event.preventDefault();

		const gallery = item.closest( GALLERY );

		// A different gallery than last time: its slides and thumbnails.
		if ( gallery !== this.gallery ) {
			this.use( gallery );
		}

		// The position of the clicked item in its gallery; the click may land
		// on a caption or a link rather than the image.
		const img = item.querySelector( 'img' );
		let index = this.images.indexOf( img );

		if ( index < 0 ) {
			index = Array.from( gallery.querySelectorAll( ITEM ) ).indexOf( item );
		}

		this.open( Math.max( 0, index ) );
	}

	key( event ) {
		// The keys are left alone while the lightbox is closed, and in fields.
		if ( ! this.isOpen || event.target.closest?.( 'input, textarea, select, [contenteditable]' ) ) {
			return;
		}

		const actions = { Escape: () => this.close(), ArrowLeft: () => this.previous(), ArrowRight: () => this.next() };

		if ( actions[ event.key ] ) {
			event.preventDefault();
			actions[ event.key ]();
		}
	}

	/** Points the slideshow at a gallery, discarding what was built from another. */
	use( gallery ) {
		this.gallery = gallery;
		this.images = Array.from( gallery.querySelectorAll( `${ ITEM } img` ) );
		this.root.replaceChildren();
		this.built = false;
	}

	open( index ) {
		this.isOpen = true;

		// At once: the slideshow is built while it shows.
		Object.assign( this.box.style, { display: 'block', opacity: '1', zIndex: '99999' } );
		document.body.style.overflow = 'hidden';

		if ( ! this.built ) {
			this.build();
		}

		this.show( index, 'instant' );
		this.box.dispatchEvent( new CustomEvent( 'pp-slideshow-opened', { bubbles: true } ) );
	}

	close() {
		this.isOpen = false;
		// display:none, or the hidden full-screen lightbox still paints over
		// the page background.
		Object.assign( this.box.style, { display: 'none', opacity: '0', zIndex: '-100' } );
		document.body.style.overflow = '';
		this.box.dispatchEvent( new CustomEvent( 'pp-slideshow-closed', { bubbles: true } ) );
	}

	previous() {
		this.show( this.index - 1 );
	}

	next() {
		this.show( this.index + 1 );
	}

	/** The panels: arrows either side of the slide, and the thumbnails under it. */
	build() {
		// The settings, read as the lightbox is first built.
		this.options = Object.fromEntries( Object.keys( DEFAULTS ).map( ( key ) => [ key, setting( this.root, key ) ] ) );

		const panels = element( 'div', 'panels' );
		panels.append(
			element( 'div', 'nav-control left', '<i class="arrow left"></i>' ),
			( this.center = element( 'div', 'center', '<div class="loader-circle"></div>' ) ),
			element( 'div', 'nav-control right', '<i class="arrow right"></i>' )
		);

		if ( 'right' === this.options.detail_position ) {
			this.center.classList.add( 'info-right' );
		}

		this.strip = element( 'div', 'thumbnails' );
		this.list = element( 'div', 'thumbnail-list' );
		this.strip.append( this.list );
		this.root.append( panels, this.strip );

		// A press on the left or right half of the slide goes back or forward,
		// as in the Gallery Slideshow block.
		pressNavigation( panels, { prev: () => this.previous(), next: () => this.next(), ignore: 'a' } );

		if ( this.options.showthumbnails ) {
			this.buildThumbnails();
		} else {
			this.root.classList.add( 'no-thumbnails' );
			document.documentElement.style.setProperty( '--pp-slideshow-thumbnails-total-height', '0px' );
		}

		this.built = true;
	}

	/** A thumbnail of each image, its width from its shape at the thumbnail height. */
	buildThumbnails() {
		const height = parseInt( this.options.thumbnailheight, 10 ) || 120;

		this.thumbnails = this.images.map( ( source, position ) => {
			const width = Math.round( height * ( ratioOf( source ) || 1 ) );
			const img = source.cloneNode( false );

			img.removeAttribute( 'id' );
			img.removeAttribute( 'style' );
			img.classList.add( 'thumbnail' );
			img.setAttribute( 'sizes', `${ width }px` );
			img.setAttribute( 'width', String( width ) );
			img.setAttribute( 'height', String( height ) );
			img.setAttribute( 'loading', 'lazy' );
			img.setAttribute( 'decoding', 'async' );
			img.style.aspectRatio = `${ width } / ${ height }`;

			const item = element( 'div', 'thumbnail-item' );
			item.dataset.position = String( position );
			item.append( img );
			return item;
		} );

		this.list.append( ...this.thumbnails );
	}

	/** Slide `index`, counting round from either end, and its thumbnail in the middle. */
	show( index, behavior = 'smooth' ) {
		const count = this.images.length;

		if ( ! count ) {
			return;
		}

		this.index = ( ( index % count ) + count ) % count;
		this.showSlide( this.images[ this.index ] );

		if ( this.thumbnails ) {
			this.thumbnails.forEach( ( item, i ) => item.classList.toggle( 'is-current', i === this.index ) );
			const item = this.thumbnails[ this.index ];
			const left = item.offsetLeft - ( this.list.clientWidth - item.offsetWidth ) / 2;
			this.list.scrollTo( { left: Math.max( 0, left ), behavior } );
		}
	}

	showSlide( source ) {
		const request = ++this.request;
		const id = source.dataset.id || '';
		const ratio = ratioOf( source );
		const fullWidth = fullWidthOf( source );
		const info = this.slideInfo( source );
		const full = new Image();

		full.className = 'fade-in';
		full.dataset.id = id;
		full.alt = source.alt;

		// Until the full image arrives, the copy the page has already loaded,
		// at the size the full image will have; a spinner if there is none.
		const preview = this.previewOf( source );

		if ( preview ) {
			this.center.replaceChildren( preview, info );
			this.sizePreview( preview, ratio, fullWidth );
		} else {
			this.center.replaceChildren( element( 'div', 'loader-circle' ) );
		}

		// The full image at the preview's size: its width is auto, so it is
		// laid out at its sizes, which the browser also picks its file by,
		// no larger than the image itself.
		const shown = this.slideWidth( ratio, fullWidth );
		const vh = getComputedStyle( document.body ).getPropertyValue( '--vh' );
		const thumbs = getComputedStyle( document.body ).getPropertyValue( '--pp-slideshow-thumbnails-total-height' );

		if ( shown ) {
			full.sizes = `${ Math.round( shown ) }px`;
		} else if ( this.viewportHeight <= this.viewportWidth && ratio ) {
			full.sizes = `calc( ( ${ vh } - ${ thumbs } ) * ${ ratio } )`;
		} else {
			full.sizes = `${ this.viewportWidth }px`;
		}

		full.addEventListener( 'error', () => {
			if ( request === this.request ) {
				this.center.replaceChildren( element( 'div', 'slide-error', 'This image could not be loaded.' ) );
			}
		} );

		full.addEventListener( 'load', () => {
			// A later slide was asked for while this one was loading.
			if ( request !== this.request ) {
				return;
			}
			if ( preview && preview.isConnected ) {
				// The same picture, sharper: no fade.
				full.classList.remove( 'fade-in' );
				preview.replaceWith( full );
			} else {
				this.center.replaceChildren( full, info );
			}
		} );

		// An image with one size has no srcset.
		if ( source.getAttribute( 'srcset' ) ) {
			full.srcset = source.getAttribute( 'srcset' );
		}
		full.src = fullFileOf( source );
	}

	/** The title, caption, description and link shown with a slide. */
	slideInfo( source ) {
		const info = element( 'div', 'slide-info' );
		const data = source.dataset;
		const figcaption = source.closest( ITEM )?.querySelector( 'figcaption' );
		const caption = data.caption || ( figcaption ? figcaption.innerHTML : '' );

		if ( data.imageTitle && this.options.showtitleincaption ) {
			info.append( element( 'div', 'info title', data.imageTitle ) );
		}
		if ( caption && this.options.showcaptions ) {
			info.append( element( 'div', 'info caption', caption ) );
		}
		if ( data.imageDescription && this.options.showdescriptionincaption ) {
			info.append( element( 'div', 'info description', data.imageDescription ) );
		}
		if ( this.options.showattachmentlink && data.attachmentUrl ) {
			const link = element( 'a' );
			link.href = data.attachmentUrl;
			link.textContent = this.options.attachmentlinktext;
			const row = element( 'div', 'info attachment-link' );
			row.append( link );
			info.append( row );
		}

		return info;
	}

	/** A copy of the gallery's image of the slide, if the page has loaded it. */
	previewOf( source ) {
		if ( ! source.complete || ! source.naturalWidth ) {
			return null;
		}
		const preview = new Image();
		preview.src = source.currentSrc || source.src;
		preview.className = 'slide-preview';
		preview.alt = source.alt;
		return preview;
	}

	/**
	 * Gives the preview the size the full image will be shown at: as large
	 * as fits the slide, keeping its shape and no larger than the image.
	 */
	sizePreview( preview, ratio, fullWidth ) {
		const shape = ratio || preview.naturalWidth / preview.naturalHeight;
		const width = this.slideWidth( shape, fullWidth );

		if ( width ) {
			// Not shrunk to make room for the details: the full image is not.
			Object.assign( preview.style, { width: `${ Math.round( width ) }px`, height: `${ Math.round( width / shape ) }px`, flexShrink: '0' } );
		}
	}

	/**
	 * The width a slide shows an image at: as large as fits the slide,
	 * keeping its shape, and no larger than the image itself. Null before
	 * the slide is laid out. With the details on the right, the full image
	 * still takes the whole width, and they wrap beside it.
	 */
	slideWidth( ratio, fullWidth ) {
		const { width, height } = this.center.getBoundingClientRect();
		const style = getComputedStyle( this.center );
		const inner = {
			width: width - parseFloat( style.paddingLeft ) - parseFloat( style.paddingRight ),
			height: height - parseFloat( style.paddingTop ) - parseFloat( style.paddingBottom ),
		};

		if ( inner.width <= 0 || inner.height <= 0 || ! ratio ) {
			return null;
		}

		return Math.min( inner.width, inner.height * ratio, fullWidth || Infinity );
	}
}

function start() {
	const root = document.querySelector( '#lightbox-gallery .photopress-slideshow' );

	if ( root && ! window.photopressLightbox ) {
		window.photopressLightbox = new Lightbox( root );
	}
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', start );
} else {
	start();
}
