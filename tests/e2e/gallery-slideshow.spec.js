/**
 * The Gallery Slideshow block on the front end: press navigation, the drawn
 * cursor, wrap-around, the jump from the gallery and the return button.
 */
const { test, expect } = require( './test' );

const ROOT = '.wp-block-photopress-gallery-slideshow';

/** Index of the current slide, and what the page shows around it. */
const state = ( page ) => page.evaluate( ( selector ) => {
	const root = document.querySelector( selector );
	const slides = [ ...root.querySelectorAll( '.photopress-gallery-slideshow__slide' ) ];
	const cursor = document.querySelector( '.photopress-press-cursor' );
	return {
		index: slides.findIndex( ( s ) => s.classList.contains( 'is-current' ) ),
		count: slides.length,
		top: Math.round( root.getBoundingClientRect().top ),
		returnShown: ! root.querySelector( '.photopress-gallery-slideshow__return' ).hidden,
		cursor: cursor && getComputedStyle( cursor ).display,
		cursorPath: cursor && cursor.querySelector( 'path' ).getAttribute( 'd' ),
	};
}, ROOT );

const NEXT = 'M26 14 L42 32 L26 50';
const PREV = 'M38 14 L22 32 L38 50';

test.beforeEach( async ( { page, made } ) => {
	await page.goto( `/?page_id=${ made.pages.slideshow }&preview=true` );
	await page.locator( ROOT ).scrollIntoViewIfNeeded();
} );

async function middle( page, fraction ) {
	const box = await page.locator( ROOT ).boundingBox();
	return { x: box.x + box.width * fraction, y: box.y + Math.min( box.height / 2, 300 ) };
}

test( 'a mouse press moves at once, once, without selecting anything', async ( { page, made } ) => {
	expect( ( await state( page ) ).count ).toBe( made.images.length );

	const right = await middle( page, 0.75 );
	await page.mouse.move( right.x, right.y );
	await page.mouse.down();

	let now = await state( page );
	expect( now.index ).toBe( 1 );
	expect( now.cursor ).toBe( 'block' );
	expect( now.cursorPath ).toBe( NEXT );

	await page.mouse.up();
	expect( ( await state( page ) ).index ).toBe( 1 );
	expect( await page.evaluate( () => String( getSelection() ) ) ).toBe( '' );

	const left = await middle( page, 0.25 );
	await page.mouse.click( left.x, left.y );
	now = await state( page );
	expect( now.index ).toBe( 0 );
	expect( now.cursorPath ).toBe( PREV );
	expect( await page.locator( `${ ROOT } .photopress-gallery-slideshow__next` ).evaluate( ( b ) => getComputedStyle( b ).cursor ) ).toBe( 'none' );
} );

test( 'the cursor shows on a press without a move, and hides on window blur', async ( { page } ) => {
	// Near the top: the press wraps to the last, small image, and the
	// slideshow shrinks to its height; lower down the pointer would end up
	// below the slideshow, where the cursor is rightly hidden.
	const box = await page.locator( ROOT ).boundingBox();
	const left = { x: box.x + box.width * 0.25, y: box.y + 40 };
	await page.mouse.move( left.x, left.y );
	await page.evaluate( () => ( document.querySelector( '.photopress-press-cursor' ).style.display = 'none' ) );
	await page.mouse.down();
	await page.mouse.up();

	expect( ( await state( page ) ).cursor ).toBe( 'block' );
	expect( await page.evaluate( () => document.querySelector( '.photopress-press-cursor' ).parentNode === document.body ) ).toBe( true );

	await page.evaluate( () => window.dispatchEvent( new Event( 'blur' ) ) );
	expect( ( await state( page ) ).cursor ).toBe( 'none' );
} );

