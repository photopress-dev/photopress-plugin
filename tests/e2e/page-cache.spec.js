/**
 * The page cache, through Proxy Cache Purge: what it purges, and Varnish
 * lets through, as an image is given a term, edited, its term renamed and
 * deleted, attached to a page with a dynamic gallery, replaced by Enable
 * Media Replace (when active; else its hook is fired), and deleted, taking it out of a
 * page's static gallery too.
 *
 * Each step is a request of its own, at whose end Proxy Cache Purge sends
 * its purges, as it would on the site. Each must reach Varnish and be
 * answered Purged (200), not refused (405) as from an address it doesn't
 * trust. The test site is never cached, so what is purged is checked, not
 * that pages were cached and then weren't.
 *
 * Needs Proxy Cache Purge active, attachment pages and image taxonomy pages
 * on, and Remove deleted images on in the Gallery tab, as they are by
 * default; the settings are not changed. The image, keyword and pages made
 * here are deleted.
 */
const path = require( 'path' );
const { test, expect } = require( './test' );
const { wp, lastLine } = require( './wp' );

const stamp = Date.now();
const WORD = `ppcache${ stamp }`;
const NS = '\\PhotoPress\\modules\\search';

/**
 * Runs PHP in a request of its own, as an administrator. Returns what it put
 * in $out, with the purges Proxy Cache Purge sent at its end: the URLs,
 * relative, and Varnish's answers.
 */
function step( php ) {
	return JSON.parse( lastLine( wp( 'eval', `
		$out = [];
		$purged = [];
		add_action( 'after_purge_url', function ( $url, $purgeme, $response ) use ( &$purged ) {
			$purged[] = [
				wp_make_link_relative( $url ),
				is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_response_code( $response ) . ' ' . wp_remote_retrieve_header( $response, 'server' ),
			];
		}, 10, 3 );
		add_action( 'shutdown', function () use ( &$out, &$purged ) {
			echo "\\n", wp_json_encode( [ 'out' => $out, 'purged' => array_column( $purged, 0 ), 'answers' => array_column( $purged, 1 ) ] );
		}, 20 );
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		wp_set_current_user( (int) get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] )[0] );
		// Every page of each archive of the image's terms.
		$archives = static function ( $id ) {
			$links = [];
			foreach ( ${ NS }\\ImageSearch::taxonomies() as $taxonomy ) {
				foreach ( get_the_terms( $id, $taxonomy ) ?: [] as $t ) {
					$links[] = wp_make_link_relative( get_term_link( $t ) ) . '?vhp-regex';
				}
			}
			return $links;
		};
		${ php }
	` ) ) );
}

