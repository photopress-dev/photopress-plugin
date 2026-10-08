<?php

namespace PhotoPress\Tests;

use Brain\Monkey\Functions;
use PhotoPress\modules\gallery\GalleryAdd;
use PhotoPress\modules\gallery\GallerySlideshow;

/**
 * Choosing a post's gallery, and the image block GalleryAdd writes into it.
 */
final class GalleryAddTest extends TestCase {

	private const IMAGE = '<!-- wp:image {"id":10,"sizeSlug":"large","linkDestination":"media"} -->'
		. "\n" . '<figure class="wp-block-image size-large"><a href="/up/a.jpg"><img src="/up/a-1024x683.jpg" alt="Alice" class="wp-image-10"/></a><figcaption class="wp-element-caption">Alice at the lake</figcaption></figure>'
		. "\n" . '<!-- /wp:image -->';

	protected function setUp(): void {

		parent::setUp();

		Functions\stubs( [
			'parse_blocks'               => static fn( $content ) => ( new \WP_Block_Parser() )->parse( $content ),
			'wp_get_attachment_image_url' => static fn( $id, $size ) => "/up/img-$id-$size.jpg",
			'wp_get_attachment_url'      => static fn( $id ) => "/up/img-$id.jpg",
			'wp_get_attachment_image_src' => static fn( $id, $size ) => [ "/up/img-$id-$size.jpg", 100 + $id, 200 + $id ],
			'get_attachment_link'        => static fn( $id ) => "/page/img-$id/",
			'get_post_meta'              => static fn( $id, $key ) => '_wp_attachment_image_alt' === $key ? "Bob $id" : '',
			'wp_get_attachment_caption'  => static fn( $id ) => 20 === $id ? 'Bob on the hill' : '',
			'wp_kses_post'               => static fn( $html ) => $html,
		] );
	}

	private static function gallery( string $images, string $attrs = '{"linkTo":"media"}', string $anchor = '' ): string {

		return "<!-- wp:gallery $attrs -->\n<figure class=\"wp-block-gallery has-nested-images columns-default is-cropped\"" . ( $anchor ? " id=\"$anchor\"" : '' ) . ">$images</figure>\n<!-- /wp:gallery -->";
	}

	private static function blocks( string $content ): array {

		return ( new \WP_Block_Parser() )->parse( $content );
	}

	public function test_the_only_gallery_is_chosen_at_any_depth(): void {

		$blocks = self::blocks( '<!-- wp:group --><div class="wp-block-group">' . self::gallery( self::IMAGE ) . '</div><!-- /wp:group -->' );
		$gallery = GalleryAdd::chooseGallery( $blocks, '' );

		$this->assertSame( [ 0, 0 ], $gallery['path'] );
		$this->assertSame( 'core/gallery', $gallery['block']['blockName'] );
	}

