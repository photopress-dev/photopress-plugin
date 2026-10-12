/**
 * The Pages & Search settings tab: attachment pages and image taxonomy
 * pages, both on until turned off. Off, an image's page goes to its file,
 * and a keyword has no archive page while its terms are still shown,
 * unlinked. The settings are put back afterwards.
 */
const { test, expect } = require( './test' );
const { wp, lastLine } = require( './wp' );

const KEY = 'photopress_core_search';
const URL = '/wp-admin/admin.php?page=photopress-core-base#photopress_core_search';
let saved;

const settings = () => JSON.parse( lastLine( wp( 'eval', `echo wp_json_encode( get_option( '${ KEY }', null ) );` ) ) );

test.beforeAll( () => {
	saved = settings();
} );

test.afterAll( () => {
	if ( null === saved ) {
		wp( 'option', 'delete', KEY );
	} else {
		wp( 'option', 'update', KEY, JSON.stringify( saved ), '--format=json' );
	}
} );

const featureSwitch = ( page, title ) => page.locator( '.photopress-feature' )
	.filter( { has: page.getByRole( 'heading', { name: title, exact: true } ) } )
	.getByRole( 'checkbox', { name: title, exact: true } );

/** An image's page, its file, and the archive of a keyword it has. */
const urlsOf = ( id ) => JSON.parse( lastLine( wp( 'eval', `
	$terms = get_the_terms( ${ id }, 'photos_keywords' );
	echo wp_json_encode( [
		'page'    => wp_make_link_relative( get_permalink( ${ id } ) ),
		'link'    => get_attachment_link( ${ id } ),
		'file'    => wp_get_attachment_url( ${ id } ),
		'keyword' => $terms ? $terms[0]->name : '',
		'archive' => $terms ? wp_make_link_relative( get_term_link( $terms[0] ) ) : '',
	] );
` ) ) );

test( 'both switches are on by default; attachment pages off send an image\'s page and links to its file', async ( { page, made } ) => {
	wp( 'option', 'delete', KEY );
	const id = made.images[ 0 ];
	const on = urlsOf( id );
	expect( on.link ).not.toBe( on.file );
	expect( lastLine( wp( 'eval', `echo get_option( 'wp_attachment_pages_enabled' );` ) ) ).toBe( '1' );

	await page.goto( URL );
	await expect( page.getByRole( 'tab', { name: 'Pages & Search', exact: true } ) ).toHaveAttribute( 'aria-selected', 'true', { timeout: 30000 } );
	await expect( featureSwitch( page, 'Attachment pages' ) ).toBeChecked();
	await expect( featureSwitch( page, 'Image taxonomy pages' ) ).toBeChecked();

	if ( process.env.PP_E2E_SHOTS ) {
		await page.locator( '.photopress-options-container .settings' ).screenshot( { path: `${ process.env.PP_E2E_SHOTS }/search-settings.png` } );
	}

	await featureSwitch( page, 'Attachment pages' ).click();
	await expect.poll( () => settings()?.attachment_pages, { timeout: 15000 } ).toBe( false );
	expect( settings().taxonomy_pages ).not.toBe( false );

	// WordPress's own setting follows; links go to the file.
	expect( lastLine( wp( 'eval', `echo get_option( 'wp_attachment_pages_enabled' );` ) ) ).toBe( '0' );
	const off = urlsOf( id );
	expect( off.link ).toBe( off.file );
	await page.goto( on.page );
	expect( page.url() ).toBe( on.file );

	await page.goto( URL );
	await featureSwitch( page, 'Attachment pages' ).click();
	await expect.poll( () => settings()?.attachment_pages, { timeout: 15000 } ).toBe( true );
	const back = await page.goto( on.page );
	expect( back.status() ).toBe( 200 );
	expect( page.url() ).not.toBe( on.file );
} );

test( 'image taxonomy pages off: no keyword archive, terms shown unlinked, and the Metadata tab shows none', async ( { page, made } ) => {
	wp( 'option', 'delete', KEY );
	const { page: imagePage, keyword, archive } = urlsOf( made.images[ 0 ] );
	test.skip( ! archive, 'The test image has no keyword.' );
	expect( ( await page.goto( archive ) ).status() ).toBe( 200 );

	await page.goto( URL );
	await featureSwitch( page, 'Image taxonomy pages' ).click();
	await expect.poll( () => settings()?.taxonomy_pages, { timeout: 15000 } ).toBe( false );

	expect( ( await page.goto( archive ) ).status() ).toBe( 404 );

	await page.goto( imagePage );
	const keywords = page.locator( '.wp-block-photopress-image-taxonomies .container' ).filter( { hasText: 'Keywords:' } ).first();
	await expect( keywords ).toContainText( keyword );
	await expect( keywords.locator( 'a' ) ).toHaveCount( 0 );

	// The Metadata tab, opened from the same settings page: no archive pages.
	await page.goto( URL );
	await page.getByRole( 'tab', { name: 'Metadata', exact: true } ).click();
	const standard = page.locator( '.photopress-taxonomies__standard' );
	await expect( standard.locator( 'tr[data-kind="keywords"]' ) ).toBeVisible( { timeout: 30000 } );
	await expect( standard.locator( 'tr[data-kind="keywords"] code' ) ).toHaveCount( 0 );

	await page.getByRole( 'tab', { name: 'Pages & Search', exact: true } ).click();
	await featureSwitch( page, 'Image taxonomy pages' ).click();
	await expect.poll( () => settings()?.taxonomy_pages, { timeout: 15000 } ).toBe( true );
	expect( ( await page.goto( archive ) ).status() ).toBe( 200 );
} );
