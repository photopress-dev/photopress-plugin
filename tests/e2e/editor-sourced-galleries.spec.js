/**
 * Taxonomy Gallery and Child Pages Gallery in the block editor: their
 * summaries in the canvas, their settings, what is saved and what the site
 * shows; and the legacy Child Pages block turned into a Child Pages Gallery.
 * Runs only with PP_E2E_EDITOR=1 (editor.js).
 */
const { test, expect } = require( './test' );
const { wp, lastLine } = require( './wp' );
const { editorOnly, openEditor, canvas, insertBlock, selectBlock, openPanel, attributesOf, save, savedContent, deletePosts, makePost, invalidBlocks } = require( './editor' );

test.describe.configure( { mode: 'serial' } );
test.beforeEach( editorOnly );

const made = [];
test.afterAll( () => deletePosts( made ) );

/** A variation's own attributes, as the inserter gives them. */
const variation = ( page, name ) => page.evaluate( ( n ) => window.wp.blocks.getBlockVariations( 'core/gallery' ).find( ( v ) => v.name === n ).attributes, name );

test( 'a Taxonomy Gallery: the latest images, then a keyword’s, its summary following its settings; saved and shown on the site', async ( { page, made: fixtures } ) => {
	// A keyword the fixture images have.
	const keyword = JSON.parse( lastLine( wp( 'eval', `$t = wp_get_object_terms( ${ fixtures.images[ 0 ] }, 'photos_keywords' )[0]; echo wp_json_encode( [ 'id' => $t->term_id, 'name' => $t->name, 'taxonomy' => get_taxonomy( 'photos_keywords' )->labels->name ] );` ) ) );

	await openEditor( page, { type: 'page' } );
	const id = await insertBlock( page, 'core/gallery', await variation( page, 'photopress-taxonomy-gallery' ) );
	const block = canvas( page ).locator( `[data-block="${ id }"]` );
	await expect( block ).toContainText( 'The 12 latest images, newest first, in the Masonry layout.' );

	const sidebar = await selectBlock( page, id );
	await openPanel( sidebar, 'Images' );
	await sidebar.getByLabel( 'Taxonomy', { exact: true } ).selectOption( 'photos_keywords' );
	const term = sidebar.getByRole( 'combobox', { name: 'Term' } );
	await term.fill( keyword.name );
	await page.getByRole( 'option', { name: keyword.name, exact: true } ).click();
	await sidebar.getByRole( 'spinbutton', { name: 'Number of images' } ).fill( '3' );
	await sidebar.getByLabel( 'Order', { exact: true } ).selectOption( 'date-asc' );

	await expect( block ).toContainText( `3 images in ${ keyword.taxonomy }: “${ keyword.name }”, oldest first, in the Masonry layout.` );
	expect( await attributesOf( page, id ) ).toMatchObject( { photopressSource: 'query', photopressTaxonomy: 'photos_keywords', photopressTerm: keyword.id, photopressCount: 3, photopressOrder: 'date-asc' } );

	await sidebar.getByLabel( 'Show heading', { exact: true } ).check();
	await sidebar.getByLabel( 'Heading', { exact: true } ).fill( 'Ojai' );
	await expect( block.locator( 'h2.photopress-gallery-heading' ) ).toHaveText( 'Ojai' );

	const post = await save( page );
	made.push( post.id );
	expect( savedContent( post.id ) ).toMatch( new RegExp( `"photopressSource":"query","photopressTaxonomy":"photos_keywords","photopressTerm":${ keyword.id },"photopressCount":3,"photopressOrder":"date-asc","photopressShowHeading":true,"photopressHeading":"Ojai"` ) );

	await page.goto( post.link );
	await expect( page.locator( 'h2.photopress-gallery-heading' ) ).toHaveText( 'Ojai' );
	const images = page.locator( '.wp-block-gallery.photopress-layout .wp-block-image img' );
	await expect( images.first() ).toBeVisible();
	expect( await images.count() ).toBeLessThanOrEqual( 3 );
} );

test( 'a Child Pages Gallery on a page with child pages: its summary counts those it shows; its heading saved and shown on the site', async ( { page, made: fixtures } ) => {
	// One child with a featured image, one with neither it nor a gallery.
	const pages = JSON.parse( lastLine( wp( 'eval', `
		$parent = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'E2E editor parent ${ Date.now() }' ] );
		$shown = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_parent' => $parent, 'post_title' => 'E2E shown child' ] );
		set_post_thumbnail( $shown, ${ fixtures.images[ 0 ] } );
		$bare = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_parent' => $parent, 'post_title' => 'E2E bare child' ] );
		echo wp_json_encode( compact( 'parent', 'shown', 'bare' ) );
	` ) ) );
	made.push( pages.shown, pages.bare, pages.parent );

	await openEditor( page, { id: pages.parent } );
	const id = await insertBlock( page, 'core/gallery', await variation( page, 'photopress-child-pages-gallery' ) );
	const block = canvas( page ).locator( `[data-block="${ id }"]` );
	await expect( block ).toContainText( '1 of the 2 pages under this one' );

	const sidebar = await selectBlock( page, id );
	await openPanel( sidebar, 'Pages' );
	await sidebar.getByLabel( 'Show heading', { exact: true } ).check();
	await sidebar.getByLabel( 'Heading', { exact: true } ).fill( 'Places' );

	const post = await save( page );
	expect( savedContent( post.id ) ).toContain( '"photopressSource":"children"' );

	await page.goto( post.link );
	await expect( page.locator( 'h2.photopress-gallery-heading' ) ).toHaveText( 'Places' );
	await expect( page.locator( '.wp-block-gallery figcaption a', { hasText: 'E2E shown child' } ) ).toHaveCount( 1 );
	await expect( page.locator( '.wp-block-gallery', { hasText: 'E2E bare child' } ) ).toHaveCount( 0 );
} );

test( 'a Child Pages Gallery on a page with none says so', async ( { page } ) => {
	const id = makePost( '', 'page' );
	made.push( id );
	await openEditor( page, { id } );
	const block = await insertBlock( page, 'core/gallery', await variation( page, 'photopress-child-pages-gallery' ) );
	await expect( canvas( page ).locator( `[data-block="${ block }"]` ) ).toContainText( 'This page has no published pages under it yet.' );
} );

test( 'a legacy Child Pages block, converted by the upgrade, opens as a valid Child Pages Gallery', async ( { page } ) => {
	const id = makePost( '<!-- wp:photopress/childpages {"columns":3,"imageSize":"medium","imageCrop":false,"align":"wide"} /-->', 'page' );
	made.push( id );
	wp( 'eval', `\\PhotoPress\\modules\\gallery\\GalleryUpgrade::upgrade( ${ id } );` );
	expect( savedContent( id ) ).toContain( '"photopressSource":"children"' );

	await openEditor( page, { id } );
	expect( await invalidBlocks( page ) ).toEqual( [] );

	const attributes = await page.evaluate( () => window.wp.data.select( 'core/block-editor' ).getBlocks()[ 0 ].attributes );
	expect( attributes ).toMatchObject( { photopressSource: 'children', columns: 3, sizeSlug: 'medium', imageCrop: false, align: 'wide' } );
	await expect( canvas( page ).getByRole( 'document', { name: 'Block: Child Pages Gallery' } ) ).toHaveCount( 1 );
} );
