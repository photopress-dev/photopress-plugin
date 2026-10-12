<?php

namespace PhotoPress\Tests;

use Brain\Monkey\Functions;
use PhotoPress\modules\search\PageCache;
use PhotoPress\modules\search\search;

/**
 * The pages PhotoPress clears through Proxy Cache Purge: an image's page,
 * while attachment pages are on, and every page of its terms' archives,
 * while image taxonomy pages are on.
 */
final class PageCacheTest extends TestCase {

	/** The Pages & Search settings saved. */
	private array $saved = [];

	protected function setUp(): void {

		parent::setUp();

		require_once __DIR__ . '/fixtures/plugins/VarnishPurger.php';
		\VarnishPurger::$purged = [];

		$saved = &$this->saved;
		$this->saved = [];

		Functions\stubs( [
			'get_option'            => static function ( $name, $default = false ) use ( &$saved ) {
				return 'photopress_core_search' === $name ? $saved : $default;
			},
			'get_object_taxonomies' => [ 'photos_keywords', 'pp_client' ],
			'get_taxonomy'          => static fn( $name ) => (object) [ 'object_type' => [ 'attachment' ], 'public' => 'pp_client' !== $name ],
			'get_post_type'         => static fn( $id ) => $id < 100 ? 'attachment' : 'post',
			'is_taxonomy_viewable'  => true,
			'get_permalink'         => static fn( $id ) => "https://example.com/image-$id/",
			'get_term_link'         => static fn( $term ) => "https://example.com/keywords/{$term->slug}/",
			// term_taxonomy_id 31 is ojai, 32 big-sur.
			'get_term_by'           => static fn( $field, $tt_id ) => (object) [ 'taxonomy' => 'photos_keywords', 'slug' => [ 31 => 'ojai', 32 => 'big-sur' ][ $tt_id ] ],
			'get_the_terms'         => static fn( $id, $taxonomy ) => 'photos_keywords' === $taxonomy ? [ (object) [ 'taxonomy' => $taxonomy, 'slug' => 'ojai' ] ] : false,
			'wp_attachment_is_image' => true,
		] );
	}

	private function purged(): array {

		PageCache::purge();
		$purged = \VarnishPurger::$purged;
		\VarnishPurger::$purged = [];

		return $purged;
	}

	public function test_an_image_joining_a_term_or_leaving_terms(): void {

		// big-sur added.
		PageCache::termsChanged( 7, 32, 'photos_keywords' );
		$this->assertSame( [ 'https://example.com/image-7/', 'https://example.com/keywords/big-sur/?vhp-regex' ], $this->purged() );

		// Both removed.
		PageCache::termsChanged( 7, [ 31, 32 ], 'photos_keywords' );
		$this->assertSame( [ 'https://example.com/image-7/', 'https://example.com/keywords/ojai/?vhp-regex', 'https://example.com/keywords/big-sur/?vhp-regex' ], $this->purged() );

		// A taxonomy visitors don't see; a post.
		PageCache::termsChanged( 7, [ 40 ], 'pp_client' );
		PageCache::termsChanged( 101, 31, 'photos_keywords' );
		$this->assertSame( [], $this->purged() );
	}

	public function test_image_pages_only_while_attachment_pages_are_on_and_archives_while_taxonomy_pages_are(): void {

		$this->saved = [ 'attachment_pages' => false ];
		PageCache::termsChanged( 7, 32, 'photos_keywords' );
		$this->assertSame( [ 'https://example.com/keywords/big-sur/?vhp-regex' ], $this->purged() );

		$this->saved = [ 'taxonomy_pages' => false ];
		PageCache::termsChanged( 7, 32, 'photos_keywords' );
		$this->assertSame( [ 'https://example.com/image-7/' ], $this->purged() );
	}

	public function test_an_image_edited_or_deleted(): void {

		$before = (object) [ 'post_title' => 'Ojai', 'post_excerpt' => '', 'post_content' => '', 'post_name' => 'ojai' ];

		PageCache::imageEdited( 7, clone $before, $before );
		$this->assertSame( [], $this->purged(), 'nothing visitors see changed' );

		PageCache::imageEdited( 7, (object) ( [ 'post_title' => 'Ojai at dusk' ] + (array) $before ), $before );
		$this->assertSame( [ 'https://example.com/image-7/', 'https://example.com/keywords/ojai/?vhp-regex' ], $this->purged() );

		PageCache::imageDeleted( 7 );
		$this->assertSame( [ 'https://example.com/image-7/', 'https://example.com/keywords/ojai/?vhp-regex' ], $this->purged() );
	}

	public function test_each_page_is_cleared_once_at_the_end_of_the_request(): void {

		PageCache::imageDeleted( 7 );
		PageCache::termsChanged( 7, 31, 'photos_keywords' );

		$this->assertNotFalse( has_action( 'shutdown', [ PageCache::class, 'purge' ] ) );
		$this->assertSame( [ 'https://example.com/image-7/', 'https://example.com/keywords/ojai/?vhp-regex' ], $this->purged() );
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
