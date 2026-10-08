/**
 * REST requests from the page, and fixture images to send with them.
 */
const fs = require( 'fs' );
const path = require( 'path' );

const IMAGES = path.join( __dirname, '..', 'fixtures', 'images' );

/**
 * Sends a REST request from the page, so it goes where the page's requests
 * go (PP_E2E_ORIGIN) with the logged-in cookies and a REST nonce.
 */
async function rest( page, { method = 'GET', route, file, multipart = false, headers = {}, json, auth = true } ) {
	return page.evaluate( async ( { method, route, file, multipart, headers, json, auth } ) => {
		// The editor has one already; elsewhere, ask for one. Trimmed: admin
		// pages can print whitespace around it.
		const nonce = ! auth ? '' : ( window.wpApiSettings?.nonce || ( await ( await fetch( '/wp-admin/admin-ajax.php?action=rest-nonce' ) ).text() ).trim() );
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

module.exports = { rest, file };
