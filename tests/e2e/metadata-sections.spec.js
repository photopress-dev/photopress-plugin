/**
 * The Alt Text, Description and Licensing sections of the Metadata settings
 * tab: each switch at the top shows the section's settings and its
 * reprocess job only when on. And the license JSON-LD on an image's page,
 * printed by the plugin whatever the theme, only with licensing on. The
 * settings are put back afterwards, and the image made here is deleted.
 */
const path = require( 'path' );
const { test, expect } = require( './test' );
const { wp, lastLine } = require( './wp' );

const KEY = 'photopress_core_metadata';
const URL = '/wp-admin/admin.php?page=photopress-core-base#photopress_core_metadata';
let saved;

const settings = () => JSON.parse( lastLine( wp( 'option', 'get', KEY, '--format=json' ) ) );
const patch = ( values ) => wp( 'option', 'update', KEY, JSON.stringify( { ...settings(), ...values } ), '--format=json' );

test.beforeAll( () => {
	saved = lastLine( wp( 'option', 'get', KEY, '--format=json' ) );
} );

test.afterAll( () => {
	wp( 'option', 'update', KEY, saved, '--format=json' );
} );

/** A feature's card, by its heading, and its switch. */
const card = ( page, title ) => page.locator( '.photopress-feature' ).filter( { has: page.getByRole( 'heading', { name: title, exact: true } ) } );
const featureSwitch = ( panel, title ) => panel.getByRole( 'checkbox', { name: title, exact: true } );

const sections = [
	{ title: 'Alt Text', key: 'alt_text_enable', field: '#alt_text_template', job: 'metadata.alt_text' },
	{ title: 'Description', key: 'description_enable', field: '#description_template', job: 'metadata.description' },
];

for ( const { title, key, field, job } of sections ) {
	test( `${ title }: the switch shows the settings and the reprocess job`, async ( { page } ) => {
		patch( { [ key ]: false } );
		await page.goto( URL );

		const panel = card( page, title );
		const sw = featureSwitch( panel, title );
		await expect( sw ).not.toBeChecked( { timeout: 30000 } );
		await expect( panel.locator( field ) ).toHaveCount( 0 );
		await expect( panel.locator( `.photopress-job[data-job-type="${ job }"]` ) ).toHaveCount( 0 );

		await sw.click();
		await expect( panel.locator( field ) ).toBeVisible();
		await expect( panel.getByRole( 'button', { name: 'Save and reprocess all images' } ) ).toBeVisible();
		await expect( panel.getByRole( 'checkbox', { name: /^Empty the .+ of images whose files have no metadata for the template$/ } ) ).not.toBeChecked();
		await expect.poll( () => settings()[ key ], { timeout: 15000 } ).toBe( true );

		if ( process.env.PP_E2E_SHOTS ) {
			await panel.screenshot( { path: `${ process.env.PP_E2E_SHOTS }/${ key }.png` } );
		}
	} );
}

test( 'Licensing: the switch shows the settings, all three are required, and then the reprocess job', async ( { page } ) => {
	patch( { embed_licensor_enable: false, licensor_name: '', licensor_url: '', web_statement_of_rights: '' } );
	await page.goto( URL );

	const panel = card( page, 'Licensing' );
	const sw = featureSwitch( panel, 'Licensing' );
	await expect( sw ).not.toBeChecked( { timeout: 30000 } );
	await expect( panel.locator( '#licensor_name' ) ).toHaveCount( 0 );

	// Typed into at once: the switch saves only itself, and what was typed stays.
	await sw.click();
	await panel.locator( '#licensor_name' ).fill( 'Test Licensor' );
	await expect( panel.locator( '.photopress-licensing-incomplete' ) ).toBeVisible();
	await expect.poll( () => settings().embed_licensor_enable ).toBe( true );
	await expect( panel.locator( '#licensor_name' ) ).toHaveValue( 'Test Licensor' );
	expect( settings().licensor_name, 'the switch saves only itself' ).toBe( '' );

	await panel.locator( '#licensor_url' ).fill( 'https://licensor.example/buy' );
	await panel.getByRole( 'button', { name: 'Save', exact: true } ).click();
	await expect( panel.locator( '.components-notice.is-error' ) ).toContainText( 'Fill in: Web Statement of Rights URL.' );
	expect( settings().licensor_name ).toBe( '' );

	await panel.locator( '#web_statement_of_rights' ).fill( 'https://licensor.example/terms' );
	await panel.getByRole( 'button', { name: 'Save', exact: true } ).click();
	await expect.poll( () => settings().web_statement_of_rights ).toBe( 'https://licensor.example/terms' );
	await expect( panel.locator( '.photopress-licensing-incomplete' ) ).toHaveCount( 0 );
	await expect( panel.getByRole( 'button', { name: 'Save and reprocess all images' } ) ).toBeVisible();
	await expect( panel.getByRole( 'checkbox', { name: /^Empty the/ } ) ).toHaveCount( 0 );

	if ( process.env.PP_E2E_SHOTS ) {
		await panel.screenshot( { path: `${ process.env.PP_E2E_SHOTS }/licensing.png` } );
	}
} );

