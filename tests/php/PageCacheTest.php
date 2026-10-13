<?php

namespace PhotoPress\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PhotoPress\modules\search\PageCache;
use PhotoPress\modules\search\search;

/**
 * The page cache, through Proxy Cache Purge's documented hooks: a post's or
 * image's event when what it shows changed, and, as Proxy Cache Purge
 * purges it, the files given with it; for an image, its own page while
 * attachment pages are on and every page of its terms' archives while image
 * taxonomy pages are on.
 */
final class PageCacheTest extends TestCase {

	/** The Pages & Search settings saved. */
	private array $saved = [];

	/** Other options, by name. */
	private array $options = [];

	/**
	 * Proxy Cache Purge's purges, as it makes them on PhotoPress's events:
	 * [ ID, the URLs from its permalink through vhp_purge_urls ], or 'all'
	 * for the cache emptied.
	 */
	private array $purged = [];

	protected function setUp(): void {

		parent::setUp();

		$saved = &$this->saved;
		$options = &$this->options;
		$purged = &$this->purged;
		$this->saved = [];
		$this->options = [];
		$this->purged = [];

		// A new request.
		\Closure::bind( static function () {
			static::$purged   = [];
			static::$emptied  = false;
			static::$inBatch  = false;
			static::$heldBack = false;
		}, null, PageCache::class )();

		Functions\stubs( [
			'get_option'            => static function ( $name, $default = false ) use ( &$saved, &$options ) {
				// WordPress's own Attachment pages on, as on a site older than 6.4.
				return [ 'photopress_core_search' => $saved, 'wp_attachment_pages_enabled' => '1' ][ $name ] ?? $options[ $name ] ?? $default;
			},
			'update_option'         => static function ( $name, $value ) use ( &$options ) {
				$options[ $name ] = $value;
				return true;
			},
			'delete_option'         => static function ( $name ) use ( &$options ) {
				unset( $options[ $name ] );
				return true;
			},
			'get_rest_url'          => 'https://example.com/wp-json/',
			'get_object_taxonomies' => [ 'photos_keywords', 'pp_client' ],
			'get_taxonomy'          => static fn( $name ) => (object) [ 'object_type' => [ 'attachment' ], 'public' => 'pp_client' !== $name ],
			'wp_attachment_is_image' => static fn( $id ) => $id < 100,
			'is_taxonomy_viewable'  => true,
			'get_permalink'         => static fn( $id ) => $id < 100 ? "https://example.com/image-$id/" : "https://example.com/post-$id/",
			'get_term_link'         => static fn( $term ) => "https://example.com/keywords/{$term->slug}/",
			'get_the_terms'         => static fn( $id, $taxonomy ) => 'photos_keywords' === $taxonomy ? [ (object) [ 'taxonomy' => $taxonomy, 'slug' => 'ojai', 'term_id' => 3 ] ] : false,
		] );

		// Proxy Cache Purge, listening.
		add_action( PageCache::POST, 'purge_post' );
		Actions\expectDone( PageCache::POST )->zeroOrMoreTimes()->whenHappen( static function ( $id ) use ( &$purged ) {
			$purged[] = [ $id, PageCache::urls( [ get_permalink( $id ) ], $id ) ];
		} );
		Actions\expectDone( PageCache::ALL )->zeroOrMoreTimes()->whenHappen( static function () use ( &$purged ) {
			$purged[] = 'all';
		} );
	}

	/** What Proxy Cache Purge was asked to purge since. */
	private function purged(): array {

		$purged = $this->purged;
		$this->purged = [];

		return $purged;
	}

	/** photopress_image_terms_changed's context: these terms' archives. */
	private static function context( array $slugs, bool $job = false ): array {

		return [ 'archives' => array_map( static fn( $slug ) => "https://example.com/keywords/$slug/", $slugs ), 'job' => $job ];
	}

