/**
 * The lightbox: the Gallery Slideshow block's slideshow, full screen, opened
 * from a gallery. A press on either half of the slide goes back or forward,
 * with the block's cursor; a row of thumbnails under it. Slideshows are
 * turned on for the tests, and the setting put back afterwards.
 */
const { test, expect } = require( './test' );
const { wp, lastLine } = require( './wp' );

const KEY = 'photopress_core_slideshow';
let saved;

test.beforeAll( () => {
	saved = lastLine( wp( 'eval', `echo wp_json_encode( get_option( '${ KEY }', null ) );` ) );
	wp( 'eval', `update_option( '${ KEY }', array_merge( (array) get_option( '${ KEY }', [] ), [ 'enable' => true ] ) );` );
} );

test.afterAll( () => {
	if ( 'null' === saved ) {
		wp( 'option', 'delete', KEY );
	} else {
		wp( 'option', 'update', KEY, saved, '--format=json' );
	}
} );

const SLIDESHOW = '.lightbox .photopress-gallery-slideshow';
const CURRENT = `${ SLIDESHOW } .photopress-gallery-slideshow__slide.is-current`;
const current = ( page ) => page.locator( CURRENT ).getAttribute( 'data-id' ).then( Number );

/** The slideshow opened on the nth image of the gallery. */
async function openOn( page, n ) {
	const item = page.locator( '.photopress-has-slideshow .photopress-gallery-item' ).nth( n );
	await item.scrollIntoViewIfNeeded();
	await item.click( { force: true } );
	await expect( page.locator( `${ CURRENT } .photopress-gallery-slideshow__image` ) ).toBeVisible( { timeout: 30000 } );
}

/** The part of the slideshow the slides take: above the thumbnails. */
async function slidesBox( page ) {
	return page.locator( `${ SLIDESHOW } .photopress-gallery-slideshow__track` ).boundingBox();
}

test( 'press navigation and arrow keys, through the halves of the slide, as in the block', async ( { page, made } ) => {
	// Nothing on the page may request the page itself as an image (an img
	// whose src is empty resolves to the page's URL).
	const selfRequests = [];
	page.on( 'request', ( request ) => {
		if ( request.resourceType() === 'image' && new URL( request.url() ).searchParams.has( 'page_id' ) ) {
			selfRequests.push( request.url() );
		}
	} );
	await page.goto( `/?page_id=${ made.pages.lightbox }&preview=true` );
	await openOn( page, 0 );

	const [ one, two ] = made.images;
	const last = made.images[ made.images.length - 1 ];
	await expect.poll( () => current( page ) ).toBe( one );

	// The halves are buttons, as in the block; no arrow columns.
	await expect( page.locator( `${ SLIDESHOW } .photopress-gallery-slideshow__next` ) ).toHaveAttribute( 'aria-label', 'Next image' );
	await expect( page.locator( '.lightbox .nav-control' ) ).toHaveCount( 0 );

	const box = await slidesBox( page );
	const y = box.y + box.height / 2;

	await page.mouse.move( box.x + box.width * 0.7, y );
	await page.mouse.down();
	await expect.poll( () => current( page ) ).toBe( two );
	await expect( page.locator( '.photopress-press-cursor' ) ).toBeVisible();
	await page.mouse.up();
	await page.waitForTimeout( 1000 );
	expect( await current( page ) ).toBe( two );

	await page.mouse.click( box.x + box.width * 0.3, y );
	await expect.poll( () => current( page ) ).toBe( one );

	await page.mouse.click( box.x + 30, y );
	await expect.poll( () => current( page ) ).toBe( last );

	await page.keyboard.press( 'ArrowRight' );
	await expect.poll( () => current( page ) ).toBe( one );

	expect( selfRequests ).toEqual( [] );
} );

