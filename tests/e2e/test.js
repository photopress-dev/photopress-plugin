/**
 * The test() used by the specs: the fixture content created by global-setup.js
 * as `made`, and, with PP_E2E_ORIGIN, the site's requests sent to that origin.
 */
const base = require( '@playwright/test' );

const test = base.test.extend( {
	made: async ( {}, use ) => {
		await use( JSON.parse( process.env.PP_E2E_FIXTURES ) );
	},

	page: async ( { page, baseURL }, use ) => {
		const origin = process.env.PP_E2E_ORIGIN;
		const site = new URL( baseURL );

		if ( origin ) {
			await page.context().route( ( url ) => url.host === site.host, async ( route ) => {
				const request = route.request();
				const url = new URL( request.url() );
				const response = await route.fetch( {
					url: `${ origin }${ url.pathname }${ url.search }`,
					headers: { ...( await request.allHeaders() ), host: site.host, 'x-forwarded-proto': site.protocol.replace( ':', '' ) },
					maxRedirects: 0,
				} );
				await route.fulfill( { response } );
			} );
		}

		const errors = [];
		page.on( 'pageerror', ( error ) => errors.push( error.message ) );

		await use( page );

		base.expect( errors, 'errors on the page' ).toEqual( [] );
	},
} );

module.exports = { test, expect: base.expect };
