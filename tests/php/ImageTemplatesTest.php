<?php

namespace PhotoPress\Tests;

use Brain\Monkey\Functions;
use PhotoPress\modules\gallery\ImageTemplates;

/**
 * Where taxonomy-attachment goes in the template hierarchy, and the gallery
 * a Taxonomy Gallery is rendered as, and its query.
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

	public function test_an_image_query_is_the_gallery_saved_with_the_images(): void {

		Functions\stubs( [
			'wp_get_attachment_image_url' => static fn( $id, $size ) => "/up/$id-$size.jpg",
			'wp_get_attachment_url'       => static fn( $id ) => "/up/$id.jpg",
			'get_attachment_link'         => static fn( $id ) => "/image-$id/",
			'get_post_meta'               => static fn( $id ) => "Alt <$id>",
			'wp_get_attachment_caption'   => static fn( $id ) => 11 === $id ? 'A caption' : '',
			'wp_kses_post'                => static fn( $html ) => $html,
			'sanitize_html_class'         => static fn( $class ) => $class,
		] );

		$gallery = [ 'linkTo' => 'attachment', 'sizeSlug' => 'medium', 'align' => 'wide', 'photopressLayout' => 'mosaic', 'photopressRowHeight' => 250, 'photopressSlideshow' => true, 'photopressHideCaptions' => true ];
		$query   = [ 'photopressSource' => 'query', 'photopressTaxonomy' => 'photos_keywords', 'photopressTerm' => 3, 'photopressCount' => 8, 'photopressOrder' => 'rand' ];
		$blocks  = ( new \WP_Block_Parser() )->parse( ImageTemplates::galleryMarkup( [ 10, 11 ], $gallery + $query ) );

		// Its own settings, less the query, so it renders as a saved gallery.
		$this->assertSame( 'core/gallery', $blocks[0]['blockName'] );
		$this->assertSame( $gallery, $blocks[0]['attrs'] );
		$this->assertStringStartsWith( '<figure class="wp-block-gallery has-nested-images columns-default is-cropped alignwide">', $blocks[0]['innerHTML'] );

		$this->assertSame( [ [ 'id' => 10, 'sizeSlug' => 'medium', 'linkDestination' => 'attachment' ], [ 'id' => 11, 'sizeSlug' => 'medium', 'linkDestination' => 'attachment' ] ], array_column( $blocks[0]['innerBlocks'], 'attrs' ) );
		$this->assertSame( '<figure class="wp-block-image size-medium"><a href="/image-10/"><img src="/up/10-medium.jpg" alt="Alt &lt;10&gt;" class="wp-image-10"/></a></figure>', $blocks[0]['innerBlocks'][0]['innerHTML'] );
		$this->assertStringEndsWith( '<figcaption class="wp-element-caption">A caption</figcaption></figure>', $blocks[0]['innerBlocks'][1]['innerHTML'] );
	}

	public function test_unknown_settings_fall_back_to_columns_linked_to_the_image_page(): void {

		Functions\stubs( [
			'wp_get_attachment_image_url' => static fn( $id, $size ) => "/up/$id-$size.jpg",
			'get_attachment_link'         => static fn( $id ) => "/image-$id/",
			'get_post_meta'               => '',
			'wp_get_attachment_caption'   => '',
		] );

		$blocks = ( new \WP_Block_Parser() )->parse( ImageTemplates::galleryMarkup( [ 10 ], [ 'photopressLayout' => 'carousel', 'linkTo' => 'elsewhere' ] ) );

		$this->assertArrayNotHasKey( 'photopressLayout', $blocks[0]['attrs'] );
		$this->assertSame( 'attachment', $blocks[0]['attrs']['linkTo'] );
		$this->assertStringContainsString( '<a href="/image-10/">', $blocks[0]['innerBlocks'][0]['innerHTML'] );
	}

	public function test_the_query_of_images_chosen(): void {

		Functions\stubs( [
			'taxonomy_exists' => static fn( $name ) => in_array( $name, [ 'photos_keywords', 'pp_client', 'category' ], true ),
			'get_taxonomy'    => static fn( $name ) => (object) [ 'object_type' => 'category' === $name ? [ 'post' ] : [ 'attachment' ], 'public' => 'pp_client' !== $name ],
		] );

		// The latest, newest first, public images only.
		$latest = ImageTemplates::queryArgs( [] );
		$this->assertSame( [ 'attachment', 'inherit', 'image', 12, 'date', 'DESC', true ], [ $latest['post_type'], $latest['post_status'], $latest['post_mime_type'], $latest['posts_per_page'], $latest['orderby'], $latest['order'], $latest['photopress_public_images'] ] );
		$this->assertArrayNotHasKey( 'tax_query', $latest );

		$chosen = ImageTemplates::queryArgs( [ 'photopressTaxonomy' => 'photos_keywords', 'photopressTerm' => 3, 'photopressCount' => 500, 'photopressOrder' => 'date-asc' ] );
		$this->assertSame( [ [ 'taxonomy' => 'photos_keywords', 'field' => 'term_id', 'terms' => 3 ] ], $chosen['tax_query'] );
		$this->assertSame( [ 100, 'date', 'ASC' ], [ $chosen['posts_per_page'], $chosen['orderby'], $chosen['order'] ] );

		// A taxonomy for the admin only, not for images, or gone: nothing.
		foreach ( [ 'pp_client', 'category', 'photos_gone' ] as $taxonomy ) {
			$this->assertNull( ImageTemplates::queryArgs( [ 'photopressTaxonomy' => $taxonomy, 'photopressTerm' => 3 ] ), $taxonomy );
		}
	}

	public function test_only_an_image_query_gallery_is_filled(): void {

		$this->assertNull( ImageTemplates::renderTaxonomyGallery( null, [ 'blockName' => 'core/gallery', 'attrs' => [] ] ) );
		$this->assertNull( ImageTemplates::renderTaxonomyGallery( null, [ 'blockName' => 'core/image', 'attrs' => [ 'photopressSource' => 'archive' ] ] ) );
		$this->assertSame( 'other', ImageTemplates::renderTaxonomyGallery( 'other', [ 'blockName' => 'core/gallery', 'attrs' => [ 'photopressSource' => 'archive' ] ] ) );
	}
}
