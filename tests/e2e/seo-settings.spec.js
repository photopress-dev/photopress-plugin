/**
 * The SEO settings tab: the sitemap in use, with a link to it, and the
 * Unneeded archives switch with one switch each for author, date and post
 * format archives. Its settings are put back afterwards.
 */
const { test, expect } = require( './test' );
const { wp, lastLine } = require( './wp' );

const KEY = 'photopress_core_seo';
const URL = '/wp-admin/admin.php?page=photopress-core-base#photopress_core_seo';
let saved;

const settings = () => JSON.parse( lastLine( wp( 'eval', `echo wp_json_encode( get_option( '${ KEY }', null ) );` ) ) );

const KEYS = [ 'archives_off', 'author_archives_off', 'date_archives_off', 'format_archives_off' ];
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
	const card = page.locator( '.photopress-seo-sitemap' );
	await expect( card.getByRole( 'heading', { name: 'Sitemap' } ) ).toBeVisible( { timeout: 30000 } );
	await expect( page.getByRole( 'heading', { name: 'Sitemap', exact: true } ) ).toHaveCount( 1 );
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
