/**
 * The PhotoPress galleries in the block editor: offered in the inserter,
 * their PhotoPress settings in the sidebar, the layout switched by those
 * settings and by the block's variations, and what is saved and shown on
 * the site. Runs only with PP_E2E_EDITOR=1 (editor.js).
 */
const { test, expect } = require( './test' );
const { editorOnly, openEditor, canvas, insertBlock, imageBlocks, selectBlock, attributesOf, save, savedContent, deletePosts, openPanel } = require( './editor' );

test.describe.configure( { mode: 'serial' } );
test.beforeEach( editorOnly );

const made = [];
test.afterAll( () => deletePosts( made ) );

test( 'the inserter offers Masonry, Rows, Mosaic, Taxonomy and Child Pages Galleries', async ( { page } ) => {
	await openEditor( page );

	const titles = await page.evaluate( () => window.wp.blocks.getBlockVariations( 'core/gallery', 'inserter' ).map( ( v ) => v.title ) );
	expect( titles ).toEqual( expect.arrayContaining( [ 'Masonry Gallery', 'Rows Gallery', 'Mosaic Gallery', 'Taxonomy Gallery', 'Child Pages Gallery' ] ) );

	// As a person finds them.
	await page.getByRole( 'button', { name: /block inserter/i } ).first().click();
	await page.getByRole( 'searchbox' ).first().fill( 'photopress' );
	for ( const title of [ 'Masonry Gallery', 'Rows Gallery', 'Mosaic Gallery', 'Taxonomy Gallery', 'Child Pages Gallery' ] ) {
		await expect( page.getByRole( 'option', { name: title, exact: true } ) ).toBeVisible();
	}

	// The legacy blocks are not offered.
	for ( const title of [ 'PhotoPress Gallery', 'Child Pages' ] ) {
		await expect( page.getByRole( 'option', { name: title, exact: true } ) ).toHaveCount( 0 );
	}
} );

test( 'a Masonry Gallery: its column width and captions set in the PhotoPress panel, saved and laid out on the site', async ( { page, made: fixtures } ) => {
	await openEditor( page );
	const id = await insertBlock( page, 'core/gallery', { photopressLayout: 'masonry', linkTo: 'none' }, imageBlocks( fixtures.images.slice( 0, 4 ) ) );
	const sidebar = await selectBlock( page, id );
	await openPanel( sidebar, 'PhotoPress' );

	await expect( sidebar.getByLabel( 'Layout', { exact: true } ) ).toHaveValue( 'masonry' );
	await expect( sidebar.getByLabel( 'Row height', { exact: true } ) ).toHaveCount( 0 );
	await sidebar.getByRole( 'spinbutton', { name: 'Column width' } ).fill( '250' );
	await sidebar.getByLabel( 'Hide captions', { exact: true } ).check();

	// WordPress's own Columns setting does nothing in a PhotoPress layout.
	await expect( sidebar.locator( '[data-pp-hidden="1"]' ) ).toHaveCount( 1 );

	// The canvas lays it out as the site will.
	const block = canvas( page ).locator( `[data-block="${ id }"]` );
	await expect( block ).toHaveClass( /photopress-layout-masonry/ );
	await expect( block ).toHaveClass( /photopress-hide-captions/ );
	expect( await block.getAttribute( 'style' ) ).toMatch( /--pp-column-width:\s*250px/ );

	expect( await attributesOf( page, id ) ).toMatchObject( { photopressLayout: 'masonry', photopressColumnWidth: 250, photopressHideCaptions: true } );

	const post = await save( page );
	made.push( post.id );
	const content = savedContent( post.id );
	expect( content ).toMatch( /<!-- wp:gallery \{[^}]*"photopressLayout":"masonry","photopressColumnWidth":250,"photopressHideCaptions":true/ );

	await page.goto( post.link );
	const gallery = page.locator( '.wp-block-gallery.photopress-layout' );
	await expect( gallery ).toHaveClass( /photopress-layout-masonry/ );
	await expect( gallery.locator( '.wp-block-image' ) ).toHaveCount( 4 );
} );

test( 'the layout switched in the PhotoPress panel and by the block’s variations; back to columns gives WordPress’s Columns back', async ( { page, made: fixtures } ) => {
	await openEditor( page );
	const id = await insertBlock( page, 'core/gallery', { photopressLayout: 'masonry', linkTo: 'none' }, imageBlocks( fixtures.images.slice( 0, 3 ) ) );
	const sidebar = await selectBlock( page, id );
	await openPanel( sidebar, 'PhotoPress' );

	await sidebar.getByLabel( 'Layout', { exact: true } ).selectOption( 'rows' );
	await expect( sidebar.getByRole( 'spinbutton', { name: 'Row height' } ) ).toBeVisible();
	await expect( sidebar.getByRole( 'spinbutton', { name: 'Column width' } ) ).toHaveCount( 0 );
	await expect( canvas( page ).getByRole( 'document', { name: 'Block: Rows Gallery' } ) ).toHaveCount( 1 );

	// From the block card's variations.
	await sidebar.getByRole( 'button', { name: 'Transform to variation' } ).click();
	await page.getByRole( 'menuitemradio', { name: /Mosaic Gallery/ } ).click();
	expect( ( await attributesOf( page, id ) ).photopressLayout ).toBe( 'mosaic' );
	await openPanel( sidebar, 'PhotoPress' );
	await expect( sidebar.getByLabel( 'Layout', { exact: true } ) ).toHaveValue( 'mosaic' );

	await sidebar.getByLabel( 'Layout', { exact: true } ).selectOption( '' );
	expect( ( await attributesOf( page, id ) ).photopressLayout ).toBeUndefined();
	await expect( sidebar.locator( '[data-pp-hidden="1"]:visible' ) ).toHaveCount( 0 );
	await expect( canvas( page ).locator( `[data-block="${ id }"]` ) ).not.toHaveClass( /photopress-layout/ );
} );

test( 'Slideshow turned on in the PhotoPress panel is saved', async ( { page, made: fixtures } ) => {
	await openEditor( page );
	const id = await insertBlock( page, 'core/gallery', { photopressLayout: 'rows', linkTo: 'none' }, imageBlocks( fixtures.images.slice( 0, 3 ) ) );
	const sidebar = await selectBlock( page, id );
	await openPanel( sidebar, 'PhotoPress' );
	await sidebar.getByLabel( 'Slideshow', { exact: true } ).check();

	const post = await save( page );
	made.push( post.id );
	expect( savedContent( post.id ) ).toContain( '"photopressSlideshow":true' );
} );
