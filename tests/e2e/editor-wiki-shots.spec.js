/**
 * Cutouts of the block editor's PhotoPress controls for the wiki. Runs only
 * in CI (PP_E2E_EDITOR=1); saved to .results/wiki.
 */
const path = require( 'path' );
const fs = require( 'fs' );
const { test, expect } = require( './test' );
const { wp, lastLine } = require( './wp' );
const { editorOnly, openEditor, canvas, insertBlock, imageBlocks, selectBlock, openPanel, deletePosts, makePost } = require( './editor' );

test.describe.configure( { mode: 'serial' } );
test.beforeEach( editorOnly );
test.use( { viewport: { width: 1400, height: 1000 }, deviceScaleFactor: 2 } );

const OUT = path.join( __dirname, '.results', 'wiki' );
fs.mkdirSync( OUT, { recursive: true } );
const shot = ( name ) => ( { path: path.join( OUT, `${ name }.png` ) } );
const made = [];
test.afterAll( () => deletePosts( made ) );

const variation = ( page, name ) => page.evaluate( ( n ) => window.wp.blocks.getBlockVariations( 'core/gallery' ).find( ( v ) => v.name === n ).attributes, name );
const panelOf = ( sidebar, title ) => sidebar.getByRole( 'button', { name: title, exact: true } ).locator( 'xpath=ancestor::*[contains(@class,"components-panel__body")][1]' );

test( 'the inserter offers the PhotoPress galleries', async ( { page } ) => {
	await openEditor( page );
	await page.getByRole( 'button', { name: /block inserter/i } ).first().click();
	await page.getByRole( 'searchbox' ).first().fill( 'gallery' );
	await expect( page.getByRole( 'option', { name: 'Masonry Gallery', exact: true } ) ).toBeVisible();
	await page.waitForTimeout( 800 );
	await page.locator( '.block-editor-inserter__panel-content, .block-editor-inserter__main-area' ).first().screenshot( shot( 'editor-gallery-inserter-variations' ) );
} );

test( 'a gallery in a PhotoPress layout: its PhotoPress panel', async ( { page, made: fixtures } ) => {
	await openEditor( page );
	const id = await insertBlock( page, 'core/gallery', { photopressLayout: 'masonry', photopressSlideshow: true, linkTo: 'none' }, imageBlocks( fixtures.images.slice( 0, 5 ) ) );
	const sidebar = await selectBlock( page, id );
	await openPanel( sidebar, 'PhotoPress' );
	await page.waitForTimeout( 800 );
	await panelOf( sidebar, 'PhotoPress' ).screenshot( shot( 'editor-gallery-photopress-panel' ) );
	await canvas( page ).locator( `[data-block="${ id }"]` ).screenshot( shot( 'editor-gallery-canvas-masonry' ) );
} );

test( 'the Gallery Slideshow block: its settings', async ( { page, made: fixtures } ) => {
	await openEditor( page );
	await insertBlock( page, 'core/gallery', { photopressLayout: 'rows', linkTo: 'none', anchor: 'gallery' }, imageBlocks( fixtures.images.slice( 0, 4 ) ) );
	const id = await insertBlock( page, 'photopress/gallery-slideshow', { galleryAnchor: 'gallery', showThumbnails: true, thumbnailHeight: 80 } );
	const sidebar = await selectBlock( page, id );
	for ( const title of [ 'Source', 'Slideshow' ] ) {
		await openPanel( sidebar, title );
	}
	await page.waitForTimeout( 800 );
	await panelOf( sidebar, 'Source' ).screenshot( shot( 'editor-gallery-slideshow-source' ) );
	await panelOf( sidebar, 'Slideshow' ).screenshot( shot( 'editor-gallery-slideshow-settings' ) );
	await canvas( page ).locator( `[data-block="${ id }"]` ).screenshot( shot( 'editor-gallery-slideshow-canvas' ) );
} );

