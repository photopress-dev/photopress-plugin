<?php

namespace PhotoPress\Tests;

use Brain\Monkey\Functions;
use PhotoPress\modules\gallery\ImageTemplates;

/**
 * Where taxonomy-attachment goes in the template hierarchy, and the gallery
 * the Archive Images block renders.
 */
final class ImageTemplatesTest extends TestCase {

	public function test_taxonomy_attachment_goes_before_taxonomy(): void {

		$this->assertSame(
			[ 'taxonomy-photos_keywords-sunset.php', 'taxonomy-photos_keywords.php', 'taxonomy-attachment.php', 'taxonomy.php' ],
			ImageTemplates::withTaxonomyAttachment( [ 'taxonomy-photos_keywords-sunset.php', 'taxonomy-photos_keywords.php', 'taxonomy.php' ] )
		);
	}

	public function test_taxonomy_attachment_is_not_added_twice_or_lost(): void {

		// A theme on Frame has it already.
		$frame = [ 'taxonomy-photos_keywords.php', 'taxonomy-attachment.php', 'taxonomy.php' ];
		$this->assertSame( $frame, ImageTemplates::withTaxonomyAttachment( $frame ) );

		// Without taxonomy.php, last.
		$this->assertSame( [ 'taxonomy-photos_keywords.php', 'taxonomy-attachment.php' ], ImageTemplates::withTaxonomyAttachment( [ 'taxonomy-photos_keywords.php' ] ) );
	}

	public function test_the_archive_images_are_a_gallery_block_in_the_chosen_layout(): void {

		Functions\stubs( [
			'wp_get_attachment_image_url' => static fn( $id, $size ) => "/up/$id-$size.jpg",
			'wp_get_attachment_url'       => static fn( $id ) => "/up/$id.jpg",
			'get_attachment_link'         => static fn( $id ) => "/image-$id/",
			'get_post_meta'               => static fn( $id ) => "Alt <$id>",
			'wp_get_attachment_caption'   => static fn( $id ) => 11 === $id ? 'A caption' : '',
			'wp_kses_post'                => static fn( $html ) => $html,
		] );

		$markup = ImageTemplates::galleryMarkup( [ 10, 11 ], [ 'layout' => 'mosaic', 'rowHeight' => 250, 'sizeSlug' => 'medium', 'linkTo' => 'attachment', 'slideshow' => true, 'showCaptions' => true ] );
		$blocks = ( new \WP_Block_Parser() )->parse( $markup );

		$this->assertSame( 'core/gallery', $blocks[0]['blockName'] );
		$this->assertSame( [ 'linkTo' => 'attachment', 'sizeSlug' => 'medium', 'photopressSlideshow' => true, 'photopressColumnWidth' => 300, 'photopressRowHeight' => 250, 'photopressLayout' => 'mosaic' ], $blocks[0]['attrs'] );
		$this->assertSame( [ [ 'id' => 10, 'sizeSlug' => 'medium', 'linkDestination' => 'attachment' ], [ 'id' => 11, 'sizeSlug' => 'medium', 'linkDestination' => 'attachment' ] ], array_column( $blocks[0]['innerBlocks'], 'attrs' ) );
		$this->assertSame( '<figure class="wp-block-image size-medium"><a href="/image-10/"><img src="/up/10-medium.jpg" alt="Alt &lt;10&gt;" class="wp-image-10"/></a></figure>', $blocks[0]['innerBlocks'][0]['innerHTML'] );
		$this->assertStringEndsWith( '<figcaption class="wp-element-caption">A caption</figcaption></figure>', $blocks[0]['innerBlocks'][1]['innerHTML'] );
	}

	public function test_unknown_settings_fall_back_to_columns_linked_to_the_image_page(): void {

		Functions\stubs( [
			'wp_get_attachment_image_url' => static fn( $id, $size ) => "/up/$id-$size.jpg",
			'get_attachment_link'         => static fn( $id ) => "/image-$id/",
			'get_post_meta'               => '',
		] );

		$blocks = ( new \WP_Block_Parser() )->parse( ImageTemplates::galleryMarkup( [ 10 ], [ 'layout' => 'carousel', 'linkTo' => 'elsewhere' ] ) );

		$this->assertArrayNotHasKey( 'photopressLayout', $blocks[0]['attrs'] );
		$this->assertSame( 'attachment', $blocks[0]['attrs']['linkTo'] );
		$this->assertStringContainsString( '<a href="/image-10/">', $blocks[0]['innerBlocks'][0]['innerHTML'] );
	}
}
