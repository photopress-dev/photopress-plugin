<?php

namespace PhotoPress\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PhotoPress\modules\search\PageCache;
use PhotoPress\modules\search\search;

/**
 * The page cache, through Proxy Cache Purge's documented hooks: an image's
 * event fired at the end of a request in which it was edited or its terms
 * changed, and, as Proxy Cache Purge purges an image, its own page while
 * attachment pages are on and every page of its terms' archives while image
 * taxonomy pages are on.
 */
final class PageCacheTest extends TestCase {

	/** The Pages & Search settings saved. */
	private array $saved = [];

	/**
	 * Proxy Cache Purge's purges, as it makes them on PhotoPress's events:
	 * by image ID, the URLs from its permalink through vhp_purge_urls; or
	 * 'all' for the cache emptied.
	 */
	private array $purged = [];

	protected function setUp(): void {

		parent::setUp();

		$saved = &$this->saved;
		$purged = &$this->purged;
		$this->saved = [];
		$this->purged = [];

		Functions\stubs( [
			'get_option'            => static function ( $name, $default = false ) use ( &$saved ) {
				return 'photopress_core_search' === $name ? $saved : $default;
			},
			'get_object_taxonomies' => [ 'photos_keywords', 'pp_client' ],
			'get_taxonomy'          => static fn( $name ) => (object) [ 'object_type' => [ 'attachment' ], 'public' => 'pp_client' !== $name ],
			'wp_attachment_is_image' => static fn( $id ) => $id < 100,
			'is_taxonomy_viewable'  => true,
			'get_permalink'         => static fn( $id ) => "https://example.com/image-$id/",
			'get_term_link'         => static fn( $term ) => "https://example.com/keywords/{$term->slug}/",
			'get_the_terms'         => static fn( $id, $taxonomy ) => 'photos_keywords' === $taxonomy ? [ (object) [ 'taxonomy' => $taxonomy, 'slug' => 'ojai' ] ] : false,
		] );

		// Proxy Cache Purge, listening.
		add_action( PageCache::IMAGE, 'purge_post' );
		Actions\expectDone( PageCache::IMAGE )->zeroOrMoreTimes()->whenHappen( static function ( $id ) use ( &$purged ) {
			$purged[ $id ] = PageCache::urls( [ get_permalink( $id ) ], $id );
		} );
		Actions\expectDone( PageCache::ALL )->zeroOrMoreTimes()->whenHappen( static function () use ( &$purged ) {
			$purged[] = 'all';
		} );
	}

	/** The end of the request: what Proxy Cache Purge was asked to purge. */
	private function purged(): array {

		PageCache::fire();
		$purged = $this->purged;
		$this->purged = [];

		return $purged;
	}

	public function test_proxy_cache_purge_has_the_events_and_purges_images(): void {

		$this->assertSame( [ 'save_post', PageCache::IMAGE, PageCache::ALL ], PageCache::events( [ 'save_post' ] ) );
		$this->assertSame( [ 'switch_theme', PageCache::ALL ], PageCache::fullEvents( [ 'switch_theme' ] ) );
		$this->assertSame( [ 'publish', 'inherit' ], PageCache::statuses( [ 'publish' ], 7 ) );
		$this->assertSame( [ 'publish' ], PageCache::statuses( [ 'publish' ], 101 ), 'not for posts' );
	}

	public function test_an_images_purge_has_every_page_of_its_archives_and_its_page_while_there_are_pages(): void {

		$urls = [ 'https://example.com/image-7/', 'https://example.com/keywords/ojai/', 'https://example.com/' ];

		$this->assertSame( [ 'https://example.com/image-7/', 'https://example.com/', 'https://example.com/keywords/ojai/?vhp-regex' ], PageCache::urls( $urls, 7 ) );
		$this->assertSame( $urls, PageCache::urls( $urls, 101 ), 'a post’s, as they were' );

		$this->saved = [ 'attachment_pages' => false, 'taxonomy_pages' => false ];
		$this->assertSame( [ 'https://example.com/keywords/ojai/', 'https://example.com/' ], PageCache::urls( $urls, 7 ) );
	}

