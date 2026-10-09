/**
 * Front end of the Gallery Slideshow block (modules/gallery/GallerySlideshow.php).
 *
 * Each half of the slideshow is a button: the left goes back, the right goes
 * forward (see src/shared/press-navigation.js for the mouse and touch
 * handling). Arrow keys work too. A click on an image in the source gallery shows that
 * image here, scrolls to the slideshow and offers a way back.
 */

import { pressNavigation } from '../shared/press-navigation';

const SLIDE = '.photopress-gallery-slideshow__slide';

/**
 * Scrolls el to the top of the window at once, even where the theme sets
 * scroll-behavior: smooth.
 */
function scrollToInstantly( el ) {
	const html = el.ownerDocument.documentElement;
	const view = el.ownerDocument.defaultView;
	const previous = html.style.scrollBehavior;

	html.style.scrollBehavior = 'auto';
	view.scrollTo( 0, el.getBoundingClientRect().top + view.pageYOffset );
	html.style.scrollBehavior = previous;
}

/**
 * The attachment id of a gallery image: core images carry wp-image-<id>;
 * PhotoPress also sets data-id.
 */
export function attachmentIdOf( img ) {
	const match = /\bwp-image-(\d+)\b/.exec( img.className || '' );

	return match ? Number( match[ 1 ] ) : Number( img.dataset.id ) || 0;
}

/**
 * Sets up one slideshow.
 *
 * @param {HTMLElement} root The block's root element.
 * @return {{show: Function, next: Function, prev: Function, current: Function, destroy: Function}} Its controls.
 */
