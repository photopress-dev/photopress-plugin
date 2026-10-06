import { test, expect, beforeEach, afterEach, vi } from 'vitest';

/**
 * Internal dependencies
 */
import { createSlideshow } from '../../src/frontend/gallery-slideshow';

/**
 * A pointer event as a browser sends it; jsdom's PointerEvent does not
 * take pointerType.
 */
function pointer( type, { x = 0, y = 0, kind = 'mouse', button = 0 } = {} ) {
	const event = new MouseEvent( type, { bubbles: true, cancelable: true, clientX: x, clientY: y, button } );
	Object.defineProperty( event, 'pointerType', { value: kind } );
	return event;
}

function click( el, init = {} ) {
	const event = new MouseEvent( 'click', { bubbles: true, cancelable: true, ...init } );
	el.dispatchEvent( event );
	return event;
}

const SLIDES = [ 10, 11, 12, 13 ];

let root, gallery, slideshow, addedScrollIntoView;

beforeEach( () => {
	document.body.innerHTML = `
		<figure class="wp-block-gallery" id="main-gallery">
			${ SLIDES.map( ( id ) => `<figure class="wp-block-image"><a href="/img-${ id }"><img class="wp-image-${ id }" src="t${ id }.jpg"></a></figure>` ).join( '' ) }
		</figure>
		<div class="wp-block-photopress-gallery-slideshow is-effect-slide" data-gallery="main-gallery" data-gallery-navigation="1" data-scroll-behavior="instant" data-autoplay="0">
			<div class="photopress-gallery-slideshow__track">
				${ SLIDES.map( ( id, i ) => `<figure class="photopress-gallery-slideshow__slide${ i ? '' : ' is-current' }" data-id="${ id }"><img ${ i ? `data-src="s${ id }.jpg" src="data:,"` : `src="s${ id }.jpg"` }></figure>` ).join( '' ) }
			</div>
			<button class="photopress-gallery-slideshow__prev"></button>
			<button class="photopress-gallery-slideshow__next"></button>
			<button class="photopress-gallery-slideshow__return" hidden></button>
			<p class="photopress-gallery-slideshow__status"></p>
		</div>`;

	root = document.querySelector( '.wp-block-photopress-gallery-slideshow' );
	gallery = document.getElementById( 'main-gallery' );
	root.getBoundingClientRect = () => ( { left: 0, top: 500, width: 800, height: 600 } );
	// jsdom has no scrolling; restored after each test (restoreMocks).
	vi.spyOn( window, 'scrollTo' ).mockImplementation( () => {} );
	addedScrollIntoView = ! Element.prototype.scrollIntoView;
	if ( addedScrollIntoView ) {
		Element.prototype.scrollIntoView = () => {};
	}
	vi.spyOn( Element.prototype, 'scrollIntoView' ).mockImplementation( () => {} );
	slideshow = createSlideshow( root );
} );

afterEach( () => {
	slideshow.destroy();
	document.body.innerHTML = '';
	document.documentElement.removeAttribute( 'style' );
	vi.restoreAllMocks();
	if ( addedScrollIntoView ) {
		delete Element.prototype.scrollIntoView;
	}
} );

const cursor = () => document.querySelector( '.photopress-press-cursor' );

test( 'a mouse press navigates at once, by the half it lands on', () => {
	const press = pointer( 'pointerdown', { x: 600 } );
	root.querySelector( '.photopress-gallery-slideshow__next' ).dispatchEvent( press );

	expect( slideshow.current() ).toBe( 1 );
	expect( press.defaultPrevented ).toBe( true );

	root.querySelector( '.photopress-gallery-slideshow__prev' ).dispatchEvent( pointer( 'pointerdown', { x: 100 } ) );
	expect( slideshow.current() ).toBe( 0 );
} );

test( 'the click that ends a press does not navigate again', () => {
	const next = root.querySelector( '.photopress-gallery-slideshow__next' );
	next.dispatchEvent( pointer( 'pointerdown', { x: 600 } ) );
	click( next );

	expect( slideshow.current() ).toBe( 1 );

	// A keyboard click has no press before it.
	click( next );
	expect( slideshow.current() ).toBe( 2 );
} );

test( 'pressing the return button does not navigate', () => {
	root.querySelector( '.photopress-gallery-slideshow__return' ).dispatchEvent( pointer( 'pointerdown', { x: 790 } ) );

	expect( slideshow.current() ).toBe( 0 );
} );

