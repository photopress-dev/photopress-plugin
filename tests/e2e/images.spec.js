/**
 * The Image Sizes settings: the quality JPEG sizes are saved at, sizes turned
 * off, and regenerating sizes in the background. Run on the fixture images
 * only, never the whole library; the settings are put back afterwards.
 */
const { test, expect } = require( './test' );
const { wp, lastLine } = require( './wp' );

const KEY = 'photopress_core_images';
let saved;

test.beforeAll( () => {
	// Not there until the settings are first saved.
	try {
		saved = lastLine( wp( 'option', 'get', KEY, '--format=json' ) );
	} catch ( e ) {
		saved = null;
	}
} );

test.afterAll( () => {
	if ( saved ) {
		wp( 'option', 'update', KEY, saved, '--format=json' );
	} else {
		wp( 'eval', `delete_option( '${ KEY }' );` );
	}
	// The job records this test made.
	wp( 'eval', `foreach ( (array) get_option( 'photopress_jobs', [] ) as $id ) { delete_option( "photopress_job_$id" ); as_unschedule_all_actions( 'photopress_job_batch', [ $id ], 'photopress' ); } delete_option( 'photopress_jobs' );` );
} );

/** Each fixture image's sizes, their JPEG quality, and whether it is marked up to date. */
const sizesOf = ( ids ) => JSON.parse( lastLine( wp( 'eval', `
	$out = [];
	foreach ( ${ JSON.stringify( ids ) } as $id ) {
		$meta = wp_get_attachment_metadata( $id );
		$dir = dirname( get_attached_file( $id ) );
		$medium = $meta['sizes']['medium']['file'] ?? null;
		$out[ $id ] = [
			'sizes'    => array_keys( $meta['sizes'] ?? [] ),
			'quality'  => $medium && class_exists( 'Imagick' ) ? ( new Imagick( "$dir/$medium" ) )->getImageCompressionQuality() : null,
			'upToDate' => get_post_meta( $id, '_photopress_sizes_signature', true ) === PhotoPress\\modules\\images\\images::signature(),
		];
	}
	echo wp_json_encode( $out );
` ) ) );

test( 'the tab lists the sizes and the quality, 92 unless set', async ( { page } ) => {
	wp( 'eval', `delete_option( '${ KEY }' );` );
	await page.goto( '/wp-admin/admin.php?page=photopress-core-base#photopress_core_images' );

	await expect( page.locator( '.photopress-image-sizes tr[data-size="medium"]' ) ).toBeVisible( { timeout: 30000 } );
	await expect( page.getByRole( 'slider', { name: 'JPEG and WebP quality' } ) ).toHaveValue( '92' );
	await expect( page.locator( '.photopress-job[data-job-type="images.regenerate"]' ) ).toBeVisible();
} );

test( 'regenerating makes sizes at the quality set, without the sizes turned off', async ( { page, made } ) => {
	const ids = made.images.slice( 0, 3 );
	wp( 'option', 'update', KEY, JSON.stringify( { quality: 90, disabled_sizes: 'medium_large' } ), '--format=json' );

	await page.goto( '/wp-admin/admin.php?page=photopress-core-base#photopress_core_images' );
	const started = await page.evaluate( async ( ids ) => {
		const nonce = await ( await fetch( '/wp-admin/admin-ajax.php?action=rest-nonce' ) ).text();
		const response = await fetch( '/?rest_route=/photopress/v1/jobs', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
			body: JSON.stringify( { type: 'images.regenerate', args: { ids } } ),
		} );
		return { status: response.status, job: await response.json() };
	}, ids );

	expect( started.status, JSON.stringify( started.job ) ).toBe( 200 );
	expect( started.job.total ).toBe( ids.length );

	// The batches are run here, with the busy-server wait lifted: the test's
	// own browser keeps this server busier than the job allows. The rests
	// between batches are skipped too.
	wp( 'eval', `
		add_filter( 'photopress_job_max_load', static fn() => PHP_INT_MAX );
		for ( $i = 0; $i < 20; $i++ ) {
			$job = PhotoPress\\jobs\\Jobs::get( '${ started.job.id }' );
			if ( ! in_array( $job['status'], [ 'queued', 'running' ], true ) ) { break; }
			$job['next'] = 0;
			update_option( 'photopress_job_' . $job['id'], $job, false );
			PhotoPress\\jobs\\Jobs::runBatch( $job['id'] );
		}
	` );

	// The panel shows it finished.
	await page.reload();
	const panel = page.locator( '.photopress-job[data-job-type="images.regenerate"]' );
	await expect( panel.locator( '.photopress-job__progress' ) ).toHaveAttribute( 'data-status', 'done', { timeout: 120000 } );
	await expect( panel ).toContainText( `${ ids.length } of ${ ids.length }` );

	for ( const image of Object.values( sizesOf( ids ) ) ) {
		expect( image.upToDate ).toBe( true );
		expect( image.sizes ).toContain( 'medium' );
		expect( image.sizes ).not.toContain( 'medium_large' );
		if ( null !== image.quality ) {
			expect( image.quality ).toBe( 90 );
		}
	}

	// Then put back: every size, at 92.
	wp( 'option', 'update', KEY, JSON.stringify( { quality: 92, disabled_sizes: '' } ), '--format=json' );
	wp( 'eval', `foreach ( ${ JSON.stringify( ids ) } as $id ) { PhotoPress\\modules\\images\\images::regenerate( $id ); }` );
	for ( const image of Object.values( sizesOf( ids ) ) ) {
		expect( image.sizes ).toContain( 'medium_large' );
	}
} );
