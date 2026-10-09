/**
 * The lightbox: a press on either half of the slide goes back or forward,
 * with the same cursor as the Gallery Slideshow block.
 */
const { test, expect } = require( './test' );

const current = ( page ) => page.locator( '.panels .center img' ).getAttribute( 'data-id' ).then( Number );

test( 'press navigation, arrow columns and arrow keys', async ( { page, made } ) => {
	// Nothing on the page may request the page itself as an image (an img
	// whose src is empty resolves to the page's URL).
	const selfRequests = [];
	page.on( 'request', ( request ) => {
		if ( request.resourceType() === 'image' && new URL( request.url() ).searchParams.has( 'page_id' ) ) {
			selfRequests.push( request.url() );
		}
	} );
	await page.goto( `/?page_id=${ made.pages.lightbox }&preview=true` );

	const first = page.locator( '.photopress-has-slideshow .photopress-gallery-item' ).first();
	await first.scrollIntoViewIfNeeded();
	await first.click( { force: true } );
	await expect( page.locator( '.panels .center img' ) ).toBeVisible( { timeout: 30000 } );

	const [ one, two ] = made.images;
	const last = made.images[ made.images.length - 1 ];
	await expect.poll( () => current( page ) ).toBe( one );

	const panels = await page.locator( '.photopress-slideshow .panels' ).boundingBox();
	const y = panels.y + panels.height / 2;

	await page.mouse.move( panels.x + panels.width * 0.7, y );
	await page.mouse.down();
	await expect.poll( () => current( page ) ).toBe( two );
	await expect( page.locator( '.photopress-press-cursor' ) ).toBeVisible();
	await page.mouse.up();
	await page.waitForTimeout( 1000 );
	expect( await current( page ) ).toBe( two );

	await page.mouse.click( panels.x + panels.width * 0.3, y );
	await expect.poll( () => current( page ) ).toBe( one );

	await page.mouse.click( panels.x + 30, y );
	await expect.poll( () => current( page ) ).toBe( last );

	await page.keyboard.press( 'ArrowRight' );
	await expect.poll( () => current( page ) ).toBe( one );

	expect( selfRequests ).toEqual( [] );
} );

test( 'gallery images say how wide they are shown, so the browser does not take them for the width of the window', async ( { page, made } ) => {
	await page.goto( `/?page_id=${ made.pages.lightbox }&preview=true` );

	const images = await page.locator( '.wp-block-gallery img' ).evaluateAll( ( imgs ) => imgs.map( ( img ) => ( { srcset: img.hasAttribute( 'srcset' ), sizes: img.getAttribute( 'sizes' ) } ) ) );

	// All but 08-small, which has no smaller size and so no srcset.
	expect( images.filter( ( img ) => img.srcset ) ).toHaveLength( made.images.length - 1 );
	expect( images.filter( ( img ) => img.srcset && ! img.sizes ) ).toEqual( [] );
} );

test( 'with a mouse the arrows give way to the cursor; the caption takes its padding', async ( { page, made } ) => {
	await page.goto( `/?page_id=${ made.pages.lightbox }&preview=true` );

	const first = page.locator( '.photopress-has-slideshow .photopress-gallery-item' ).first();
	await first.scrollIntoViewIfNeeded();
	await first.click( { force: true } );
	await expect( page.locator( '.panels .center img' ) ).toBeVisible( { timeout: 30000 } );

	await expect( page.locator( '.nav-control .arrow' ).first() ).toBeHidden();

	const info = page.locator( '.panels .slide-info' );
	await expect( info ).toContainText( 'Caption of 01-portrait' );
	expect( await info.evaluate( ( el ) => getComputedStyle( el ).paddingTop ) ).toBe( '0px' );

	// The setting's value arrives as this variable (see SlideshowTest).
	await page.locator( '.photopress-slideshow' ).evaluate( ( el ) => el.style.setProperty( '--pp-slideshow-caption-padding', '24px' ) );
	expect( await info.evaluate( ( el ) => [ 'Top', 'Right', 'Bottom', 'Left' ].map( ( side ) => getComputedStyle( el )[ `padding${ side }` ] ) ) ).toEqual( [ '24px', '24px', '24px', '24px' ] );
} );

test( 'opens at once on the clicked image, previewed at the size of the full one', async ( { page, made } ) => {
	await page.goto( `/?page_id=${ made.pages.lightbox }&preview=true` );
	await page.waitForLoadState( 'networkidle' );

	// Milliseconds from the click until the slide shows the clicked image.
	const open = ( n ) => page.evaluate( ( n ) => new Promise( ( resolve ) => {
		const item = document.querySelectorAll( '.photopress-has-slideshow .photopress-gallery-item' )[ n ];
		const id = item.querySelector( 'img' ).dataset.id;
		const t0 = performance.now();
		const check = () => {
			const img = document.querySelector( '.panels .center img' );
			if ( img && img.dataset.id === id || img && img.classList.contains( 'slide-preview' ) && img.src === ( item.querySelector( 'img' ).currentSrc || item.querySelector( 'img' ).src ) ) {
				return resolve( performance.now() - t0 );
			}
			requestAnimationFrame( check );
		};
		item.scrollIntoView();
		item.click();
		check();
	} ), n );

	expect( await open( 2 ) ).toBeLessThan( 500 );

	const preview = page.locator( '.panels .center img.slide-preview' );
	if ( await preview.count() ) {
		const before = await preview.boundingBox();
		await expect( preview ).toHaveCount( 0, { timeout: 15000 } );
		const after = await page.locator( '.panels .center img' ).boundingBox();
		expect( Math.abs( after.width - before.width ) ).toBeLessThanOrEqual( 2 );
		expect( Math.abs( after.height - before.height ) ).toBeLessThanOrEqual( 2 );
	}
	await expect( page.locator( '.panels .center img' ) ).toHaveAttribute( 'data-id', String( made.images[ 2 ] ) );

	await page.locator( '.lightbox__close' ).click();
	expect( await open( 4 ) ).toBeLessThan( 500 );
	await expect( page.locator( '.panels .center img:not(.slide-preview)' ) ).toHaveAttribute( 'data-id', String( made.images[ 4 ] ), { timeout: 15000 } );
} );