test( 'an image given a term, edited, its term renamed and deleted, attached to a gallery’s page, and deleted', () => {
	const ready = lastLine( wp( 'eval', `echo ${ NS }\\PageCache::purges() && ${ NS }\\search::enabled( 'attachment_pages' ) && ${ NS }\\search::enabled( 'taxonomy_pages' ) && pp_api::getOption( 'core', 'gallery', 'remove_deleted_images' ) ? 'yes' : 'no';` ) );
	test.skip( 'yes' !== ready, 'Proxy Cache Purge is not active, or attachment pages, image taxonomy pages or Remove deleted images are off.' );

	const answers = [];
	const run = ( php ) => {
		const result = step( php );
		answers.push( ...result.answers );
		return result;
	};

	const made = run( `
		$tmp = wp_tempnam( 'pp-e2e-cache' );
		copy( ${ JSON.stringify( path.join( __dirname, '../fixtures/images' ) ) } . '/03-square.jpg', $tmp );
		$out['id'] = media_handle_sideload( [ 'name' => 'pp-e2e-cache.jpg', 'tmp_name' => $tmp ], 0, 'PP E2E cache image' );
		$out['page'] = wp_make_link_relative( get_permalink( $out['id'] ) );
	` ).out;
	const { id, page } = made;

	// A page with a dynamic gallery, and one whose static gallery shows the image.
	const image = `<!-- wp:image {"id":${ id },"linkDestination":"none"} -->\n<figure class="wp-block-image"><img src="/pp-e2e-cache.jpg" alt="" class="wp-image-${ id }"/></figure>\n<!-- /wp:image -->`;
	const galleries = {
		dynamic: '<!-- wp:gallery {"dynamicContent":{"source":"core/attached-media"}} /-->',
		static: `<!-- wp:gallery {"linkTo":"none"} -->\n<figure class="wp-block-gallery has-nested-images columns-default is-cropped">${ image }</figure>\n<!-- /wp:gallery -->`,
	};
	const pages = run( Object.entries( galleries ).map( ( [ key, content ] ) => `
		$out['${ key }'] = wp_insert_post( wp_slash( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'PP E2E cache: ${ key } gallery', 'post_content' => base64_decode( '${ Buffer.from( content ).toString( 'base64' ) }' ) ] ) );
		$out['${ key }Url'] = wp_make_link_relative( get_permalink( $out['${ key }'] ) );
	` ).join( '' ) ).out;

	try {
		const termed = run( `
			wp_set_object_terms( ${ id }, '${ WORD }', 'photos_keywords', true );
			$term = get_term_by( 'name', '${ WORD }', 'photos_keywords' );
			$out['term'] = $term->term_id;
			$out['archive'] = wp_make_link_relative( get_term_link( $term ) );
		` );
		const { term, archive } = termed.out;
		expect( termed.purged ).toEqual( expect.arrayContaining( [ page, `${ archive }?vhp-regex` ] ) );
		expect( termed.purged, 'every page of the archive, not the first alone' ).not.toContain( archive );

		const again = run( `wp_set_object_terms( ${ id }, '${ WORD }', 'photos_keywords', true );` );
		expect( again.purged, 'a term it already had' ).toEqual( [] );

		const edited = run( `
			wp_update_post( [ 'ID' => ${ id }, 'post_excerpt' => 'A caption' ] );
			$out['archives'] = $archives( ${ id } );
		` );
		// The archives of all its terms, among them those read from its metadata.
		expect( edited.out.archives ).toContain( `${ archive }?vhp-regex` );
		expect( edited.purged ).toEqual( expect.arrayContaining( [ page, ...edited.out.archives ] ) );

		const renamed = run( `
			wp_update_term( ${ term }, 'photos_keywords', [ 'name' => '${ WORD } renamed', 'slug' => '${ WORD }-renamed' ] );
			$out['archive'] = wp_make_link_relative( get_term_link( ${ term }, 'photos_keywords' ) );
		` );
		const newArchive = renamed.out.archive;
		expect( renamed.purged ).toEqual( expect.arrayContaining( [ page, `${ archive }?vhp-regex`, `${ newArchive }?vhp-regex` ] ) );

		const termDeleted = run( `wp_delete_term( ${ term }, 'photos_keywords' );` );
		expect( termDeleted.purged ).toEqual( expect.arrayContaining( [ page, `${ newArchive }?vhp-regex` ] ) );

		// The dynamic gallery's page shows it now.
		const attached = run( `wp_update_post( [ 'ID' => ${ id }, 'post_parent' => ${ pages.dynamic } ] );` );
		expect( attached.purged ).toContain( pages.dynamicUrl );

		// Replaced by Enable Media Replace, as from its upload screen (or, not
		// active, its hook fired): announced once as PhotoPress's own, and the
		// image with its file and the dynamic gallery's page purged.
		const replaced = run( `
			$out['announced'] = 0;
			add_action( 'photopress_attachment_file_replaced', function () use ( &$out ) { $out['announced']++; } );
			$out['page'] = wp_make_link_relative( get_permalink( ${ id } ) );
			$emr = '\\EnableMediaReplace\\Controller\\ReplaceController';
			$out['emr'] = class_exists( $emr );
			if ( $out['emr'] ) {
				$tmp = wp_tempnam( 'pp-e2e-emr' );
				copy( ${ JSON.stringify( path.join( __dirname, '../fixtures/images' ) ) } . '/01-portrait-2x3.jpg', $tmp );
				$replace = new $emr( ${ id } );
				$replace->setupParams( [ 'post_id' => ${ id }, 'replace_type' => 'replace', 'timestamp_replace' => $emr::TIME_UPDATEMODIFIED, 'new_date' => current_time( 'mysql' ), 'new_location' => false, 'location_dir' => null, 'is_custom_date' => false, 'remove_background' => false, 'uploadFile' => $tmp, 'new_filename' => 'pp-e2e-cache.jpg' ] );
				$out['ran'] = $replace->run();
			} else {
				$url = wp_get_attachment_url( ${ id } );
				do_action( 'enable-media-replace-upload-done', $url, $url, ${ id } );
			}
			$out['file'] = wp_make_link_relative( wp_get_attachment_url( ${ id } ) );
		` );
		if ( replaced.out.emr ) {
			expect( replaced.out.ran ).toBe( true );
		}
		expect( replaced.out.announced ).toBe( 1 );
		expect( replaced.purged ).toEqual( expect.arrayContaining( [ replaced.out.page, replaced.out.file, pages.dynamicUrl ] ) );

		// The image by Proxy Cache Purge itself, with images among the
		// statuses it purges; both pages, out of whose galleries it goes.
		// Its page is the gallery page's child now.
		const deleted = run( `
			$out['archives'] = $archives( ${ id } );
			$out['page'] = wp_make_link_relative( get_permalink( ${ id } ) );
			wp_delete_attachment( ${ id }, true );
		` );
		expect( deleted.out.page ).toBe( `${ pages.dynamicUrl }${ page.replace( /^\//, '' ) }` );
		expect( deleted.out.archives ).not.toContain( `${ newArchive }?vhp-regex` );
		expect( deleted.purged ).toEqual( expect.arrayContaining( [ deleted.out.page, ...deleted.out.archives, pages.dynamicUrl, pages.staticUrl ] ) );
	} finally {
		wp( 'eval', `foreach ( [ ${ pages.dynamic }, ${ pages.static } ] as $p ) { wp_delete_post( $p, true ); } if ( get_post( ${ id } ) ) { wp_delete_attachment( ${ id }, true ); } foreach ( [ '${ WORD }', '${ WORD } renamed' ] as $name ) { $t = get_term_by( 'name', $name, 'photos_keywords' ); if ( $t ) { wp_delete_term( $t->term_id, 'photos_keywords' ); } }` );
	}

	expect( answers.length ).toBeGreaterThan( 0 );
	for ( const answer of answers ) {
		expect( answer, 'a purge Varnish let through' ).toBe( '200 Varnish' );
	}
} );
