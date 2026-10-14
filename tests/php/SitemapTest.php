<?php

namespace PhotoPress\Tests;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PhotoPress\modules\search\CoreImageSitemap;
use PhotoPress\modules\search\CoreSitemap;
use PhotoPress\modules\search\ImageSitemap;
use PhotoPress\modules\search\JetpackSitemap;
use PhotoPress\modules\search\YoastImagesSitemap;
use PhotoPress\modules\search\YoastSitemap;

/**
 * Image and term pages in the site's sitemap, whichever builds it, through
 * its documented filters: WordPress's, Jetpack's and Yoast SEO's, each given
 * what ImageSitemap decides; and which is in use. WordPress's is given an
 * image sitemap of PhotoPress's own (CoreImageSitemap).
 */
final class SitemapTest extends TestCase {

	/** Options, by name: the Pages & Search settings among them. */
	private array $options = [];

	/** Whether WordPress's sitemap is on. */
	private bool $coreSitemap = false;

	/** Whether Yoast SEO lists media pages. */
	public static bool $yoastMediaPages = true;

	protected function setUp(): void {

		parent::setUp();

		if ( ! defined( 'DAY_IN_SECONDS' ) ) {
			define( 'DAY_IN_SECONDS', 86400 );
		}

		require_once ABSPATH . WPINC . '/sitemaps/class-wp-sitemaps-provider.php';

		if ( ! interface_exists( '\WPSEO_Sitemap_Provider' ) ) {
			eval( 'interface WPSEO_Sitemap_Provider { public function handles_type( $type ); public function get_index_links( $max_entries ); public function get_sitemap_links( $type, $max_entries, $current_page ); }' );
			eval( 'class WPSEO_Post_Type { public static function is_post_type_accessible( $type ) { return \PhotoPress\Tests\SitemapTest::$yoastMediaPages; } public static function is_post_type_indexable( $type ) { return true; } }' );
		}

		self::$yoastMediaPages = true;
		// WordPress's own Attachment pages on, as on a site older than 6.4.
		$this->options = [ 'photopress_core_search' => [], 'wp_attachment_pages_enabled' => '1', 'blog_public' => '1', 'posts_per_page' => 10 ];
		$this->coreSitemap = false;
		\Closure::bind( static function () { static::$archives = null; }, null, ImageSitemap::class )();

		$options = &$this->options;
		$core = &$this->coreSitemap;

		Functions\stubs( [
			'get_option'             => static function ( $name, $default = false ) use ( &$options ) {
				return $options[ $name ] ?? $default;
			},
			'update_option'          => static function ( $name, $value ) use ( &$options ) {
				$options[ $name ] = $value;
				return true;
			},
			'get_object_taxonomies'  => [ 'photos_keywords', 'pp_client' ],
			'get_taxonomy'           => static fn( $name ) => (object) [ 'object_type' => [ 'attachment' ], 'public' => 'pp_client' !== $name ],
			// Archive pages, as the metadata module registers the taxonomies.
			'is_taxonomy_viewable'   => static function ( $name ) use ( &$options ) {
				return $options['photopress_core_search']['taxonomy_pages'] ?? true;
			},
			'get_terms'              => static fn( $args ) => [ new \WP_Term( (object) [ 'term_id' => 3, 'taxonomy' => $args['taxonomy'], 'slug' => 'ojai' ] ) ],
			'get_term_link'          => static fn( $term ) => "https://example.com/{$term->taxonomy}/{$term->slug}/",
			// Image 7 has a keyword; 8 has none.
			'get_the_terms'          => static fn( $id, $taxonomy ) => 7 === $id && 'photos_keywords' === $taxonomy ? [ new \WP_Term( (object) [ 'term_id' => 3, 'taxonomy' => $taxonomy, 'slug' => 'ojai' ] ) ] : false,
			'get_permalink'          => static fn( $id ) => "https://example.com/image-$id/",
			'wp_get_post_parent_id'  => static fn( $id ) => 9 === $id ? 50 : 0,
			'get_post_type_object'   => static fn( $type ) => (object) [ 'name' => $type ],
			'home_url'               => static fn( $path = '' ) => 'https://example.com' . $path,
			'get_sitemap_url'        => 'https://example.com/wp-sitemap.xml',
			'human_time_diff'        => static fn( $from ) => ( time() - $from ) . ' seconds',
			'wp_sitemaps_get_server' => static function () use ( &$core ) {
				return new class( $core ) {
					public function __construct( private bool $enabled ) {}
					public function sitemaps_enabled() { return $this->enabled; }
				};
			},
		] );
	}

