/**
 * Image and term pages in the site's sitemap, whichever builds it:
 * WordPress's, Jetpack's and Yoast SEO's, each built here by its own code in
 * a request of its own. Search engines are discouraged on the test site, so
 * no sitemap is served there: each is built as it would be once served.
 *
 * Four images are made: one with a keyword of its own, unattached; one
 * attached to a published page; one attached to a draft (private); and one
 * unattached with no terms. Attachment pages off is tried in the request
 * alone, as is Yoast SEO listing media pages (off by default); the settings
 * are not changed. The images, keyword and pages made here are deleted, with
 * any sitemap Jetpack stored that it had not before.
 *
 * Jetpack's and Yoast SEO's are tried while each is active; Jetpack's
 * Sitemaps module need not be.
 */
const path = require( 'path' );
const { test, expect } = require( './test' );
const { wp, lastLine } = require( './wp' );

const stamp = Date.now();
const WORD = `ppsitemap${ stamp }`;

// In a request: attachment pages off; Yoast SEO listing media pages.
const PAGES_OFF = '--exec=$GLOBALS["wp_filter"]["option_photopress_core_search"][10][] = [ "function" => function ( $v ) { return [ "attachment_pages" => false ] + (array) $v; }, "accepted_args" => 1 ];';
const YOAST_MEDIA_PAGES = '--exec=$GLOBALS["wp_filter"]["option_wpseo_titles"][10][] = [ "function" => function ( $v ) { return [ "disable-attachment" => false ] + (array) $v; }, "accepted_args" => 1 ];';

/** Runs PHP in a request of its own; returns what it put in $out. */
function run( php, ...flags ) {
	return JSON.parse( lastLine( wp( ...flags, 'eval', `
		$out = [];
		${ php }
		echo "\\n", wp_json_encode( $out );
	` ) ) );
}

/** Jetpack's page sitemap, from after the site's posts, and its image sitemap from the first image made. */
const JETPACK = `
	$dir = WP_PLUGIN_DIR . '/jetpack/modules/sitemaps/';
	require_once $dir . 'sitemaps.php';
	require_once $dir . 'sitemap-builder.php';
	global $wpdb;
	$types = "'jp_sitemap', 'jp_img_sitemap'";
	$before = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( $types )" );
	$builder = new Jetpack_Sitemap_Builder();
	$builder->build_one_page_sitemap( 1, (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" ) );
	$builder->build_one_image_sitemap( 1, min( $made['unattached'], $made['attached'], $made['private'], $made['bare'] ) - 1 );
	$entries = static function ( $type ) use ( $wpdb ) {
		$xml = base64_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE post_type = %s AND post_title LIKE %s", $type, '%-1.xml' ) ) );
		preg_match_all( '#<url>.*?</url>#s', $xml, $urls );
		return array_map( static function ( $url ) {
			preg_match( '#<loc>([^<]+)</loc>#', $url, $loc );
			preg_match( '#<image:loc>([^<]+)</image:loc>#', $url, $image );
			return [ html_entity_decode( $loc[1] ), isset( $image[1] ) ? html_entity_decode( $image[1] ) : '' ];
		}, $urls[0] );
	};
	$out['pages'] = array_column( $entries( 'jp_sitemap' ), 0 );
	$out['images'] = array_column( $entries( 'jp_img_sitemap' ), 0, 1 );
	foreach ( array_diff( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( $types )" ), $before ) as $id ) {
		wp_delete_post( (int) $id, true );
	}
`;

let made;
let seen;

test.beforeAll( () => {
	seen = run( `$out = get_option( 'photopress_sitemap_seen', null );` );
	made = run( `
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		$image = static function ( $name, $parent ) {
			$tmp = wp_tempnam( 'pp-e2e-sitemap' );
			copy( ${ JSON.stringify( path.join( __dirname, '../fixtures/images' ) ) } . '/03-square.jpg', $tmp );
			return media_handle_sideload( [ 'name' => "pp-e2e-sitemap-$name.jpg", 'tmp_name' => $tmp ], $parent, "PP E2E sitemap $name ${ stamp }" );
		};
		$out['page'] = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'PP E2E sitemap page ${ stamp }' ] );
		$out['draft'] = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'PP E2E sitemap draft ${ stamp }' ] );
		$out['unattached'] = $image( 'unattached', 0 );
		$out['attached'] = $image( 'attached', $out['page'] );
		$out['private'] = $image( 'private', $out['draft'] );
		$out['bare'] = $image( 'bare', 0 );
		wp_set_object_terms( $out['unattached'], '${ WORD }', 'photos_keywords' );
		// Without the terms read from its file.
		wp_delete_object_term_relationships( $out['bare'], get_object_taxonomies( 'attachment' ) );
		foreach ( [ 'unattached', 'attached', 'private', 'bare', 'page' ] as $key ) {
			$out['urls'][ $key ] = get_permalink( $out[ $key ] );
		}
		$out['file'] = wp_get_attachment_url( $out['unattached'] );
		$term = get_term_by( 'name', '${ WORD }', 'photos_keywords' );
		$out['term'] = $term->term_id;
		$out['archive'] = get_term_link( $term );
		// Its camera's, from its file, among them.
		$out['archives'] = [];
		foreach ( \\PhotoPress\\modules\\metadata\\ImageTaxonomies::shownImageTaxonomies() as $taxonomy ) {
			foreach ( get_the_terms( $out['unattached'], $taxonomy ) ?: [] as $t ) {
				$out['archives'][] = get_term_link( $t );
			}
		}
	` );
} );

