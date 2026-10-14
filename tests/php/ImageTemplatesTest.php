<?php

namespace PhotoPress\Tests;

use Brain\Monkey\Functions;
use PhotoPress\modules\gallery\ImageTemplates;

/**
 * Where taxonomy-attachment goes in the template hierarchy, and the gallery
 * a Taxonomy Gallery is rendered as, and its query.
 */
final class ImageTemplatesTest extends TestCase {

	/** The gallery markup of these images, as a Taxonomy Gallery renders it. */
	private static function markup( array $ids, array $attributes ): string {

		return ImageTemplates::galleryMarkup( ImageTemplates::imageItems( $ids, $attributes ), $attributes );
	}

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
		$query   = [ 'photopressSource' => 'query', 'photopressTaxonomy' => 'photos_keywords', 'photopressTerm' => 3, 'photopressCount' => 8, 'photopressOrder' => 'rand', 'photopressShowHeading' => true, 'photopressHeading' => 'Portraits' ];
		$blocks  = ( new \WP_Block_Parser() )->parse( self::markup( [ 10, 11 ], $gallery + $query ) );

		// Its own settings, less the query and heading, so it renders as a saved gallery.
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

		$blocks = ( new \WP_Block_Parser() )->parse( self::markup( [ 10 ], [ 'photopressLayout' => 'carousel', 'linkTo' => 'elsewhere' ] ) );