test( 'the cursor shows on a press without a move, and hides on window blur', () => {
	root.dispatchEvent( pointer( 'pointerdown', { x: 100, y: 40 } ) );

	expect( cursor().parentNode ).toBe( document.body );
	expect( cursor().style.display ).toBe( 'block' );
	expect( cursor().querySelector( 'path' ).getAttribute( 'd' ) ).toBe( 'M38 14 L22 32 L38 50' );
	expect( root.classList.contains( 'photopress-press-cursor-active' ) ).toBe( true );

	window.dispatchEvent( new Event( 'blur' ) );
	expect( cursor().style.display ).toBe( 'none' );
} );

test( 'the cursor gives way to the normal one over the return button', () => {
	root.dispatchEvent( pointer( 'pointermove', { x: 600 } ) );
	expect( cursor().querySelector( 'path' ).getAttribute( 'd' ) ).toBe( 'M26 14 L42 32 L26 50' );

	root.querySelector( '.photopress-gallery-slideshow__return' ).dispatchEvent( pointer( 'pointermove', { x: 790 } ) );
	expect( cursor().style.display ).toBe( 'none' );
	expect( root.classList.contains( 'photopress-press-cursor-active' ) ).toBe( false );
} );

test( 'wrapping around is instant; the steps between are animated', () => {
	slideshow.next();
	expect( root.querySelector( '.is-leaving' ) ).not.toBeNull();

	slideshow.show( 3 );
	const spy = vi.spyOn( root.classList, 'add' );
	slideshow.next();

	expect( slideshow.current() ).toBe( 0 );
	expect( spy ).toHaveBeenCalledWith( 'is-instant' );
	expect( root.classList.contains( 'is-instant' ) ).toBe( false );
} );

test( 'a swipe navigates and its click is swallowed', () => {
	const next = root.querySelector( '.photopress-gallery-slideshow__next' );
	next.dispatchEvent( pointer( 'pointerdown', { x: 600, kind: 'touch' } ) );
	expect( slideshow.current() ).toBe( 0 );

	next.dispatchEvent( pointer( 'pointerup', { x: 500, kind: 'touch' } ) );
	click( next );

	expect( slideshow.current() ).toBe( 1 );
} );

test( 'a touch tap uses the buttons', () => {
	const next = root.querySelector( '.photopress-gallery-slideshow__next' );
	next.dispatchEvent( pointer( 'pointerdown', { x: 600, kind: 'touch' } ) );
	next.dispatchEvent( pointer( 'pointerup', { x: 601, kind: 'touch' } ) );
	click( next );

	expect( slideshow.current() ).toBe( 1 );
} );

test( 'a gallery click jumps to its image without animation and scrolls at once', () => {
	document.documentElement.style.scrollBehavior = 'smooth';
	let behaviourDuringScroll;
	window.scrollTo.mockImplementation( () => ( behaviourDuringScroll = document.documentElement.style.scrollBehavior ) );

	const link = gallery.querySelectorAll( 'a' )[ 2 ];
	const event = click( link.querySelector( 'img' ) );

	expect( event.defaultPrevented ).toBe( true );
	expect( slideshow.current() ).toBe( 2 );
	expect( root.querySelector( '.is-leaving' ) ).toBeNull();
	expect( window.scrollTo ).toHaveBeenCalledWith( 0, 500 );
	expect( behaviourDuringScroll ).toBe( 'auto' );
	expect( document.documentElement.style.scrollBehavior ).toBe( 'smooth' );
	expect( root.querySelector( '.photopress-gallery-slideshow__slide.is-current img' ).getAttribute( 'src' ) ).toBe( 's12.jpg' );
} );

test( 'modified gallery clicks are left to the browser', () => {
	// Record what reached the page, then stop jsdom following the link.
	const reached = [];
	const record = ( event ) => {
		reached.push( event.defaultPrevented );
		event.preventDefault();
	};
	document.addEventListener( 'click', record );

	for ( const key of [ 'metaKey', 'ctrlKey', 'shiftKey', 'altKey' ] ) {
		click( gallery.querySelectorAll( 'img' )[ 1 ], { [ key ]: true } );
	}

	document.removeEventListener( 'click', record );
	expect( reached ).toEqual( [ false, false, false, false ] );
	expect( slideshow.current() ).toBe( 0 );
} );

test( 'the return button stays and scrolls back to the clicked link, top of the window', () => {
	const link = gallery.querySelectorAll( 'a' )[ 1 ];
	click( link.querySelector( 'img' ) );

	const back = root.querySelector( '.photopress-gallery-slideshow__return' );
	expect( back.hidden ).toBe( false );

	click( back );
	expect( back.hidden ).toBe( false );
	expect( Element.prototype.scrollIntoView ).toHaveBeenLastCalledWith( { behavior: 'smooth', block: 'start' } );
	expect( Element.prototype.scrollIntoView.mock.contexts.at( -1 ) ).toBe( link );
	expect( slideshow.current() ).toBe( 1 );
} );
