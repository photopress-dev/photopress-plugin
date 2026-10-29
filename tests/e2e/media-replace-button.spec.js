/**
 * The Replace file button in an image's details, on its edit screen and in
 * the media modal: the new file goes to Replace File, which reads its
 * metadata as for an upload.
 */
const path = require( 'path' );
const { test, expect } = require( './test' );
const { rest, file } = require( './rest' );

const IMAGES = path.join( __dirname, '..', 'fixtures', 'images' );

// The image is shared with media-replace.spec.js, which expects its own
// file: put back.
test.afterEach( async ( { page, made } ) => {
	const restored = await rest( page, { method: 'POST', route: `/photopress/v1/media/${ made.replace.image }/file`, file: file( '02-landscape-3x2.jpg', 'pp-fixture-replace-me.jpg' ) } );
	expect( restored.status, JSON.stringify( restored.data ) ).toBe( 200 );
} );

test( 'edit screen: the file is replaced and the page shows its new text', async ( { page, made } ) => {
	const { image } = made.replace;
	await page.goto( `/wp-admin/post.php?post=${ image }&action=edit` );

	const field = page.locator( '.photopress-replace-file' );
	await expect( field.getByRole( 'button', { name: 'Replace file' } ) ).toBeVisible();

	const reloaded = page.waitForEvent( 'load' );
	await field.locator( 'input[type="file"]' ).setInputFiles( path.join( IMAGES, '01-portrait-2x3.jpg' ) );
	await reloaded;

	await expect( page.locator( '#title' ) ).toHaveValue( 'Alice' );
	await expect( page.locator( '#attachment_alt' ) ).toHaveValue( /Alice/ );
} );

test( 'media modal: the file is replaced and the details show its new text', async ( { page, made } ) => {
	const { image } = made.replace;
	await page.goto( `/wp-admin/upload.php?item=${ image }` );

	const modal = page.locator( '.media-modal' );
	const field = modal.locator( '.photopress-replace-file' );
	await expect( field.getByRole( 'button', { name: 'Replace file' } ) ).toBeVisible( { timeout: 30000 } );

	await field.locator( 'input[type="file"]' ).setInputFiles( path.join( IMAGES, '01-portrait-2x3.jpg' ) );

	await expect( modal.locator( '[data-setting="title"] input' ) ).toHaveValue( 'Alice', { timeout: 30000 } );
} );
