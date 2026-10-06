<?php

namespace PhotoPress\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PhotoPress\modules\slideshow\slideshow;

final class SlideshowTest extends TestCase {

	private const LEGACY = '<div class="wp-block"><figure class="photopress-gallery"><ul class="photopress-gallery-masonry"><li class="photopress-gallery-item" data-id="1"><img data-position="0" src="a.jpg"></li></ul></figure></div>';
	private const CORE = '<figure class="wp-block-gallery has-nested-images"><figure class="wp-block-image"><img src="a.jpg"></figure></figure>';

	private slideshow $slideshow;

	protected function setUp(): void {

		parent::setUp();

		$this->slideshow = ( new \ReflectionClass( slideshow::class ) )->newInstanceWithoutConstructor();

		Functions\stubs( [
			'plugins_url'       => static fn( $path = '' ) => 'https://example.test/' . $path,
			'wp_register_style' => null,
			'wp_enqueue_style'  => null,
			'wp_enqueue_script' => null,
		] );
	}

	private function render( string $name, array $attrs, string $html ): string {

		return $this->slideshow->render_slideshow( $html, [ 'blockName' => $name, 'attrs' => $attrs ] );
	}

	public function test_legacy_gallery_with_the_slideshow_on_is_marked(): void {

		$out = $this->render( 'photopress/gallery', [ 'linkToSlideshow' => true ], self::LEGACY );

		$this->assertStringContainsString( 'class="photopress-gallery photopress-has-slideshow"', $out );
	}

	public function test_legacy_gallery_with_the_slideshow_switched_off_is_not(): void {

		// Saved as false once the toggle is switched off; isset() treated that as on.
		$this->assertSame( self::LEGACY, $this->render( 'photopress/gallery', [ 'linkToSlideshow' => false ], self::LEGACY ) );
		$this->assertSame( self::LEGACY, $this->render( 'photopress/gallery', [], self::LEGACY ) );
	}

	public function test_core_gallery_with_the_slideshow_on_is_marked_with_or_without_a_layout(): void {

		$this->assertSame( self::CORE, $this->render( 'core/gallery', [ 'photopressLayout' => 'rows' ], self::CORE ) );

		$this->assertStringContainsString( 'photopress-has-slideshow', $this->render( 'core/gallery', [ 'photopressSlideshow' => true ], self::CORE ) );
		$this->assertStringContainsString( 'photopress-has-slideshow', $this->render( 'core/gallery', [ 'photopressLayout' => 'rows', 'photopressSlideshow' => true ], self::CORE ) );
	}

	public function test_other_blocks_are_untouched(): void {

		$this->assertSame( '<p>x</p>', $this->render( 'core/paragraph', [ 'linkToSlideshow' => true ], '<p>x</p>' ) );
	}

	public function test_one_lightbox_per_page_however_many_galleries(): void {

		Actions\expectAdded( 'wp_footer' )->once();

		$this->render( 'photopress/gallery', [ 'linkToSlideshow' => true ], self::LEGACY );
		$this->render( 'core/gallery', [ 'photopressLayout' => 'rows', 'photopressSlideshow' => true ], self::CORE );
	}

	public function test_lightbox_options_are_escaped(): void {

		\pp_api::$options['core/slideshow/attachmentLinkText'] = '"><script>alert(1)</script>';
		\pp_api::$options['core/slideshow/detail_components'] = [ 'title', '"x"' ];

		ob_start();
		$this->slideshow->printLightbox();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( 'data-attachmentlinktext="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $html );
		$this->assertSame( 1, substr_count( $html, 'id="lightbox-gallery"' ) );
	}

	public function test_caption_padding_is_a_whole_number_of_pixels(): void {

		$lightbox = function () {
			ob_start();
			$this->slideshow->printLightbox();
			return ob_get_clean();
		};

		$this->assertStringContainsString( 'style="--pp-slideshow-caption-padding:0px"', $lightbox(), 'unset' );

		\pp_api::$options['core/slideshow/captionPadding'] = '24';
		$this->assertStringContainsString( 'style="--pp-slideshow-caption-padding:24px"', $lightbox() );

		\pp_api::$options['core/slideshow/captionPadding'] = '-5"><b>';
		$this->assertStringContainsString( 'style="--pp-slideshow-caption-padding:0px"', $lightbox() );
	}
}
