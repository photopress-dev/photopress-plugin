<?php

namespace PhotoPress\Tests;

use Brain\Monkey\Functions;
use PhotoPress\modules\metadata\ImageTaxonomies;
use PhotoPress\modules\metadata\XmpDisplayWidget;

final class ImageTaxonomiesTest extends TestCase {

	protected function setUp(): void {

		parent::setUp();

		$terms = [
			'photos_city'     => [ (object) [ 'name' => 'Los Angeles', 'slug' => 'los-angeles' ] ],
			'photos_keywords' => [ (object) [ 'name' => 'portrait', 'slug' => 'portrait' ], (object) [ 'name' => 'Q&amp;A <b>', 'slug' => 'qa' ] ],
			'photos_lens'     => [],
		];
		$labels = [ 'photos_city' => 'Cities', 'photos_keywords' => 'Keywords', 'photos_lens' => 'Lenses' ];

		Functions\stubs( [
			'taxonomy_exists'        => static fn( $name ) => isset( $labels[ $name ] ),
			'get_the_terms'          => static fn( $id, $name ) => 7 === $id ? ( $terms[ $name ] ?: false ) : false,
			'get_term_link'          => static fn( $term, $name ) => "https://example.test/$name/{$term->slug}/",
			'get_taxonomy'           => static fn( $name ) => (object) [ 'label' => $labels[ $name ] ],
			'get_object_taxonomies'  => static fn() => array_keys( $labels ),
			'is_wp_error'            => static fn( $v ) => $v instanceof \WP_Error,
			'get_the_ID'             => 7,
			'get_block_wrapper_attributes' => static fn( $extra ) => 'class="' . $extra['class'] . ' wp-block-photopress-image-taxonomies"',
		] );
	}

	public function test_rows_in_the_chosen_order_with_labels_and_links(): void {

		$html = ImageTaxonomies::renderRows( 7, [ 'photos_keywords', 'photos_city' ], true, true );

		$this->assertSame(
			'<div class="container"><div class="label">Keywords: </div><div class="terms"><a href="https://example.test/photos_keywords/portrait/" rel="tag">portrait</a>, <a href="https://example.test/photos_keywords/qa/" rel="tag">Q&amp;A &lt;b&gt;</a></div></div>'
			. '<div class="container"><div class="label">Cities: </div><div class="terms"><a href="https://example.test/photos_city/los-angeles/" rel="tag">Los Angeles</a></div></div>',
			$html
		);
	}

	public function test_unlinked_and_without_labels(): void {

		$this->assertSame(
			'<div class="container"><div class="terms">Los Angeles</div></div>',
			ImageTaxonomies::renderRows( 7, [ 'photos_city' ], false, false )
		);
	}

	public function test_no_choice_means_every_taxonomy_and_empty_or_unknown_ones_are_skipped(): void {

		$html = ImageTaxonomies::renderRows( 7, [], true, true );
		$this->assertSame( 2, substr_count( $html, 'class="container"' ), 'Lenses has no terms' );

		$this->assertSame( '', ImageTaxonomies::renderRows( 7, [ 'photos_people', 'photos_lens' ] ) );
		$this->assertSame( '', ImageTaxonomies::renderRows( 0, [ 'photos_city' ] ) );
	}

	public function test_block_renders_the_context_post_in_a_wrapper(): void {

		$block = (object) [ 'context' => [ 'postId' => 7 ] ];
		$html = ImageTaxonomies::renderBlock( [ 'taxonomies' => [ 'photos_city' ], 'linkTerms' => false, 'showLabels' => true ], '', $block );

		$this->assertSame( '<div class="display-taxonomy-terms-widget wp-block-photopress-image-taxonomies"><div class="container"><div class="label">Cities: </div><div class="terms">Los Angeles</div></div></div>', $html );
	}

	public function test_block_with_nothing_to_show_renders_nothing(): void {

		$this->assertSame( '', ImageTaxonomies::renderBlock( [ 'taxonomies' => [ 'photos_lens' ], 'linkTerms' => true, 'showLabels' => true ], '', (object) [ 'context' => [ 'postId' => 7 ] ] ) );
		// No context: the current post.
		$this->assertNotSame( '', ImageTaxonomies::renderBlock( [ 'taxonomies' => [ 'photos_city' ], 'linkTerms' => true, 'showLabels' => true ] ) );
	}

	public function test_widget_keeps_its_markup(): void {

		Functions\when( 'apply_filters' )->returnArg( 2 );

		$widget = ( new \ReflectionClass( XmpDisplayWidget::class ) )->newInstanceWithoutConstructor();

		ob_start();
		$widget->widget(
			[ 'before_widget' => '<section>', 'after_widget' => '</section>', 'before_title' => '<h2>', 'after_title' => '</h2>' ],
			[ 'title' => 'Image Details', 'taxonomies' => 'photos_city, photos_people' ]
		);

		$this->assertSame(
			'<section><h2>Image Details</h2><div class="display-taxonomy-terms-widget"><div class="container"><div class="label">Cities: </div><div class="terms"><a href="https://example.test/photos_city/los-angeles/" rel="tag">Los Angeles</a></div></div></div></section>',
			ob_get_clean()
		);
	}

	public function test_widget_list_parsing(): void {

		$this->assertSame( [ 'photos_keywords', 'photos_city' ], ImageTaxonomies::parseList( ' photos_keywords ,photos_city, ' ) );
	}
}