test( 'an image page gets the license JSON-LD from the plugin, only with licensing on', async ( { page } ) => {
	const file = path.join( __dirname, '..', 'fixtures', 'images', '03-square.jpg' );
	const id = Number( lastLine( wp( 'media', 'import', file, '--porcelain' ) ) );
	const link = lastLine( wp( 'eval', `echo get_attachment_link( ${ id } );` ) );

	try {
		patch( { embed_licensor_enable: true, licensor_name: 'Test Licensor', licensor_url: 'https://licensor.example/buy', web_statement_of_rights: 'https://licensor.example/terms' } );
		await page.goto( link );
		const schema = await page.locator( 'script[type="application/ld+json"]' ).allTextContents();
		const license = schema.map( ( s ) => JSON.parse( s ) ).flat().find( ( s ) => s && s.acquireLicensePage );
		expect( license ).toMatchObject( { '@type': 'ImageObject', acquireLicensePage: 'https://licensor.example/buy', license: 'https://licensor.example/terms' } );

		patch( { embed_licensor_enable: false } );
		await page.goto( link );
		await expect( page.locator( 'script[type="application/ld+json"]', { hasText: 'acquireLicensePage' } ) ).toHaveCount( 0 );
	} finally {
		wp( 'post', 'delete', String( id ), '--force' );
	}
} );

test( 'Slideshow: each switch hides what it governs', async ( { page } ) => {
	const SLIDESHOW = 'photopress_core_slideshow';
	const before = lastLine( wp( 'option', 'get', SLIDESHOW, '--format=json' ) );

	try {
		wp( 'option', 'update', SLIDESHOW, JSON.stringify( { ...JSON.parse( before ), enable: true, showThumbnails: false, showCaptions: true, showAttachmentLink: false } ), '--format=json' );
		await page.goto( '/wp-admin/admin.php?page=photopress-core-base#photopress_core_slideshow' );

		const tab = card( page, 'Slideshows' );
		await expect( featureSwitch( tab, 'Slideshows' ) ).toBeChecked( { timeout: 30000 } );
		await expect( tab.getByLabel( 'Thumbnail Height' ) ).toHaveCount( 0 );
		await expect( tab.getByLabel( 'Attachment Link Text' ) ).toHaveCount( 0 );
		await expect( tab.getByLabel( 'Caption Info Box Position' ) ).toBeVisible();

		await tab.getByLabel( 'Link to the image’s page' ).click();
		await expect( tab.getByLabel( 'Attachment Link Text' ) ).toBeVisible();

		await featureSwitch( tab, 'Slideshows' ).click();
		await expect( tab.getByLabel( 'Caption info' ) ).toHaveCount( 0 );
		await expect.poll( () => JSON.parse( lastLine( wp( 'option', 'get', SLIDESHOW, '--format=json' ) ) ).enable ).toBe( false );
	} finally {
		wp( 'option', 'update', SLIDESHOW, before, '--format=json' );
	}
} );

test( 'a feature explains itself behind its info button, and the separators are under Advanced settings', async ( { page } ) => {
	await page.goto( URL );
	const panel = card( page, 'Image Taxonomies' );
	await expect( featureSwitch( panel, 'Image Taxonomies' ) ).toBeChecked( { timeout: 30000 } );

	await expect( panel.locator( '.photopress-feature__info' ) ).toHaveCount( 0 );
	await panel.getByRole( 'button', { name: 'About Image Taxonomies' } ).click();
	await expect( panel.locator( '.photopress-feature__info' ) ).toContainText( 'archive pages' );

	await expect( panel.getByLabel( 'Prefix separators' ) ).toHaveCount( 0 );
	await panel.getByRole( 'button', { name: 'Advanced settings' } ).click();
	await expect( panel.getByLabel( 'Prefix separators' ) ).toBeVisible();

	if ( process.env.PP_E2E_SHOTS ) {
		await page.setViewportSize( { width: 1280, height: 2400 } );
		await page.locator( '.components-tab-panel__tab-content' ).screenshot( { path: `${ process.env.PP_E2E_SHOTS }/metadata-tab.png` } );
	}
} );