test( 'only a page whose gallery opens the slideshow loads it, one script with no jQuery, and gives its images what it shows', async ( { page, made } ) => {
	await page.goto( `/?page_id=${ made.pages.masonry }&preview=true` );
	await expect( page.locator( '.wp-block-gallery img' ).first() ).toBeVisible();
	await expect( page.locator( 'script#photopress-gallery-slideshow-js' ) ).toHaveCount( 0 );
	await expect( page.locator( 'img[data-orig-file]' ) ).toHaveCount( 0 );

	await page.goto( `/?page_id=${ made.pages.lightbox }&preview=true` );
	await expect( page.locator( '.photopress-has-slideshow' ) ).toHaveCount( 1 );
	await expect( page.locator( 'script#photopress-gallery-slideshow-js' ) ).toHaveCount( 1 );
	await expect( page.locator( '.photopress-has-slideshow img[data-orig-file][data-aspectratio][data-attachment-url]' ) ).toHaveCount( made.images.length );

	// Nor jQuery, nor a carousel library: none is on the page or in its scripts.
	expect( await page.evaluate( () => [ typeof window.jQuery, typeof window.Owl ] ) ).toEqual( [ 'undefined', 'undefined' ] );
	await expect( page.locator( 'script[src*="owl"], script[src*="jquery"], link[href*="owl"]' ) ).toHaveCount( 0 );
} );

test( 'the thumbnails: the current one brought to the middle, a click on another shows it, and the last slide goes round to the first', async ( { page, made } ) => {
	await page.setViewportSize( { width: 700, height: 900 } );
	await page.goto( `/?page_id=${ made.pages.lightbox }&preview=true` );
	await openOn( page, 3 );
	await expect.poll( () => current( page ) ).toBe( made.images[ 3 ] );

	const thumbnails = page.locator( `${ SLIDESHOW } .photopress-gallery-slideshow__thumbnail` );
	await expect( thumbnails ).toHaveCount( made.images.length );
	await expect( thumbnails.nth( 3 ) ).toHaveClass( /is-current/ );

	// The current thumbnail in the middle of the row, give or take, when the row is wider than the window.
	const middle = async () => page.evaluate( () => {
		const list = document.querySelector( '.lightbox .photopress-gallery-slideshow__thumbnails' );
		const item = list.querySelector( '.is-current' ).getBoundingClientRect();
		const box = list.getBoundingClientRect();
		return { overflows: list.scrollWidth > list.clientWidth, off: Math.abs( item.left + item.width / 2 - ( box.left + box.width / 2 ) ), atEnd: list.scrollLeft <= 0 || list.scrollLeft >= list.scrollWidth - list.clientWidth - 1 };
	} );
	await expect.poll( async () => {
		const m = await middle();
		return ! m.overflows || m.atEnd || m.off < 20;
	} ).toBe( true );

	await thumbnails.nth( 5 ).click();
	await expect.poll( () => current( page ) ).toBe( made.images[ 5 ] );
	await expect( thumbnails.nth( 5 ) ).toHaveClass( /is-current/ );

	await thumbnails.last().click();
	await page.keyboard.press( 'ArrowRight' );
	await expect.poll( () => current( page ) ).toBe( made.images[ 0 ] );
	await expect( thumbnails.first() ).toHaveClass( /is-current/ );

	// A dialog: the page behind it out of reach while it shows.
	await expect( page.locator( '.lightbox' ) ).toHaveAttribute( 'role', 'dialog' );
	await expect( page.locator( '.lightbox' ) ).toHaveAttribute( 'aria-modal', 'true' );
	const behind = () => page.evaluate( () => !! document.querySelector( '.photopress-has-slideshow' ).closest( '[inert]' ) );
	expect( await behind() ).toBe( true );

	await page.keyboard.press( 'Escape' );
	await expect( page.locator( '.lightbox' ) ).toBeHidden();
	expect( await behind() ).toBe( false );
	// Nothing hidden keeps the focus.
	expect( await page.evaluate( () => !! document.activeElement.closest( '.lightbox' ) ) ).toBe( false );
} );

test( 'gallery images say how wide they are shown, so the browser does not take them for the width of the window', async ( { page, made } ) => {
	await page.goto( `/?page_id=${ made.pages.lightbox }&preview=true` );

	const images = await page.locator( '.wp-block-gallery img' ).evaluateAll( ( imgs ) => imgs.map( ( img ) => ( { srcset: img.hasAttribute( 'srcset' ), sizes: img.getAttribute( 'sizes' ) } ) ) );

	// All but 08-small, which has no smaller size and so no srcset.
	expect( images.filter( ( img ) => img.srcset ) ).toHaveLength( made.images.length - 1 );
	expect( images.filter( ( img ) => img.srcset && ! img.sizes ) ).toEqual( [] );
} );

