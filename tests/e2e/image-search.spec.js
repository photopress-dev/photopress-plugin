/**
 * Images in search results (the Pages & Search tab): off by default; on, a
 * search finds public images by their terms and alt text, shown in the
 * Image Search template's Taxonomy Gallery, with posts and pages listed
 * apart. With core's search, and with Relevanssi, which PhotoPress has
 * index images and their terms.
 *
 * Only the two search settings are changed, and put back as they were; the
 * images, keyword, posts and page made here are deleted afterwards, and
 * Relevanssi is left as it was found.
 */
const path = require( 'path' );
const { test, expect } = require( './test' );
const { wp, lastLine } = require( './wp' );

const SEARCH = 'photopress_core_search';
const KEYS = [ 'images_in_search', 'posts_in_search' ];
const stamp = Date.now();
// A word only the keyword has: not in any title.
const WORD = `ppsearch${ stamp }`;
let made;
let saved;
let relevanssiWasActive;
let jobsBefore;

const php = ( value ) => `json_decode( base64_decode( '${ Buffer.from( JSON.stringify( value ) ).toString( 'base64' ) }' ), true )`;

/** Sets the search keys, leaving the tab's other settings alone. */
const setSearch = ( values ) => wp( 'eval', `
	$o = get_option( '${ SEARCH }' );
	$o = is_array( $o ) ? $o : [];
	foreach ( ${ php( values ) } as $k => $v ) { if ( null === $v ) { unset( $o[ $k ] ); } else { $o[ $k ] = $v; } }
	$o ? update_option( '${ SEARCH }', $o ) : delete_option( '${ SEARCH }' );
` );

const relevanssi = ( on ) => wp( 'plugin', on ? 'activate' : 'deactivate', 'relevanssi' );

test.beforeAll( () => {
	jobsBefore = JSON.parse( lastLine( wp( 'eval', `echo wp_json_encode( array_values( (array) get_option( 'photopress_jobs', [] ) ) );` ) ) );
	saved = JSON.parse( lastLine( wp( 'eval', `$o = get_option( '${ SEARCH }' ); $o = is_array( $o ) ? $o : []; echo wp_json_encode( array_intersect_key( $o, array_flip( ${ php( KEYS ) } ) ) ?: new stdClass() );` ) ) );
	relevanssiWasActive = 'yes' === lastLine( wp( 'eval', `echo is_plugin_active( 'relevanssi/relevanssi.php' ) ? 'yes' : 'no';` ) );

	made = JSON.parse( lastLine( wp( 'eval', `
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		wp_set_current_user( (int) get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] )[0] );
		$draft_page = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'PP E2E search draft page ${ stamp }' ] );
		$post = wp_insert_post( [ 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'PP E2E post about ${ WORD }', 'post_content' => 'A post that mentions ${ WORD }.' ] );
		$draft = wp_insert_post( [ 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'PP E2E draft about ${ WORD }' ] );
		$images = [];
		foreach ( [ '01-portrait-2x3.jpg', '02-landscape-3x2.jpg', '03-square.jpg' ] as $i => $name ) {
			$tmp = wp_tempnam( 'pp-e2e-search' );
			copy( ${ JSON.stringify( path.join( __dirname, '../fixtures/images' ) ) } . '/' . $name, $tmp );
			$id = media_handle_sideload( [ 'name' => "pp-e2e-search-$i.jpg", 'tmp_name' => $tmp ], 2 === $i ? $draft_page : 0, "PP E2E search image $i" );
			$images[] = $id;
		}
		// Found by its keyword, by its alt text, and not at all (attached to a draft).
		wp_set_object_terms( $images[0], '${ WORD }', 'photos_keywords', true );
		update_post_meta( $images[1], '_wp_attachment_image_alt', 'An alt text with ${ WORD } in it' );
		wp_set_object_terms( $images[2], '${ WORD }', 'photos_keywords', true );
		echo wp_json_encode( [ 'images' => $images, 'post' => $post, 'draft' => $draft, 'draftPage' => $draft_page ] );
	` ) ) );
} );

