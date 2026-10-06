<?php

namespace PhotoPress\Tests;

use Brain\Monkey\Functions;
use PhotoPress\modules\gallery\gallery;

final class GalleryLayoutTest extends TestCase {

	/**
	 * A rendered core/gallery as WordPress produces it, after the metadata
	 * module has added its attributes to the images.
	 */
	private const GALLERY = '<figure class="wp-block-gallery has-nested-images columns-default is-cropped wp-block-gallery-1 is-layout-flex">'
		. '<figure class="wp-block-image size-large"><img data-id="10" data-aspectratio="0.75" src="a.jpg" alt="" class="wp-image-10"/><figcaption class="wp-element-caption">One</figcaption></figure>'
		. '<figure class="wp-block-image size-large"><img src="b.jpg" alt="" class="wp-image-11"/></figure>'
		. '<figcaption class="blocks-gallery-caption wp-element-caption">Gallery caption</figcaption></figure>';

	private function render( array $attributes, string $html = self::GALLERY ): string {

		$module = ( new \ReflectionClass( gallery::class ) )->newInstanceWithoutConstructor();
		$instance = (object) [ 'attributes' => $attributes + [ 'photopressColumnWidth' => 300, 'photopressRowHeight' => 300, 'photopressSlideshow' => false ] ];

		return $module->renderGalleryLayout( $html, [ 'blockName' => 'core/gallery', 'attrs' => $attributes ], $instance );
	}

	protected function setUp(): void {

		parent::setUp();

		Functions\stubs( [
			'plugins_url' => static fn( $path = '' ) => 'https://example.test/wp-content/plugins/photopress/' . $path,
			// Attachment 11 has no data-aspectratio; its ratio comes from here.
			'wp_get_attachment_metadata' => static fn( $id ) => 11 === $id ? [ 'width' => 1000, 'height' => 500 ] : false,
		] );
	}

	public function test_a_gallery_without_photopress_options_is_untouched(): void {

		Functions\expect( 'wp_enqueue_style' )->never();

		$this->assertSame( self::GALLERY, $this->render( [] ) );
		$this->assertSame( self::GALLERY, $this->render( [ 'photopressLayout' => 'carousel' ] ) );
	}

	public function test_classes_variables_and_per_image_hooks(): void {

		Functions\expect( 'wp_enqueue_style' )->once()->with( 'photopress-frontend' );
		Functions\expect( 'wp_enqueue_script' )->once();

		$out = $this->render( [ 'photopressLayout' => 'mosaic', 'photopressRowHeight' => 250 ] );
		$p = new \WP_HTML_Tag_Processor( $out );

		$p->next_tag( 'figure' );
		$this->assertTrue( $p->has_class( 'photopress-layout' ) );
		$this->assertTrue( $p->has_class( 'photopress-layout-mosaic' ) );
		$this->assertStringContainsString( '--pp-row-height:250px', $p->get_attribute( 'style' ) );
		$this->assertSame( '250', $p->get_attribute( 'data-pp-row-height' ) );

		$p->next_tag( 'figure' );
		$this->assertTrue( $p->has_class( 'photopress-gallery-item' ) );
		$this->assertSame( '0', $p->get_attribute( 'data-position' ) );
		$this->assertSame( '10', $p->get_attribute( 'data-id' ) );
		$this->assertSame( '--pp-ar:0.7500', $p->get_attribute( 'style' ), 'from data-aspectratio' );
		$p->next_tag( 'img' );
		$this->assertSame( '0', $p->get_attribute( 'data-position' ) );

		$p->next_tag( 'figure' );
		$this->assertSame( '1', $p->get_attribute( 'data-position' ) );
		$this->assertSame( '--pp-ar:2.0000', $p->get_attribute( 'style' ), 'from the attachment size' );

		$this->assertStringContainsString( 'Gallery caption', $out );
	}

	public function test_slideshow_without_a_layout_prepares_the_images_only(): void {

		Functions\expect( 'wp_enqueue_style' )->once()->with( 'photopress-frontend' );
		Functions\expect( 'wp_enqueue_script' )->never();

		$p = new \WP_HTML_Tag_Processor( $this->render( [ 'photopressSlideshow' => true ] ) );

		$p->next_tag( 'figure' );
		$this->assertFalse( $p->has_class( 'photopress-layout' ) );
		$this->assertNull( $p->get_attribute( 'data-pp-column-width' ) );

		$p->next_tag( 'figure' );
		$this->assertTrue( $p->has_class( 'photopress-gallery-item' ) );
		$this->assertSame( '10', $p->get_attribute( 'data-id' ) );
		$p->next_tag( 'img' );
		$this->assertSame( '0', $p->get_attribute( 'data-position' ) );
	}

	public function test_hide_captions_marks_the_gallery_and_keeps_the_captions(): void {

		Functions\when( 'wp_enqueue_style' )->justReturn();

		$out = $this->render( [ 'photopressHideCaptions' => true ] );
		$p = new \WP_HTML_Tag_Processor( $out );

		$p->next_tag( 'figure' );
		$this->assertTrue( $p->has_class( 'photopress-hide-captions' ) );
		$p->next_tag( 'figure' );
		$this->assertFalse( $p->has_class( 'photopress-gallery-item' ), 'no slideshow, no item hooks' );
		$this->assertStringContainsString( '<figcaption class="wp-element-caption">One</figcaption>', $out );
	}

	public function test_existing_inline_style_is_kept(): void {

		Functions\when( 'wp_enqueue_style' )->justReturn();
		Functions\when( 'wp_enqueue_script' )->justReturn();

		$html = str_replace( 'is-layout-flex">', 'is-layout-flex" style="--wp--style--unstable-gallery-gap: 8px">', self::GALLERY );
		$p = new \WP_HTML_Tag_Processor( $this->render( [ 'photopressLayout' => 'masonry' ], $html ) );
		$p->next_tag( 'figure' );

		$this->assertSame( '--wp--style--unstable-gallery-gap: 8px;--pp-column-width:300px;--pp-row-height:300px', $p->get_attribute( 'style' ) );
	}

	public function test_only_masonry_and_mosaic_load_the_layout_script(): void {

		Functions\when( 'wp_enqueue_style' )->justReturn();
		Functions\expect( 'wp_enqueue_script' )->once()->with( 'photopress-gallery-layouts', \Mockery::any(), \Mockery::on( fn( $deps ) => in_array( 'masonry', $deps, true ) ), \Mockery::any(), true );

		$this->render( [ 'photopressLayout' => 'rows' ] );
		$this->render( [ 'photopressLayout' => 'masonry' ] );
	}

	public function test_core_gallery_gets_the_attributes_registered(): void {

		$module = ( new \ReflectionClass( gallery::class ) )->newInstanceWithoutConstructor();

		$args = $module->addGalleryAttributes( [ 'attributes' => [ 'columns' => [ 'type' => 'number' ] ] ], 'core/gallery' );
		$this->assertSame( [ 'columns', 'photopressLayout', 'photopressColumnWidth', 'photopressRowHeight', 'photopressSlideshow', 'photopressHideCaptions' ], array_keys( $args['attributes'] ) );

		$this->assertSame( [ 'x' => 1 ], $module->addGalleryAttributes( [ 'x' => 1 ], 'core/image' ) );
	}
}