test( 'an image smaller than the slide is shown at its own size, not enlarged', async ( { page, made } ) => {
	// 02-landscape-3x2 is 1200 x 800, its largest file. The slide in this
	// window is about 930 high, which at 3:2 would be about 1395 wide.
	await page.setViewportSize( { width: 1600, height: 1100 } );
	await page.goto( `/?page_id=${ made.pages.lightbox }&preview=true` );

	const id = made.names[ '02-landscape-3x2' ];
	const item = page.locator( `.photopress-has-slideshow .photopress-gallery-item[data-id="${ id }"]` );
	await item.scrollIntoViewIfNeeded();
	await item.click( { force: true } );

	const full = page.locator( `${ SLIDESHOW } .photopress-gallery-slideshow__slide[data-id="${ id }"] img` );
	// Its full file: the original, with no size in its name.
	await expect.poll( () => full.evaluate( ( img ) => img.complete && img.naturalWidth > 1 && ! /-\d+x\d+\.\w+$/.test( img.currentSrc ) ) ).toBe( true );
	expect( await full.getAttribute( 'srcset' ) ).toContain( '1200w' );

	const box = await full.boundingBox();
	expect( box.width ).toBeLessThanOrEqual( 1200 );
	expect( box.height ).toBeLessThanOrEqual( 800 );
} );

test( 'the caption takes its padding', async ( { page, made } ) => {
	await page.goto( `/?page_id=${ made.pages.lightbox }&preview=true` );
	await openOn( page, 0 );

	const caption = page.locator( `${ CURRENT } .photopress-gallery-slideshow__caption` );
	await expect( caption ).toContainText( 'Caption of 01-portrait' );
	expect( await caption.evaluate( ( el ) => getComputedStyle( el ).paddingTop ) ).toBe( '0px' );

	// The setting's value arrives as this variable (see SlideshowTest).
	await page.locator( '.photopress-slideshow' ).evaluate( ( el ) => el.style.setProperty( '--pp-slideshow-caption-padding', '24px' ) );
	expect( await caption.evaluate( ( el ) => [ 'Top', 'Right', 'Bottom', 'Left' ].map( ( side ) => getComputedStyle( el )[ `padding${ side }` ] ) ) ).toEqual( [ '24px', '24px', '24px', '24px' ] );
} );

test( 'opens at once on the clicked image, the copy the page has shown at the size of the full one', async ( { page, made } ) => {
	// No routes here: routing turns the browser's cache off, and the copy
	// is shown from it.
	await page.goto( `/?page_id=${ made.pages.lightbox }&preview=true` );
	await page.waitForLoadState( 'networkidle' );

	// Milliseconds from the click until the slide shows the clicked image,
	// and its size then and once its full file has arrived.
	const open = ( n ) => page.evaluate( ( n ) => new Promise( ( resolve, reject ) => {
		const item = document.querySelectorAll( '.photopress-has-slideshow .photopress-gallery-item' )[ n ];
		const id = item.querySelector( 'img' ).dataset.id;
		const t0 = performance.now();
		const found = {};
		const check = () => {
			const img = document.querySelector( '.lightbox .photopress-gallery-slideshow__slide.is-current img' );
			// Showing a picture: its current file has a size (complete is
			// false while a sharper file is on its way).
			const shown = img && img.closest( 'figure' ).dataset.id === id && img.naturalWidth > 1;
			if ( shown && ! found.at ) {
				found.at = performance.now() - t0;
				found.before = { width: img.getBoundingClientRect().width, height: img.getBoundingClientRect().height, src: img.currentSrc };
			}
			if ( shown && found.before && img.currentSrc !== found.before.src ) {
				found.after = { width: img.getBoundingClientRect().width, height: img.getBoundingClientRect().height };
				return resolve( found );
			}
			if ( performance.now() - t0 > 5000 ) {
				return found.at ? resolve( found ) : reject( new Error( 'the slide did not show' ) );
			}
			requestAnimationFrame( check );
		};
		item.scrollIntoView();
		item.click();
		check();
	} ), n );

	const first = await open( 2 );
	expect( first.at ).toBeLessThan( 500 );
	if ( first.after ) {
		expect( Math.abs( first.after.width - first.before.width ) ).toBeLessThanOrEqual( 2 );
		expect( Math.abs( first.after.height - first.before.height ) ).toBeLessThanOrEqual( 2 );
	}
	expect( await current( page ) ).toBe( made.images[ 2 ] );

	await page.locator( '.lightbox__close' ).click();
	expect( ( await open( 4 ) ).at ).toBeLessThan( 500 );
	expect( await current( page ) ).toBe( made.images[ 4 ] );
} );

