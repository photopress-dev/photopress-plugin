<?php

namespace PhotoPress\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PhotoPress\modules\gallery\GalleryAdd;

/**
 * A post whose dynamic gallery shows other images, announced: an image
 * attached, moved or detached, or given the date or title the gallery
 * orders its images by.
 */
final class GalleryAddAttachmentTest extends TestCase {

	/** Posts announced, with their context. */
	private array $announced = [];

	protected function setUp(): void {

		parent::setUp();

		$this->announced = [];
		$announced = &$this->announced;

		Functions\stubs( [
			'wp_attachment_is_image' => static fn( $id ) => 7 === $id,
			// Posts 10 and 20 have dynamic galleries; 30 doesn't.
			'get_post_field'         => static fn( $field, $id ) => in_array( $id, [ 10, 20 ], true ) ? '<!-- wp:gallery {"dynamicContent":{"source":"core/attached-media"}} /-->' : '<!-- wp:paragraph /-->',
		] );

		Actions\expectDone( 'photopress_post_changed' )->zeroOrMoreTimes()->whenHappen( static function ( $id, $context ) use ( &$announced ) {
			$announced[] = [ $id, $context ];
		} );
	}

	private static function image( array $fields = [] ): object {

		return (object) ( $fields + [ 'post_parent' => 10, 'post_date' => '2026-10-01 12:00:00', 'post_title' => 'Ojai', 'post_excerpt' => '' ] );
	}

	public function test_moved_between_posts_both_dynamic_galleries(): void {

		GalleryAdd::attachmentUpdated( 7, self::image( [ 'post_parent' => 20 ] ), self::image() );
		GalleryAdd::attachmentUpdated( 7, self::image( [ 'post_parent' => 30 ] ), self::image() );

		$this->assertSame( [ [ 10, [ 'gallery' => true ] ], [ 20, [ 'gallery' => true ] ], [ 10, [ 'gallery' => true ] ] ], $this->announced );
	}

	public function test_a_date_or_title_its_gallery_orders_by(): void {

		GalleryAdd::attachmentUpdated( 7, self::image( [ 'post_date' => '2026-10-02 12:00:00' ] ), self::image() );
		GalleryAdd::attachmentUpdated( 7, self::image( [ 'post_title' => 'Ojai Valley' ] ), self::image() );
		GalleryAdd::attachmentUpdated( 7, self::image( [ 'post_excerpt' => 'A caption' ] ), self::image() );
		GalleryAdd::attachmentUpdated( 8, self::image( [ 'post_title' => 'A file' ] ), self::image(), 'not an image' );

		$this->assertSame( [ 10, 10 ], array_column( $this->announced, 0 ), 'not for a caption, or a file that is not an image' );
	}
}
