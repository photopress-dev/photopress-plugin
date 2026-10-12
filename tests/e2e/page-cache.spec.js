/**
 * The pages PhotoPress clears through Proxy Cache Purge, with WordPress's own
 * hooks: an image's page and its terms' archives as the image is given a
 * term, edited, and deleted, and a renamed term's archive at both addresses
 * with its images' pages. Proxy Cache Purge is not installed: within the one
 * request, a VarnishPurger class records what it is asked to purge.
 *
 * Needs attachment pages and image taxonomy pages on, as they are by
 * default; the settings are not changed. The image and keyword made here
 * are deleted.
 */
const path = require( 'path' );
const { test, expect } = require( './test' );
const { wp, lastLine } = require( './wp' );

const stamp = Date.now();
const WORD = `ppcache${ stamp }`;

test( 'an image given a term, edited, its term renamed, and deleted', () => {
	const on = lastLine( wp( 'eval', `echo \\PhotoPress\\modules\\search\\search::enabled( 'attachment_pages' ) && \\PhotoPress\\modules\\search\\search::enabled( 'taxonomy_pages' ) ? 'yes' : 'no';` ) );
	test.skip( 'yes' !== on, 'Attachment pages or image taxonomy pages are off.' );

	const out = JSON.parse( lastLine( wp( 'eval', `
		class VarnishPurger {
			public static $purged = [];
			public static function purge_url( $url ) { self::$purged[] = $url; }
		}
		\\PhotoPress\\modules\\search\\PageCache::addHooks();
		\\PhotoPress\\modules\\search\\RenamedTerms::addHooks();
		// Every archive of the image's terms, as PageCache clears them.
		$archives = static function ( $id ) {
			$links = [];
			foreach ( \\PhotoPress\\modules\\search\\ImageSearch::taxonomies() as $taxonomy ) {
				foreach ( get_the_terms( $id, $taxonomy ) ?: [] as $t ) {
					$links[] = wp_make_link_relative( get_term_link( $t ) ) . '?vhp-regex';
				}
			}
			return $links;
		};
		$take = static function () {
			\\PhotoPress\\modules\\search\\PageCache::purge();
			$purged = array_map( 'wp_make_link_relative', VarnishPurger::$purged );
			VarnishPurger::$purged = [];
			return $purged;
		};

		require_once ABSPATH . 'wp-admin/includes/admin.php';
		wp_set_current_user( (int) get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] )[0] );
		$tmp = wp_tempnam( 'pp-e2e-cache' );
		copy( ${ JSON.stringify( path.join( __dirname, '../fixtures/images' ) ) } . '/03-square.jpg', $tmp );
		$id = media_handle_sideload( [ 'name' => 'pp-e2e-cache.jpg', 'tmp_name' => $tmp ], 0, 'PP E2E cache image' );
		$take();

		$out = [ 'page' => wp_make_link_relative( get_permalink( $id ) ) ];
		wp_set_object_terms( $id, '${ WORD }', 'photos_keywords', true );
		$term = get_term_by( 'name', '${ WORD }', 'photos_keywords' );
		$out['archive'] = wp_make_link_relative( get_term_link( $term ) );
		$out['termed'] = $take();

		wp_set_object_terms( $id, '${ WORD }', 'photos_keywords', true );
		$out['again'] = $take();

		$out['archives'] = $archives( $id );
		wp_update_post( [ 'ID' => $id, 'post_excerpt' => 'A caption' ] );
		$out['edited'] = $take();

		wp_update_term( $term->term_id, 'photos_keywords', [ 'name' => '${ WORD } renamed', 'slug' => '${ WORD }-renamed' ] );
		$out['newArchive'] = wp_make_link_relative( get_term_link( (int) $term->term_id, 'photos_keywords' ) );
		$out['renamed'] = $take();

		$out['archivesRenamed'] = $archives( $id );
		wp_delete_attachment( $id, true );
		$out['deleted'] = $take();
		wp_delete_term( $term->term_id, 'photos_keywords' );

		echo wp_json_encode( $out );
	` ) ) );

	const archive = `${ out.archive }?vhp-regex`;
	const newArchive = `${ out.newArchive }?vhp-regex`;

	expect( out.termed ).toEqual( [ out.page, archive ] );
	expect( out.again, 'a term it already had' ).toEqual( [] );
	// The archives of all its terms, among them those read from its metadata.
	expect( out.archives ).toContain( archive );
	expect( out.edited ).toEqual( [ out.page, ...out.archives ] );
	expect( out.renamed ).toEqual( [ archive, newArchive, out.page ] );
	expect( out.archivesRenamed ).toContain( newArchive );
	expect( out.deleted ).toEqual( [ out.page, ...out.archivesRenamed ] );
} );