	public function test_proxy_cache_purge_has_the_events_and_purges_images(): void {

		$this->assertSame( [ 'save_post', PageCache::POST, PageCache::ALL ], PageCache::events( [ 'save_post' ] ) );
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

	public function test_a_post_or_image_changed_with_its_files(): void {

		PageCache::postChanged( 101 );
		PageCache::postChanged( 7, [ 'files' => [ 'https://example.com/ojai.jpg', 'https://example.com/ojai-300x200.jpg' ] ] );

		$this->assertSame( [
			[ 101, [ 'https://example.com/post-101/' ] ],
			[ 7, [ 'https://example.com/image-7/', 'https://example.com/keywords/ojai/?vhp-regex', 'https://example.com/ojai.jpg', 'https://example.com/ojai-300x200.jpg' ] ],
		], $this->purged() );
	}

	public function test_an_images_terms_changed(): void {

		// big-sur added.
		PageCache::imageTermsChanged( 7, self::context( [ 'big-sur' ] ) );
		$this->assertSame( [ [ 7, [ 'https://example.com/image-7/', 'https://example.com/keywords/ojai/?vhp-regex', 'https://example.com/keywords/big-sur/?vhp-regex' ] ] ], $this->purged() );

		// From the job: the cache was emptied when it started.
		PageCache::imageTermsChanged( 8, self::context( [ 'ojai' ], true ) );
		$this->assertSame( [], $this->purged() );
	}

	public function test_an_image_edited(): void {

		$before = (object) [ 'post_title' => 'Ojai', 'post_excerpt' => '', 'post_content' => '', 'post_name' => 'ojai' ];

		PageCache::imageEdited( 7, clone $before, $before );
		$this->assertSame( [], $this->purged(), 'nothing visitors see changed' );

		PageCache::imageEdited( 7, (object) ( [ 'post_title' => 'Ojai at dusk' ] + (array) $before ), $before );
		$this->assertSame( [ [ 7, [ 'https://example.com/image-7/', 'https://example.com/keywords/ojai/?vhp-regex' ] ] ], $this->purged() );
	}

	public function test_once_a_request_unless_with_more_and_the_cache_emptied_once(): void {

		PageCache::purge( 7 );
		PageCache::purge( 7 );
		PageCache::purge( 7, [ 'https://example.com/keywords/big-sur/' ] );
		PageCache::purge( 7, [], [ 'https://example.com/ojai.jpg' ] );
		$this->assertSame( [ 7, 7, 7 ], array_column( $this->purged(), 0 ) );

		PageCache::purgeAll();
		PageCache::purgeAll();
		$this->assertSame( [ 'all' ], $this->purged() );
	}

	public function test_nothing_without_image_pages_or_proxy_cache_purge(): void {

		$this->saved = [ 'attachment_pages' => false, 'taxonomy_pages' => false ];
		PageCache::purge( 7 );
		PageCache::purgeAll();
		PageCache::purge( 101 );
		$this->assertSame( [ [ 101, [ 'https://example.com/post-101/' ] ] ], $this->purged(), 'a post’s, still' );

		$this->saved = [];
		remove_action( PageCache::POST, 'purge_post' );
		PageCache::purge( 8 );
		$this->assertSame( [], $this->purged() );
	}

	public function test_proxy_cache_purge_active_installed_or_not(): void {

		$plugins = [ 'akismet/akismet.php' => [] ];
		Functions\when( 'get_plugins' )->alias( static function () use ( &$plugins ) { return $plugins; } );

		$this->assertTrue( PageCache::purges() );
		$this->assertSame( 'active', \photopress_util::pluginState( PageCache::purges(), [ 'varnish-http-purge/' ] ) );
		$this->assertSame( 'missing', \photopress_util::pluginState( false, [ 'varnish-http-purge/' ] ) );
		$plugins['varnish-http-purge/varnish-http-purge.php'] = [];
		$this->assertSame( 'inactive', \photopress_util::pluginState( false, [ 'varnish-http-purge/' ] ) );
	}

	public function test_an_images_terms_rest_addresses_left_out_as_page_caches_pass_them_by(): void {

		$urls = [ 'https://example.com/image-7/', 'https://example.com/wp-json/wp/v2/media/7/', 'https://example.com/wp-json/wp/v2/photos_keywords/3/', 'https://example.com/wp-json/wp/v2/categories/3/' ];

		$this->assertSame(
			[ 'https://example.com/image-7/', 'https://example.com/wp-json/wp/v2/media/7/', 'https://example.com/wp-json/wp/v2/categories/3/', 'https://example.com/keywords/ojai/?vhp-regex' ],
			PageCache::urls( $urls, 7 ),
			'its own, and other taxonomies’, kept'
		);
		$this->assertSame( $urls, PageCache::urls( $urls, 101 ), 'a post’s, as they were' );
	}

	public function test_a_jobs_batches_hold_back_their_purges_for_the_cache_emptied_once_the_job_ends(): void {

		$job = [ 'id' => 'j1', 'type' => 'metadata.reprocess', 'status' => 'running' ];

		PageCache::batchStarted( $job );
		$this->assertSame( [ 'publish' ], PageCache::statuses( [ 'publish' ], 7 ), 'nor does Proxy Cache Purge purge images itself' );
		PageCache::imageEdited( 7, (object) [ 'post_title' => 'New', 'post_excerpt' => '', 'post_content' => '', 'post_name' => '' ], (object) [ 'post_title' => 'Old', 'post_excerpt' => '', 'post_content' => '', 'post_name' => '' ] );
		PageCache::imageTermsChanged( 8, self::context( [ 'ojai' ] ) );
		PageCache::batchEnded( $job );
		$this->assertSame( [], $this->purged() );

		// A later batch changing nothing; then the job's end.
		PageCache::batchStarted( $job );
		PageCache::batchEnded( $job );
		PageCache::jobEnded( [ 'status' => 'done' ] + $job );
		$this->assertSame( [ 'all' ], $this->purged() );
		$this->assertArrayNotHasKey( PageCache::AFTER_JOBS, $this->options );

		// Outside a batch, as before.
		PageCache::postChanged( 101 );
		$this->assertSame( [ [ 101, [ 'https://example.com/post-101/' ] ] ], $this->purged() );
	}

	public function test_a_job_that_changed_no_page_empties_nothing_and_one_cancelled_mid_batch_empties_it_at_once(): void {

		PageCache::batchStarted( [ 'id' => 'j2', 'status' => 'running' ] );
		PageCache::batchEnded( [ 'id' => 'j2', 'status' => 'running' ] );
		PageCache::jobEnded( [ 'id' => 'j2', 'status' => 'done' ] );
		$this->assertSame( [], $this->purged() );

		PageCache::batchStarted( [ 'id' => 'j3', 'status' => 'running' ] );
		PageCache::postChanged( 7 );
		PageCache::batchEnded( [ 'id' => 'j3', 'status' => 'cancelled' ] );
		$this->assertSame( [ 'all' ], $this->purged() );
	}

	public function test_the_cache_is_emptied_once_after_photopress_is_updated(): void {

		$this->options[ PageCache::VERSION ] = '1.9.0';

		PageCache::versionChanged();
		$this->assertSame( [ 'all' ], $this->purged() );
		$this->assertSame( PHOTOPRESS_CORE_VERSION, $this->options[ PageCache::VERSION ] );

		// The next request: the same version.
		( new \ReflectionProperty( PageCache::class, 'emptied' ) )->setValue( null, false );
		PageCache::versionChanged();
		$this->assertSame( [], $this->purged() );
	}
}
