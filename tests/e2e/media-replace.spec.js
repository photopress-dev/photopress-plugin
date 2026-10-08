/**
 * POST /photopress/v1/media/<id>/file over HTTP, as a publishing tool calls
 * it: raw and multipart bodies, the reprocess_metadata option, the file
 * keeping its name, posts pointed at the new sizes, and the requests it
 * refuses.
 */
const { test, expect } = require( './test' );
const { rest, file } = require( './rest' );

test( 'same type: the name stays, sizes follow the new shape, text follows the file', async ( { page, made } ) => {
	const { image, post } = made.replace;
	await page.goto( '/' );

	const before = ( await rest( page, { route: `/wp/v2/media/${ image }?context=edit` } ) ).data;
	const name = before.source_url.split( '/' ).pop();
	const oldMedium = before.media_details.sizes.medium.source_url;

	// The landscape image becomes a portrait; metadata read as for an upload.
	const first = await rest( page, { method: 'POST', route: `/photopress/v1/media/${ image }/file`, file: file( '01-portrait-2x3.jpg', 'IMG_1234.jpg' ) } );
	expect( first.status, JSON.stringify( first.data ) ).toBe( 200 );
	expect( first.data.id ).toBe( image );
	expect( first.data.source_url ).toBe( before.source_url );
	expect( first.data.slug ).toBe( before.slug );
	expect( [ first.data.media_details.width, first.data.media_details.height ] ).toEqual( [ 800, 1200 ] );

	// Title and caption follow the file (its XMP; it has no IPTC).
	expect( first.data.title.raw ).toBe( 'Alice' );
	expect( first.data.caption.raw ).toMatch( /^Alice, a 800.1200 test image\.$/ );
	expect( first.data.photopress_replaced.text_updated ).toBe( true );
	expect( first.data.alt_text ).toMatch( /Alice/ );

	// The medium size has a new name for the new shape: the post now shows
	// it, and the old file is gone. Its own alt text and caption stay.
	const medium = first.data.media_details.sizes.medium.source_url;
	expect( medium ).toMatch( /-200x300\.jpg$/ );
	expect( first.data.photopress_replaced.posts_updated ).toContain( post );
	let content = ( await rest( page, { route: `/wp/v2/pages/${ post }?context=edit` } ) ).data.content.raw;
	expect( content ).toContain( new URL( medium ).pathname );
	expect( content ).not.toContain( new URL( oldMedium ).pathname );
	expect( content ).toContain( 'Caption written in the post' );
	expect( await page.evaluate( async ( url ) => ( await fetch( new URL( url ).pathname ) ).status, oldMedium ) ).toBe( 404 );

	// The same file again: nothing to rename, nothing to update.
	const again = await rest( page, { method: 'POST', route: `/photopress/v1/media/${ image }/file`, file: file( '01-portrait-2x3.jpg', 'IMG_1234.jpg' ) } );
	expect( again.status ).toBe( 200 );
	expect( again.data.photopress_replaced.files ).toEqual( [] );
	expect( again.data.photopress_replaced.posts_updated ).toEqual( [] );
	expect( again.data.photopress_replaced.text_updated ).toBe( false );

	// reprocess_metadata off: hand-written alt text stays, but the title
	// still follows the file. sync_embedded puts the image's alt text and
	// caption into the post's image block.
	expect( ( await rest( page, { method: 'POST', route: `/wp/v2/media/${ image }`, json: { alt_text: 'Written by hand' } } ) ).status ).toBe( 200 );
	const second = await rest( page, { method: 'POST', route: `/photopress/v1/media/${ image }/file?reprocess_metadata=false&sync_embedded=true`, file: file( '03-square.jpg', 'IMG_1234.jpg' ), multipart: true } );
	expect( second.status, JSON.stringify( second.data ) ).toBe( 200 );
	expect( second.data.source_url ).toBe( before.source_url );
	expect( [ second.data.media_details.width, second.data.media_details.height ] ).toEqual( [ 1000, 1000 ] );
	expect( second.data.alt_text ).toBe( 'Written by hand' );
	expect( second.data.title.raw ).toBe( 'Carol' );
	expect( second.data.photopress_replaced.embedded_synced ).toEqual( [ post ] );
	content = ( await rest( page, { route: `/wp/v2/pages/${ post }?context=edit` } ) ).data.content.raw;
	expect( content ).toContain( 'alt="Written by hand"' );
	expect( content ).toMatch( /<figcaption class="wp-element-caption">Carol, a 1000.1000 test image\.<\/figcaption>/ );

	// The block as sync_embedded wrote it is one the editor accepts.
	await page.goto( `/wp-admin/post.php?post=${ post }&action=edit` );
	await page.waitForFunction( () => window.wp?.data?.select( 'core/block-editor' )?.getBlocks().length > 0, null, { timeout: 90000 } );
	expect( await page.evaluate( () => wp.data.select( 'core/block-editor' ).getBlocks().map( ( b ) => [ b.name, b.isValid ] ) ) )
		.toContainEqual( [ 'core/image', true ] );
} );

test( 'another file type: new extension, old files gone, links rewritten', async ( { page, made } ) => {
	const { image, post } = made.replace;
	await page.goto( '/' );

	const before = ( await rest( page, { route: `/wp/v2/media/${ image }?context=edit` } ) ).data;
	const replaced = await rest( page, { method: 'POST', route: `/photopress/v1/media/${ image }/file`, file: file( '09-png-3x2.png', 'IMG_1234.png', 'image/png' ) } );
	expect( replaced.status, JSON.stringify( replaced.data ) ).toBe( 200 );
	expect( replaced.data.mime_type ).toBe( 'image/png' );
	expect( replaced.data.source_url ).toBe( before.source_url.replace( /\.jpg$/, '.png' ) );
	expect( replaced.data.slug ).toBe( before.slug );

	const content = ( await rest( page, { route: `/wp/v2/pages/${ post }?context=edit` } ) ).data.content.raw;
	expect( content ).toContain( new URL( replaced.data.source_url ).pathname );
	expect( content ).not.toMatch( /pp-fixture-replace-me[^"]*\.jpg/ );
	expect( await page.evaluate( async ( url ) => ( await fetch( new URL( url ).pathname ) ).status, before.source_url ) ).toBe( 404 );
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
