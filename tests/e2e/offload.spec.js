/**
 * Replacing an image served by WP Offload Media through CloudFront: the same
 * URL, PhotoPress's cache header, the old renamed sizes deleted from the
 * bucket, and the invalidation from the Offload Media settings tab. Needs Offload Media installed and configured for a test bucket
 * and distribution (see the test site's AS3CF_SETTINGS), so it runs only with
 * PP_E2E_OFFLOAD=1. Offload Media is active only while it runs.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { test, expect } = require( './test' );
const { wp } = require( './wp' );

test.skip( ! process.env.PP_E2E_OFFLOAD, 'Set PP_E2E_OFFLOAD=1 to run against the Offload Media test bucket.' );

const IMAGES = path.join( __dirname, '..', 'fixtures', 'images' );

/** A REST call from the page, with a nonce. */
const rest = ( page, route, { method = 'GET', body, type, name } = {} ) => page.evaluate( async ( { route, method, body, type, name } ) => {
	const nonce = await ( await fetch( '/wp-admin/admin-ajax.php?action=rest-nonce' ) ).text();
	const headers = { 'X-WP-Nonce': nonce };
	let data;
	if ( body ) {
		data = new Blob( [ Uint8Array.from( atob( body ), ( c ) => c.charCodeAt( 0 ) ) ], { type } );
		headers[ 'Content-Type' ] = type;
		headers[ 'Content-Disposition' ] = `attachment; filename="${ name }"`;
	}
	const response = await fetch( `/?rest_route=${ route }`, { method, headers, body: data } );
	return { status: response.status, data: await response.json() };
}, { route, method, body, type, name } );

const jpeg = ( file ) => fs.readFileSync( path.join( IMAGES, file ) ).toString( 'base64' );

let image = null;

// Offload Media on, and the setting that deletes renamed files' old copies.
const MEDIA_OPTION = 'photopress_core_media';
let mediaOption = '';

test.beforeAll( () => {
	wp( 'plugin', 'activate', 'amazon-s3-and-cloudfront' );
	mediaOption = wp( 'eval', `echo wp_json_encode( get_option( '${ MEDIA_OPTION }', null ) );` ).trim().split( '\n' ).pop();
	wp( 'eval', `$o = (array) get_option( '${ MEDIA_OPTION }', [] ); $o['delete_replaced_objects'] = true; update_option( '${ MEDIA_OPTION }', $o );` );
} );

test.afterAll( async ( { browser } ) => {
	if ( image ) {
		// Offload Media deletes the bucket's copies with the image.
		wp( 'post', 'delete', String( image ), '--force' );
	}
	wp( 'eval', "delete_option( 'photopress_cdn_last' ); delete_option( 'photopress_cdn_pending' ); as_unschedule_all_actions( 'photopress_cdn_invalidate', [], 'photopress' ); as_unschedule_all_actions( 'photopress_offload_delete_objects' );" );
	wp( 'eval', `$before = json_decode( '${ mediaOption.replace( /'/g, "\\'" ) }', true ); null === $before ? delete_option( '${ MEDIA_OPTION }' ) : update_option( '${ MEDIA_OPTION }', $before );` );
	wp( 'plugin', 'deactivate', 'amazon-s3-and-cloudfront' );
} );

test( 'a replaced image keeps its CloudFront URL and is cleared from the CDN', async ( { page, request } ) => {
	test.setTimeout( 300000 );
	await page.goto( '/wp-admin/admin.php?page=photopress-core-base#photopress_core_media' );

	const uploaded = await rest( page, '/wp/v2/media', { method: 'POST', body: jpeg( '02-landscape-3x2.jpg' ), type: 'image/jpeg', name: 'pp-fixture-offload.jpg' } );
	expect( uploaded.status, JSON.stringify( uploaded.data ) ).toBe( 201 );
	image = uploaded.data.id;
	const url = uploaded.data.source_url;
	expect( url ).toMatch( /^https:\/\/[^/]*cloudfront\.net\// );

	// PhotoPress's cache header, not Offload Media's year. Twice, so
	// CloudFront has it cached.
	let served = await request.get( url );
	expect( served.headers()[ 'cache-control' ] ).toBe( 'max-age=86400, stale-while-revalidate=3600' );
	const before = served.headers().etag;
	await request.get( url );

	const oldMedium = uploaded.data.media_details.sizes.medium.source_url;
	expect( ( await request.get( oldMedium ) ).status() ).toBe( 200 );

	// A new shape (16:9 for 3:2): the original keeps its URL; sizes such as
	// the medium one get new names, and their old copies in the bucket are
	// scheduled for deletion.
	const replaced = await rest( page, `/photopress/v1/media/${ image }/file`, { method: 'POST', body: jpeg( '04-wide-16x9.jpg' ), type: 'image/jpeg', name: 'IMG.jpg' } );
	expect( replaced.status, JSON.stringify( replaced.data ) ).toBe( 200 );
	expect( replaced.data.source_url ).toBe( url );
	expect( replaced.data.media_details.sizes.medium.source_url ).not.toBe( oldMedium );

	const scheduled = JSON.parse( wp( 'eval', "echo wp_json_encode( array_values( array_map( fn( $a ) => $a->get_args(), as_get_scheduled_actions( [ 'hook' => 'photopress_offload_delete_objects', 'status' => 'pending' ] ) ) ) );" ).trim().split( '\n' ).pop() );
	expect( scheduled ).toHaveLength( 1 );
	expect( scheduled[ 0 ][ 2 ] ).toContain( new URL( oldMedium ).pathname.slice( 1 ) );

	// Run now rather than in two days.
	wp( 'eval', "foreach ( as_get_scheduled_actions( [ 'hook' => 'photopress_offload_delete_objects', 'status' => 'pending' ] ) as $id => $a ) { do_action_ref_array( 'photopress_offload_delete_objects', $a->get_args() ); as_unschedule_action( 'photopress_offload_delete_objects', $a->get_args(), 'photopress' ); }" );

	// Cleared from the settings tab rather than waiting for the queue.
	await page.reload();
	const status = page.locator( '.photopress-offload-status' );
	await expect( status ).toContainText( '1 image(s) waiting', { timeout: 60000 } );
	await status.getByRole( 'button', { name: 'Clear waiting images now' } ).click();
	await expect( status ).toContainText( 'Last invalidation:', { timeout: 60000 } );
	await expect( status ).not.toContainText( 'failed' );

	// CloudFront serves the new file once the invalidation completes, and
	// the old medium size no longer exists.
	await expect.poll( async () => ( await request.get( url ) ).headers().etag, { timeout: 240000, intervals: [ 10000 ] } ).not.toBe( before );
	expect( ( await request.get( oldMedium ) ).status() ).toBeGreaterThanOrEqual( 400 );
} );
