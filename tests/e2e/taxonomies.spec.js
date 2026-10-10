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
	await page.getByRole( 'button', { name: 'Add a hierarchical keyword taxonomy' } ).click();
	// A page of its own, at the top of the window.
	await expect( page ).toHaveURL( /#photopress_core_metadata\/taxonomy\/parent\/new$/ );
	await expect( page.locator( '.photopress-taxonomies__intro' ) ).toHaveCount( 0 );

	await page.getByLabel( 'Parent keyword', { exact: true } ).fill( 'Clients › Acme' );
	await page.getByLabel( 'Also written as (optional)' ).fill( 'acme' );
	await page.getByLabel( 'Plural name' ).fill( 'Acme jobs' );
	await page.getByLabel( 'Singular name' ).fill( 'Acme job' );
	await expect( page.locator( '.photopress-taxonomies__preview' ) ).toContainText( 'Clients|Acme|Group|Example' );
	await expect( page.locator( '.photopress-taxonomies__preview' ) ).toContainText( '/acme-job/group/example' );

	if ( process.env.PP_E2E_SHOTS ) {
		await page.locator( '.photopress-taxonomies__editor' ).screenshot( { path: `${ process.env.PP_E2E_SHOTS }/add-parent.png` } );
	}

	await expect( page.getByRole( 'heading', { name: 'Add Hierarchical Keyword Taxonomy' } ) ).toBeVisible();
	await page.locator( '.photopress-taxonomies__editor' ).getByRole( 'button', { name: 'Add', exact: true } ).click();

	// Back on the tab, at its top, where it says so.
	await expect( page ).toHaveURL( /#photopress_core_metadata$/ );
	await expect( page.locator( '.photopress-flash' ) ).toContainText( 'Acme jobs added.' );
	await expect( page.locator( '.photopress-flash' ) ).toBeInViewport();

	const row = page.locator( '.photopress-taxonomies__parents tr[data-taxonomy="pp_acme_job"]' );
	await expect( row ).toContainText( 'Clients › Acme' );
	await expect.poll( () => taxonomies().find( ( t ) => 'pp_acme_job' === t.id ) ).toMatchObject( {
		pluralLabel: 'Acme jobs',
		singularLabel: 'Acme job',
		tag: 'dc:subject',
		parseTagValue: true,
		names: [ 'Clients|Acme', 'acme' ],
	} );

	await row.getByRole( 'button', { name: 'Edit' } ).click();
	await expect( page ).toHaveURL( /taxonomy\/parent\/pp_acme_job$/ );
	await page.getByLabel( 'Plural name' ).fill( 'Acme projects' );
	await page.locator( '.photopress-taxonomies__editor' ).getByRole( 'button', { name: 'Save', exact: true } ).click();
	await expect.poll( () => taxonomies().find( ( t ) => 'pp_acme_job' === t.id ).pluralLabel ).toBe( 'Acme projects' );
	await expect( page.locator( '.photopress-flash' ) ).toContainText( 'Acme projects saved.' );

	// The browser's Back returns to the screen it left.
	await page.goBack();
	await expect( page ).toHaveURL( /taxonomy\/parent\/pp_acme_job$/ );
	await expect( page.getByLabel( 'Plural name' ) ).toHaveValue( 'Acme projects' );
	await page.locator( '.photopress-taxonomies__editor' ).getByRole( 'button', { name: 'Cancel' } ).click();

	page.once( 'dialog', ( dialog ) => dialog.accept() );
	await page.locator( '.photopress-taxonomies__parents tr[data-taxonomy="pp_acme_job"]' ).getByRole( 'button', { name: 'Remove' } ).click();
	await expect.poll( () => taxonomies().some( ( t ) => 'pp_acme_job' === t.id ) ).toBe( false );
} );

test( 'a change that moves photos offers to reprocess just those, emptying nothing unless asked', async ( { page } ) => {
	// A People parent keyword written people: or person:, as on the test site.
	const list = taxonomies().filter( ( t ) => ! t.parseTagValue );
	list.push( { id: 'pp_e2e_person', pluralLabel: 'E2E people', singularLabel: 'E2E person', tag: 'dc:subject', parseTagValue: true, names: [ 'person' ] } );
	wp( 'option', 'patch', 'update', KEY, 'custom_taxonomies', JSON.stringify( list ), '--format=json' );

	await page.goto( URL );
	await page.locator( '.photopress-taxonomies__parents tr[data-taxonomy="pp_e2e_person"]' ).getByRole( 'button', { name: 'Edit' } ).click();

	const editor = page.locator( '.photopress-taxonomies__editor' );
	await expect( editor.getByRole( 'button', { name: /^Save and reprocess/ } ) ).toHaveCount( 0, { timeout: 10000 } );

	await page.getByLabel( 'Parent keyword', { exact: true } ).fill( 'nobody' );
	await expect( editor.getByRole( 'button', { name: /^Save and reprocess \d+ affected images?$/ } ) ).toBeVisible( { timeout: 10000 } );
	await editor.getByRole( 'button', { name: 'Advanced options' } ).click();
	await expect( editor.getByRole( 'checkbox', { name: 'Empty the Keywords and E2E people terms of images whose files have no keywords' } ) ).not.toBeChecked();

	if ( process.env.PP_E2E_SHOTS ) {
		await editor.locator( '.photopress-savebar' ).screenshot( { path: `${ process.env.PP_E2E_SHOTS }/save-and-reprocess.png` } );
	}

	await editor.getByRole( 'button', { name: 'Cancel' } ).click();

	await page.getByRole( 'button', { name: 'Add a custom metadata taxonomy' } ).click();
	await expect( editor.getByRole( 'button', { name: 'Save and reprocess all images' } ) ).toBeVisible();
	await editor.getByRole( 'button', { name: 'Advanced options' } ).click();
	await expect( editor.getByRole( 'checkbox', { name: /^Empty the .+ terms of images whose files have no .+ field$/ } ) ).not.toBeChecked();
	await editor.getByRole( 'button', { name: 'Cancel' } ).click();
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
	await page.getByRole( 'button', { name: 'Add a custom metadata taxonomy' } ).click();

	// Another taxonomy's archive URL is refused.
	await page.getByLabel( 'Singular name' ).fill( 'Camera' );
	await page.locator( '.photopress-taxonomies__editor' ).getByRole( 'button', { name: 'Save', exact: true } ).click();
	await expect( page.locator( '.photopress-taxonomies__editor .components-notice' ) ).toContainText( 'Cameras already uses /camera/' );

	await page.getByLabel( 'Metadata field' ).selectOption( 'Iptc4xmpExt:Event' );
	await page.getByLabel( 'Plural name' ).fill( 'Events' );
	await page.getByLabel( 'Singular name' ).fill( 'Event' );
	await page.locator( '.photopress-taxonomies__editor' ).getByRole( 'button', { name: 'Save', exact: true } ).click();

	await expect.poll( () => taxonomies().find( ( t ) => 'Iptc4xmpExt:Event' === t.tag ) ).toMatchObject( { id: 'pp_event', parseTagValue: false, singularLabel: 'Event' } );

	page.once( 'dialog', ( dialog ) => dialog.accept() );
	await page.locator( '.photopress-taxonomies__custom tr[data-taxonomy="pp_event"]' ).getByRole( 'button', { name: 'Delete' } ).click();
	await expect.poll( () => taxonomies().some( ( t ) => 'Iptc4xmpExt:Event' === t.tag ) ).toBe( false );
} );
