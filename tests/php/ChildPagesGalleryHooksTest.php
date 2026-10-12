<?php

namespace PhotoPress\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PhotoPress\modules\gallery\ChildPagesGallery;

/**
 * The pages a Child Pages Gallery is on, announced as changed when one of
 * their child pages changes in a way it shows.
 */
final class ChildPagesGalleryHooksTest extends TestCase {

	/** Pages announced as changed. */
	private array $announced = [];

	/** Page contents, by ID: 10 and 20 show their child pages, 30 doesn't. */
	private array $contents = [];

	/** Templates edited in the Site Editor. */
	private array $templates = [];

	protected function setUp(): void {

		parent::setUp();

		// The posts here are WordPress's, as hooks pass them.
		require_once ABSPATH . WPINC . '/class-wp-post.php';

		$this->announced = [];
		$this->contents = [
			10 => '<!-- wp:gallery {"photopressSource":"children","photopressLayout":"masonry"} /-->',
			20 => '<!-- wp:photopress/childpages {"columns":3} /-->',
			30 => '<!-- wp:paragraph --><p>No child pages.</p><!-- /wp:paragraph -->',
		];
		$this->templates = [];
		$announced = &$this->announced;
		$contents = &$this->contents;
		$templates = &$this->templates;
		self::newRequest();

		Functions\stubs( [
			'is_post_type_hierarchical' => static fn( $type ) => 'page' === $type,
			'wp_is_post_revision'       => false,
			// Page 6 has a featured image.
			'has_post_thumbnail'        => static fn( $post ) => 6 === $post->ID,
			'get_post_field'            => static fn( $field, $id ) => $contents[ $id ] ?? '',
			'get_posts'                 => static function () use ( &$templates ) { return $templates; },
		] );

		// Announced with gallery would show as "10 gallery": it would go on up.
		Actions\expectDone( 'photopress_post_changed' )->zeroOrMoreTimes()->whenHappen( static function ( $id, $context = [] ) use ( &$announced ) {
			$announced[] = empty( $context['gallery'] ) ? $id : "$id gallery";
		} );
	}

	/** What PhotoPress remembers for a request, forgotten. */
	private static function newRequest(): void {

		\Closure::bind( static function () { static::$inTemplates = null; }, null, ChildPagesGallery::class )();
	}

	private static function page( array $fields = [] ): \WP_Post {

		return new \WP_Post( (object) ( $fields + [ 'ID' => 5, 'post_type' => 'page', 'post_status' => 'publish', 'post_parent' => 10, 'post_title' => 'Ojai', 'post_name' => 'ojai', 'menu_order' => 1, 'post_content' => '' ] ) );
	}

	private function announced(): array {

		$announced = $this->announced;
		$this->announced = [];

		return $announced;
	}

	public function test_moved_both_parents_that_show_their_child_pages(): void {

		ChildPagesGallery::pageSaved( 5, self::page( [ 'post_parent' => 20 ] ), true, self::page() );
		$this->assertSame( [ 10, 20 ], $this->announced() );

		ChildPagesGallery::pageSaved( 5, self::page( [ 'post_parent' => 30 ] ), true, self::page() );
		$this->assertSame( [ 10 ], $this->announced(), 'not the one that does not show them' );
	}

	public function test_published_unpublished_and_what_the_gallery_shows(): void {

		ChildPagesGallery::pageSaved( 5, self::page(), false, null );
		ChildPagesGallery::pageSaved( 5, self::page( [ 'post_status' => 'trash' ] ), true, self::page() );
		ChildPagesGallery::pageSaved( 5, self::page( [ 'post_title' => 'Ojai Valley' ] ), true, self::page() );
		ChildPagesGallery::pageSaved( 5, self::page( [ 'menu_order' => 3 ] ), true, self::page() );
		$this->assertSame( [ 10, 10, 10, 10 ], $this->announced() );

		// Its content: only without a featured image, whose gallery it may change.
		ChildPagesGallery::pageSaved( 5, self::page( [ 'post_content' => '<!-- wp:gallery /-->' ] ), true, self::page() );
		ChildPagesGallery::pageSaved( 6, self::page( [ 'ID' => 6, 'post_content' => '<!-- wp:gallery /-->' ] ), true, self::page( [ 'ID' => 6 ] ) );
		$this->assertSame( [ 10 ], $this->announced() );
	}

	public function test_nothing_for_drafts_posts_or_saves_that_change_nothing_shown(): void {

		ChildPagesGallery::pageSaved( 5, self::page( [ 'post_status' => 'draft', 'post_title' => 'New' ] ), true, self::page( [ 'post_status' => 'draft' ] ) );
		ChildPagesGallery::pageSaved( 5, self::page( [ 'post_type' => 'post', 'post_parent' => 20 ] ), true, self::page( [ 'post_type' => 'post' ] ) );
		ChildPagesGallery::pageSaved( 5, self::page(), true, self::page() );

		$this->assertSame( [], $this->announced() );
	}

	public function test_a_featured_image_changed_or_the_page_deleted(): void {

		Functions\when( 'get_post' )->justReturn( self::page() );

		ChildPagesGallery::metaChanged( [ 1 ], 5, '_thumbnail_id' );
		ChildPagesGallery::metaChanged( [ 1 ], 5, '_edit_lock' );
		ChildPagesGallery::pageDeleted( 5, self::page() );

		$this->assertSame( [ 10, 10 ], $this->announced() );
	}

	public function test_its_gallery_changed_without_a_featured_image_one_level_up(): void {

		Functions\when( 'get_post' )->alias( static fn( $id ) => self::page( [ 'ID' => $id ] ) );

		ChildPagesGallery::galleryChanged( 5, [ 'gallery' => true ] );
		ChildPagesGallery::galleryChanged( 6, [ 'gallery' => true ] );
		ChildPagesGallery::galleryChanged( 5, [] );

		// Page 6 has a featured image; and no gallery, nothing. The parent is
		// announced without gallery, so it goes no further.
		$this->assertSame( [ 10 ], $this->announced() );
	}

	public function test_a_template_edited_to_show_child_pages_counts_for_every_page(): void {

		$this->assertFalse( ChildPagesGallery::showsChildPages( 30 ) );

		$this->templates = [ (object) [ 'post_content' => '<!-- wp:gallery {"photopressSource":"children"} /-->' ] ];
		$this->assertFalse( ChildPagesGallery::showsChildPages( 30 ), 'looked up once a request' );

		self::newRequest();
		$this->assertTrue( ChildPagesGallery::showsChildPages( 30 ) );
	}
}
