/**
 * The Pages & Search settings tab: attachment pages and image taxonomy
 * pages, both on until turned off. Off, an image's page goes to its file,
 * and a keyword has no archive page while its terms are still shown,
 * unlinked. The two settings are put back afterwards, and the tab's others
 * left alone.
 */
const { test, expect } = require( './test' );
const { wp, lastLine } = require( './wp' );

const KEY = 'photopress_core_search';
const URL = '/wp-admin/admin.php?page=photopress-core-base#photopress_core_search';
let saved;

const settings = () => JSON.parse( lastLine( wp( 'eval', `echo wp_json_encode( get_option( '${ KEY }', null ) );` ) ) );

// The tab's other settings, such as images in search, are left alone.
const KEYS = [ 'attachment_pages', 'taxonomy_pages', 'archives_off', 'author_archives_off', 'date_archives_off', 'format_archives_off' ];
const php = ( value ) => `json_decode( base64_decode( '${ Buffer.from( JSON.stringify( value ) ).toString( 'base64' ) }' ), true )`;

/** Sets these keys, or with null clears them back to their defaults. */
const setKeys = ( values ) => wp( 'eval', `
	$o = get_option( '${ KEY }' );
	$o = is_array( $o ) ? $o : [];
	foreach ( ${ php( values ) } as $k => $v ) { if ( null === $v ) { unset( $o[ $k ] ); } else { $o[ $k ] = $v; } }
	$o ? update_option( '${ KEY }', $o ) : delete_option( '${ KEY }' );
` );

const defaults = () => setKeys( Object.fromEntries( KEYS.map( ( k ) => [ k, null ] ) ) );

test.beforeAll( () => {
	const now = settings() || {};
	saved = Object.fromEntries( KEYS.map( ( k ) => [ k, k in now ? now[ k ] : null ] ) );
} );