	/** photopress_image_terms_changed's context: these terms' archives. */
	private static function context( array $slugs, bool $job = false ): array {

		return [ 'archives' => array_map( static fn( $slug ) => "https://example.com/keywords/$slug/", $slugs ), 'job' => $job ];
	}

	public function test_an_images_terms_changed(): void {

		// big-sur added.
		PageCache::imageTermsChanged( 7, self::context( [ 'big-sur' ] ) );
		$this->assertSame( [ 7 => [ 'https://example.com/image-7/', 'https://example.com/keywords/big-sur/?vhp-regex', 'https://example.com/keywords/ojai/?vhp-regex' ] ], $this->purged() );

		// Both removed: the archives of terms it no longer has.
		PageCache::imageTermsChanged( 7, self::context( [ 'ojai', 'big-sur' ] ) );
		$this->assertSame( [ 7 => [ 'https://example.com/image-7/', 'https://example.com/keywords/ojai/?vhp-regex', 'https://example.com/keywords/big-sur/?vhp-regex' ] ], $this->purged() );

		// From the job: the cache was emptied when it started. A post.
		PageCache::imageTermsChanged( 7, self::context( [ 'ojai' ], true ) );
		PageCache::imageTermsChanged( 101, self::context( [ 'ojai' ] ) );
		$this->assertSame( [], $this->purged() );
	}

	public function test_an_image_edited(): void {

		$before = (object) [ 'post_title' => 'Ojai', 'post_excerpt' => '', 'post_content' => '', 'post_name' => 'ojai' ];

		PageCache::imageEdited( 7, clone $before, $before );
		$this->assertSame( [], $this->purged(), 'nothing visitors see changed' );

		PageCache::imageEdited( 7, (object) ( [ 'post_title' => 'Ojai at dusk' ] + (array) $before ), $before );
		$this->assertSame( [ 7 => [ 'https://example.com/image-7/', 'https://example.com/keywords/ojai/?vhp-regex' ] ], $this->purged() );
	}

	public function test_once_an_image_at_the_end_of_the_request_or_the_cache_emptied(): void {

		PageCache::imageTermsChanged( 7, self::context( [ 'ojai' ] ) );
		PageCache::imageTermsChanged( 7, self::context( [ 'big-sur' ] ) );
		PageCache::imageTermsChanged( 8, self::context( [ 'ojai' ] ) );

		$this->assertSame( 5, has_action( 'shutdown', [ PageCache::class, 'fire' ] ) );
		$this->assertSame( [ 7, 8 ], array_keys( $this->purged() ) );

		PageCache::imageTermsChanged( 7, self::context( [ 'ojai' ] ) );
		PageCache::purgeAll();
		$this->assertSame( [ 'all' ], $this->purged() );
	}

	public function test_nothing_without_pages_or_proxy_cache_purge(): void {

		$this->saved = [ 'attachment_pages' => false, 'taxonomy_pages' => false ];
		PageCache::imageTermsChanged( 7, self::context( [ 'big-sur' ] ) );
		PageCache::purgeAll();
		$this->assertSame( [], $this->purged() );

		$this->saved = [];
		remove_action( PageCache::IMAGE, 'purge_post' );
		PageCache::imageTermsChanged( 7, self::context( [ 'big-sur' ] ) );
		$this->assertSame( [], $this->purged() );
	}

	public function test_proxy_cache_purge_active_installed_or_not(): void {

		$plugins = [ 'akismet/akismet.php' => [] ];
		Functions\when( 'get_plugins' )->alias( static function () use ( &$plugins ) { return $plugins; } );

		$this->assertTrue( PageCache::purges() );
		$this->assertSame( 'active', search::pluginState( PageCache::purges(), [ 'varnish-http-purge/' ] ) );
		$this->assertSame( 'missing', search::pluginState( false, [ 'varnish-http-purge/' ] ) );
		$plugins['varnish-http-purge/varnish-http-purge.php'] = [];
		$this->assertSame( 'inactive', search::pluginState( false, [ 'varnish-http-purge/' ] ) );
	}
}
