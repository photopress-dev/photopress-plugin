/**
 * The full-screen slideshow (the lightbox) of galleries with the slideshow
 * on: the Gallery Slideshow block's slideshow (src/shared/slideshow.js),
 * built from the clicked gallery's images and shown over the page.
 * Container: modules/slideshow/slideshow.php (printLightbox); styles:
 * src/blocks/gallery-slideshow/style.scss, and src/shared/styles/slideshow.scss
 * for what is its own.
 *
 * Each slide first shows the copy of its image the page has already loaded,
 * at the size of the full one, until the file its srcset picks arrives.
 * What it shows of each image comes from the gallery's markup (its srcset,
 * width and height, and caption) and from the data-* attributes the
 * metadata module adds to slideshow galleries: the full-size file, the
 * shape, and the media library's caption, title, description and page.
 */
import { createSlideshow } from '../shared/slideshow';

const GALLERY = '.photopress-has-slideshow';
const ITEM = '.photopress-gallery-item';
const PLACEHOLDER = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

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
	labels: { previous: 'Previous image', next: 'Next image', slideshow: 'Slideshow', slide: 'slide' },
};

/** A setting from the container's data-* attribute: true is "1", false is "". */
function setting( container, key ) {
	const value = container.getAttribute( `data-${ key }` );
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

/**
 * Caption HTML with nothing that runs: parsed in a document of its own,
 * where nothing loads or runs, then without scripts, frames, event
 * handlers and javascript: addresses. The page has cleaned it already
 * (metadata::safeAttribute()); a page cached by an earlier version had not.
 */
function safeHtml( html ) {
	const doc = new DOMParser().parseFromString( `<body>${ html }</body>`, 'text/html' );

	doc.body.querySelectorAll( 'script, style, iframe, object, embed, link, meta, base, form' ).forEach( ( el ) => el.remove() );
	doc.body.querySelectorAll( '*' ).forEach( ( el ) => {
		Array.from( el.attributes ).forEach( ( { name, value } ) => {
			if ( /^on/i.test( name ) || ( /^(href|src|xlink:href|action|formaction)$/i.test( name ) && /^\s*(javascript|data|vbscript):/i.test( value ) ) ) {
				el.removeAttribute( name );
			}
		} );
	} );

	return doc.body.innerHTML;
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
	constructor( container ) {
		this.container = container;
		this.box = container.closest( '.lightbox' ) || container;
		this.gallery = null;
		this.slideshow = null;
		this.isOpen = false;

		document.addEventListener( 'click', ( event ) => this.clicked( event ) );
		document.addEventListener( 'keydown', ( event ) => this.key( event ) );
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
		const images = Array.from( gallery.querySelectorAll( `${ ITEM } img` ) );

		// The position of the clicked item in its gallery; the click may land
		// on a caption or a link rather than the image.
		let index = images.indexOf( item.querySelector( 'img' ) );

		if ( index < 0 ) {
			index = Array.from( gallery.querySelectorAll( ITEM ) ).indexOf( item );
		}

		this.open( gallery, images, Math.max( 0, index ) );
	}

	key( event ) {
		if ( ! this.isOpen ) {
			return;
		}

		if ( 'Escape' === event.key ) {
			event.preventDefault();
			this.close();
			return;
		}

		// The slideshow takes the arrow keys while it has the focus; and
		// from anywhere else while it is open, as the page is behind it.
		if ( this.root.contains( event.target ) || event.target.closest?.( 'input, textarea, select, [contenteditable]' ) ) {
			return;
		}

		if ( 'ArrowLeft' === event.key || 'ArrowRight' === event.key ) {
			event.preventDefault();
			this.slideshow[ 'ArrowLeft' === event.key ? 'prev' : 'next' ]();
		}
	}

	open( gallery, images, index ) {
		this.isOpen = true;
		// Given back on closing: the link a keyboard user opened it from.
		this.returnFocus = document.activeElement !== document.body ? document.activeElement : null;

		// At once: the slideshow is built while it shows.
		Object.assign( this.box.style, { display: 'block', opacity: '1', zIndex: '99999' } );
		document.body.style.overflow = 'hidden';
		this.inertBehind( true );

		if ( gallery !== this.gallery ) {
			this.build( gallery, images, index );
		} else {
			this.slideshow.show( index, { instant: true } );
		}

		this.root.focus( { preventScroll: true } );
		this.box.dispatchEvent( new CustomEvent( 'pp-slideshow-opened', { bubbles: true } ) );
	}

	close() {
		this.isOpen = false;
		// display:none, or the hidden full-screen lightbox still paints over
		// the page background.
		Object.assign( this.box.style, { display: 'none', opacity: '0', zIndex: '-100' } );
		document.body.style.overflow = '';
		this.inertBehind( false );
		if ( this.root.contains( document.activeElement ) ) {
			document.activeElement.blur();
		}
		this.returnFocus?.focus?.( { preventScroll: true } );
		this.box.dispatchEvent( new CustomEvent( 'pp-slideshow-closed', { bubbles: true } ) );
	}

	/**
	 * The page behind the lightbox, while it shows: out of reach of the Tab
	 * key, clicks and screen readers (inert), as for any dialog. Only what
	 * this made inert is given back.
	 */
	inertBehind( on ) {
		if ( on ) {
			const own = Array.from( document.body.children ).find( ( child ) => child.contains( this.box ) );
			this.inerted = Array.from( document.body.children ).filter( ( child ) => child !== own && ! child.inert && ! [ 'SCRIPT', 'STYLE', 'LINK' ].includes( child.tagName ) );
			this.inerted.forEach( ( child ) => ( child.inert = true ) );
		} else {
			( this.inerted || [] ).forEach( ( child ) => ( child.inert = false ) );
			this.inerted = [];
		}
	}

	/**
	 * The slideshow of a gallery, in the markup of the Gallery Slideshow
	 * block (modules/gallery/GallerySlideshow.php), on slide `index`.
	 */
	build( gallery, images, index ) {
		const options = Object.fromEntries( Object.keys( DEFAULTS ).map( ( key ) => [ key, setting( this.container, key ) ] ) );
		const labels = { ...DEFAULTS.labels, ...( typeof options.labels === 'object' ? options.labels : {} ) };

		this.slideshow?.destroy();
		this.gallery = gallery;

		const root = element( 'div', `photopress-gallery-slideshow is-effect-slide has-captions-${ 'right' === options.detail_position ? 'right' : 'below' }` );
		Object.assign( root.dataset, { preview: '1', autoplay: '0' } );
		if ( options.showthumbnails ) {
			root.dataset.thumbnails = String( parseInt( options.thumbnailheight, 10 ) || 120 );
		}
		root.tabIndex = 0;
		root.setAttribute( 'role', 'region' );
		root.setAttribute( 'aria-roledescription', labels.slideshow.toLowerCase() );
		root.setAttribute( 'aria-label', labels.slideshow );

		const track = element( 'div', 'photopress-gallery-slideshow__track' );
		track.append( ...images.map( ( source, i ) => this.slide( source, i === index, options, labels ) ) );

		const previous = element( 'button', 'photopress-gallery-slideshow__prev' );
		const next = element( 'button', 'photopress-gallery-slideshow__next' );
		previous.type = next.type = 'button';
		previous.setAttribute( 'aria-label', labels.previous );
		next.setAttribute( 'aria-label', labels.next );

		const status = element( 'p', 'photopress-gallery-slideshow__status screen-reader-text' );
		status.setAttribute( 'aria-live', 'polite' );

		root.append( track, previous, next, status );
		this.container.replaceChildren( root );
		this.root = root;

		// Laid out, as it shows: the slideshow measures its slides.
		this.slideshow = createSlideshow( root );
	}

	/** A slide of the image, and its details; its image loaded as it comes near. */
	slide( source, current, options, labels ) {
		const ratio = ratioOf( source ) || 1.5;
		const [ largest ] = candidates( source );
		const fullWidth = largest ? largest.width : parseInt( source.getAttribute( 'width' ), 10 ) || source.naturalWidth || 1200;
		const full = source.dataset.origFile || source.dataset.fullUrl || ( largest && largest.url ) || source.currentSrc || source.src;
		const copy = source.currentSrc || source.getAttribute( 'src' ) || full;

		const img = element( 'img', 'photopress-gallery-slideshow__image' );
		img.alt = source.alt;
		img.width = fullWidth;
		img.height = Math.round( fullWidth / ratio );
		img.decoding = 'async';
		img.style.aspectRatio = `${ img.width } / ${ img.height }`;
		img.src = PLACEHOLDER;
		img.dataset.src = copy;

		// The srcset picks the file for the width shown; an image with one
		// size has none, and is shown from its full file.
		if ( source.getAttribute( 'srcset' ) ) {
			img.dataset.srcset = source.getAttribute( 'srcset' );
		} else if ( full !== copy ) {
			img.dataset.srcset = `${ full } ${ fullWidth }w`;
		}

		const slide = element( 'figure', `photopress-gallery-slideshow__slide${ current ? ' is-current' : '' }` );
		slide.dataset.id = source.dataset.id || '';
		slide.setAttribute( 'role', 'group' );
		slide.setAttribute( 'aria-roledescription', labels.slide );
		slide.append( img );

		const details = this.details( source, options );
		if ( details ) {
			slide.append( details );
		}

		return slide;
	}

	/** The title, caption, description and link shown with a slide, if any. */
	details( source, options ) {
		const caption = element( 'figcaption', 'photopress-gallery-slideshow__caption wp-element-caption' );
		const data = source.dataset;
		const figcaption = source.closest( ITEM )?.querySelector( 'figcaption' );
		const text = data.caption || ( figcaption ? figcaption.innerHTML : '' );

		if ( data.imageTitle && options.showtitleincaption ) {
			caption.append( element( 'div', 'info title', safeHtml( data.imageTitle ) ) );
		}
		if ( text && options.showcaptions ) {
			caption.append( element( 'div', 'info caption', safeHtml( text ) ) );
		}
		if ( data.imageDescription && options.showdescriptionincaption ) {
			caption.append( element( 'div', 'info description', safeHtml( data.imageDescription ) ) );
		}
		if ( options.showattachmentlink && /^https?:\/\//i.test( data.attachmentUrl || '' ) ) {
			const link = element( 'a' );
			link.href = data.attachmentUrl;
			link.textContent = options.attachmentlinktext;
			const row = element( 'div', 'info attachment-link' );
			row.append( link );
			caption.append( row );
		}

		return caption.childElementCount ? caption : null;
	}
}

/** The lightbox, if the page has one (modules/slideshow/slideshow.php prints it). */
export function startLightbox( doc = document ) {
	const container = doc.querySelector( '#lightbox-gallery .photopress-slideshow' );

	if ( container && ! window.photopressLightbox ) {
		window.photopressLightbox = new Lightbox( container );
	}
}
