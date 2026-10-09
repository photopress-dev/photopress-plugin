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

/**
 * Gives slide i the layout a browser would: the slide's width and the
 * height it allows, and the image's width and height attributes.
 */
function layOut( i, { slideWidth = 930, maxHeight = 700, width = 768, height = 1024 } = {} ) {
	const slide = root.querySelectorAll( '.photopress-gallery-slideshow__slide' )[ i ];
	const img = slide.querySelector( 'img' );
	Object.defineProperty( slide, 'clientWidth', { configurable: true, get: () => slideWidth } );
	slide.style.maxHeight = `${ maxHeight }px`;
	img.setAttribute( 'width', width );
	img.setAttribute( 'height', height );
	return img;
}

test( 'a slide gets sizes from the width it is shown at, before its srcset', () => {
	// A 3:4 portrait in a slide 930 wide that allows 700 high: 525 wide.
	const img = layOut( 2 );
	img.dataset.srcset = 's12-768.jpg 768w, s12-1152.jpg 1152w';

	// What srcset the image had when its sizes was set.
	let srcsetThen;
	const setAttribute = img.setAttribute.bind( img );
	img.setAttribute = ( name, value ) => {
		if ( 'sizes' === name ) {
			srcsetThen = img.getAttribute( 'srcset' );
		}
		setAttribute( name, value );
	};

	// Showing slide 11 loads its neighbour, slide 12.
	slideshow.next();

	expect( img.getAttribute( 'sizes' ) ).toBe( '525px' );
	expect( img.getAttribute( 'srcset' ) ).toBe( 's12-768.jpg 768w, s12-1152.jpg 1152w' );
	// Set first: with srcset before sizes, the browser would pick by the old sizes.
	expect( srcsetThen ).toBeNull();
} );

/**
 * The slideshow set up again, with every slide but the first not loaded:
 * set up, it loads the first's neighbours, slides 1 and 3.
 */
function restart() {
	slideshow.destroy();
	root.querySelectorAll( 'img' ).forEach( ( img, i ) => {
		img.removeAttribute( 'sizes' );
		if ( i ) {
			img.dataset.src = `s${ SLIDES[ i ] }.jpg`;
		}
	} );
	slideshow = createSlideshow( root );
}

test( 'the width shown allows for the slide, a caption, and the width WordPress gives the image', () => {
	const sizesOf = ( i ) => root.querySelectorAll( 'img' )[ i ].getAttribute( 'sizes' );
	const caption = document.createElement( 'figcaption' );

	// A landscape, limited by the slide's width.
	layOut( 1, { slideWidth: 900, width: 1024, height: 768 } );
	// A caption below: the image has 700 less the caption's 60 and its margin's 12.
	layOut( 3, { maxHeight: 700 } ).after( caption );
	caption.style.marginTop = '12px';
	caption.getBoundingClientRect = () => ( { width: 600, height: 60 } );
	restart();
	expect( sizesOf( 1 ) ).toBe( '900px' );
	expect( sizesOf( 3 ) ).toBe( `${ Math.ceil( 628 * 0.75 ) }px` );

	// Beside the image: the slide's width less the caption's and its margin.
	layOut( 3, { slideWidth: 900, maxHeight: 2000, width: 1024, height: 768 } ).closest( 'figure' ).style.flexDirection = 'row';
	caption.style.marginTop = '0';
	caption.style.marginLeft = '16px';
	caption.getBoundingClientRect = () => ( { width: 200, height: 300 } );
	restart();
	expect( sizesOf( 3 ) ).toBe( '684px' );

	// A small image is never given more than its own width.
	caption.remove();
	layOut( 3, { width: 200, height: 150 } ).closest( 'figure' ).style.flexDirection = 'column';
	restart();
	expect( sizesOf( 3 ) ).toBe( '200px' );
} );

test( 'a slide that is not laid out keeps the sizes it has', () => {
	const img = root.querySelectorAll( 'img' )[ 2 ];
	img.setAttribute( 'sizes', '(max-width: 768px) 100vw, 768px' );

	slideshow.next();

	expect( img.getAttribute( 'sizes' ) ).toBe( '(max-width: 768px) 100vw, 768px' );
} );

test( 'loaded images are measured again when the slideshow changes size', () => {
	slideshow.destroy();

	let resized;
	const observed = [];
	window.ResizeObserver = class {
		constructor( callback ) {
			resized = callback;
		}
		observe( el ) {
			observed.push( el );
		}
		disconnect() {}
	};
	vi.spyOn( window, 'requestAnimationFrame' ).mockImplementation( ( callback ) => {
		callback();
		return 1;
	} );

	let slideWidth = 600;
	const images = [ 0, 1, 2, 3 ].map( ( i ) => {
		const img = layOut( i, { maxHeight: 2000, width: 1024, height: 768 } );
		Object.defineProperty( img.closest( 'figure' ), 'clientWidth', { configurable: true, get: () => slideWidth } );
		return img;
	} );

	slideshow = createSlideshow( root );
	expect( observed ).toEqual( [ root ] );

	resized();
	expect( images[ 0 ].getAttribute( 'sizes' ) ).toBe( '600px' );

	slideWidth = 930;
	resized();
	expect( images[ 0 ].getAttribute( 'sizes' ), 'no wider than WordPress gives it' ).toBe( '930px' );
	expect( images[ 1 ].getAttribute( 'sizes' ), 'loaded as a neighbour' ).toBe( '930px' );
	expect( images[ 2 ].hasAttribute( 'sizes' ), 'not loaded yet' ).toBe( false );

	delete window.ResizeObserver;
} );