test.afterAll( () => {
	// The indexing jobs turning images on started.
	wp( 'eval', `
		$before = ${ php( jobsBefore ) };
		foreach ( array_diff( (array) get_option( 'photopress_jobs', [] ), $before ) as $id ) {
			delete_option( "photopress_job_$id" );
			as_unschedule_all_actions( 'photopress_job_batch', [ $id ], 'photopress' );
		}
		update_option( 'photopress_jobs', $before, false );
	` );
	setSearch( Object.fromEntries( KEYS.map( ( k ) => [ k, k in saved ? saved[ k ] : null ] ) ) );
	relevanssi( relevanssiWasActive );
	if ( made ) {
		wp( 'eval', `
			foreach ( ${ php( made.images ) } as $id ) { wp_delete_attachment( $id, true ); }
			foreach ( [ ${ made.post }, ${ made.draft }, ${ made.draftPage } ] as $id ) { wp_delete_post( $id, true ); }
			$t = get_term_by( 'name', '${ WORD }', 'photos_keywords' );
			if ( $t ) { wp_delete_term( $t->term_id, 'photos_keywords' ); }
		` );
	}
} );

const gallery = ( page ) => page.locator( 'main figure.wp-block-gallery' );
const postsFound = ( page ) => page.locator( '.wp-block-photopress-search-posts' );

test( 'off by default: a search finds no images', async ( { page } ) => {
	relevanssi( false );
	setSearch( { images_in_search: null, posts_in_search: null } );

	await page.goto( `/?s=${ WORD }` );
	await expect( gallery( page ) ).toHaveCount( 0 );
	await expect( page.locator( 'body' ) ).toContainText( `PP E2E post about ${ WORD }` );
} );

test( 'with core\'s search: public images by their terms and alt text, and posts apart', async ( { page } ) => {
	relevanssi( false );
	setSearch( { images_in_search: true, posts_in_search: null } );
	const [ byTerm, byAlt, attachedToDraft ] = made.images;

	await page.goto( `/?s=${ WORD }` );
	// As dorthea's results: the terms, the count, and images in rows with
	// each one's file name and title under it.
	const summary = page.locator( '.wp-block-photopress-search-summary' );
	await expect( summary ).toContainText( 'Search results for:' );
	await expect( summary.getByRole( 'heading', { level: 1 } ) ).toHaveText( WORD );
	await expect( summary ).toContainText( '(2 images.)' );
	await expect( gallery( page ) ).toHaveClass( /photopress-layout-rows/ );
	await expect( gallery( page ).locator( 'figcaption.photopress-details' ).first() ).toContainText( /pp-e2e-search-\d\.jpg/ );
	await expect( gallery( page ).locator( '.photopress-details__title' ).first() ).toHaveText( /PP E2E search image \d/ );
	await expect( gallery( page ).locator( 'img' ) ).toHaveCount( 2 );
	await expect( gallery( page ).locator( `img.wp-image-${ byTerm }` ) ).toHaveCount( 1 );
	await expect( gallery( page ).locator( `img.wp-image-${ byAlt }` ) ).toHaveCount( 1 );
	await expect( gallery( page ).locator( `img.wp-image-${ attachedToDraft }` ) ).toHaveCount( 0 );

	await expect( postsFound( page ).getByRole( 'heading', { name: 'Posts and pages' } ) ).toBeVisible();
	await expect( postsFound( page ).getByRole( 'link', { name: `PP E2E post about ${ WORD }` } ) ).toBeVisible();
	await expect( postsFound( page ) ).not.toContainText( 'PP E2E draft' );

	if ( process.env.PP_E2E_SHOTS ) {
		await page.screenshot( { path: `${ process.env.PP_E2E_SHOTS }/image-search.png`, fullPage: true } );
	}

	// Posts and pages off: images alone.
	setSearch( { posts_in_search: false } );
	await page.goto( `/?s=${ WORD }` );
	await expect( gallery( page ).locator( 'img' ) ).toHaveCount( 2 );
	await expect( postsFound( page ) ).toHaveCount( 0 );

	// Nothing found: a note.
	await page.goto( `/?s=${ WORD }nothing` );
	await expect( gallery( page ) ).toHaveCount( 0 );
	await expect( page.locator( 'body' ) ).toContainText( `Nothing was found for “${ WORD }nothing”.` );

	// More images than a page holds: numbered pages.
	const perPage = lastLine( wp( 'option', 'get', 'posts_per_page' ) );
	wp( 'option', 'update', 'posts_per_page', '1' );
	try {
		await page.goto( `/?s=${ WORD }` );
		await expect( gallery( page ).locator( 'img' ) ).toHaveCount( 1 );
		const pages = page.locator( 'nav.photopress-archive-images__pages' );
		await expect( pages.getByRole( 'link', { name: '2' } ) ).toBeVisible();
		const first = await gallery( page ).locator( 'img' ).getAttribute( 'class' );
		await pages.getByRole( 'link', { name: '2' } ).click();
		await expect( gallery( page ).locator( 'img' ) ).toHaveCount( 1 );
		expect( await gallery( page ).locator( 'img' ).getAttribute( 'class' ) ).not.toBe( first );
	} finally {
		wp( 'option', 'update', 'posts_per_page', perPage );
	}

	// A search of posts of its own is the theme's.
	setSearch( { posts_in_search: null } );
	await page.goto( `/?s=${ WORD }&post_type=post` );
	await expect( gallery( page ) ).toHaveCount( 0 );
	await expect( page.locator( 'body' ) ).toContainText( `PP E2E post about ${ WORD }` );
} );

