/**
 * The Image Taxonomies section of the Metadata settings tab: Standard
 * Metadata, parent keywords and Custom Metadata, added, edited, turned off
 * and removed on the screen, and saved to the custom_taxonomies setting. The
 * settings are put back afterwards.
 */
const { test, expect } = require( './test' );
const { wp, lastLine } = require( './wp' );

const KEY = 'photopress_core_metadata';
const URL = '/wp-admin/admin.php?page=photopress-core-base#photopress_core_metadata';
let saved;

const taxonomies = () => JSON.parse( lastLine( wp( 'option', 'get', KEY, '--format=json' ) ) ).custom_taxonomies;

test.beforeAll( () => {
	saved = lastLine( wp( 'option', 'get', KEY, '--format=json' ) );
} );

test.afterAll( () => {
	wp( 'option', 'update', KEY, saved, '--format=json' );
} );

test( 'the three kinds are listed, each standard taxonomy with a switch', async ( { page } ) => {
	await page.goto( URL );

	const standard = page.locator( '.photopress-taxonomies__standard' );
	await expect( standard.locator( 'tr[data-kind="camera"]' ) ).toBeVisible( { timeout: 30000 } );
	await expect( standard.locator( 'tbody tr' ) ).toHaveCount( 6 );
	// Each taxonomy's number of terms, from the server.
	await expect( standard.locator( 'tr[data-kind="keywords"] td.num' ) ).toHaveText( /^\d[\d,]*$/ );
	await expect( standard.getByRole( 'checkbox', { name: 'Keywords on' } ) ).toBeVisible();
	await expect( page.getByRole( 'heading', { name: 'Hierarchical Keyword Metadata' } ) ).toBeVisible();
	await expect( page.getByRole( 'heading', { name: 'Custom Metadata' } ) ).toBeVisible();
	await expect( page.locator( '.photopress-job[data-job-type="metadata.reprocess"]' ) ).toBeVisible();

	if ( process.env.PP_E2E_SHOTS ) {
		await page.locator( '.photopress-taxonomies' ).screenshot( { path: `${ process.env.PP_E2E_SHOTS }/taxonomies.png` } );
	}
} );

test( 'a parent keyword is added, edited and removed', async ( { page } ) => {
	await page.goto( URL );
	await page.getByRole( 'button', { name: 'Add parent keyword' } ).first().click();

	await page.getByLabel( 'Parent keyword', { exact: true } ).fill( 'Clients › Acme' );
	await page.getByLabel( 'Also written as (optional)' ).fill( 'acme' );
	await page.getByLabel( 'Plural name' ).fill( 'Acme jobs' );
	await page.getByLabel( 'Singular name' ).fill( 'Acme job' );
	await page.getByLabel( 'Keep the levels as nested terms, each with its own archive page' ).check();
	await expect( page.locator( '.photopress-taxonomies__preview' ) ).toContainText( 'Clients|Acme|Subgroup|Example' );

	if ( process.env.PP_E2E_SHOTS ) {
		await page.locator( '.photopress-taxonomies__editor' ).screenshot( { path: `${ process.env.PP_E2E_SHOTS }/add-parent.png` } );
	}

	await page.locator( '.photopress-taxonomies__editor' ).getByRole( 'button', { name: 'Add parent keyword' } ).click();

	const row = page.locator( '.photopress-taxonomies__parents tr[data-taxonomy="pp_acme_job"]' );
	await expect( row ).toContainText( 'Clients › Acme' );
	await expect.poll( () => taxonomies().find( ( t ) => 'pp_acme_job' === t.id ) ).toMatchObject( {
		pluralLabel: 'Acme jobs',
		singularLabel: 'Acme job',
		tag: 'dc:subject',
		parseTagValue: true,
		names: [ 'Clients|Acme', 'acme' ],
		nested: true,
	} );

	await row.getByRole( 'button', { name: 'Edit' } ).click();
	await page.getByLabel( 'Use the last keyword; the levels between go to Keywords' ).check();
	await page.locator( '.photopress-taxonomies__editor' ).getByRole( 'button', { name: 'Save' } ).click();
	await expect.poll( () => taxonomies().find( ( t ) => 'pp_acme_job' === t.id ).nested ).toBeUndefined();

	page.once( 'dialog', ( dialog ) => dialog.accept() );
	await page.locator( '.photopress-taxonomies__parents tr[data-taxonomy="pp_acme_job"]' ).getByRole( 'button', { name: 'Remove' } ).click();
	await expect.poll( () => taxonomies().some( ( t ) => 'pp_acme_job' === t.id ) ).toBe( false );
} );

test( 'a standard taxonomy is turned off and on, keeping its names', async ( { page } ) => {
	await page.goto( URL );

	const toggle = page.getByRole( 'checkbox', { name: /^Lenses on$/i } );
	await expect( toggle ).toBeChecked( { timeout: 30000 } );

	await toggle.click();
	await expect.poll( () => taxonomies().find( ( t ) => 'aux:Lens' === t.tag ).disabled ).toBe( true );

	await toggle.click();
	await expect.poll( () => taxonomies().find( ( t ) => 'aux:Lens' === t.tag ).disabled ).toBeUndefined();
} );

test( 'custom metadata is added for a field and deleted', async ( { page } ) => {
	await page.goto( URL );
	await page.getByRole( 'button', { name: 'Add custom metadata' } ).click();

	// Another taxonomy's archive URL is refused.
	await page.getByLabel( 'Singular name' ).fill( 'Camera' );
	await page.locator( '.photopress-taxonomies__editor' ).getByRole( 'button', { name: 'Save' } ).click();
	await expect( page.locator( '.photopress-taxonomies__editor .components-notice' ) ).toContainText( 'Cameras already uses /camera/' );

	await page.getByLabel( 'Metadata field' ).selectOption( 'Iptc4xmpExt:Event' );
	await page.getByLabel( 'Plural name' ).fill( 'Events' );
	await page.getByLabel( 'Singular name' ).fill( 'Event' );
	await page.locator( '.photopress-taxonomies__editor' ).getByRole( 'button', { name: 'Save' } ).click();

	await expect.poll( () => taxonomies().find( ( t ) => 'Iptc4xmpExt:Event' === t.tag ) ).toMatchObject( { id: 'pp_event', parseTagValue: false, singularLabel: 'Event' } );

	page.once( 'dialog', ( dialog ) => dialog.accept() );
	await page.locator( '.photopress-taxonomies__custom tr[data-taxonomy="pp_event"]' ).getByRole( 'button', { name: 'Delete' } ).click();
	await expect.poll( () => taxonomies().some( ( t ) => 'Iptc4xmpExt:Event' === t.tag ) ).toBe( false );
} );
