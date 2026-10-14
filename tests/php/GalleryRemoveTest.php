<?php

namespace PhotoPress\Tests;

use PhotoPress\modules\gallery\GalleryRemove;
use PhotoPress\modules\gallery\GallerySlideshow;

/**
 * Taking a deleted image out of a post's galleries.
 */
final class GalleryRemoveTest extends TestCase {

	private static function image( int $id ): string {

		return sprintf( '<!-- wp:image {"id":%1$d,"sizeSlug":"large","linkDestination":"none"} -->' . "\n" . '<figure class="wp-block-image size-large"><img src="/up/%1$d.jpg" alt="" class="wp-image-%1$d"/></figure>' . "\n" . '<!-- /wp:image -->', $id );
	}

	private static function gallery( array $ids, string $attrs = '{"linkTo":"none"}' ): string {

		return "<!-- wp:gallery $attrs -->\n<figure class=\"wp-block-gallery has-nested-images columns-default is-cropped\">"
			. implode( "\n\n", array_map( [ self::class, 'image' ], $ids ) )
			. "</figure>\n<!-- /wp:gallery -->";
	}

	private static function blocks( string $content ): array {

		return ( new \WP_Block_Parser() )->parse( $content );
	}

	public function test_the_image_is_taken_out_with_its_separator(): void {

		$removed = 0;
		$blocks  = GalleryRemove::withoutImages( self::blocks( self::gallery( [ 10, 11, 12 ] ) ), [ 11 ], $removed );
		$expected = self::blocks( self::gallery( [ 10, 12 ] ) )[0];

		$this->assertSame( 1, $removed );
		$this->assertSame( [ 10, 12 ], GallerySlideshow::imageIds( $blocks[0] ) );
		$this->assertSame( $expected['innerContent'], $blocks[0]['innerContent'] );
	}

	public function test_the_first_and_last_images_can_go(): void {

		$blocks = GalleryRemove::withoutImages( self::blocks( self::gallery( [ 10, 11, 12 ] ) ), [ 10, 12 ] );

		$this->assertSame( self::blocks( self::gallery( [ 11 ] ) )[0]['innerContent'], $blocks[0]['innerContent'] );
	}

	public function test_a_gallery_left_with_no_images_stays_empty(): void {

		$removed = 0;
		$blocks  = GalleryRemove::withoutImages( self::blocks( self::gallery( [ 10 ] ) ), [ 10 ], $removed );

		$this->assertSame( 1, $removed );
		$this->assertSame( 'core/gallery', $blocks[0]['blockName'] );
		$this->assertSame( [], $blocks[0]['innerBlocks'] );
		$this->assertSame( [ "\n<figure class=\"wp-block-gallery has-nested-images columns-default is-cropped\">", "</figure>\n" ], $blocks[0]['innerContent'] );
	}

	public function test_galleries_at_any_depth_and_the_image_wherever_it_is(): void {

		$content = self::gallery( [ 10, 11 ] ) . '<!-- wp:group --><div class="wp-block-group">' . self::gallery( [ 11, 12 ] ) . '</div><!-- /wp:group -->';
		$removed = 0;
		$blocks  = GalleryRemove::withoutImages( self::blocks( $content ), [ 11 ], $removed );

		$this->assertSame( 2, $removed );
		$this->assertSame( [ 10 ], GallerySlideshow::imageIds( $blocks[0] ) );
		$this->assertSame( [ 12 ], GallerySlideshow::imageIds( $blocks[1]['innerBlocks'][0] ) );
	}

	public function test_an_image_without_an_id_is_found_by_its_class(): void {

		$content = str_replace( '"id":11,', '', self::gallery( [ 10, 11 ] ) );
		$blocks  = GalleryRemove::withoutImages( self::blocks( $content ), [ 11 ] );

		$this->assertCount( 1, $blocks[0]['innerBlocks'] );
	}

	public function test_other_images_and_blocks_are_left_alone(): void {

		$content = self::image( 11 ) . self::gallery( [ 110, 12 ] ) . self::gallery( [], '{"anchor":"dynamic","dynamicContent":{"source":"core/attached-media"}}' );
		$parsed  = self::blocks( $content );
		$removed = 0;

		// An image block outside a gallery, wp-image-110, and a dynamic gallery.
		$this->assertSame( $parsed, GalleryRemove::withoutImages( $parsed, [ 11 ], $removed ) );
		$this->assertSame( 0, $removed );
	}

	public function test_a_post_open_in_the_editor_is_tried_again_later_for_a_day(): void {

		\Brain\Monkey\Functions\stubs( [ 'as_has_scheduled_action' => false, 'get_option' => 'pending', 'did_action' => 1 ] );
		\Brain\Monkey\Functions\when( 'wp_check_post_lock' )->justReturn( 3 );
		\Brain\Monkey\Functions\expect( 'get_post' )->never();

		$scheduled = [];
		\Brain\Monkey\Functions\when( 'as_schedule_single_action' )->alias( static function ( $when, $hook, $args, $group ) use ( &$scheduled ) {
			$scheduled[] = [ $when - time(), $hook, $args, $group ];
			return 1;
		} );

		$since = time() - 60;
		$this->assertNull( GalleryRemove::removeUnlessLocked( 7, [ 11, 12 ], $since ) );
		$this->assertSame( [ [ 300, 'photopress_gallery_remove_retry', [ 7, [ 11, 12 ], $since ], 'photopress' ] ], $scheduled );

		// A day on, it is left.
		$scheduled = [];
		$this->assertNull( GalleryRemove::removeUnlessLocked( 7, [ 11 ], time() - 86400 ) );
		$this->assertSame( [], $scheduled );
	}

	public function test_a_retry_stops_once_the_setting_is_off(): void {

		\Brain\Monkey\Functions\expect( 'wp_check_post_lock' )->never();

		GalleryRemove::retry( 7, [ 11 ], time() );
	}
}
