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
			function_exists( 'as_unschedule_all_actions' ) && as_unschedule_all_actions( 'photopress_job_batch', [ $id ], 'photopress' );
		}
		update_option( 'photopress_jobs', $before, false );
	` );
} );

test( 're-reading the metadata of some images, with progress on the settings page until it is left', async ( { page, made } ) => {
	// With nothing scheduled, Action Scheduler is not loaded: the job starts it.
	wp( 'eval', `update_option( 'photopress_scheduled', 'idle', true ); wp_clear_scheduled_hook( 'action_scheduler_run_queue', [ 'WP Cron' ] );` );

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

	// Its batches went through Action Scheduler; once its queue has none of
	// PhotoPress's left, it is idle again, and its every-minute event gone.
	const after = JSON.parse( lastLine( wp( 'eval', `
		\\PhotoPress\\jobs\\Scheduler::ensure();
		do_action( 'action_scheduler_run_queue', 'WP Cron' );
		echo wp_json_encode( [
			'batches' => count( as_get_scheduled_actions( [ 'hook' => 'photopress_job_batch', 'group' => 'photopress', 'status' => '', 'per_page' => -1 ], 'ids' ) ),
			'state'   => get_option( 'photopress_scheduled' ),
			'cron'    => (bool) wp_next_scheduled( 'action_scheduler_run_queue', [ 'WP Cron' ] ),
		] );
	` ) ) );
	expect( after.batches ).toBeGreaterThan( 0 );
	expect( after.state ).toBe( 'idle' );
	expect( after.cron ).toBe( false );
} );
