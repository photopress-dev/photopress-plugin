/**
 * A background job, started on its settings page and watched until it
 * finishes. Run on the fixture images only, never the whole library.
 */
const { test, expect } = require( './test' );
const { wp, lastLine } = require( './wp' );

let before;

test.beforeAll( () => {
	before = lastLine( wp( 'eval', `echo wp_json_encode( array_values( (array) get_option( 'photopress_jobs', [] ) ) );` ) );
} );

test.afterAll( () => {
	// The job records this test made, and the list as it was.
	wp( 'eval', `
		$before = (array) json_decode( '${ before }', true );
		foreach ( array_diff( (array) get_option( 'photopress_jobs', [] ), $before ) as $id ) {
			delete_option( "photopress_job_$id" );
			as_unschedule_all_actions( 'photopress_job_batch', [ $id ], 'photopress' );
		}
		update_option( 'photopress_jobs', $before, false );
	` );
} );

test( 're-reading the metadata of some images, with progress on the settings page until it is left', async ( { page, made } ) => {
	await page.goto( '/wp-admin/admin.php?page=photopress-core-base#photopress_core_metadata' );

	// Reprocess all images, for the fixture images only.
	await page.route( ( url ) => url.pathname.endsWith( '/photopress/v1/jobs' ) || url.searchParams.get( 'rest_route' ) === '/photopress/v1/jobs', ( route ) => {
		const request = route.request();
		if ( 'POST' !== request.method() ) {
			return route.fallback();
		}
		const body = request.postDataJSON();
		return route.fallback( { postData: JSON.stringify( { ...body, args: { ...body.args, ids: made.images } } ) } );
	} );

	const panel = page.locator( '.photopress-job[data-job-type="metadata.reprocess"]' );
	page.once( 'dialog', ( dialog ) => dialog.accept() );
	await panel.getByRole( 'button', { name: 'Reprocess all images' } ).click( { timeout: 30000 } );

	// The panel follows it to the end.
	await expect( panel.locator( '.photopress-job__progress' ) ).toHaveAttribute( 'data-status', 'done', { timeout: 90000 } );
	await expect( panel ).toContainText( `${ made.images.length } of ${ made.images.length }` );
	await expect( panel ).toContainText( 'Finished' );
	await expect( panel.getByRole( 'button', { name: 'Reprocess all images' } ) ).toBeVisible();

	// Once it has ended, a new visit to the page starts without it.
	const fetched = page.waitForResponse( ( response ) => decodeURIComponent( response.url() ).includes( 'photopress/v1/jobs?type=metadata.reprocess' ) );
	await page.reload();
	await fetched;
	await expect( panel.getByRole( 'button', { name: 'Reprocess all images' } ) ).toBeVisible();
	await expect( panel.locator( '.photopress-job__progress' ) ).toHaveCount( 0 );
} );