		$this->assertArrayNotHasKey( 'photopressLayout', $blocks[0]['attrs'] );
		$this->assertSame( 'attachment', $blocks[0]['attrs']['linkTo'] );
		$this->assertStringContainsString( '<a href="/image-10/">', $blocks[0]['innerBlocks'][0]['innerHTML'] );
	}

	public function test_the_query_of_images_chosen(): void {

		Functions\stubs( [
			'taxonomy_exists' => static fn( $name ) => in_array( $name, [ 'photos_keywords', 'pp_client', 'category' ], true ),
			// false for a taxonomy that doesn't exist, as WordPress returns.
			'get_taxonomy'    => static fn( $name ) => in_array( $name, [ 'photos_keywords', 'pp_client', 'category' ], true ) ? (object) [ 'object_type' => 'category' === $name ? [ 'post' ] : [ 'attachment' ], 'public' => 'pp_client' !== $name ] : false,
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

		$this->assertNull( ImageTemplates::renderSourcedGallery( null, [ 'blockName' => 'core/gallery', 'attrs' => [] ] ) );
		$this->assertNull( ImageTemplates::renderSourcedGallery( null, [ 'blockName' => 'core/image', 'attrs' => [ 'photopressSource' => 'archive' ] ] ) );
		$this->assertNull( ImageTemplates::renderSourcedGallery( null, [ 'blockName' => 'core/gallery', 'attrs' => [ 'photopressSource' => 'elsewhere' ] ] ) );
		$this->assertSame( 'other', ImageTemplates::renderSourcedGallery( 'other', [ 'blockName' => 'core/gallery', 'attrs' => [ 'photopressSource' => 'archive' ] ] ) );
	}

	/**
	 * An image archive's gallery, as its template has it: in columns, with
	 * file names and titles; nothing when the archive has no images. Its
	 * stylesheet comes with core/gallery (gallery::loadStylesheetWithGallery()).
	 */
	public function test_an_archive_gallery_in_columns_with_file_names_and_titles(): void {

		require_once ABSPATH . WPINC . '/class-wp-post.php';
		require_once ABSPATH . WPINC . '/class-wp-query.php';

		global $wp_query;
		$saved    = $wp_query;
		$wp_query = new \WP_Query();
		$wp_query->posts = [ new \WP_Post( (object) [ 'ID' => 10, 'post_type' => 'attachment' ] ) ];
		$wp_query->max_num_pages = 1;

		Functions\stubs( [
			'wp_attachment_is_image'      => true,
			'wp_get_attachment_image_url' => static fn( $id, $size ) => "/up/$id-$size.jpg",
			'get_attachment_link'         => static fn( $id ) => "/image-$id/",
			'get_post_meta'               => '',
			'get_the_title'               => static fn( $id ) => "Ken $id",
			'get_attached_file'           => static fn( $id ) => "/var/www/uploads/ken-$id.jpg",
			'wp_basename'                 => static fn( $path ) => basename( $path ),
			'get_query_var'               => 1,
			'paginate_links'              => '',
			'do_blocks'                   => static fn( $markup ) => $markup,
			'wp_filter_content_tags'      => static fn( $html ) => $html,
		] );
		$html = ImageTemplates::renderSourcedGallery( null, [ 'blockName' => 'core/gallery', 'attrs' => [ 'photopressSource' => 'archive', 'columns' => 4, 'photopressDetails' => true ] ] );
		$this->assertStringContainsString( 'photopress-details__file', $html );

		// An archive with no images: nothing.
		$wp_query->posts = [];
		$this->assertSame( '', ImageTemplates::renderSourcedGallery( null, [ 'blockName' => 'core/gallery', 'attrs' => [ 'photopressSource' => 'archive' ] ] ) );

		$wp_query = $saved;
	}

	public function test_each_images_file_name_and_title_under_it_as_a_search_result(): void {

		Functions\stubs( [
			'wp_get_attachment_image_url' => static fn( $id, $size ) => "/up/$id-$size.jpg",
			'get_attachment_link'         => static fn( $id ) => "/image-$id/",
			'get_post_meta'               => '',
			'get_the_title'               => static fn( $id ) => "Ken & $id",
			'get_attached_file'           => static fn( $id ) => "/var/www/uploads/2026/10/ken-$id.jpg",
			'wp_basename'                 => static fn( $path ) => basename( $path ),
		] );

		$blocks = ( new \WP_Block_Parser() )->parse( self::markup( [ 10 ], [ 'photopressLayout' => 'rows', 'photopressDetails' => true ] ) );

		$this->assertStringContainsString( 'photopress-has-details', $blocks[0]['innerHTML'] );
		$this->assertArrayNotHasKey( 'photopressDetails', $blocks[0]['attrs'] );
		$this->assertStringEndsWith(
			'<figcaption class="wp-element-caption photopress-details"><span class="photopress-details__file">ken-10.jpg</span><a class="photopress-details__title" href="/image-10/">Ken &amp; 10</a></figcaption></figure>',
			$blocks[0]['innerBlocks'][0]['innerHTML']
		);
	}

	public function test_random_images_are_shuffled_from_ids_kept_for_ten_minutes(): void {

		$kept = [];
		$queries = 0;
		Functions\when( 'get_transient' )->alias( static function ( $key ) use ( &$kept ) { return $kept[ $key ] ?? false; } );
		Functions\when( 'set_transient' )->alias( static function ( $key, $value, $ttl ) use ( &$kept ) {
			$kept[ $key ] = $value;
			return 600 === $ttl;
		} );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$args = [ 'posts_per_page' => 3, 'orderby' => 'rand', 'post_type' => 'attachment' ];
		$cached = ImageTemplates::RANDOM_IDS . md5( json_encode( array_merge( $args, [ 'orderby' => 'ID', 'order' => 'DESC', 'posts_per_page' => ImageTemplates::RANDOM_FROM ] ) ) );
		$kept[ $cached ] = [ 1, 2, 3, 4, 5, 6, 7, 8 ];

		$ids = ImageTemplates::randomIds( $args );

		// From those kept, with no query: WP_Query would need a database.
		$this->assertCount( 3, $ids );
		$this->assertSame( [], array_diff( $ids, [ 1, 2, 3, 4, 5, 6, 7, 8 ] ) );
		$this->assertCount( 3, array_unique( $ids ) );
	}
}