test( 'details on the right: beside the image, and no thumbnail is broken', async ( { page, made } ) => {
	// The Slide Details Position setting, set to right before the slideshow reads it.
	await page.addInitScript( () => document.addEventListener( 'DOMContentLoaded', () => {
		document.querySelector( '.photopress-slideshow' )?.setAttribute( 'data-detail_position', 'right' );
	} ) );
	await page.goto( `/?page_id=${ made.pages.lightbox }&preview=true` );
	await openOn( page, 1 );

	await expect( page.locator( SLIDESHOW ) ).toHaveClass( /has-captions-right/ );
	const image = await page.locator( `${ CURRENT } img` ).boundingBox();
	expect( ( await page.locator( `${ CURRENT } .photopress-gallery-slideshow__caption` ).boundingBox() ).x ).toBeGreaterThanOrEqual( image.x + image.width - 1 );

	// Broken: finished loading with no picture. Thumbnails out of view load lazily.
	await page.waitForTimeout( 1500 );
	expect( await page.locator( `${ SLIDESHOW } .photopress-gallery-slideshow__thumbnails img` ).evaluateAll( ( imgs ) => imgs.filter( ( img ) => img.complete && ! img.naturalWidth ).map( ( img ) => img.src ) ) ).toEqual( [] );
} );

test( 'a page rendered by 1.6 and served from a page cache still opens the lightbox', async ( { page, made } ) => {
	// What such a page lacks: the class marking slideshow galleries.
	await page.addInitScript( () => document.addEventListener( 'DOMContentLoaded', () => {
		document.querySelectorAll( '.photopress-has-slideshow' ).forEach( ( g ) => g.classList.remove( 'photopress-has-slideshow' ) );
		document.querySelectorAll( '.wp-block-gallery' ).forEach( ( g ) => g.classList.add( 'photopress-gallery' ) );
	} ) );

	await page.goto( `/?page_id=${ made.pages.lightbox }&preview=true` );

	const item = page.locator( '.photopress-gallery-item' ).nth( 1 );
	await item.scrollIntoViewIfNeeded();
	await item.click( { force: true } );
	await expect.poll( () => current( page ) ).toBe( made.images[ 1 ] );

	const box = await slidesBox( page );
	await page.mouse.click( box.x + box.width - 30, box.y + box.height / 2 );
	await expect.poll( () => current( page ) ).toBe( made.images[ 2 ] );
} );

test( 'a page cached by 1.9, which asks for the old slideshow script after jQuery and Owl Carousel, still opens the lightbox', async ( { page, made } ) => {
	const url = `/?page_id=${ made.pages.lightbox }&preview=true`;

	// The page as 1.9 had it: the old script where the new one is.
	await page.route( ( u ) => u.pathname === '/' && u.searchParams.get( 'page_id' ) === String( made.pages.lightbox ), async ( route ) => {
		const response = await route.fetch();
		const html = ( await response.text() ).replace(
			/<script[^>]*id="photopress-gallery-slideshow-js"[^>]*><\/script>/,
			( tag ) => '<script src="' + tag.match( /src="([^"]+)"/ )[ 1 ].replace( /dist\/gallery-slideshow\.build\.js[^"]*/, 'modules/slideshow/assets/js/owl.carousel.min.js?ver=1.9.0' ) + '"></script>'
				+ '<script src="' + tag.match( /src="([^"]+)"/ )[ 1 ].replace( /dist\/gallery-slideshow\.build\.js[^"]*/, 'modules/slideshow/assets/js/slideshow.js?ver=1.9.0' ) + '"></script>'
		);
		await route.fulfill( { response, body: html } );
	} );

	await page.goto( url );
	await expect( page.locator( 'script#photopress-gallery-slideshow-js' ) ).toHaveCount( 0 );

	await openOn( page, 2 );
	await expect.poll( () => current( page ) ).toBe( made.images[ 2 ] );
} );