test( 'with Relevanssi: its index has images and their terms, kept up to date as terms change and are renamed', async ( { page } ) => {
	relevanssi( true );
	setSearch( { images_in_search: null } );
	setSearch( { images_in_search: true, posts_in_search: null } );

	// Turning images on started the job that adds them to its index.
	const jobs = JSON.parse( lastLine( wp( 'eval', `echo wp_json_encode( array_column( \\PhotoPress\\jobs\\Jobs::recent( 'search.index' ), 'status' ) );` ) ) );
	expect( jobs.length ).toBeGreaterThan( 0 );

	// Its settings as PhotoPress has them.
	const options = JSON.parse( lastLine( wp( 'eval', `echo wp_json_encode( [ get_option( 'relevanssi_index_post_types' ), get_option( 'relevanssi_index_taxonomies_list' ) ] );` ) ) );
	expect( options[ 0 ] ).toContain( 'attachment' );
	expect( options[ 1 ] ).toContain( 'photos_keywords' );

	// Indexed now, as Relevanssi's Build the index would.
	wp( 'eval', `foreach ( ${ php( [ ...made.images, made.post ] ) } as $id ) { relevanssi_index_doc( $id, true, relevanssi_get_custom_fields(), true ); }` );
	const [ byTerm, byAlt, attachedToDraft ] = made.images;

	await page.goto( `/?s=${ WORD }` );
	await expect( gallery( page ).locator( 'img' ) ).toHaveCount( 2 );
	await expect( gallery( page ).locator( `img.wp-image-${ byTerm }` ) ).toHaveCount( 1 );
	await expect( gallery( page ).locator( `img.wp-image-${ byAlt }` ) ).toHaveCount( 1 );
	await expect( gallery( page ).locator( `img.wp-image-${ attachedToDraft }` ) ).toHaveCount( 0 );
	await expect( postsFound( page ).getByRole( 'link', { name: `PP E2E post about ${ WORD }` } ) ).toBeVisible();

	// A term given as PhotoPress reprocessing gives it, with no save of the image.
	const later = `${ WORD }later`;
	wp( 'eval', `wp_set_object_terms( ${ byAlt }, '${ later }', 'photos_keywords', true );` );
	await page.goto( `/?s=${ later }` );
	await expect( gallery( page ).locator( `img.wp-image-${ byAlt }` ) ).toHaveCount( 1 );

	// The term renamed: its images found by the new name, not the old.
	const renamed = `${ WORD }renamed`;
	wp( 'eval', `$t = get_term_by( 'name', '${ later }', 'photos_keywords' ); wp_update_term( $t->term_id, 'photos_keywords', [ 'name' => '${ renamed }' ] );` );
	await page.goto( `/?s=${ renamed }` );
	await expect( gallery( page ).locator( `img.wp-image-${ byAlt }` ) ).toHaveCount( 1 );
	await page.goto( `/?s=${ later }` );
	await expect( gallery( page ) ).toHaveCount( 0 );
	wp( 'eval', `$t = get_term_by( 'name', '${ renamed }', 'photos_keywords' ); if ( $t ) { wp_delete_term( $t->term_id, 'photos_keywords' ); }` );
} );
