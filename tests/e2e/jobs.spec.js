/**
 * A background job, started over REST and watched on its settings page until
 * it finishes. Run on the fixture images only, never the whole library.
 */
const { test, expect } = require( './test' );
const { wp } = require( './wp' );

test.afterAll( () => {
	// The job records this test made.
	wp( 'eval', `foreach ( (array) get_option( 'photopress_jobs', [] ) as $id ) { delete_option( "photopress_job_$id" ); as_unschedule_all_actions( 'photopress_job_batch', [ $id ], 'photopress' ); } delete_option( 'photopress_jobs' );` );
} );

test( 're-reading the metadata of some images, with progress on the settings page', async ( { page, made } ) => {
	await page.goto( '/wp-admin/admin.php?page=photopress-core-base#photopress_core_metadata' );

	const started = await page.evaluate( async ( ids ) => {
		const nonce = await ( await fetch( '/wp-admin/admin-ajax.php?action=rest-nonce' ) ).text();
		const response = await fetch( '/?rest_route=/photopress/v1/jobs', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
			body: JSON.stringify( { type: 'metadata.reprocess', args: { ids } } ),
		} );
		return { status: response.status, job: await response.json() };
	}, made.images );

	expect( started.status, JSON.stringify( started.job ) ).toBe( 200 );
	expect( started.job.total ).toBe( made.images.length );

	// The panel finds the job and follows it to the end.
	await page.reload();
	const panel = page.locator( '.photopress-job[data-job-type="metadata.reprocess"]' );
	await expect( panel.locator( '.photopress-job__progress' ) ).toHaveAttribute( 'data-status', 'done', { timeout: 90000 } );
	await expect( panel ).toContainText( `${ made.images.length } of ${ made.images.length }` );
	await expect( panel ).toContainText( 'Finished' );
	await expect( panel.getByRole( 'button', { name: 'Reprocess all images' } ) ).toBeVisible();
} );
