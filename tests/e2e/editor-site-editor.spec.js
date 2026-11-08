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

test( 'Image Search opens with an Image Filter per taxonomy in Image Filters, each named by its taxonomy', async ( { page } ) => {
	// Images in search results, which registers the template, for this test
	// only: the setting put back.
	const before = lastLine( wp( 'eval', `$o = get_option( 'photopress_core_search' ); echo wp_json_encode( is_array( $o ) ? $o : null );` ) );
	wp( 'eval', `$o = get_option( 'photopress_core_search' ); $o = is_array( $o ) ? $o : []; $o['images_in_search'] = true; update_option( 'photopress_core_search', $o );` );

	try {
		const titles = JSON.parse( lastLine( wp( 'eval', `echo wp_json_encode( array_column( \\PhotoPress\\modules\\search\\ImageFilters::variations(), 'title' ) );` ) ) );
		await openTemplate( page, 'search-images' );
		expect( await invalidBlocks( page ) ).toEqual( [] );

		// The block as the template has it, without filters, is given one
		// per taxonomy once they load.
		const filters = () => page.evaluate( () => {
			const { getBlocks, getBlocksByName } = window.wp.data.select( 'core/block-editor' );
			const [ parent ] = getBlocksByName( 'photopress/image-filters' );
			return getBlocks( parent ).map( ( b ) => window.wp.blocks.getActiveBlockVariation( b.name, b.attributes )?.title ?? b.attributes.taxonomy );
		} );
		await expect.poll( filters, { timeout: 30000 } ).toEqual( titles );
		await expect( canvas( page ).locator( '.wp-block-photopress-image-filter summary' ) ).toHaveText( titles );

		// Filling them in is not an edit to save.
		expect( await page.evaluate( () => window.wp.data.select( 'core/editor' ).isEditedPostDirty() ) ).toBe( false );
	} finally {
		wp( 'eval', `$o = json_decode( '${ before }', true ); null === $o ? delete_option( 'photopress_core_search' ) : update_option( 'photopress_core_search', $o );` );
	}
} );