	private function turnOff( string $key ): void {

		$this->options['photopress_core_search'][ $key ] = false;
		\Closure::bind( static function () { static::$archives = null; }, null, ImageSitemap::class )();
	}

	/** An image as Jetpack gives it: a row of its own query. */
	private static function image( int $id ): object {

		return (object) [ 'ID' => (string) $id, 'post_type' => 'attachment', 'post_parent' => (string) ( 9 === $id ? 50 : 0 ) ];
	}

	/** An image as WordPress gives it, attached to post 50 or not, modified on this day of October. */
	private static function post( int $id, int $parent, int $day ): object {

		return (object) [ 'ID' => $id, 'post_parent' => $parent, 'post_modified_gmt' => sprintf( '2026-10-%02d 12:00:00', $day ) ];
	}

	public function test_core_lists_its_image_sitemap_at_an_address_of_its_own(): void {

		global $wp_rewrite;

		Functions\when( 'add_query_arg' )->alias( static fn( $key, $value, $url ) => "$url?$key=$value" );
		$permalinks = true;
		$wp_rewrite = new class( $permalinks ) {
			public function __construct( private bool &$on ) {}
			public function using_permalinks() { return $this->on; }
		};

		$sitemap = new CoreImageSitemap();
		$this->assertSame( 'https://example.com/photopress-image-sitemap-2.xml', $sitemap->get_sitemap_url( 'photopress', 2 ) );
		$this->assertSame( [], $sitemap->get_url_list( 1 ), 'none for WordPress to render: it would leave out the images' );

		$permalinks = false;
		$this->assertSame( 'https://example.com/?photopress-image-sitemap=2', CoreImageSitemap::url( 2 ) );

		$this->assertSame( [ 's', 'photopress-image-sitemap' ], CoreSitemap::queryVars( [ 's' ] ) );
		$wp_rewrite = null;
	}

	public function test_image_entries_give_each_image_the_page_it_is_shown_on(): void {

		// Image 6 has no file.
		Functions\when( 'wp_get_attachment_url' )->alias( static fn( $id ) => 6 === $id ? false : "https://example.com/files/$id.jpg" );

		$images = [ self::post( 7, 0, 1 ), self::post( 8, 0, 2 ), self::post( 9, 50, 3 ), self::post( 11, 50, 5 ), self::post( 6, 0, 4 ) ];

		$this->assertSame( [
			[ 'loc' => 'https://example.com/image-7/', 'images' => [ 'https://example.com/files/7.jpg' ], 'lastmod' => '2026-10-01 12:00:00' ],
			[ 'loc' => 'https://example.com/image-8/', 'images' => [ 'https://example.com/files/8.jpg' ], 'lastmod' => '2026-10-02 12:00:00' ],
			[ 'loc' => 'https://example.com/image-9/', 'images' => [ 'https://example.com/files/9.jpg' ], 'lastmod' => '2026-10-03 12:00:00' ],
			[ 'loc' => 'https://example.com/image-11/', 'images' => [ 'https://example.com/files/11.jpg' ], 'lastmod' => '2026-10-05 12:00:00' ],
		], ImageSitemap::entriesFor( $images ), 'each with its own page' );

		// Without pages: attached, its post's page, shared with the post's
		// other images; unattached, its term's archive; with no term, left out.
		$this->turnOff( 'attachment_pages' );
		$this->assertSame( [
			[ 'loc' => 'https://example.com/photos_keywords/ojai/', 'images' => [ 'https://example.com/files/7.jpg' ], 'lastmod' => '2026-10-01 12:00:00' ],
			[ 'loc' => 'https://example.com/image-50/', 'images' => [ 'https://example.com/files/9.jpg', 'https://example.com/files/11.jpg' ], 'lastmod' => '2026-10-05 12:00:00' ],
		], ImageSitemap::entriesFor( $images ) );
	}

