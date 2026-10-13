/**
 * PhotoPress's templates in the Site Editor: listed while their kind of page
 * is on, and opening with their blocks as they are meant to show there.
 * Nothing is saved. Runs only with PP_E2E_EDITOR=1 (editor.js).
 */
const { test, expect } = require( './test' );
const { wp, lastLine } = require( './wp' );
const { editorOnly, canvas, settle, invalidBlocks } = require( './editor' );

test.beforeEach( editorOnly );

const theme = () => lastLine( wp( 'eval', 'echo get_stylesheet();' ) );

/** A template open in the Site Editor, ready. */
async function openTemplate( page, slug ) {
	await page.goto( `/wp-admin/site-editor.php?postType=wp_template&postId=${ encodeURIComponent( `${ theme() }//${ slug }` ) }&canvas=edit` );
	await page.waitForFunction( () => window.wp?.data?.select( 'core/block-editor' )?.getBlocks().length > 0, null, { timeout: 60000 } );
	await settle( page );
}

test( 'Image Archives and Image Pages are among the templates', async ( { page } ) => {
	await page.goto( '/wp-admin/site-editor.php?postType=wp_template' );
	for ( const title of [ 'Image Archives', 'Image Pages' ] ) {
		await expect( page.getByText( title, { exact: true } ).first() ).toBeVisible( { timeout: 60000 } );
	}
} );

test( 'Image Archives opens with its Taxonomy Gallery showing the archive’s images', async ( { page } ) => {
	await openTemplate( page, 'taxonomy-attachment' );
	expect( await invalidBlocks( page ) ).toEqual( [] );
	await expect( canvas( page ).getByText( 'The images of the archive or search results being viewed, in the Masonry layout' ) ).toBeVisible();
} );

test( 'Image Pages opens with the image and its metadata', async ( { page } ) => {
	await openTemplate( page, 'attachment' );
	expect( await invalidBlocks( page ) ).toEqual( [] );
	const names = await page.evaluate( () => {
		const flat = ( list ) => list.flatMap( ( b ) => [ b.name, ...flat( b.innerBlocks ) ] );
		return flat( window.wp.data.select( 'core/block-editor' ).getBlocks() );
	} );
	expect( names ).toEqual( expect.arrayContaining( [ 'photopress/attachment-image', 'photopress/image-taxonomies' ] ) );
	await expect( canvas( page ).getByText( 'The image of an image (attachment) page, with its caption.' ).first() ).toBeVisible();
} );
