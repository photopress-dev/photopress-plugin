<?php

namespace PhotoPress\Tests;

use Brain\Monkey\Functions;
use PhotoPress\modules\gallery\gallery;
use PhotoPress\modules\gallery\ImageTemplates;

/**
 * Child Pages Gallery: the published child pages of the page being viewed,
 * each as its featured image or the first image of its first gallery,
 * linked to it with its title.
 */
final class ChildPagesGalleryTest extends TestCase {

	/** Posts by ID: post_content, and the featured image. */
	private array $posts = [];

	protected function setUp(): void {

		parent::setUp();

		$this->posts = [
			// A featured image.
			2 => [ '', 5 ],
			// None; a gallery inside a group, its first image 21.
			3 => [ '<!-- wp:group --><div><!-- wp:gallery {"linkTo":"none"} --><figure class="wp-block-gallery"><!-- wp:image {"id":21} --><figure class="wp-block-image"><img class="wp-image-21"/></figure><!-- /wp:image --><!-- wp:image {"id":22} --><figure class="wp-block-image"><img class="wp-image-22"/></figure><!-- /wp:image --></figure><!-- /wp:gallery --></div><!-- /wp:group -->', 0 ],
			// None, and no gallery: left out.
			4 => [ '<!-- wp:paragraph --><p>Words.</p><!-- /wp:paragraph -->', 0 ],
			// A legacy PhotoPress gallery, and a gallery before WordPress 5.9.
			6 => [ '<!-- wp:photopress/gallery {"ids":[31,32]} /-->', 0 ],
			7 => [ '<!-- wp:gallery {"ids":[35,36]} /-->', 0 ],
			// A dynamic gallery: its first attached image.
			8 => [ '<!-- wp:gallery {"dynamicContent":{"source":"core/attached-media"}} /-->', 0 ],
		];
		$posts = &$this->posts;

		Functions\stubs( [
			'get_post'               => static function ( $post ) use ( $posts ) {
				$id = is_object( $post ) ? $post->ID : $post;
				return isset( $posts[ $id ] ) ? (object) [ 'ID' => $id, 'post_content' => $posts[ $id ][0] ] : null;
			},
			'parse_blocks'           => static fn( $content ) => ( new \WP_Block_Parser() )->parse( $content ),
			'get_children'           => static fn( $args ) => 8 === $args['post_parent'] ? [ 41 ] : [],
			'wp_attachment_is_image' => true,
			'get_post_thumbnail_id'  => static fn( $post ) => $posts[ is_object( $post ) ? $post->ID : $post ][1] ?? 0,
			'get_permalink'          => static fn( $post ) => '/parent/child-' . ( is_object( $post ) ? $post->ID : $post ) . '/',
			'get_the_title'          => static fn( $post ) => 'Child & ' . ( is_object( $post ) ? $post->ID : $post ),
			'get_the_ID'             => 1,
			'get_post_type'          => 'page',
			'is_post_type_hierarchical' => true,
		] );
	}

	public function test_the_first_image_of_a_posts_first_gallery(): void {

		$this->assertSame( 21, gallery::firstImage( 3 ) );
		$this->assertSame( 31, gallery::firstImage( 6 ), 'the legacy gallery' );
		$this->assertSame( 35, gallery::firstImage( 7 ), 'before WordPress 5.9' );
		$this->assertSame( 41, gallery::firstImage( 8 ), 'dynamic: its first attached image' );
		$this->assertSame( 0, gallery::firstImage( 4 ) );
		$this->assertSame( 0, gallery::firstImage( 99 ) );
	}

	public function test_each_child_page_as_its_featured_image_or_first_gallery_image(): void {

		Functions\expect( 'get_posts' )->once()->andReturnUsing( function ( $args ) {
			$this->assertSame( [ 'page', 1, 'publish', [ 'menu_order' => 'ASC', 'title' => 'ASC' ] ], [ $args['post_type'], $args['post_parent'], $args['post_status'], $args['orderby'] ] );
			return array_map( 'get_post', [ 2, 3, 4 ] );
		} );

		$items = ImageTemplates::childPageItems( [] );

		$this->assertSame( [ 5, 21 ], array_column( $items, 'id' ), 'the page with neither left out' );
		$this->assertSame( [ '/parent/child-2/', 'custom', '<figcaption class="wp-element-caption"><a href="/parent/child-2/">Child &amp; 2</a></figcaption>' ], [ $items[0]['href'], $items[0]['link'], $items[0]['figcaption'] ] );
	}

	public function test_only_on_a_page_that_can_have_child_pages(): void {

		Functions\when( 'is_post_type_hierarchical' )->justReturn( false );
		Functions\expect( 'get_posts' )->never();

		$this->assertSame( [], ImageTemplates::childPageItems( [] ) );
	}
}