	public function test_image_sitemap_xml_has_an_image_entry_for_each_image(): void {

		Functions\when( 'esc_url' )->alias( static fn( $url ) => str_replace( '&', '&#038;', $url ) );
		Functions\when( 'wp_date' )->alias( static fn( $format, $time ) => gmdate( $format, $time ) );

		$xml = CoreImageSitemap::xmlFor( [
			[ 'loc' => 'https://example.com/?p=50&x=1', 'images' => [ 'https://example.com/files/9.jpg', 'https://example.com/files/11.jpg' ], 'lastmod' => '2026-10-05 12:00:00' ],
			[ 'loc' => 'https://example.com/image-7/', 'images' => [ 'https://example.com/files/7.jpg' ], 'lastmod' => '' ],
		] );

		$doc = new \DOMDocument();
		$this->assertTrue( $doc->loadXML( $xml ), 'well-formed' );

		$path = new \DOMXPath( $doc );
		$path->registerNamespace( 's', 'http://www.sitemaps.org/schemas/sitemap/0.9' );
		$path->registerNamespace( 'image', 'http://www.google.com/schemas/sitemap-image/1.1' );
		$text = static fn( $query ) => array_map( static fn( $node ) => $node->textContent, iterator_to_array( $path->query( $query ) ) );

		$this->assertSame( [ 'https://example.com/?p=50&x=1', 'https://example.com/image-7/' ], $text( '/s:urlset/s:url/s:loc' ) );
		$this->assertSame( [ 'https://example.com/files/9.jpg', 'https://example.com/files/11.jpg' ], $text( '/s:urlset/s:url[1]/image:image/image:loc' ) );
		$this->assertSame( [ '2026-10-05T12:00:00+00:00' ], $text( '//s:lastmod' ), 'none for an entry without a date' );
	}

	public function test_image_sitemap_requests(): void {

		$wp = new \stdClass();
		$wp->query_vars = [ 'pagename' => 'about' ];
		$this->assertNull( CoreSitemap::page( $wp ), 'another request' );
		$this->assertSame( [ 'pagename' => 'about' ], $wp->query_vars );

		// WordPress's sitemap off: a 404.
		$wp->query_vars = [ 'photopress-image-sitemap' => '1' ];
		$this->assertNull( CoreSitemap::page( $wp ) );
		$this->assertSame( [ 'error' => '404' ], $wp->query_vars );

		// On, but no page 0.
		$this->coreSitemap = true;
		$wp->query_vars = [ 'photopress-image-sitemap' => '0' ];
		$this->assertNull( CoreSitemap::page( $wp ) );
		$this->assertSame( [ 'error' => '404' ], $wp->query_vars );
	}

	public function test_jetpack_lists_term_archives_among_its_pages(): void {

		$this->assertSame( [ 'https://example.com/about/', 'https://example.com/photos_keywords/ojai/' ], JetpackSitemap::otherUrls( [ 'https://example.com/about/' ] ), 'not the taxonomy visitors do not see' );
		$this->assertArrayHasKey( 'jetpack', $this->options[ ImageSitemap::SEEN ] );

		$this->turnOff( 'taxonomy_pages' );
		$this->assertSame( [], JetpackSitemap::otherUrls( [] ) );
	}

	public function test_jetpack_lists_each_image_with_its_own_page_or_where_it_is_shown(): void {

		$item = static fn( string $loc ) => [ 'url' => [ 'loc' => $loc, 'image:image' => [ 'image:loc' => 'https://example.com/file.jpg' ] ] ];

		// Attached: Jetpack gives the post's page.
		$this->assertSame( 'https://example.com/image-9/', JetpackSitemap::imageItem( $item( 'https://example.com/post-50/' ), 9 )['url']['loc'] );
		$this->assertFalse( JetpackSitemap::skipImage( false, self::image( 8 ) ) );

		// Without pages: attached, the post's; unattached, its term's archive,
		// where Jetpack would give the file; with no term, left out.
		$this->turnOff( 'attachment_pages' );
		$this->assertSame( 'https://example.com/post-50/', JetpackSitemap::imageItem( $item( 'https://example.com/post-50/' ), 9 )['url']['loc'] );
		$this->assertSame( 'https://example.com/photos_keywords/ojai/', JetpackSitemap::imageItem( $item( 'https://example.com/file.jpg' ), 7 )['url']['loc'] );
		$this->assertFalse( JetpackSitemap::skipImage( false, self::image( 7 ) ) );
		$this->assertTrue( JetpackSitemap::skipImage( false, self::image( 8 ) ) );
		$this->assertFalse( JetpackSitemap::skipImage( false, self::image( 9 ) ) );
		$this->assertTrue( JetpackSitemap::skipImage( true, self::image( 9 ) ), 'skipped by another' );
	}

