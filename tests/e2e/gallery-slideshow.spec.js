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
	const left = await middle( page, 0.25 );
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