test( 'wrapping around is instant; steps between take 300ms', async ( { page, made } ) => {
	expect( await page.locator( `${ ROOT } .photopress-gallery-slideshow__slide` ).first().evaluate( ( s ) => getComputedStyle( s ).transitionDuration ) ).toMatch( /^0\.3s/ );

	const instant = await page.evaluate( ( selector ) => new Promise( ( resolve ) => {
		const root = document.querySelector( selector );
		const classes = [];
		const observer = new MutationObserver( ( records ) => records.forEach( ( r ) => classes.push( r.oldValue || '' ) ) );
		observer.observe( root, { attributes: true, attributeOldValue: true, attributeFilter: [ 'class' ] } );
		root.querySelector( '.photopress-gallery-slideshow__prev' ).click();
		setTimeout( () => {
			observer.disconnect();
			resolve( classes.some( ( c ) => c.includes( 'is-instant' ) ) );
		}, 50 );
	} ), ROOT );

	expect( instant ).toBe( true );
	expect( ( await state( page ) ).index ).toBe( made.images.length - 1 );
} );

/** The slideshow's height, and the current slide's (image and caption). */
const heights = ( page ) => page.evaluate( ( selector ) => {
	const root = document.querySelector( selector );
	const slide = root.querySelector( '.photopress-gallery-slideshow__slide.is-current' );
	return {
		index: [ ...root.querySelectorAll( '.photopress-gallery-slideshow__slide' ) ].indexOf( slide ),
		track: root.querySelector( '.photopress-gallery-slideshow__track' ).getBoundingClientRect().height,
		slide: slide.getBoundingClientRect().height,
		image: slide.querySelector( 'img' ).getBoundingClientRect().height,
		visible: window.innerHeight,
	};
}, ROOT );

/** Every slide in turn: the slideshow's height is the slide's. */
async function checkEverySlide( page, count, label ) {
	for ( let i = 0; i < count; i++ ) {
		await page.waitForTimeout( 400 );
		const h = await heights( page );
		expect( h.index, label ).toBe( i );
		expect( Math.abs( h.track - h.slide ), `${ label }, slide ${ i }: ${ h.track } for a ${ h.slide } slide` ).toBeLessThanOrEqual( 1 );
		expect( h.image, `${ label }, slide ${ i }: taller than the window allows` ).toBeLessThanOrEqual( h.visible - 150 + 1 );
		await page.evaluate( ( selector ) => document.querySelector( selector ).photopressSlideshow.next(), ROOT );
	}
}

const VIEWPORTS = [ [ 1280, 900 ], [ 1024, 768 ], [ 768, 1024 ], [ 390, 844 ], [ 360, 640 ] ];

for ( const [ order, page_key ] of [ [ 'portrait first', 'slideshow' ], [ 'landscape first', 'slideshowLandscapeFirst' ] ] ) {
	test( `the slideshow is as tall as the current slide at every window size (${ order })`, async ( { page, made } ) => {
		test.setTimeout( 240000 );

		for ( const [ width, height ] of VIEWPORTS ) {
			await page.setViewportSize( { width, height } );
			await page.goto( `/?page_id=${ made.pages[ page_key ] }&preview=true` );
			await page.locator( ROOT ).scrollIntoViewIfNeeded();
			await checkEverySlide( page, made.images.length, `${ order } ${ width }x${ height }` );
		}

		// Resizing while the slideshow is in use, on a short slide and on a
		// tall one, narrow to wide and back.
		const names = Object.keys( made.names );
		for ( const name of [ '02-landscape-3x2', '07-tall-1x3' ] ) {
			const at = await page.evaluate( ( selector ) => [ ...document.querySelectorAll( `${ selector } .photopress-gallery-slideshow__slide` ) ].map( ( s ) => Number( s.dataset.id ) ), ROOT );
			await page.evaluate( ( [ selector, i ] ) => document.querySelector( selector ).photopressSlideshow.show( i, { instant: true } ), [ ROOT, at.indexOf( made.names[ name ] ) ] );
			for ( const [ width, height ] of [ [ 360, 640 ], [ 1280, 900 ], [ 390, 844 ] ] ) {
				await page.setViewportSize( { width, height } );
				await page.waitForTimeout( 400 );
				const h = await heights( page );
				expect( Math.abs( h.track - h.slide ), `${ order }, ${ name } resized to ${ width }x${ height }` ).toBeLessThanOrEqual( 1 );
			}
		}
		expect( names.length ).toBe( made.images.length );
	} );
}