test( 'a caption with markup that would run, as on a page cached by an earlier version, is shown without it', async ( { page, made } ) => {
	await page.addInitScript( () => document.addEventListener( 'DOMContentLoaded', () => {
		const img = document.querySelectorAll( '.photopress-has-slideshow .photopress-gallery-item img' )[ 1 ];
		img?.setAttribute( 'data-caption', '<b onmouseover="window.ppRan = 1">Bold</b><em>Kept</em><a href="javascript:window.ppRan = 2">link</a>' );
	} ) );
	await page.goto( `/?page_id=${ made.pages.lightbox }&preview=true` );
	await openOn( page, 1 );

	const caption = page.locator( `${ CURRENT } .photopress-gallery-slideshow__caption` );
	await expect( caption.locator( 'em' ) ).toHaveText( 'Kept' );
	await expect( caption.locator( '[onmouseover]' ) ).toHaveCount( 0 );
	expect( await caption.locator( 'a' ).getAttribute( 'href' ) ).toBeNull();
	await caption.locator( 'b' ).hover();
	await caption.locator( 'a' ).click();
	expect( await page.evaluate( () => window.ppRan ) ).toBeUndefined();
} );


test( 'a modified click on a linked image is left to the browser; on an image with no link, it opens the slideshow', async ( { page, made } ) => {
	// The second image linked, as with Link to: Media file. (Not in the
	// tab the link opens.)
	await page.addInitScript( () => document.addEventListener( 'DOMContentLoaded', () => {
		const img = document.querySelectorAll( '.photopress-has-slideshow figure.wp-block-image img' )[ 1 ];
		if ( ! img ) {
			return;
		}
		const link = document.createElement( 'a' );
		link.href = img.src;
		img.replaceWith( link );
		link.append( img );
	} ) );
	await page.goto( `/?page_id=${ made.pages.lightbox }&preview=true` );

	const items = page.locator( '.photopress-has-slideshow .photopress-gallery-item' );
	const modifier = process.platform === 'darwin' ? 'Meta' : 'Control';

	await items.nth( 1 ).scrollIntoViewIfNeeded();
	const tab = page.context().waitForEvent( 'page' );
	await items.nth( 1 ).locator( 'img' ).click( { modifiers: [ modifier ] } );
	await ( await tab ).close();
	await expect( page.locator( '.lightbox:visible' ) ).toHaveCount( 0 );

	await items.nth( 0 ).locator( 'img' ).click( { modifiers: [ modifier ] } );
	await expect.poll( () => current( page ) ).toBe( made.images[ 0 ] );
} );

test( 'Caption off and Title on: the title shows without the caption', async ( { page, made } ) => {
	await page.addInitScript( () => document.addEventListener( 'DOMContentLoaded', () => {
		const settings = document.querySelector( '.photopress-slideshow' );
		settings?.setAttribute( 'data-showcaptions', '' );
		settings?.setAttribute( 'data-showtitleincaption', '1' );
		document.querySelectorAll( '.photopress-has-slideshow .photopress-gallery-item img' )[ 1 ]?.setAttribute( 'data-caption', 'A caption' );
	} ) );
	await page.goto( `/?page_id=${ made.pages.lightbox }&preview=true` );
	await openOn( page, 1 );

	const caption = page.locator( `${ CURRENT } .photopress-gallery-slideshow__caption` );
	await expect( caption.locator( '.info.title' ) ).toHaveCount( 1 );
	await expect( caption.locator( '.info.caption' ) ).toHaveCount( 0 );
} );