test.afterAll( () => {
	setKeys( saved );
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

/**
 * WordPress's own Attachment pages setting as stored, which PhotoPress
 * leaves alone, or null when it was never set.
 */
const wordpressSetting = () => JSON.parse( lastLine( wp( 'eval', `global $wpdb; echo wp_json_encode( $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'wp_attachment_pages_enabled'" ) );` ) ) );
const setWordPressSetting = ( value ) => wp( 'eval', null === value ? `delete_option( 'wp_attachment_pages_enabled' );` : `update_option( 'wp_attachment_pages_enabled', '${ value }' );` );

test( 'until saved, Attachment pages follow WordPress\'s own setting; image taxonomy pages are on; attachment pages off send an image\'s page and links to its file', async ( { page, made } ) => {
	defaults();
	const stored = wordpressSetting();
	const id = made.images[ 0 ];

	try {
		// Installed since WordPress 6.4: off, and links go to the file.
		setWordPressSetting( '0' );
		const unsaved = urlsOf( id );
		expect( unsaved.link ).toBe( unsaved.file );
		await page.goto( URL );
		await expect( page.getByRole( 'tab', { name: 'Pages & Search', exact: true } ) ).toHaveAttribute( 'aria-selected', 'true', { timeout: 30000 } );
		await expect( featureSwitch( page, 'Attachment pages' ) ).not.toBeChecked();
		await expect( featureSwitch( page, 'Image taxonomy pages' ) ).toBeChecked();

		// Older sites: on.
		setWordPressSetting( '1' );
		const on = urlsOf( id );
		expect( on.link ).not.toBe( on.file );
		expect( lastLine( wp( 'eval', `echo get_option( 'wp_attachment_pages_enabled' );` ) ) ).toBe( '1' );
		// The same address with its #tab is only a jump within the page.
		await page.reload();
		await expect( featureSwitch( page, 'Attachment pages' ) ).toBeChecked( { timeout: 30000 } );

		if ( process.env.PP_E2E_SHOTS ) {
			await page.locator( '.photopress-options-container .settings' ).screenshot( { path: `${ process.env.PP_E2E_SHOTS }/search-settings.png` } );
		}

		await attachmentPagesTurnedOffAndOn( page, id, on );
	} finally {
		setWordPressSetting( stored );
	}
} );

/** Saved off, then on again: WordPress's setting follows the switch. */
async function attachmentPagesTurnedOffAndOn( page, id, on ) {
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
}

test( 'image taxonomy pages off: no keyword archive, terms shown unlinked, and the Metadata tab shows none', async ( { page, made } ) => {
	// Image pages, whatever WordPress's own setting is: the terms are on them.
	setKeys( { attachment_pages: true, taxonomy_pages: null } );
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

test( 'the page cache and search index card: Proxy Cache Purge and Relevanssi as the Offload Media tab shows its plugin, with Re-index all images', async ( { page } ) => {
	const status = JSON.parse( lastLine( wp( 'eval', `
		use PhotoPress\\modules\\search\\{ search, PageCache, Relevanssi };
		echo wp_json_encode( [
			'cache'      => \\photopress_util::pluginState( PageCache::purges(), [ 'varnish-http-purge/' ] ),
			'relevanssi' => \\photopress_util::pluginState( Relevanssi::active(), [ 'relevanssi/', 'relevanssi-premium/' ] ),
			'images'     => search::enabled( 'images_in_search', false ),
		] );
	` ) ) );
	const STATES = { active: 'Active', inactive: 'Disabled', missing: 'Not installed' };

	await page.goto( URL );
	const card = page.locator( '.photopress-search-cache' );
	await expect( card.getByRole( 'heading', { name: 'Page cache and search index' } ) ).toBeVisible( { timeout: 30000 } );
	await expect( card.getByRole( 'checkbox' ) ).toHaveCount( 0 );

	const cache = card.locator( '[data-plugin="varnish-http-purge"] .photopress-plugin-state' );
	await expect( cache ).toHaveText( `Proxy Cache Purge: ${ STATES[ status.cache ] }` );
	await expect( cache.locator( `.is-${ status.cache }` ) ).toBeVisible();

	const relevanssi = card.locator( '[data-plugin="relevanssi"]' );
	await expect( relevanssi.locator( '.photopress-plugin-state' ) ).toHaveText( `Relevanssi: ${ STATES[ status.relevanssi ] }` );
	if ( 'active' !== status.relevanssi ) {
		await expect( relevanssi.getByRole( 'button' ) ).toHaveCount( 0 );
		return;
	}

	const reindex = relevanssi.getByRole( 'button', { name: 'Re-index all images' } );
	if ( status.images ) {
		await expect( relevanssi ).toContainText( 'PhotoPress has it index images and their terms as they’re added and changed' );
		await expect( reindex ).toBeEnabled();
	} else {
		await expect( reindex ).toHaveCount( 0 );
		await expect( relevanssi ).toContainText( 'Turn on Images in search results to add images to the index.' );
	}
} );

test( 'the sitemap card: the sitemap in use, with a link to it', async ( { page } ) => {
	const inUse = JSON.parse( lastLine( wp( 'eval', `echo wp_json_encode( \\PhotoPress\\modules\\search\\ImageSitemap::inUse() );` ) ) );
	const TEXT = {
		core: 'WordPress’s sitemap is in use.',
		jetpack: 'Jetpack’s sitemap is in use',
		yoast: 'Yoast SEO’s sitemap is in use',
		other: 'Another plugin has turned off WordPress’s sitemap.',
		none: 'Search engines are discouraged from indexing this site',
	};

	await page.goto( URL );
	const card = page.locator( '.photopress-search-sitemap' );
	await expect( card.getByRole( 'heading', { name: 'Sitemap' } ) ).toBeVisible( { timeout: 30000 } );
	await expect( card.getByRole( 'checkbox' ) ).toHaveCount( 0 );

	const status = card.locator( `[data-sitemap="${ inUse.sitemap }"]` );
	await expect( status ).toContainText( TEXT[ inUse.sitemap ] );
	if ( inUse.url ) {
		await expect( status.getByRole( 'link', { name: 'View the sitemap' } ) ).toHaveAttribute( 'href', inUse.url );
	} else {
		await expect( status.getByRole( 'link' ) ).toHaveCount( 0 );
	}
} );

test( 'unneeded archives: off until turned on, then author archives and the others all off, each with its own switch', async ( { page } ) => {
	defaults();
	const author = lastLine( wp( 'eval', 'echo wp_make_link_relative( get_author_posts_url( 1 ) );' ) );
	const home = lastLine( wp( 'eval', "echo home_url( '/' );" ) );
	// A query string of its own each time, past any page cache.
	let n = 0;
	const status = async ( path ) => {
		const response = await page.request.get( `${ path }?pp-archives=${ Date.now() }-${ n++ }`, { maxRedirects: 0 } );
		return [ response.status(), response.headers().location || '' ];
	};

	expect( ( await status( author ) )[ 0 ] ).toBe( 200 );

	await page.goto( URL );
	const subSwitch = ( name ) => page.getByRole( 'checkbox', { name, exact: true } );
	await expect( subSwitch( 'Author archives' ) ).toHaveCount( 0 );

	await featureSwitch( page, 'Unneeded archives' ).click();
	await expect.poll( () => settings()?.archives_off, { timeout: 15000 } ).toBe( true );
	for ( const name of [ 'Author archives', 'Date archives', 'Post format archives' ] ) {
		await expect( subSwitch( name ) ).toBeChecked();
	}
	expect( await status( author ) ).toEqual( [ 301, home ] );

	await subSwitch( 'Author archives' ).click();
	await expect.poll( () => settings()?.author_archives_off, { timeout: 15000 } ).toBe( false );
	expect( ( await status( author ) )[ 0 ] ).toBe( 200 );
} );
