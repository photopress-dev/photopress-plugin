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
