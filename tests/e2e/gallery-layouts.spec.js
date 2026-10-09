/**
 * The PhotoPress layouts on core/gallery as a page loads: nothing moves.
 * Masonry and mosaic are laid out by an inline script right after the
 * gallery, before the page is first painted; rows by CSS alone. The plugin's
 * scripts are held back a second here, so the page is painted before they
 * run, as on a slow connection: a layout left to them would jump.
 */
const { test, expect } = require( './test' );

const GALLERY = '.wp-block-gallery.photopress-layout';

async function load( page, path ) {
	await page.route( /\/wp-content\/plugins\/photopress[^/]*\/dist\/.*\.js/, async ( route ) => {
		await new Promise( ( resolve ) => setTimeout( resolve, 1000 ) );
		await route.fallback();
	} );
	await page.addInitScript( () => {
		window.__shift = 0;
		new PerformanceObserver( ( list ) => {
			for ( const entry of list.getEntries() ) {
				window.__shift += entry.value;
			}
		} ).observe( { type: 'layout-shift', buffered: true } );
	} );
	await page.goto( path, { waitUntil: 'load' } );
	// Past the held-back scripts, and their layout if they do one.
	await page.waitForTimeout( 2500 );
}

const items = ( page ) => page.locator( `${ GALLERY } .wp-block-image` ).evaluateAll( ( els ) => els.map( ( el ) => {
	const b = el.getBoundingClientRect();
	return { x: b.left, y: b.top, w: b.width, h: b.height, ar: parseFloat( el.style.getPropertyValue( '--pp-ar' ) ) };
} ) );

for ( const layout of [ 'masonry', 'mosaic', 'rows' ] ) {
	test( `${ layout }: nothing moves as the page loads`, async ( { page, made } ) => {
		await load( page, `/?page_id=${ made.pages[ layout ] }&preview=true` );

		expect( await page.evaluate( () => window.__shift ) ).toBeLessThan( 0.01 );
		await expect( page.locator( '#after' ) ).toBeVisible();
		// Shown: masonry and mosaic are hidden only until laid out.
		await expect( page.locator( GALLERY ) ).toHaveCSS( 'visibility', 'visible' );
		if ( 'rows' !== layout ) {
			await expect( page.locator( GALLERY ) ).toHaveClass( /photopress-laid-out/ );
		}
	} );
}

test( 'masonry: columns, and no image over another', async ( { page, made } ) => {
	await load( page, `/?page_id=${ made.pages.masonry }&preview=true` );

	const placed = await items( page );
	expect( placed ).toHaveLength( made.images.length );
	expect( new Set( placed.map( ( item ) => Math.round( item.x ) ) ).size ).toBeGreaterThan( 1 );

	for ( const a of placed ) {
		for ( const b of placed ) {
			if ( a !== b ) {
				const overlap = a.x < b.x + b.w - 1 && b.x < a.x + a.w - 1 && a.y < b.y + b.h - 1 && b.y < a.y + a.h - 1;
				expect( overlap ).toBe( false );
			}
		}
	}
} );

test( 'mosaic: each row fills the gallery at one height, images at their own shape', async ( { page, made } ) => {
	await load( page, `/?page_id=${ made.pages.mosaic }&preview=true` );

	const width = await page.locator( GALLERY ).evaluate( ( el ) => el.clientWidth );
	const placed = await items( page );
	const rows = [];

	for ( const item of placed ) {
		const row = rows.find( ( r ) => Math.abs( r[ 0 ].y - item.y ) < 1 );
		row ? row.push( item ) : rows.push( [ item ] );
	}

	expect( rows.length ).toBeGreaterThan( 1 );

	rows.forEach( ( row, i ) => {
		// Not cropped: each image's slot has its shape.
		row.forEach( ( item ) => expect( Math.abs( item.w / item.h - item.ar ) / item.ar ).toBeLessThan( 0.01 ) );
		// One height per row.
		row.forEach( ( item ) => expect( Math.abs( item.h - row[ 0 ].h ) ).toBeLessThan( 1 ) );

		if ( i < rows.length - 1 ) {
			// A full row spans the gallery, and is at most the row height.
			const right = Math.max( ...row.map( ( item ) => item.x + item.w ) );
			expect( Math.abs( right - row[ 0 ].x - width ) ).toBeLessThan( 2 );
			expect( row[ 0 ].h ).toBeLessThanOrEqual( 201 );
		} else {
			// The last row keeps the row height rather than stretching.
			expect( Math.abs( row[ 0 ].h - 200 ) ).toBeLessThan( 1 );
		}
	} );
} );
