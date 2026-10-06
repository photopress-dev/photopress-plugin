/**
 * The lightbox: a press on either half of the slide goes back or forward,
 * with the same cursor as the Gallery Slideshow block.
 */
const { test, expect } = require( './test' );

const current = ( page ) => page.locator( '.panels .center img' ).getAttribute( 'data-id' ).then( Number );

test( 'press navigation, arrow columns and arrow keys', async ( { page, made } ) => {
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