	public function test_yoast_leaves_out_pages_that_are_off(): void {

		$this->assertFalse( YoastSitemap::excludePostType( false, 'attachment' ) );
		$this->assertFalse( YoastSitemap::excludeTaxonomy( false, 'photos_keywords' ) );

		$this->turnOff( 'attachment_pages' );
		$this->turnOff( 'taxonomy_pages' );
		$this->assertTrue( YoastSitemap::excludePostType( false, 'attachment' ) );
		$this->assertFalse( YoastSitemap::excludePostType( false, 'post' ) );
		$this->assertTrue( YoastSitemap::excludeTaxonomy( false, 'photos_keywords' ) );
		$this->assertTrue( YoastSitemap::excludePostType( true, 'post' ), 'excluded by another' );
	}

	public function test_yoast_adds_its_sitemap_of_unattached_images_while_it_lists_media_pages(): void {

		$providers = YoastSitemap::providers( [] );
		$this->assertInstanceOf( YoastImagesSitemap::class, $providers[0] );
		$this->assertTrue( $providers[0]->handles_type( 'photopress-image' ) );
		$this->assertFalse( $providers[0]->handles_type( 'attachment' ) );

		// Yoast SEO's media pages off: none listed, and its build noted.
		self::$yoastMediaPages = false;
		$this->assertSame( [], $providers[0]->get_index_links( 1000 ) );
		$this->assertSame( [], $providers[0]->get_sitemap_links( 'photopress-image', 1000, 1 ) );
		$this->assertArrayHasKey( 'yoast', $this->options[ ImageSitemap::SEEN ] );

		// Excluded through Yoast SEO's own filter, as PhotoPress does while
		// attachment pages are off.
		self::$yoastMediaPages = true;
		Filters\expectApplied( 'wpseo_sitemap_exclude_post_type' )->with( false, 'attachment' )->andReturn( true );
		$this->assertSame( [], $providers[0]->get_index_links( 1000 ) );
	}

	public function test_which_sitemap_is_in_use(): void {

		$this->assertSame( [ 'sitemap' => 'other' ], ImageSitemap::inUse(), 'WordPress’s off, and no other built yet' );

		ImageSitemap::seen( 'jetpack' );
		$used = ImageSitemap::inUse();
		// The clock may tick between the two calls.
		$this->assertMatchesRegularExpression( '/^[01] seconds$/', $used['built'] );
		$this->assertSame( [ 'sitemap' => 'jetpack', 'url' => 'https://example.com/sitemap.xml' ], array_diff_key( $used, [ 'built' => 1 ] ) );

		// Built again within a day: not noted again.
		$this->options[ ImageSitemap::SEEN ] = [ 'jetpack' => time() - 3600, 'yoast' => time() - 40 * DAY_IN_SECONDS ];
		ImageSitemap::seen( 'jetpack' );
		$this->assertSame( time() - 3600, $this->options[ ImageSitemap::SEEN ]['jetpack'] );

		// The latest; and not one built more than 30 days ago.
		ImageSitemap::seen( 'yoast' );
		$this->assertSame( 'yoast', ImageSitemap::inUse()['sitemap'] );
		$this->options[ ImageSitemap::SEEN ] = [ 'yoast' => time() - 40 * DAY_IN_SECONDS ];
		$this->assertSame( 'other', ImageSitemap::inUse()['sitemap'] );

		$this->coreSitemap = true;
		$this->assertSame( [ 'sitemap' => 'core', 'url' => 'https://example.com/wp-sitemap.xml' ], ImageSitemap::inUse() );

		$this->options['blog_public'] = '0';
		$this->assertSame( [ 'sitemap' => 'none' ], ImageSitemap::inUse() );
	}
}
