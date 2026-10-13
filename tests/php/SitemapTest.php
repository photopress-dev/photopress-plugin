<?php

namespace PhotoPress\Tests;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PhotoPress\modules\search\CoreSitemap;
use PhotoPress\modules\search\ImageSitemap;
use PhotoPress\modules\search\JetpackSitemap;
use PhotoPress\modules\search\YoastImagesSitemap;
use PhotoPress\modules\search\YoastSitemap;

/**
 * Image and term pages in the site's sitemap, whichever builds it, through
 * its documented filters: WordPress's, Jetpack's and Yoast SEO's, each given
 * what ImageSitemap decides; and which is in use.
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

		if ( ! interface_exists( '\WPSEO_Sitemap_Provider' ) ) {
			eval( 'interface WPSEO_Sitemap_Provider { public function handles_type( $type ); public function get_index_links( $max_entries ); public function get_sitemap_links( $type, $max_entries, $current_page ); }' );
			eval( 'class WPSEO_Post_Type { public static function is_post_type_accessible( $type ) { return \PhotoPress\Tests\SitemapTest::$yoastMediaPages; } public static function is_post_type_indexable( $type ) { return true; } }' );
		}

		self::$yoastMediaPages = true;
		$this->options = [ 'photopress_core_search' => [], 'blog_public' => '1', 'posts_per_page' => 10 ];
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

	public function test_core_lists_public_images_while_attachment_pages_are_on(): void {

		$this->assertSame( [ 'post', 'attachment' ], array_keys( CoreSitemap::postTypes( [ 'post' => (object) [] ] ) ) );

		$args = CoreSitemap::queryArgs( [ 'post_type' => 'attachment', 'post_status' => [ 'publish' ], 'orderby' => 'ID' ], 'attachment' );
		$this->assertSame( [ 'inherit', 'image', true, 'ID' ], [ $args['post_status'], $args['post_mime_type'], $args['photopress_public_images'], $args['orderby'] ] );
		$this->assertSame( [ 'post_status' => [ 'publish' ] ], CoreSitemap::queryArgs( [ 'post_status' => [ 'publish' ] ], 'post' ), 'other post types left alone' );

		$this->turnOff( 'attachment_pages' );
		$this->assertSame( [ 'post' ], array_keys( CoreSitemap::postTypes( [ 'post' => (object) [] ] ) ) );
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
		$this->assertSame( [ 'sitemap' => 'jetpack', 'url' => 'https://example.com/sitemap.xml', 'built' => '0 seconds' ], ImageSitemap::inUse() );

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
