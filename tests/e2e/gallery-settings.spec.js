/**
 * The Gallery settings tab: a switch for each PhotoPress layout and for
 * taking deleted images out of galleries, all on until turned off. The
 * editor is offered only the layouts turned on. The settings are put back
 * afterwards.
 */
const { test, expect } = require( './test' );
const { wp, lastLine } = require( './wp' );

const KEY = 'photopress_core_gallery';
const URL = '/wp-admin/admin.php?page=photopress-core-base#photopress_core_gallery';
let saved;

const settings = () => JSON.parse( lastLine( wp( 'eval', `echo wp_json_encode( get_option( '${ KEY }', null ) );` ) ) );

/** The layouts the editor is given, as enqueue_block_editor_assets adds them. */
const editorLayouts = () => {
	const php = `do_action( 'enqueue_block_editor_assets' ); $before = (array) wp_scripts()->get_data( 'photopress-editor', 'before' ); foreach ( $before as $code ) { if ( preg_match( '/photopressGalleryLayouts = (\\[.*?\\]);/', (string) $code, $m ) ) { echo $m[1]; } }`;
	return JSON.parse( lastLine( wp( 'eval', php ) ) );
};

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

const card = ( page, title ) => page.locator( '.photopress-feature' ).filter( { has: page.getByRole( 'heading', { name: title, exact: true } ) } );
const featureSwitch = ( page, title ) => card( page, title ).getByRole( 'checkbox', { name: title, exact: true } );

test( 'every switch is on by default, and a layout turned off is not offered in the editor', async ( { page } ) => {
	wp( 'option', 'delete', KEY );
	await page.goto( URL );

	await expect( page.getByRole( 'tab', { name: 'Gallery', exact: true } ) ).toHaveAttribute( 'aria-selected', 'true', { timeout: 30000 } );
	for ( const title of [ 'Masonry layout', 'Rows layout', 'Mosaic layout', 'Remove deleted images' ] ) {
		await expect( featureSwitch( page, title ) ).toBeChecked();
	}
	expect( editorLayouts() ).toEqual( [ 'masonry', 'rows', 'mosaic' ] );

	await featureSwitch( page, 'Rows layout' ).click();
	await expect.poll( () => settings()?.layout_rows, { timeout: 15000 } ).toBe( false );
	expect( settings() ).toMatchObject( { layout_masonry: true, layout_mosaic: true, remove_deleted_images: true } );
	expect( editorLayouts() ).toEqual( [ 'masonry', 'mosaic' ] );

	await page.reload();
	await expect( featureSwitch( page, 'Rows layout' ) ).not.toBeChecked( { timeout: 30000 } );
	await expect( featureSwitch( page, 'Masonry layout' ) ).toBeChecked();

	if ( process.env.PP_E2E_SHOTS ) {
		await page.locator( '.photopress-options-container .settings' ).screenshot( { path: `${ process.env.PP_E2E_SHOTS }/gallery-settings.png` } );
	}

	await featureSwitch( page, 'Remove deleted images' ).click();
	await expect.poll( () => settings()?.remove_deleted_images, { timeout: 15000 } ).toBe( false );
} );
