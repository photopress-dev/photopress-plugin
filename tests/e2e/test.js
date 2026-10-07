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
				const forward = async () => route.fetch( {
					url: `${ origin }${ url.pathname }${ url.search }`,
					headers: { ...( await request.allHeaders() ), host: site.host, 'x-forwarded-proto': site.protocol.replace( ':', '' ) },
					maxRedirects: 0,
				} );
				let response;
				try {
					response = await forward();
				} catch ( error ) {
					// A kept-alive connection to the origin is sometimes dropped
					// ("socket hang up") over a long run: once more on a new one.
					if ( ! /socket hang up|ECONNRESET/.test( String( error ) ) ) {
						throw error;
					}
					response = await forward();
				}
				await route.fulfill( { response } );
			} );
		}

		const errors = [];
		page.on( 'pageerror', ( error ) => errors.push( error.message ) );

		await use( page );

		// Requests still in flight when a test ends are not its concern.
		await page.unrouteAll( { behavior: 'ignoreErrors' } );
		await page.context().unrouteAll( { behavior: 'ignoreErrors' } );

		base.expect( errors, 'errors on the page' ).toEqual( [] );
	},
} );

module.exports = { test, expect: base.expect };