test( 'a Taxonomy Gallery: its Images panel and its summary', async ( { page, made: fixtures } ) => {
	const keyword = JSON.parse( lastLine( wp( 'eval', `$t = wp_get_object_terms( ${ fixtures.images[ 0 ] }, 'photos_keywords' )[0]; echo wp_json_encode( [ 'id' => $t->term_id ] );` ) ) );
	await openEditor( page, { type: 'page' } );
	const id = await insertBlock( page, 'core/gallery', { ...( await variation( page, 'photopress-taxonomy-gallery' ) ), photopressTaxonomy: 'photos_keywords', photopressTerm: keyword.id, photopressCount: 6, photopressShowHeading: true, photopressHeading: 'Fixture images' } );
	const sidebar = await selectBlock( page, id );
	await openPanel( sidebar, 'Images' );
	await page.waitForTimeout( 1200 );
	await panelOf( sidebar, 'Images' ).screenshot( shot( 'editor-taxonomy-gallery-panel' ) );
	await canvas( page ).locator( `[data-block="${ id }"]` ).screenshot( shot( 'editor-taxonomy-gallery-canvas' ) );
} );

test( 'a Child Pages Gallery: its Pages panel', async ( { page, made: fixtures } ) => {
	const pages = JSON.parse( lastLine( wp( 'eval', `
		$parent = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Places' ] );
		$a = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_parent' => $parent, 'post_title' => 'Ojai' ] );
		set_post_thumbnail( $a, ${ fixtures.images[ 0 ] } );
		$b = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_parent' => $parent, 'post_title' => 'Big Sur' ] );
		set_post_thumbnail( $b, ${ fixtures.images[ 1 ] } );
		echo wp_json_encode( compact( 'parent', 'a', 'b' ) );
	` ) ) );
	made.push( pages.a, pages.b, pages.parent );
	await openEditor( page, { id: pages.parent } );
	const id = await insertBlock( page, 'core/gallery', { ...( await variation( page, 'photopress-child-pages-gallery' ) ), photopressLayout: 'rows' } );
	const sidebar = await selectBlock( page, id );
	await openPanel( sidebar, 'Pages' );
	await page.waitForTimeout( 1200 );
	await panelOf( sidebar, 'Pages' ).screenshot( shot( 'editor-child-pages-gallery-panel' ) );
	await canvas( page ).locator( `[data-block="${ id }"]` ).screenshot( shot( 'editor-child-pages-gallery-canvas' ) );
} );

test( 'the Image Metadata block: its Rows and Display panels', async ( { page } ) => {
	await openEditor( page );
	const id = await insertBlock( page, 'photopress/image-taxonomies', { taxonomies: [ 'photos_keywords', 'photos_camera', 'exif:aperture' ] } );
	const sidebar = await selectBlock( page, id );
	for ( const title of [ 'Rows', 'Display' ] ) {
		await openPanel( sidebar, title );
	}
	await page.waitForTimeout( 800 );
	await panelOf( sidebar, 'Rows' ).screenshot( shot( 'editor-image-metadata-panel' ) );
	await panelOf( sidebar, 'Display' ).screenshot( shot( 'editor-image-metadata-display' ) );
} );

test( 'the search blocks: their settings', async ( { page } ) => {
	await openEditor( page );
	const summary = await insertBlock( page, 'photopress/search-summary' );
	let sidebar = await selectBlock( page, summary );
	await openPanel( sidebar, 'Settings' );
	await page.waitForTimeout( 600 );
	await panelOf( sidebar, 'Settings' ).screenshot( shot( 'editor-search-summary-panel' ) );
	const posts = await insertBlock( page, 'photopress/search-posts' );
	sidebar = await selectBlock( page, posts );
	await openPanel( sidebar, 'Settings' );
	await page.waitForTimeout( 600 );
	await panelOf( sidebar, 'Settings' ).screenshot( shot( 'editor-search-blocks-panels' ) );
} );

test( 'the Site Editor lists the image templates', async ( { page } ) => {
	await page.goto( '/wp-admin/site-editor.php?postType=wp_template' );
	await expect( page.getByText( 'Image Archives', { exact: true } ).first() ).toBeVisible( { timeout: 60000 } );
	await page.waitForTimeout( 1500 );
	await page.screenshot( shot( 'site-editor-templates' ) );
} );
