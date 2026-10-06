<?php

namespace PhotoPress\Tests;

use Brain\Monkey\Functions;
use PhotoPress\modules\gallery\GallerySlideshow;

final class GallerySlideshowTest extends TestCase {

	/**
	 * A post with an anchored gallery of three images (as WordPress saves
	 * it: the anchor only in the markup) inside a group, and the slideshow.
	 */
	private const CONTENT = '<!-- wp:group --><div class="wp-block-group">'
		. '<!-- wp:gallery {"linkTo":"none"} --><figure class="wp-block-gallery has-nested-images" id="main-gallery">'
		. '<!-- wp:image {"id":10} --><figure class="wp-block-image"><img src="a.jpg" class="wp-image-10"/></figure><!-- /wp:image -->'
		. '<!-- wp:image {"id":11} --><figure class="wp-block-image"><img src="b.jpg" class="wp-image-11"/></figure><!-- /wp:image -->'
		. '<!-- wp:image {"id":12} --><figure class="wp-block-image"><img src="c.jpg" class="wp-image-12"/></figure><!-- /wp:image -->'
		. '</figure><!-- /wp:gallery -->'
		. '</div><!-- /wp:group -->';

	protected function setUp(): void {

		parent::setUp();

		Functions\stubs( [
			'parse_blocks'               => static fn( $content ) => ( new \WP_Block_Parser() )->parse( $content ),
			'get_post'                   => static fn( $id ) => 5 === $id ? (object) [ 'ID' => 5, 'post_content' => self::CONTENT ] : null,
			'get_the_ID'                 => 0,
			'sanitize_key'               => static fn( $key ) => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $key ) ),
			'esc_attr__'                 => static fn( $text ) => $text,
			'wp_kses_post'               => static fn( $html ) => $html,
			'wp_get_attachment_caption'  => static fn( $id ) => 11 === $id ? 'Bob at the lake' : '',
			'wp_get_attachment_image'    => static fn( $id, $size, $icon, $attr ) => sprintf(
				'<img width="1200" height="800" src="img-%1$d-%2$s.jpg" srcset="img-%1$d-%2$s.jpg 1200w" class="%3$s" loading="%4$s"/>',
				$id, $size, $attr['class'], $attr['loading'] ?: 'lazy'
			),
			'get_block_wrapper_attributes' => static function ( $extra ) {
				$out = 'class="wp-block-photopress-gallery-slideshow ' . $extra['class'] . '"';
				unset( $extra['class'] );
				foreach ( $extra as $name => $value ) {
					$out .= sprintf( ' %s="%s"', $name, htmlspecialchars( $value, ENT_QUOTES ) );
				}
				return $out;
			},
		] );
	}

	private function render( array $attributes ): string {

		$block = (object) [ 'context' => [ 'postId' => 5 ] ];

		return GallerySlideshow::render( $attributes + [ 'galleryAnchor' => 'main-gallery', 'showCaptions' => true, 'galleryNavigation' => true ], '', $block );
	}

	public function test_slides_follow_the_gallery_found_by_its_markup_anchor(): void {

		$p = new \WP_HTML_Tag_Processor( $this->render( [ 'maxHeightOffset' => 150, 'scrollBehavior' => 'smooth' ] ) );

		$p->next_tag();
		$this->assertTrue( $p->has_class( 'is-effect-slide' ) );
		$this->assertSame( 'main-gallery', $p->get_attribute( 'data-gallery' ) );
		$this->assertSame( '1', $p->get_attribute( 'data-gallery-navigation' ) );
		$this->assertSame( 'smooth', $p->get_attribute( 'data-scroll-behavior' ) );
		$this->assertSame( '--pp-slideshow-offset:150px', $p->get_attribute( 'style' ) );

		$ids = [];
		while ( $p->next_tag( [ 'class_name' => 'photopress-gallery-slideshow__slide' ] ) ) {
			$ids[] = $p->get_attribute( 'data-id' );
		}
		$this->assertSame( [ '10', '11', '12' ], $ids );
	}

	public function test_only_the_first_slide_loads_its_image(): void {

		$p = new \WP_HTML_Tag_Processor( $this->render( [] ) );

		$p->next_tag( 'img' );
		$this->assertSame( 'img-10-large.jpg', $p->get_attribute( 'src' ) );
		$this->assertSame( 'eager', $p->get_attribute( 'loading' ) );
		$this->assertSame( 'aspect-ratio:1200/800', $p->get_attribute( 'style' ) );

		$p->next_tag( 'img' );
		$this->assertSame( GallerySlideshow::PLACEHOLDER, $p->get_attribute( 'src' ) );
		$this->assertSame( 'img-11-large.jpg', $p->get_attribute( 'data-src' ) );
		$this->assertSame( 'img-11-large.jpg 1200w', $p->get_attribute( 'data-srcset' ) );
		$this->assertNull( $p->get_attribute( 'srcset' ) );
	}

	public function test_captions_and_controls(): void {

		$html = $this->render( [] );

		$this->assertSame( 1, substr_count( $html, '<figcaption' ) );
		$this->assertStringContainsString( 'Bob at the lake</figcaption>', $html );
		$this->assertStringContainsString( 'class="photopress-gallery-slideshow__return" aria-label="Return to gallery image" hidden', $html );

		$this->assertStringNotContainsString( '<figcaption', $this->render( [ 'showCaptions' => false ] ) );
	}

	public function test_nothing_without_a_matching_gallery(): void {

		$this->assertSame( '', $this->render( [ 'galleryAnchor' => 'other' ] ) );
		$this->assertSame( '', $this->render( [ 'galleryAnchor' => '' ] ) );
	}

	public function test_galleries_from_before_5_9_list_their_ids(): void {

		$this->assertSame( [ 3, 4 ], GallerySlideshow::imageIds( [ 'attrs' => [ 'ids' => [ '3', 4, 3 ] ], 'innerBlocks' => [] ] ) );
	}
}