test.afterAll( () => {
	if ( made ) {
		wp( 'eval', `foreach ( [ ${ made.unattached }, ${ made.attached }, ${ made.private }, ${ made.bare } ] as $id ) { wp_delete_attachment( $id, true ); } foreach ( [ ${ made.page }, ${ made.draft } ] as $id ) { wp_delete_post( $id, true ); } wp_delete_term( ${ made.term }, 'photos_keywords' );` );
	}
	wp( 'eval', null === seen ? `delete_option( 'photopress_sitemap_seen' );` : `update_option( 'photopress_sitemap_seen', json_decode( ${ JSON.stringify( JSON.stringify( seen ) ) }, true ), false );` );
} );

const withMade = ( php ) => `$made = json_decode( '${ JSON.stringify( made ) }', true ); ${ php }`;

test( 'WordPress’s: public images’ pages while attachment pages are on, and image taxonomy terms', () => {
	const listed = run( withMade( `
		$posts = new WP_Sitemaps_Posts();
		$out['types'] = array_keys( $posts->get_object_subtypes() );
		$out['images'] = [];
		for ( $page = 1; $page <= $posts->get_max_num_pages( 'attachment' ); $page++ ) {
			$out['images'] = array_merge( $out['images'], array_column( $posts->get_url_list( $page, 'attachment' ), 'loc' ) );
		}
		$terms = new WP_Sitemaps_Taxonomies();
		$out['terms'] = [];
		for ( $page = 1; $page <= $terms->get_max_num_pages( 'photos_keywords' ); $page++ ) {
			$out['terms'] = array_merge( $out['terms'], array_column( $terms->get_url_list( $page, 'photos_keywords' ), 'loc' ) );
		}
	` ) );

	expect( listed.types ).toContain( 'attachment' );
	expect( listed.images ).toEqual( expect.arrayContaining( [ made.urls.unattached, made.urls.attached, made.urls.bare ] ) );
	expect( listed.images, 'an image attached to a draft' ).not.toContain( made.urls.private );
	expect( listed.terms ).toContain( made.archive );

	const off = run( `$out = array_keys( ( new WP_Sitemaps_Posts() )->get_object_subtypes() );`, PAGES_OFF );
	expect( off ).not.toContain( 'attachment' );
} );

test( 'Jetpack’s: term archives among its pages, and each image with its own page, or, without attachment pages, where it is shown', () => {
	const active = lastLine( wp( 'eval', `echo class_exists( 'Jetpack' ) ? 'yes' : 'no';` ) );
	test.skip( 'yes' !== active, 'Jetpack is not active.' );

	const on = run( withMade( JETPACK ) );
	expect( on.pages ).toContain( made.archive );
	expect( on.images[ made.file ] ).toBe( made.urls.unattached );
	expect( Object.values( on.images ) ).toEqual( expect.arrayContaining( [ made.urls.attached, made.urls.bare ] ) );
	expect( Object.values( on.images ), 'an image attached to a draft' ).not.toContain( made.urls.private );

	// Jetpack would give the attached image its page's address; unattached,
	// its own, which is now its file.
	const off = run( withMade( JETPACK ), PAGES_OFF );
	expect( made.archives, 'the archive of one of its terms' ).toContain( off.images[ made.file ] );
	expect( Object.values( off.images ) ).toContain( made.urls.page );
	expect( Object.keys( off.images ).filter( ( file ) => file.includes( 'pp-e2e-sitemap-bare' ) ), 'an unattached image with no terms' ).toEqual( [] );
} );

test( 'Yoast SEO’s: the images on each term’s archive page, and, while it lists media pages, unattached images’ pages', () => {
	const active = lastLine( wp( 'eval', `echo defined( 'WPSEO_VERSION' ) ? 'yes' : 'no';` ) );
	test.skip( 'yes' !== active, 'Yoast SEO is not active.' );

	const yoast = `
		$ours = array_values( array_filter( apply_filters( 'wpseo_sitemaps_providers', [] ), static fn( $p ) => $p->handles_type( 'photopress-image' ) ) )[0];
		$out['index'] = array_column( $ours->get_index_links( 1000 ), 'loc' );
		$out['unattached'] = [];
		foreach ( $out['index'] as $page => $loc ) {
			$out['unattached'] = array_merge( $out['unattached'], array_column( $ours->get_sitemap_links( 'photopress-image', 1000, $page + 1 ), 'loc' ) );
		}
		$theirs = new WPSEO_Post_Type_Sitemap_Provider();
		try {
			$out['media'] = array_column( $theirs->get_sitemap_links( 'attachment', 50000, 1 ), 'loc' );
		} catch ( OutOfBoundsException $e ) {
			// No media sitemap.
			$out['media'] = [];
		}
		$out['termImages'] = array_column( ( new WPSEO_Sitemap_Image_Parser() )->get_term_images( get_term( $made['term'] ) ), 'src' );
	`;

	// Its media pages off, as by default: none from PhotoPress either.
	const off = run( withMade( yoast ) );
	expect( off.index ).toEqual( [] );
	expect( off.termImages ).toContain( made.file );

	const on = run( withMade( yoast ), YOAST_MEDIA_PAGES );
	expect( on.index ).toEqual( [ expect.stringMatching( /\/photopress-image-sitemap\.xml$/ ) ] );
	expect( on.unattached ).toEqual( expect.arrayContaining( [ made.urls.unattached, made.urls.bare ] ) );
	expect( on.unattached ).not.toContain( made.urls.attached );
	expect( on.media, 'Yoast SEO’s own: attached to a published post' ).toContain( made.urls.attached );
	expect( [ ...on.unattached, ...on.media ], 'an image attached to a draft' ).not.toContain( made.urls.private );

	// PhotoPress's attachment pages off: Yoast SEO lists no media pages.
	const pagesOff = run( withMade( yoast ), YOAST_MEDIA_PAGES, PAGES_OFF );
	expect( pagesOff.index ).toEqual( [] );
} );