test( 'the height eases from one slide to the next', async ( { page, made } ) => {
	await page.goto( `/?page_id=${ made.pages.slideshow }&preview=true` );
	await page.locator( ROOT ).scrollIntoViewIfNeeded();

	// From the 3:1 panorama to the 1:3 tall image, which follows it.
	const order = Object.keys( made.names );
	await page.evaluate( ( [ selector, i ] ) => document.querySelector( selector ).photopressSlideshow.show( i, { instant: true } ), [ ROOT, order.indexOf( '06-panorama-3x1' ) ] );
	await page.waitForTimeout( 450 );
	const short = ( await heights( page ) ).track;
	const midway = await page.evaluate( ( selector ) => new Promise( ( resolve ) => {
		const root = document.querySelector( selector );
		root.photopressSlideshow.next();
		setTimeout( () => resolve( root.querySelector( '.photopress-gallery-slideshow__track' ).getBoundingClientRect().height ), 150 );
	} ), ROOT );
	await page.waitForTimeout( 450 );
	const tall = ( await heights( page ) ).track;

	expect( tall ).toBeGreaterThan( short + 100 );
	expect( midway ).toBeGreaterThan( short + 10 );
	expect( midway ).toBeLessThan( tall - 10 );
} );

test( 'the slide that leaves does not come back into view', async ( { page } ) => {
	// Where the previous slide is, and whether it can be seen, on every frame
	// for most of a second after moving on.
	const frames = await page.evaluate( ( selector ) => new Promise( ( resolve ) => {
		const root = document.querySelector( selector );
		const from = root.querySelector( '.photopress-gallery-slideshow__slide.is-current' );
		const out = [];
		const t0 = performance.now();
		const tick = () => {
			const style = getComputedStyle( from );
			out.push( {
				ms: performance.now() - t0,
				leaving: from.classList.contains( 'is-leaving' ),
				seen: 'visible' === style.visibility && Number( style.opacity ) > 0.01,
				left: from.getBoundingClientRect().left - root.getBoundingClientRect().left,
			} );
			if ( performance.now() - t0 < 900 ) {
				requestAnimationFrame( tick );
			} else {
				resolve( out );
			}
		};
		root.photopressSlideshow.next();
		tick();
	} ), ROOT );

	// Seen only while it slides off; never moving back towards the frame.
	expect( frames.filter( ( f ) => f.seen && ! f.leaving ) ).toEqual( [] );
	const seen = frames.filter( ( f ) => f.seen );
	for ( let i = 1; i < seen.length; i++ ) {
		expect( seen[ i ].left ).toBeLessThanOrEqual( seen[ i - 1 ].left + 1 );
	}
} );

test( 'a gallery click jumps to its image and scrolls there at once; modified clicks do not', async ( { page } ) => {
	const fifth = page.locator( '#main-gallery figure.wp-block-image img' ).nth( 4 );
	await fifth.scrollIntoViewIfNeeded();

	await fifth.click( { modifiers: [ 'Shift' ], force: true } );
	expect( ( await state( page ) ).returnShown ).toBe( false );

	await fifth.click( { force: true } );
	const now = await state( page );
	expect( now.index ).toBe( 4 );
	expect( now.top ).toBe( 0 );
	expect( now.returnShown ).toBe( true );
	await expect( page.locator( '.lightbox:visible' ) ).toHaveCount( 0 );
} );

test( 'the return button stays, and takes the clicked image to the top of the window', async ( { page } ) => {
	const fifth = page.locator( '#main-gallery figure.wp-block-image img' ).nth( 4 );
	await fifth.scrollIntoViewIfNeeded();
	await fifth.click( { force: true } );

	// Below the admin bar.
	await page.evaluate( () => scrollBy( 0, -100 ) );
	const back = page.locator( '.photopress-gallery-slideshow__return' );
	const box = await back.boundingBox();
	await page.mouse.move( box.x + 10, box.y + 10 );

	expect( ( await state( page ) ).cursor ).toBe( 'none' );
	expect( await back.evaluate( ( b ) => getComputedStyle( b ).cursor ) ).toBe( 'pointer' );
	await expect( back ).toHaveAttribute( 'aria-label', 'Return to gallery image' );

	await page.mouse.click( box.x + 10, box.y + 10 );
	await expect.poll( () => fifth.evaluate( ( img ) => Math.round( img.closest( 'figure' ).getBoundingClientRect().top ) ) )
		.toBeLessThanOrEqual( await page.evaluate( () => document.getElementById( 'wpadminbar' )?.offsetHeight || 0 ) + 1 );

	const now = await state( page );
	expect( now.index ).toBe( 4 );
	expect( now.returnShown ).toBe( true );
} );

