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

	// Gives a slide its real image; slides beyond the first are rendered
	// with a placeholder so the page does not load every image at once.
	const load = ( i ) => {
		const img = slides[ ( i + count ) % count ]?.querySelector( 'img' );

		if ( img && img.dataset.src ) {
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

			view.setTimeout( () => from.classList.remove( 'is-leaving', 'to-left', 'to-right' ), 400 );
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