export function createSlideshow( root ) {
	const doc = root.ownerDocument;
	const view = doc.defaultView;
	const slides = Array.from( root.querySelectorAll( SLIDE ) );
	const count = slides.length;
	const status = root.querySelector( '.photopress-gallery-slideshow__status' );
	const returnButton = root.querySelector( '.photopress-gallery-slideshow__return' );
	const reducedMotion = view.matchMedia && view.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	const cleanup = [];
	let index = Math.max( 0, slides.findIndex( ( s ) => s.classList.contains( 'is-current' ) ) );
	let returnTarget = null;

	const on = ( target, type, handler, options ) => {
		target.addEventListener( type, handler, options );
		cleanup.push( () => target.removeEventListener( type, handler, options ) );
	};

	// The width an image is shown at, worked out from its slide rather than
	// read from the image: an image's own width follows its sizes (its CSS
	// width is auto), so reading it would only give back the sizes it has,
	// or, before it loads, the placeholder's. As in the CSS: the slide's
	// width, less a caption beside the image; the height a slide allows,
	// less a caption below it, times the image's shape; and no wider than
	// the width WordPress gives it (its width attribute), as with
	// WordPress's own sizes.
	const shownWidth = ( img ) => {
		const slide = img.closest( SLIDE );
		const ratio = Number( img.getAttribute( 'width' ) ) / Number( img.getAttribute( 'height' ) );
		const caption = slide.querySelector( 'figcaption' );
		const px = ( value ) => parseFloat( value ) || 0;
		const slideStyle = view.getComputedStyle( slide );
		let width = slide.clientWidth;
		let height = Math.min( px( slideStyle.maxHeight ) || Infinity, px( view.getComputedStyle( img ).maxHeight ) || Infinity );

		if ( caption ) {
			const style = view.getComputedStyle( caption );
			const box = caption.getBoundingClientRect();
			if ( slideStyle.flexDirection === 'row' ) {
				width -= box.width + px( style.marginLeft ) + px( style.marginRight );
			} else {
				height -= box.height + px( style.marginTop ) + px( style.marginBottom );
			}
		}

		return ratio > 0 ? Math.min( width, height * ratio, Number( img.getAttribute( 'width' ) ) ) : 0;
	};

	// Sets an image's sizes to the width it is shown at, which the browser
	// picks its file from srcset by; changing it makes the browser pick
	// again, a larger file if the image has grown. Slides that are not
	// showing are laid out all the same (hidden, not removed), so any slide
	// can be measured before its image loads.
	const fit = ( img ) => {
		const width = Math.ceil( shownWidth( img ) );

		if ( width > 0 && Number.isFinite( width ) && img.getAttribute( 'sizes' ) !== `${ width }px` ) {
			img.setAttribute( 'sizes', `${ width }px` );
		}
	};

	// Gives a slide its real image; slides beyond the first are rendered
	// with a placeholder so the page does not load every image at once.
	const load = ( i ) => {
		const img = slides[ ( i + count ) % count ]?.querySelector( 'img' );

		if ( img && img.dataset.src ) {
			fit( img );
			if ( img.dataset.srcset ) {
				img.srcset = img.dataset.srcset;
			}
			img.src = img.dataset.src;
			delete img.dataset.src;
			delete img.dataset.srcset;
		}
	};

	const announce = () => {
		if ( status ) {
			status.textContent = `${ index + 1 } / ${ count }`;
		}
	};

	// instant: change slides without the transition, as when wrapping
	// around or jumping from the gallery.
	function show( target, { direction = target > index ? 1 : -1, instant = false } = {} ) {
		const next = ( ( target % count ) + count ) % count;

		if ( next === index ) {
			return;
		}

		const from = slides[ index ];
		const to = slides[ next ];


		if ( instant || reducedMotion ) {
			root.classList.add( 'is-instant' );
			from.classList.remove( 'is-current' );
			to.classList.add( 'is-current' );
			void root.offsetWidth; // apply the change before transitions return
			root.classList.remove( 'is-instant' );
		} else {
			// Slide effect: the incoming slide starts on the side it comes from.
			to.classList.add( 'is-entering', direction > 0 ? 'from-right' : 'from-left' );
			void to.offsetWidth; // apply the starting position before the transition
			to.classList.remove( 'is-entering', 'from-right', 'from-left' );

			from.classList.remove( 'is-current' );
			from.classList.add( 'is-leaving', direction > 0 ? 'to-left' : 'to-right' );
			to.classList.add( 'is-current' );

			// Once it is off, it goes back to its resting place without a
			// transition: animated, it slid back in behind the new slide while
			// it faded out. Not if it is the current slide again by then.
			view.setTimeout( () => {
				if ( from.classList.contains( 'is-current' ) ) {
					return;
				}
				from.classList.add( 'is-settling' );
				from.classList.remove( 'is-leaving', 'to-left', 'to-right' );
				void from.offsetWidth; // apply the resting state before transitions return
				from.classList.remove( 'is-settling' );
			}, 400 );
		}

		slides.forEach( ( slide, i ) => slide.setAttribute( 'aria-hidden', i === next ? 'false' : 'true' ) );

		index = next;
		load( index );
		load( index + 1 );
		load( index - 1 );
		announce();
	}

	// Wrapping from the last image to the first, or back, is instant.
	const next = () => show( index + 1, { direction: 1, instant: index === count - 1 } );
	const prev = () => show( index - 1, { direction: -1, instant: index === 0 } );

	slides.forEach( ( slide, i ) => slide.setAttribute( 'aria-hidden', i === index ? 'false' : 'true' ) );
	load( index + 1 );
	load( index - 1 );
	announce();

	// The images that have their file, measured again whenever the
	// slideshow changes size: the window resized, a phone turned. The first
	// is measured on the first call, which comes as soon as it is observed.
	if ( view.ResizeObserver ) {
		let frame = 0;
		const observer = new view.ResizeObserver( () => {
			view.cancelAnimationFrame( frame );
			frame = view.requestAnimationFrame( () => {
				slides.forEach( ( slide ) => {
					const img = slide.querySelector( 'img' );
					if ( img && ! img.dataset.src ) {
						fit( img );
					}
				} );
			} );
		} );
		observer.observe( root );
		cleanup.push( () => {
			view.cancelAnimationFrame( frame );
			observer.disconnect();
		} );
	}

	// Keyboard and touch use the buttons; the mouse navigates on press.
	on( root.querySelector( '.photopress-gallery-slideshow__next' ), 'click', next );
	on( root.querySelector( '.photopress-gallery-slideshow__prev' ), 'click', prev );
	cleanup.push( pressNavigation( root, { prev, next, ignore: '.photopress-gallery-slideshow__return' } ) );

	on( root, 'keydown', ( event ) => {
		if ( event.key === 'ArrowRight' ) {
			event.preventDefault();
			next();
		} else if ( event.key === 'ArrowLeft' ) {
			event.preventDefault();
			prev();
		}
	} );

	// Autoplay keeps going when the slideshow is hovered or clicked, as the
	// Jetpack slideshow did; it stops while the page is hidden, while a
	// keyboard user is on the slideshow, and for visitors who ask for
	// reduced motion.
	if ( root.dataset.autoplay === '1' && ! reducedMotion ) {
		const delay = Math.max( 1, Number( root.dataset.delay ) || 3 ) * 1000;
		let timer = null;
		let paused = false;

		const start = () => {
			if ( ! timer && ! paused && ! doc.hidden ) {
				timer = view.setInterval( next, delay );
			}
		};
		const stop = () => {
			view.clearInterval( timer );
			timer = null;
		};

		on( root, 'focusin', () => {
			if ( root.matches( ':focus-visible' ) || root.querySelector( ':focus-visible' ) ) {
				paused = true;
				stop();
			}
		} );
		on( root, 'focusout', () => {
			paused = false;
			start();
		} );
		on( doc, 'visibilitychange', () => ( doc.hidden ? stop() : start() ) );
		cleanup.push( stop );
		start();
	}

	// Clicking an image in the source gallery shows it here. Runs before
	// other click handlers (the PhotoPress lightbox, image links).
	const gallery = root.dataset.galleryNavigation === '1' && root.dataset.gallery ? doc.getElementById( root.dataset.gallery ) : null;

	if ( gallery ) {
		const slideOf = new Map( slides.map( ( slide, i ) => [ Number( slide.dataset.id ), i ] ) );
		const smooth = root.dataset.scrollBehavior === 'smooth';

		on( gallery, 'click', ( event ) => {
			// Modified clicks open the link in a new tab or window, as usual.
			if ( event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
				return;
			}

			const item = event.target.closest( 'figure, li' );
			const img = item && item.querySelector( 'img' );
			const target = img ? slideOf.get( attachmentIdOf( img ) ) : undefined;

			if ( target === undefined ) {
				return;
			}

			event.preventDefault();
			event.stopPropagation();

			show( target, { instant: true } );
			returnTarget = event.target.closest( 'a' ) || item;
			if ( returnButton ) {
				returnButton.hidden = false;
			}
			if ( smooth ) {
				root.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			} else {
				scrollToInstantly( root );
			}
		}, { capture: true } );

		// Stays after use, so the visitor can go back again.
		if ( returnButton ) {
			on( returnButton, 'click', ( event ) => {
				event.preventDefault();
				event.stopPropagation();
				if ( returnTarget ) {
					returnTarget.scrollIntoView( { behavior: 'smooth', block: 'start' } );
				}
			} );
		}
	}

	return {
		show,
		next,
		prev,
		current: () => index,
		destroy() {
			cleanup.forEach( ( fn ) => fn() );
		},
	};
}

export function init( doc = document ) {
	doc.querySelectorAll( '.wp-block-photopress-gallery-slideshow' ).forEach( ( root ) => {
		if ( ! root.photopressSlideshow && root.querySelector( SLIDE ) ) {
			root.photopressSlideshow = createSlideshow( root );
		}
	} );
}

if ( typeof document !== 'undefined' ) {
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', () => init() );
	} else {
		init();
	}
}