	public function test_several_galleries_need_an_anchor(): void {

		$blocks = self::blocks( self::gallery( self::IMAGE, '{}', 'first' ) . self::gallery( self::IMAGE, '{}', 'second' ) );

		$error = GalleryAdd::chooseGallery( $blocks, '' );
		$this->assertSame( 'photopress_gallery_ambiguous', $error->get_error_code() );
		$this->assertSame( [ 'first', 'second' ], $error->get_error_data()['anchors'] );

		$this->assertSame( [ 1 ], GalleryAdd::chooseGallery( $blocks, 'second' )['path'] );
		$this->assertSame( 'photopress_gallery_not_found', GalleryAdd::chooseGallery( $blocks, 'third' )->get_error_code() );
		$this->assertSame( 'photopress_gallery_none', GalleryAdd::chooseGallery( self::blocks( '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ), '' )->get_error_code() );
	}

	public function test_a_dynamic_gallery_anchor_is_read_from_the_comment(): void {

		$blocks = self::blocks( '<!-- wp:gallery {"anchor":"dyn","dynamicContent":{"source":"core/attached-media"}} /-->' );

		$this->assertSame( 'dyn', GalleryAdd::chooseGallery( $blocks, 'dyn' )['anchor'] );
	}

	public function test_the_new_block_has_the_format_of_the_last_and_its_own_content(): void {

		$block = GalleryAdd::imageBlockFrom( self::blocks( self::IMAGE )[0], 20 );
		$html = $block['innerHTML'];

		$this->assertSame( [ 'id' => 20, 'sizeSlug' => 'large', 'linkDestination' => 'media' ], $block['attrs'] );
		$this->assertStringContainsString( '<a href="/up/img-20.jpg">', $html );
		$this->assertStringContainsString( 'src="/up/img-20-large.jpg"', $html );
		$this->assertStringContainsString( 'alt="Bob 20"', $html );
		$this->assertStringContainsString( 'class="wp-image-20"', $html );
		$this->assertStringContainsString( '<figcaption class="wp-element-caption">Bob on the hill</figcaption>', $html );
		$this->assertStringNotContainsString( 'Alice', $html );
		$this->assertStringNotContainsString( 'wp-image-10', $html );
	}

	public function test_no_caption_when_the_new_image_has_none(): void {

		$html = GalleryAdd::imageBlockFrom( self::blocks( self::IMAGE )[0], 21 )['innerHTML'];

		$this->assertStringNotContainsString( 'figcaption', $html );
	}

	/**
	 * The block added is like for like with the template: its markup with
	 * attribute order and whitespace kept, and everything that belongs to the
	 * image (id, files, dimensions, alt text, caption) the new image's own.
	 */
	public function test_the_block_added_is_like_for_like(): void {

		// Alice (10) has alt text and a caption; Bob (11) alt text only.
		Functions\when( 'get_post_meta' )->alias( static fn( $id ) => [ 10 => 'Alice', 11 => 'Bob' ][ $id ] ?? '' );
		Functions\when( 'wp_get_attachment_caption' )->alias( static fn( $id ) => 10 === $id ? 'Alice at the lake' : '' );

		$image = static fn( $attrs, $id, $figure = '' ) => self::blocks(
			'<!-- wp:image {' . sprintf( $attrs, $id ) . '} -->'
			. "\n" . sprintf( $figure ?: '<figure class="wp-block-image size-large"><a href="/up/img-%1$d.jpg"><img src="/up/img-%1$d-large.jpg" alt="%2$s" class="wp-image-%1$d" width="%3$d" height="%4$d"/></a>%5$s</figure>', $id, [ 10 => 'Alice', 11 => 'Bob' ][ $id ], 100 + $id, 200 + $id, 10 === $id ? '<figcaption class="wp-element-caption">Alice at the lake</figcaption>' : '' )
			. "\n" . '<!-- /wp:image -->'
		)[0];

		$format = '"lightbox":{"enabled":true},"id":%d,"sizeSlug":"large","linkDestination":"media","className":"is-style-rounded"';

		// Each way round: a caption added, and one taken away.
		$this->assertSame( $image( $format, 11 ), GalleryAdd::imageBlockFrom( $image( $format, 10 ), 11 ) );
		$this->assertSame( $image( $format, 10 ), GalleryAdd::imageBlockFrom( $image( $format, 11 ), 10 ) );

		// From a resized, cropped template, the block as if it were not.
		$resized = $image( '"id":%d,"width":"300px","aspectRatio":"1","scale":"cover","sizeSlug":"large","linkDestination":"media"', 11, '<figure class="wp-block-image size-large is-resized"><a href="/up/img-%1$d.jpg"><img src="/up/img-%1$d-large.jpg" alt="%2$s" class="wp-image-%1$d" width="%3$d" height="%4$d" style="aspect-ratio:1;object-fit:cover;width:300px;height:auto"/></a>%5$s</figure>' );
		$this->assertSame( $image( '"id":%d,"sizeSlug":"large","linkDestination":"media"', 10 ), GalleryAdd::imageBlockFrom( $resized, 10 ) );
	}

	public function test_size_and_crop_of_the_copied_image_are_left_out(): void {

		$resized = '<!-- wp:image {"id":10,"width":"300px","aspectRatio":"1","scale":"cover","sizeSlug":"large","linkDestination":"none","className":"is-style-rounded","style":{"border":{"radius":"8px"}}} -->'
			. '<figure class="wp-block-image size-large is-resized is-style-rounded has-custom-border"><img src="/up/a.jpg" alt="" class="wp-image-10 has-border-radius" style="border-radius:8px;aspect-ratio:1;object-fit:cover;width:300px"/></figure>'
			. '<!-- /wp:image -->';

		$block = GalleryAdd::imageBlockFrom( self::blocks( $resized )[0], 20 );

		$this->assertSame( [ 'id' => 20, 'sizeSlug' => 'large', 'linkDestination' => 'none', 'className' => 'is-style-rounded', 'style' => [ 'border' => [ 'radius' => '8px' ] ] ], $block['attrs'] );
		$this->assertStringContainsString( '<figure class="wp-block-image size-large is-style-rounded has-custom-border">', $block['innerHTML'] );
		$this->assertStringContainsString( 'style="border-radius:8px"', $block['innerHTML'] );
		$this->assertStringContainsString( 'class="has-border-radius wp-image-20"', $block['innerHTML'] );
	}

	public function test_links_follow_the_link_kind(): void {

		$attachment = str_replace( [ '"media"', '/up/a.jpg"' ], [ '"attachment"', '/page/a/"' ], self::IMAGE );
		$this->assertStringContainsString( '<a href="/page/img-20/">', GalleryAdd::imageBlockFrom( self::blocks( $attachment )[0], 20 )['innerHTML'] );

		// A link chosen for that image is not the new one's.
		$custom = str_replace( '"media"', '"custom"', self::IMAGE );
		$block = GalleryAdd::imageBlockFrom( self::blocks( $custom )[0], 20 );
		$this->assertSame( 'none', $block['attrs']['linkDestination'] );
		$this->assertStringNotContainsString( '<a ', $block['innerHTML'] );
		$this->assertStringContainsString( '<figure class="wp-block-image size-large"><img ', $block['innerHTML'] );
	}

	public function test_data_attributes_of_older_galleries_follow_the_image(): void {

		$old = '<!-- wp:image {"id":10,"sizeSlug":"large","linkDestination":"none"} --><figure class="wp-block-image size-large"><img src="/up/a.jpg" alt="" data-id="10" data-full-url="/up/a.jpg" data-link="/page/a/" class="wp-image-10"/></figure><!-- /wp:image -->';
		$html = GalleryAdd::imageBlockFrom( self::blocks( $old )[0], 20 )['innerHTML'];

		$this->assertStringContainsString( 'data-id="20"', $html );
		$this->assertStringContainsString( 'data-full-url="/up/img-20.jpg"', $html );
		$this->assertStringContainsString( 'data-link="/page/img-20/"', $html );
	}

	public function test_the_block_goes_after_the_last_image_with_the_same_separator(): void {

		$gallery = self::blocks( self::gallery( self::IMAGE . "\n\n" . str_replace( '10', '11', self::IMAGE ) ) )[0];
		$added = GalleryAdd::insertImage( $gallery, 20 );

		$this->assertSame( [ 10, 11, 20 ], GallerySlideshow::imageIds( $added ) );
		$this->assertCount( 3, array_keys( $added['innerContent'], null, true ) );

		// Between each pair of inner blocks, the gallery's own separator.
		$nulls = array_keys( $added['innerContent'], null, true );
		$this->assertSame( "\n\n", $added['innerContent'][ $nulls[2] - 1 ] );
		$this->assertStringEndsWith( '</figure>', rtrim( end( $added['innerContent'] ) ) );
	}

	public function test_replace_leaves_only_the_new_image_in_the_last_ones_format(): void {

		$second = str_replace( [ '"id":10,', 'wp-image-10', '"linkDestination":"media"' ], [ '"id":11,', 'wp-image-11', '"linkDestination":"none"' ], self::IMAGE );
		$second = preg_replace( '#<a href="[^"]*">(<img[^>]*>)</a>#', '$1', $second );
		$gallery = self::blocks( self::gallery( self::IMAGE . "\n\n" . $second, '{"linkTo":"none"}', 'spring' ) )[0];

		$replaced = GalleryAdd::replaceImages( $gallery, 20 );

		$this->assertSame( [ 20 ], GallerySlideshow::imageIds( $replaced ) );
		$this->assertSame( 'none', $replaced['innerBlocks'][0]['attrs']['linkDestination'], 'the format of the last image' );

		// The gallery's own markup around the one block.
		$this->assertSame( [ "\n" . '<figure class="wp-block-gallery has-nested-images columns-default is-cropped" id="spring">', null, "</figure>\n" ], $replaced['innerContent'] );
		$this->assertSame( $gallery['attrs'], $replaced['attrs'] );
	}

	public function test_replace_with_an_image_the_gallery_has(): void {

		$gallery = self::blocks( self::gallery( self::IMAGE . "\n\n" . str_replace( '10', '11', self::IMAGE ) ) )[0];
		$replaced = GalleryAdd::replaceImages( $gallery, 10 );

		$this->assertSame( [ 10 ], GallerySlideshow::imageIds( $replaced ) );
		$this->assertCount( 1, array_keys( $replaced['innerContent'], null, true ) );
	}

	public function test_replace_needs_an_image_to_copy(): void {

		$this->assertSame( 'photopress_gallery_empty', GalleryAdd::replaceImages( self::blocks( self::gallery( '' ) )[0], 20 )->get_error_code() );
	}

	public function test_an_image_already_in_the_gallery_is_found(): void {

		$gallery = self::blocks( self::gallery( self::IMAGE ) )[0];
		$this->assertTrue( GalleryAdd::contains( $gallery, 10 ) );
		$this->assertFalse( GalleryAdd::contains( $gallery, 20 ) );
		$this->assertFalse( GalleryAdd::contains( $gallery, 1 ), 'wp-image-10 is not wp-image-1' );

		// By its class when the block has lost its id.
		$no_id = self::blocks( self::gallery( str_replace( '"id":10,', '', self::IMAGE ) ) )[0];
		$this->assertTrue( GalleryAdd::contains( $no_id, 10 ) );
	}

	public function test_a_gallery_without_images_cannot_be_copied(): void {

		$empty = self::blocks( self::gallery( '' ) )[0];
		$this->assertSame( 'photopress_gallery_empty', GalleryAdd::insertImage( $empty, 20 )->get_error_code() );

		$old = self::blocks( '<!-- wp:gallery {"ids":[1,2]} --><figure class="wp-block-gallery"><ul class="blocks-gallery-grid"></ul></figure><!-- /wp:gallery -->' )[0];
		$this->assertSame( 'photopress_gallery_old_format', GalleryAdd::insertImage( $old, 20 )->get_error_code() );
	}

	public function test_style_without_properties(): void {

		$this->assertSame( 'border-radius:8px', GalleryAdd::styleWithout( 'border-radius: 8px; aspect-ratio:1;width:300px;', [ 'width', 'aspect-ratio' ] ) );
		$this->assertSame( '', GalleryAdd::styleWithout( '', [ 'width' ] ) );
	}

	public function test_a_dynamic_gallery_has_no_images_without_core_support(): void {

		$gallery = self::blocks( '<!-- wp:gallery {"dynamicContent":{"source":"core/attached-media"}} /-->' )[0];

		// block_core_gallery_resolve_dynamic_source() is not loaded here, as on
		// WordPress before 7.1.
		$this->assertSame( [], GallerySlideshow::imageIds( $gallery, 5 ) );
	}
}
