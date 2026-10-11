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
			'upToDate' => null === PhotoPress\\modules\\images\\images::work( $meta, get_post_mime_type( $id ), PhotoPress\\modules\\images\\images::qualityOf( $id ), PhotoPress\\modules\\images\\images::settings() ),
		];
	}
	echo wp_json_encode( $out );
` ) ) );

test( 'the tab lists the sizes and the quality, 92 unless set', async ( { page } ) => {
	wp( 'eval', `delete_option( '${ KEY }' );` );
	await page.goto( '/wp-admin/admin.php?page=photopress-core-base#photopress_core_images' );

	await expect( page.locator( '.photopress-image-sizes tr[data-size="medium"]' ) ).toBeVisible( { timeout: 30000 } );
	await expect( page.getByRole( 'slider', { name: 'JPEG and WebP quality' } ) ).toHaveValue( '92' );
	await expect( page.getByRole( 'button', { name: 'Save', exact: true } ) ).toBeVisible();
} );

test( 'switches and quality are saved by Save, offering to regenerate the images a change affects', async ( { page } ) => {
	wp( 'option', 'update', KEY, JSON.stringify( { quality: 92, disabled_sizes: '' } ), '--format=json' );
	const affected = ( settings ) => Number( lastLine( wp( 'eval', `echo PhotoPress\\modules\\images\\images::countImages( [ 'outdated' => true, 'settings' => json_decode( '${ JSON.stringify( settings ) }', true ) ] );` ) ) );
	const regenerate = page.getByRole( 'button', { name: /^Save and regenerate [\d,]+ affected images?$/ } );
	await page.goto( '/wp-admin/admin.php?page=photopress-core-base#photopress_core_images' );

	const toggle = page.locator( '.photopress-image-sizes tr[data-size="medium_large"]' ).getByRole( 'checkbox' );
	await expect( toggle ).toBeChecked( { timeout: 30000 } );
	await toggle.click();

	// Not saved by the switch: by Save, or Save and regenerate.
	await page.waitForTimeout( 1000 );
	expect( JSON.parse( lastLine( wp( 'option', 'get', KEY, '--format=json' ) ) ).disabled_sizes ).toBe( '' );
	await expect( page.locator( '.photopress-images-outdated' ) ).toHaveCount( 0 );

	// A size turned off is for new uploads: it affects no image.
	expect( affected( { quality: 92, disabled_sizes: 'medium_large' } ) ).toBe( affected( { quality: 92, disabled_sizes: '' } ) );

	// Another quality: every JPEG.
	const slider = page.getByRole( 'slider', { name: 'JPEG and WebP quality' } );
	await slider.focus();
	await page.keyboard.press( 'ArrowLeft' );
	await expect( slider ).toHaveValue( '91' );
	const jpegs = affected( { quality: 91, disabled_sizes: 'medium_large' } );
	expect( jpegs ).toBeGreaterThan( 0 );
	await expect( regenerate ).toHaveText( new RegExp( ` ${ jpegs.toLocaleString( 'en-US' ) } ` ), { timeout: 10000 } );

	if ( process.env.PP_E2E_SHOTS ) {
		await page.locator( '.photopress-savebar' ).screenshot( { path: `${ process.env.PP_E2E_SHOTS }/save-and-regenerate.png` } );
	}

	await page.getByRole( 'button', { name: 'Save', exact: true } ).click();
	await expect.poll( () => JSON.parse( lastLine( wp( 'option', 'get', KEY, '--format=json' ) ) ) ).toMatchObject( { quality: 91, disabled_sizes: 'medium_large' } );
	await expect( page.locator( '.photopress-images-outdated' ) ).toBeVisible();
	await expect( page.locator( '.photopress-job[data-job-type="images.regenerate"] .photopress-job__progress[data-status="queued"], .photopress-job[data-job-type="images.regenerate"] .photopress-job__progress[data-status="running"]' ) ).toHaveCount( 0 );
	wp( 'option', 'update', KEY, JSON.stringify( { quality: 92, disabled_sizes: '' } ), '--format=json' );
} );

test( 'regenerating makes sizes at the quality set, keeping the sizes turned off', async ( { page, made } ) => {
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
		// Not made, but kept, with its file.
		expect( image.sizes ).toContain( 'medium_large' );
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

test( 'a size turned off leaves images as they are, and turned on is made alone', ( { made } ) => {
	const id = made.images[ 0 ];
	const IMAGES = 'PhotoPress\\modules\\images\\images';
	// The files of the image's sizes, each dated an hour ago or not, and what it needs.
	const state = () => JSON.parse( lastLine( wp( 'eval', `
		$meta = wp_get_attachment_metadata( ${ id } );
		$dir = dirname( get_attached_file( ${ id } ) );
		$files = [];
		foreach ( $meta['sizes'] as $name => $size ) { clearstatcache(); $files[ $name ] = filemtime( "$dir/{$size['file']}" ) < time() - 1800; }
		echo wp_json_encode( [ 'files' => $files, 'work' => ${ IMAGES }::work( $meta, get_post_mime_type( ${ id } ), ${ IMAGES }::qualityOf( ${ id } ), ${ IMAGES }::settings() ) ] );
	` ) ) );
	const age = `$meta = wp_get_attachment_metadata( ${ id } ); $dir = dirname( get_attached_file( ${ id } ) ); foreach ( $meta['sizes'] as $size ) { touch( "$dir/{$size['file']}", time() - 3600 ); }`;

	wp( 'option', 'update', KEY, JSON.stringify( { quality: 92, disabled_sizes: '' } ), '--format=json' );
	wp( 'eval', `${ IMAGES }::regenerate( ${ id }, [ 'all' => true ] ); ${ age }` );
	expect( state().work ).toBeNull();
	expect( Object.keys( state().files ) ).toContain( 'medium_large' );

	// Off: nothing to do, the size and its file stay.
	wp( 'option', 'update', KEY, JSON.stringify( { quality: 92, disabled_sizes: 'medium_large' } ), '--format=json' );
	expect( state().work ).toBeNull();
	expect( state().files.medium_large ).toBe( true );

	// As if uploaded while it was off.
	wp( 'eval', `$meta = wp_get_attachment_metadata( ${ id } ); unlink( dirname( get_attached_file( ${ id } ) ) . '/' . $meta['sizes']['medium_large']['file'] ); unset( $meta['sizes']['medium_large'] ); wp_update_attachment_metadata( ${ id }, $meta );` );
	expect( state().work ).toBeNull();

	// On: made alone, the rest as they were.
	wp( 'option', 'update', KEY, JSON.stringify( { quality: 92, disabled_sizes: '' } ), '--format=json' );
	expect( state().work ).toBe( 'missing' );
	wp( 'eval', `${ IMAGES }::regenerate( ${ id } );` );
	const now = state();
	expect( now.work ).toBeNull();
	expect( now.files.medium_large ).toBe( false );
	expect( Object.entries( now.files ).filter( ( [ name ] ) => 'medium_large' !== name ).every( ( [ , old ] ) => old ) ).toBe( true );
} );

test( 'large uploads are scaled to the longest side set, and back when it changes', async ( { page, made } ) => {
	const IMAGES = 'PhotoPress\\modules\\images\\images';
	// The 1500 × 500 panorama.
	const id = Number( lastLine( wp( 'eval', `foreach ( ${ JSON.stringify( made.images ) } as $id ) { if ( 1500 === (int) ( wp_get_attachment_metadata( $id )['width'] ?? 0 ) || str_contains( (string) get_attached_file( $id ), 'panorama' ) ) { echo $id; break; } }` ) ) );
	expect( id ).toBeGreaterThan( 0 );
	const image = () => JSON.parse( lastLine( wp( 'eval', `
		$meta = wp_get_attachment_metadata( ${ id } );
		echo wp_json_encode( [ 'file' => basename( $meta['file'] ), 'width' => $meta['width'], 'work' => ${ IMAGES }::work( $meta, get_post_mime_type( ${ id } ), ${ IMAGES }::qualityOf( ${ id } ), ${ IMAGES }::settings() ) ] );
	` ) ) );

	// Never less than the largest size turned on.
	wp( 'option', 'update', KEY, JSON.stringify( { quality: 92, disabled_sizes: '' } ), '--format=json' );
	await page.goto( '/wp-admin/admin.php?page=photopress-core-base#photopress_core_images' );
	const field = page.getByLabel( 'Longest side of the full-size image, in pixels' );
	const save = page.getByRole( 'button', { name: 'Save', exact: true } );
	await expect( field ).toHaveValue( '2560', { timeout: 30000 } );
	await field.fill( '0' );
	await expect( save ).toBeDisabled();
	await field.fill( '1200' );
	await expect( page.locator( '.photopress-images-threshold-error' ) ).toContainText( 'At least 2,048 px: the largest size turned on, 2048x2048' );
	await expect( save ).toBeDisabled();

	if ( process.env.PP_E2E_SHOTS ) {
		await page.locator( '.photopress-images-settings' ).screenshot( { path: `${ process.env.PP_E2E_SHOTS }/image-sizes.png` } );
	}

	// The sizes longer than 1200 px turned off: 1200 px.
	const largest = JSON.parse( lastLine( wp( 'eval', `echo wp_json_encode( array_keys( array_filter( wp_get_registered_image_subsizes(), static fn( $s ) => max( $s['width'], $s['height'] ) > 1200 ) ) );` ) ) );
	for ( const name of largest ) {
		await page.locator( `.photopress-image-sizes tr[data-size="${ name }"]` ).getByRole( 'checkbox' ).click();
	}
	await expect( page.locator( '.photopress-images-threshold-error' ) ).toHaveCount( 0 );
	await save.click();
	await expect.poll( () => JSON.parse( lastLine( wp( 'option', 'get', KEY, '--format=json' ) ) ) ).toMatchObject( { big_image_threshold: 1200, disabled_sizes: largest.join( ',' ) } );

	expect( image().work ).toBe( 'full' );
	wp( 'eval', `${ IMAGES }::regenerate( ${ id } );` );
	expect( image() ).toMatchObject( { width: 1200, work: null } );
	expect( image().file ).toMatch( /-scaled\.jpg$/ );

	// Off: shown at the size uploaded.
	await page.reload();
	await page.getByRole( 'checkbox', { name: 'Scale down large uploads' } ).click();
	await expect( field ).toHaveCount( 0 );
	await page.getByRole( 'button', { name: 'Save', exact: true } ).click();
	await expect.poll( () => JSON.parse( lastLine( wp( 'option', 'get', KEY, '--format=json' ) ) ).scale_large_uploads ).toBe( false );

	expect( image().work ).toBe( 'full' );
	wp( 'eval', `${ IMAGES }::regenerate( ${ id } );` );
	expect( image() ).toMatchObject( { width: 1500, work: null } );
	expect( image().file ).not.toMatch( /-scaled\.jpg$/ );

	wp( 'option', 'update', KEY, JSON.stringify( { quality: 92, disabled_sizes: '' } ), '--format=json' );
	expect( image().work ).toBeNull();
} );