test( 'images keep their shape, and a small one is not enlarged', async ( { page, made } ) => {
	const order = Object.keys( made.names );
	// The inline aspect-ratio, as width / height.
	const ratios = await page.locator( `${ ROOT } .photopress-gallery-slideshow__image` ).evaluateAll( ( imgs ) => imgs.map( ( img ) => {
		const [ w, h ] = /aspect-ratio:(\d+)\/(\d+)/.exec( img.getAttribute( 'style' ) ).slice( 1 ).map( Number );
		return w / h;
	} ) );
	expect( ratios[ order.indexOf( '06-panorama-3x1' ) ] ).toBeCloseTo( 3, 1 );
	expect( ratios[ order.indexOf( '07-tall-1x3' ) ] ).toBeCloseTo( 1 / 3, 1 );
	expect( ratios[ order.indexOf( '03-square' ) ] ).toBe( 1 );

	await page.evaluate( ( [ selector, i ] ) => document.querySelector( selector ).photopressSlideshow.show( i, { instant: true } ), [ ROOT, order.indexOf( '08-small' ) ] );
	const small = page.locator( `${ ROOT } .photopress-gallery-slideshow__slide.is-current img` );
	await expect.poll( () => small.evaluate( ( img ) => img.complete && img.naturalWidth ) ).toBe( 200 );
	expect( ( await small.boundingBox() ).width ).toBeLessThanOrEqual( 200 );
} );

test( 'alt text comes from the image metadata', async ( { page } ) => {
	await expect( page.locator( `${ ROOT } .photopress-gallery-slideshow__image` ).first() ).toHaveAttribute( 'alt', /Alice/ );
} );

test.describe( 'caption position', () => {
	const layout = ( page ) => page.locator( ROOT ).evaluateAll( ( roots ) => roots.map( ( root ) => {
		const slide = root.querySelector( '.photopress-gallery-slideshow__slide.is-current' );
		const img = slide.querySelector( 'img' ).getBoundingClientRect();
		const caption = slide.querySelector( 'figcaption' );
		const box = caption.getBoundingClientRect();
		return {
			side: box.left >= img.right - 1 ? 'right' : box.right <= img.left + 1 ? 'left' : box.top >= img.bottom - 1 ? 'below' : 'overlap',
			bottomAligned: Math.abs( box.bottom - img.bottom ) <= 1,
			padding: getComputedStyle( caption ).paddingLeft,
		};
	} ) );

	test( 'left and right, level with the bottom of the image, with their padding', async ( { page, made } ) => {
		await page.goto( `/?page_id=${ made.pages.captions }&preview=true` );
		await page.locator( ROOT ).first().scrollIntoViewIfNeeded();

		expect( await layout( page ) ).toEqual( [
			{ side: 'left', bottomAligned: true, padding: '10px' },
			{ side: 'right', bottomAligned: true, padding: '0px' },
		] );
	} );

	test( 'below the image on a phone', async ( { page, made } ) => {
		await page.setViewportSize( { width: 390, height: 844 } );
		await page.goto( `/?page_id=${ made.pages.captions }&preview=true` );

		expect( ( await layout( page ) ).map( ( l ) => l.side ) ).toEqual( [ 'below', 'below' ] );
	} );
} );

test( 'Hide the gallery: visitors see only the slideshow, which still has every image', async ( { page, made } ) => {
	await page.goto( `/?page_id=${ made.pages.hidden }&preview=true` );

	await expect( page.locator( '#hidden-gallery' ) ).toBeHidden();
	await expect( page.locator( '#hidden-gallery' ) ).toHaveAttribute( 'hidden', '' );
	await expect( page.locator( ROOT ) ).toBeVisible();
	await expect( page.locator( `${ ROOT } .photopress-gallery-slideshow__slide` ) ).toHaveCount( made.images.length );

	// The other pages' galleries are not hidden.
	await page.goto( `/?page_id=${ made.pages.slideshow }&preview=true` );
	await expect( page.locator( '#main-gallery' ) ).toBeVisible();
} );
