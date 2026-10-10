/**
 * Click-anywhere navigation for a slideshow, shared by the Gallery Slideshow
 * block and the lightbox: a mouse press on the left half of `area` goes back,
 * on the right half forward, and a large chevron drawn at the pointer shows
 * which. Touch screens get a swipe; taps fall through to the element's own
 * buttons.
 *
 * Navigation happens on the press, not the click, so it responds at once and
 * holding the button does not select text or start dragging the image.
 */

const CURSOR_CLASS = 'photopress-press-cursor';
const ACTIVE_CLASS = 'photopress-press-cursor-active';
const PATHS = {
	prev: 'M38 14 L22 32 L38 50',
	next: 'M26 14 L42 32 L26 50',
};

/**
 * @param {HTMLElement} area           The element whose halves navigate.
 * @param {Object}      options
 * @param {Function}    options.prev   Goes back one slide.
 * @param {Function}    options.next   Goes forward one slide.
 * @param {string}      [options.ignore] Selector of controls inside `area` that
 *                                     keep their own behavior and the normal cursor.
 * @return {Function} Removes the handlers and the cursor.
 */
export function pressNavigation( area, { prev, next, ignore = '' } ) {
	const doc = area.ownerDocument;
	const view = doc.defaultView;
	const cleanup = [];

	const on = ( target, type, handler, opts ) => {
		target.addEventListener( type, handler, opts );
		cleanup.push( () => target.removeEventListener( type, handler, opts ) );
	};

	// Lets styles tell that the halves navigate (the lightbox hides its
	// arrows then).
	area.classList.add( 'has-press-navigation' );

	const ignored = ( event ) => ignore && event.target.closest && event.target.closest( ignore );
	const sideOf = ( event ) => {
		const box = area.getBoundingClientRect();
		return event.clientX < box.left + box.width / 2 ? 'prev' : 'next';
	};

	// Drawn rather than a CSS cursor image: Safari does not reliably show
	// large cursor images. On the body, above everything, so no stacking
	// context of the theme can put it behind something.
	const cursor = doc.createElement( 'div' );
	cursor.className = CURSOR_CLASS;
	cursor.setAttribute( 'aria-hidden', 'true' );
	cursor.innerHTML = '<svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg"><path d="" fill="none" stroke="black" stroke-width="7" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	doc.body.appendChild( cursor );
	const path = cursor.querySelector( 'path' );

	const showCursor = ( event ) => {
		path.setAttribute( 'd', PATHS[ sideOf( event ) ] );
		cursor.style.left = `${ event.clientX }px`;
		cursor.style.top = `${ event.clientY }px`;
		cursor.style.display = 'block';
		area.classList.add( ACTIVE_CLASS );
	};
	const hideCursor = () => {
		cursor.style.display = 'none';
		area.classList.remove( ACTIVE_CLASS );
	};

	on( area, 'pointermove', ( event ) => {
		if ( event.pointerType !== 'mouse' || ignored( event ) ) {
			hideCursor();
			return;
		}
		showCursor( event );
	} );
	on( area, 'pointerleave', hideCursor );
	on( view, 'blur', hideCursor );
	on( doc, 'mouseleave', hideCursor );

	// The click that ends a press (or a swipe) must not navigate a second
	// time through a button under the pointer. Clicks from the keyboard
	// have no press before them and go through.
	let swallowClick = false;
	on( area, 'click', ( event ) => {
		if ( swallowClick ) {
			swallowClick = false;
			event.preventDefault();
			event.stopPropagation();
		}
	}, { capture: true } );
	// A press released outside the area produces no click to swallow.
	on( doc, 'pointerup', () => {
		if ( swallowClick ) {
			view.setTimeout( () => ( swallowClick = false ), 0 );
		}
	} );

	let swipeStart = null;

	on( area, 'pointerdown', ( event ) => {
		if ( ignored( event ) ) {
			return;
		}

		if ( event.pointerType !== 'mouse' ) {
			swipeStart = { x: event.clientX, y: event.clientY };
			return;
		}

		if ( event.button !== 0 ) {
			return;
		}

		// Keep the cursor visible on a press without needing a move first.
		showCursor( event );

		// No text selection or image drag.
		if ( event.cancelable ) {
			event.preventDefault();
		}

		swallowClick = true;
		( sideOf( event ) === 'prev' ? prev : next )();
	} );

	on( area, 'pointerup', ( event ) => {
		if ( ! swipeStart ) {
			return;
		}
		const dx = event.clientX - swipeStart.x;
		const dy = event.clientY - swipeStart.y;
		swipeStart = null;
		if ( Math.abs( dx ) > 40 && Math.abs( dx ) > Math.abs( dy ) ) {
			swallowClick = true;
			view.setTimeout( () => ( swallowClick = false ), 400 );
			( dx < 0 ? next : prev )();
		}
	} );
	on( area, 'pointercancel', () => ( swipeStart = null ) );

	return () => {
		cleanup.forEach( ( fn ) => fn() );
		cursor.remove();
		area.classList.remove( ACTIVE_CLASS, 'has-press-navigation' );
	};
}
