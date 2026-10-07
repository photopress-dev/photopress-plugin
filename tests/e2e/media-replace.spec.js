/**
 * POST /photopress/v1/media/<id>/file over HTTP, as a publishing tool calls
 * it: raw and multipart bodies, the reprocess_metadata option, posts pointed
 * at the new files, and the requests it refuses.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { test, expect } = require( './test' );

const IMAGES = path.join( __dirname, '..', 'fixtures', 'images' );

/**
 * Sends a REST request from the page, so it goes where the page's requests
 * go (PP_E2E_ORIGIN) with the logged-in cookies and a REST nonce.
 */
async function rest( page, { method = 'GET', route, file, multipart = false, headers = {}, json, auth = true } ) {
	return page.evaluate( async ( { method, route, file, multipart, headers, json, auth } ) => {
		const nonce = auth ? await ( await fetch( '/wp-admin/admin-ajax.php?action=rest-nonce' ) ).text() : '';
		let body;
		const sent = { ...headers };
		if ( file ) {
			const bytes = Uint8Array.from( atob( file.data ), ( c ) => c.charCodeAt( 0 ) );
			const blob = new Blob( [ bytes ], { type: file.type } );
			if ( multipart ) {
				body = new FormData();
				body.append( 'file', blob, file.name );
			} else {
				body = blob;
				sent[ 'Content-Type' ] = file.type;
				sent[ 'Content-Disposition' ] = `attachment; filename="${ file.name }"`;
			}
		} else if ( json ) {
			body = JSON.stringify( json );
			sent[ 'Content-Type' ] = 'application/json';
		}
		if ( nonce ) {
			sent[ 'X-WP-Nonce' ] = nonce;
		}
		const response = await fetch( `/?rest_route=${ encodeURIComponent( route.split( '?' )[ 0 ] ) }${ route.includes( '?' ) ? '&' + route.split( '?' )[ 1 ] : '' }`, {
			method, body, headers: sent, credentials: auth ? 'same-origin' : 'omit',
		} );
		return { status: response.status, data: await response.json() };
	}, { method, route, file, multipart, headers, json, auth } );
}

const file = ( name, as = name, type = 'image/jpeg' ) => ( { name: as, type, data: fs.readFileSync( path.join( IMAGES, name ) ).toString( 'base64' ) } );

test( 'replacing an image: new files, posts updated, metadata as asked', async ( { page, made } ) => {
	const { image, post } = made.replace;
	await page.goto( '/' );

	// Default: the new file's metadata is read, as for an upload.
	const first = await rest( page, { method: 'POST', route: `/photopress/v1/media/${ image }/file`, file: file( '01-portrait-2x3.jpg', 'IMG_1234.jpg' ) } );
	expect( first.status, JSON.stringify( first.data ) ).toBe( 200 );
	expect( first.data.id ).toBe( image );
	expect( first.data.source_url ).toMatch( /pp-fixture-replace-me-v2\.jpg$/ );
	expect( first.data.photopress_replaced.metadata_reprocessed ).toBe( true );
	expect( first.data.photopress_replaced.posts_updated ).toContain( post );
	expect( first.data.alt_text ).toMatch( /Alice/ );

	// The post shows the new medium size, at the new shape: the width written
	// in is kept and the height follows the portrait (200x300).
	const content = ( await rest( page, { route: `/wp/v2/pages/${ post }?context=edit` } ) ).data.content.raw;
	expect( content ).toMatch( /pp-fixture-replace-me-v2-200x300\.jpg" width="300" height="450"/ );

	// The author writes their own alt text, then a new file arrives with
	// reprocess_metadata off: the alt text stays.
	expect( ( await rest( page, { method: 'POST', route: `/wp/v2/media/${ image }`, json: { alt_text: 'Written by hand' } } ) ).status ).toBe( 200 );
	const second = await rest( page, { method: 'POST', route: `/photopress/v1/media/${ image }/file?reprocess_metadata=false`, file: file( '03-square.jpg', 'IMG_1234.jpg' ), multipart: true } );
	expect( second.status, JSON.stringify( second.data ) ).toBe( 200 );
	expect( second.data.source_url ).toMatch( /pp-fixture-replace-me-v3\.jpg$/ );
	expect( second.data.photopress_replaced.metadata_reprocessed ).toBe( false );
	expect( second.data.alt_text ).toBe( 'Written by hand' );
} );

test( 'refused: a file that is not an image, and a request without credentials', async ( { page, made } ) => {
	const { image } = made.replace;
	await page.goto( '/' );

	const text = await rest( page, { method: 'POST', route: `/photopress/v1/media/${ image }/file`, file: { name: 'notes.txt', type: 'text/plain', data: Buffer.from( 'not an image' ).toString( 'base64' ) } } );
	expect( text.status ).toBe( 400 );
	expect( text.data.code ).toBe( 'rest_upload_invalid_type' );

	const anonymous = await rest( page, { method: 'POST', route: `/photopress/v1/media/${ image }/file`, file: file( '03-square.jpg' ), auth: false } );
	expect( anonymous.status ).toBe( 401 );
} );
