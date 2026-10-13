/**
 * PhotoPress's own blocks in the block editor: Image Metadata, Gallery
 * Slideshow, Search Summary and Search Posts; their settings saved. And
 * content saved by older versions opening without "This block contains
 * unexpected or invalid content" (tests/fixtures/legacy): galleries as sites
 * have them, legacy PhotoPress Galleries and galleries in PhotoPress
 * layouts, with placeholder addresses, alt text and captions. Runs only
 * with PP_E2E_EDITOR=1 (editor.js).
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { test, expect } = require( './test' );
const { editorOnly, openEditor, canvas, insertBlock, imageBlocks, selectBlock, openPanel, attributesOf, save, savedContent, deletePosts, makePost, invalidBlocks, blocks } = require( './editor' );

test.describe.configure( { mode: 'serial' } );
test.beforeEach( editorOnly );

const made = [];
test.afterAll( () => deletePosts( made ) );

test( 'Image Metadata: rows chosen and ordered, links and labels off; saved', async ( { page } ) => {
	await openEditor( page );
	const id = await insertBlock( page, 'photopress/image-taxonomies' );
	const sidebar = await selectBlock( page, id );

	await openPanel( sidebar, 'Rows' );
	await sidebar.getByLabel( 'Camera model', { exact: true } ).check();
	await sidebar.getByLabel( 'Aperture', { exact: true } ).check();
	// Aperture first.
	await sidebar.getByRole( 'button', { name: 'Move up' } ).last().click();

	await openPanel( sidebar, 'Display' );
	await sidebar.getByLabel( 'Link terms', { exact: true } ).uncheck();
	await sidebar.getByLabel( 'Show labels', { exact: true } ).uncheck();

	expect( await attributesOf( page, id ) ).toMatchObject( { taxonomies: [ 'exif:aperture', 'exif:camera' ], linkTerms: false, showLabels: false } );

	const post = await save( page );
	made.push( post.id );
	expect( savedContent( post.id ) ).toContain( '<!-- wp:photopress/image-taxonomies {"taxonomies":["exif:aperture","exif:camera"],"linkTerms":false,"showLabels":false} /-->' );
} );

test( 'Gallery Slideshow: the page’s gallery chosen, given an anchor; saved', async ( { page, made: fixtures } ) => {
	await openEditor( page );
	const gallery = await insertBlock( page, 'core/gallery', { photopressLayout: 'rows', linkTo: 'none' }, imageBlocks( fixtures.images.slice( 0, 3 ) ) );
	const id = await insertBlock( page, 'photopress/gallery-slideshow' );
	const sidebar = await selectBlock( page, id );

	await openPanel( sidebar, 'Source' );
	await sidebar.getByLabel( 'Gallery', { exact: true } ).selectOption( { label: 'Gallery 1: 3 images' } );
	expect( ( await attributesOf( page, id ) ).galleryAnchor ).toBe( 'gallery' );
	expect( ( await attributesOf( page, gallery ) ).anchor ).toBe( 'gallery' );
	await expect( canvas( page ).locator( `[data-block="${ id }"]` ) ).toContainText( '1 of 3' );

	await openPanel( sidebar, 'Slideshow' );
	await sidebar.getByLabel( 'Transition', { exact: true } ).selectOption( 'fade' );

	const post = await save( page );
	made.push( post.id );
	const content = savedContent( post.id );
	expect( content ).toContain( '"galleryAnchor":"gallery"' );
	expect( content ).toContain( '"effect":"fade"' );
	expect( content ).toMatch( /<figure [^>]*id="gallery"/ );
} );

test( 'Search Summary and Search Posts: their settings saved', async ( { page } ) => {
	await openEditor( page );
	const summary = await insertBlock( page, 'photopress/search-summary' );
	let sidebar = await selectBlock( page, summary );
	await openPanel( sidebar, 'Settings' );
	await sidebar.getByLabel( 'Show number of images', { exact: true } ).uncheck();
	await sidebar.getByLabel( 'Label', { exact: true } ).fill( 'Found for:' );
	await expect( canvas( page ).locator( '.photopress-search-summary__count' ) ).toHaveCount( 0 );

	const posts = await insertBlock( page, 'photopress/search-posts' );
	sidebar = await selectBlock( page, posts );
	await openPanel( sidebar, 'Settings' );
	await sidebar.getByRole( 'spinbutton', { name: 'Number shown' } ).fill( '3' );
	await sidebar.getByLabel( 'Show heading', { exact: true } ).uncheck();
	await expect( canvas( page ).locator( `[data-block="${ posts }"]` ) ).toContainText( 'the first 3 posts and pages found' );

	const post = await save( page );
	made.push( post.id );
	const content = savedContent( post.id );
	expect( content ).toContain( '<!-- wp:photopress/search-summary {"label":"Found for:","showCount":false} /-->' );
	expect( content ).toContain( '<!-- wp:photopress/search-posts {"showHeading":false,"count":3} /-->' );
} );

const LEGACY = path.join( __dirname, '..', 'fixtures', 'legacy' );

for ( const file of fs.readdirSync( LEGACY ).filter( ( f ) => f.endsWith( '.html' ) ) ) {
	test( `content from an older version opens without errors: ${ file }`, async ( { page } ) => {
		const id = makePost( fs.readFileSync( path.join( LEGACY, file ), 'utf8' ) );
		made.push( id );
		await openEditor( page, { id } );

		const list = await blocks( page );
		expect( list.length ).toBeGreaterThan( 0 );
		expect( await invalidBlocks( page ) ).toEqual( [] );
		await expect( canvas( page ).getByText( 'This block contains unexpected or invalid content.' ) ).toHaveCount( 0 );
	} );
}

test( 'the legacy PhotoPress Gallery turns into a gallery in its layout, its images kept', async ( { page } ) => {
	const id = makePost( fs.readFileSync( path.join( LEGACY, 'photopress-gallery-masonry.html' ), 'utf8' ) );
	made.push( id );
	await openEditor( page, { id } );

	const result = await page.evaluate( () => {
		const { select, dispatch } = window.wp.data;
		const old = select( 'core/block-editor' ).getBlocks().find( ( b ) => 'photopress/gallery' === b.name );
		const count = old.attributes.images.length;
		const [ gallery ] = window.wp.blocks.switchToBlockType( old, 'core/gallery' );
		dispatch( 'core/block-editor' ).replaceBlocks( old.clientId, gallery );
		return { count, layout: gallery.attributes.photopressLayout, images: gallery.innerBlocks.length };
	} );
	expect( result.layout ).toBe( 'masonry' );
	expect( result.images ).toBe( result.count );
	expect( await invalidBlocks( page ) ).toEqual( [] );
} );

test( 'the editor warns about nothing of PhotoPress’s: no block on an old block API, no styles put into its canvas the wrong way', async ( { page } ) => {
	const warnings = [];
	page.on( 'console', ( message ) => {
		if ( [ 'warning', 'error' ].includes( message.type() ) && /photopress/i.test( message.text() ) ) {
			warnings.push( message.text().slice( 0, 300 ) );
		}
	} );

	// With the legacy block in it, which loads its code.
	const id = makePost( fs.readFileSync( path.join( LEGACY, 'photopress-gallery-columns.html' ), 'utf8' ) );
	made.push( id );
	await openEditor( page, { id } );
	await expect( canvas( page ).locator( '.wp-block-photopress-gallery' ) ).toBeVisible();

	expect( warnings ).toEqual( [] );
} );