test( 'details on the right: the preview matches the full image, and no thumbnail is broken', async ( { page, made } ) => {
	// The Slide Details Position setting, set to right before the slideshow reads it.
	await page.addInitScript( () => document.addEventListener( 'DOMContentLoaded', () => {
		document.querySelector( '.photopress-slideshow' )?.setAttribute( 'data-detail_position', 'right' );
	} ) );
	// Full-size files arrive late, as over a real connection, so the preview is seen.
	await page.route( /pp-fixture-[^/]*\.jpg/, async ( route ) => {
		if ( ! /-\d+x\d+\.jpg/.test( route.request().url() ) ) {
			await new Promise( ( resolve ) => setTimeout( resolve, 1000 ) );
		}
		await route.fallback();
	} );
	await page.goto( `/?page_id=${ made.pages.lightbox }&preview=true` );
	await page.waitForLoadState( 'networkidle' );

	// The size of the preview and then of the full image, as each appears.
	const { before, after } = await page.evaluate( () => new Promise( ( resolve, reject ) => {
		const sizes = {};
		const t0 = performance.now();
		const check = () => {
			const img = document.querySelector( '.panels .center img' );
			const box = img && img.getBoundingClientRect();
			if ( img && img.classList.contains( 'slide-preview' ) && ! sizes.before ) {
				sizes.before = { x: box.x, width: box.width, height: box.height };
			}
			if ( img && ! img.classList.contains( 'slide-preview' ) && img.complete && img.naturalWidth ) {
				sizes.after = { x: box.x, width: box.width, height: box.height };
				return sizes.before ? resolve( sizes ) : reject( new Error( 'no preview was shown' ) );
			}
			if ( performance.now() - t0 > 15000 ) {
				return reject( new Error( 'the full image did not load' ) );
			}
			requestAnimationFrame( check );
		};
		const item = document.querySelectorAll( '.photopress-has-slideshow .photopress-gallery-item' )[ 1 ];
		item.scrollIntoView();
		item.click();
		check();
	} ) );
	expect( Math.abs( after.width - before.width ) ).toBeLessThanOrEqual( 2 );
	expect( Math.abs( after.height - before.height ) ).toBeLessThanOrEqual( 2 );

	await expect( page.locator( '.panels .center' ) ).toHaveClass( /info-right/ );
	expect( ( await page.locator( '.panels .slide-info' ).boundingBox() ).x ).toBeGreaterThanOrEqual( after.x + after.width - 1 );

	// Broken: finished loading with no picture. Thumbnails out of view load lazily.
	await page.waitForTimeout( 1500 );
	expect( await page.locator( '.thumbnail-list img' ).evaluateAll( ( imgs ) => imgs.filter( ( img ) => img.complete && ! img.naturalWidth ).map( ( img ) => img.dataset.id ) ) ).toEqual( [] );
} );

test( 'a page rendered by 1.6 and served from a page cache still opens the lightbox', async ( { page, made } ) => {
	// What such a page lacks: the class marking slideshow galleries, and the
	// press navigation script.
	await page.addInitScript( () => document.addEventListener( 'DOMContentLoaded', () => {
		document.querySelectorAll( '.photopress-has-slideshow' ).forEach( ( g ) => g.classList.remove( 'photopress-has-slideshow' ) );
		document.querySelectorAll( '.wp-block-gallery' ).forEach( ( g ) => g.classList.add( 'photopress-gallery' ) );
	} ) );
	await page.route( /press-navigation\.build\.js/, ( route ) => route.abort() );

	await page.goto( `/?page_id=${ made.pages.lightbox }&preview=true` );

	const item = page.locator( '.photopress-gallery-item' ).nth( 1 );
	await item.scrollIntoViewIfNeeded();
	await item.click( { force: true } );
	await expect( page.locator( '.panels .center img' ) ).toHaveAttribute( 'data-id', String( made.images[ 1 ] ), { timeout: 15000 } );

	// No press navigation, so the arrows stay, and the arrow columns work.
	await expect( page.locator( '.nav-control .arrow' ).first() ).toBeVisible();
	const panels = await page.locator( '.photopress-slideshow .panels' ).boundingBox();
	await page.mouse.click( panels.x + panels.width - 30, panels.y + panels.height / 2 );
	await expect( page.locator( '.panels .center img' ) ).toHaveAttribute( 'data-id', String( made.images[ 2 ] ), { timeout: 15000 } );
} );
