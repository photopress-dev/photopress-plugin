/**
 * The page cache, through Proxy Cache Purge: what it purges, and Varnish
 * lets through, as an image is given a term, edited, its term renamed and
 * deleted, and the image deleted.
 *
 * Each step is a request of its own, at whose end Proxy Cache Purge sends
 * its purges, as it would on the site. Each must reach Varnish and be let
 * through, not refused (405) as from an address it doesn't trust. The test
 * site is never cached, so what is purged is checked, not that pages were
 * cached and then weren't.
 *
 * Needs Proxy Cache Purge active, and attachment pages and image taxonomy
 * pages on, as they are by default; the settings are not changed. The image
 * and keyword made here are deleted.
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
				is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_response_code( $response ) . ' ' . wp_remote_retrieve_header( $response, 'via' ),
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

test( 'an image given a term, edited, its term renamed and deleted, and the image deleted', () => {
	const ready = lastLine( wp( 'eval', `echo ${ NS }\\PageCache::purges() && ${ NS }\\search::enabled( 'attachment_pages' ) && ${ NS }\\search::enabled( 'taxonomy_pages' ) ? 'yes' : 'no';` ) );
	test.skip( 'yes' !== ready, 'Proxy Cache Purge is not active, or attachment pages or image taxonomy pages are off.' );

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

		// By Proxy Cache Purge itself, with images among the statuses it purges.
		const deleted = run( `
			$out['archives'] = $archives( ${ id } );
			wp_delete_attachment( ${ id }, true );
		` );
		expect( deleted.out.archives ).not.toContain( `${ newArchive }?vhp-regex` );
		expect( deleted.purged ).toEqual( expect.arrayContaining( [ page, ...deleted.out.archives ] ) );
	} finally {
		wp( 'eval', `if ( get_post( ${ id } ) ) { wp_delete_attachment( ${ id }, true ); } foreach ( [ '${ WORD }', '${ WORD } renamed' ] as $name ) { $t = get_term_by( 'name', $name, 'photos_keywords' ); if ( $t ) { wp_delete_term( $t->term_id, 'photos_keywords' ); } }` );
	}

	expect( answers.length ).toBeGreaterThan( 0 );
	for ( const answer of answers ) {
		expect( answer, 'a purge Varnish let through' ).toMatch( /^(?!405)\d{3} .*Varnish/ );
	}
} );
