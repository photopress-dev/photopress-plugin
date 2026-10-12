<?php

namespace PhotoPress\Tests;

use Brain\Monkey\Functions;
use PhotoPress\modules\metadata\ImageTaxonomies;
use PhotoPress\modules\metadata\ExifDisplayWidget;
use PhotoPress\modules\metadata\XmpDisplayWidget;
use PHPUnit\Framework\Attributes\DataProvider;

final class ImageTaxonomiesTest extends TestCase {

	protected function setUp(): void {

		parent::setUp();

		$terms = [
			'photos_city'     => [ (object) [ 'name' => 'Los Angeles', 'slug' => 'los-angeles' ] ],
			'photos_keywords' => [ (object) [ 'name' => 'portrait', 'slug' => 'portrait' ], (object) [ 'name' => 'Q&amp;A <b>', 'slug' => 'qa' ] ],
			'photos_lens'     => [],
		];
		$terms['pp_client'] = [ (object) [ 'name' => 'Acme', 'slug' => 'acme' ] ];
		$labels = [ 'photos_city' => 'Cities', 'photos_keywords' => 'Keywords', 'photos_lens' => 'Lenses', 'pp_client' => 'Clients' ];

		Functions\stubs( [
			'taxonomy_exists'        => static fn( $name ) => isset( $labels[ $name ] ),
			'get_the_terms'          => static fn( $id, $name ) => 7 === $id ? ( $terms[ $name ] ?: false ) : false,
			'get_term_link'          => static fn( $term, $name ) => "https://example.test/$name/{$term->slug}/",
			'get_taxonomy'           => static fn( $name ) => (object) [ 'label' => $labels[ $name ] ],
			'get_object_taxonomies'  => static fn() => array_keys( $labels ),
			'is_taxonomy_viewable'   => static fn( $name ) => 'pp_client' !== $name,
			'is_wp_error'            => static fn( $v ) => $v instanceof \WP_Error,
			'get_the_ID'             => 7,
			'get_block_wrapper_attributes' => static fn( $extra ) => 'class="' . $extra['class'] . ' wp-block-photopress-image-taxonomies"',
			'get_post_type'          => static fn( $id ) => 7 === $id ? 'attachment' : 'post',
			'wp_get_attachment_metadata' => static fn( $id ) => [ 'image_meta' => [ 'aperture' => '2.8', 'shutter_speed' => '0.004', 'iso' => '400', 'focal_length' => '50', 'camera' => 'Canon EOS R5', 'created_timestamp' => '1357484037', 'credit' => 'Jane' ] ],
			'get_option'             => static fn( $name ) => 'date_format' === $name ? 'Y-m-d' : false,
			'wp_date'                => static fn( $format, $ts, $tz ) => ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz )->format( $format ),
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

	public function test_a_taxonomy_for_the_admin_only_is_never_shown(): void {

		$this->assertStringNotContainsString( 'Acme', ImageTaxonomies::renderRows( 7, [] ), 'not among all' );
		$this->assertSame( '', ImageTaxonomies::renderRows( 7, [ 'pp_client' ] ), 'nor when chosen' );
		$this->assertNotContains( 'pp_client', ImageTaxonomies::allTaxonomies() );
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

	public function test_exposure_rows_from_the_file_mixed_with_taxonomies(): void {

		$html = ImageTaxonomies::renderRows( 7, [ 'exif:camera', 'photos_city', 'exif:aperture', 'exif:shutter_speed', 'exif:iso', 'exif:focal_length', 'exif:created_timestamp' ], false, true );

		$this->assertSame(
			'<div class="container"><div class="label">Camera model: </div><div class="terms">Canon EOS R5</div></div>'
			. '<div class="container"><div class="label">Cities: </div><div class="terms">Los Angeles</div></div>'
			. '<div class="container"><div class="label">Aperture: </div><div class="terms">f/2.8</div></div>'
			. '<div class="container"><div class="label">Shutter speed: </div><div class="terms">1/250 s</div></div>'
			. '<div class="container"><div class="label">ISO: </div><div class="terms">400</div></div>'
			. '<div class="container"><div class="label">Focal length: </div><div class="terms">50 mm</div></div>'
			. '<div class="container"><div class="label">Date taken: </div><div class="terms">2013-01-06</div></div>',
			$html
		);
	}

	public function test_exposure_rows_only_for_known_details_of_an_image(): void {

		// credit is not one of the details; a post is not an image.
		$this->assertSame( '', ImageTaxonomies::renderRows( 7, [ 'exif:credit', 'exif:nonsense' ] ) );
		$this->assertSame( '', ImageTaxonomies::renderRows( 8, [ 'exif:aperture' ] ) );
	}

	#[DataProvider( 'exposures' )]
	public function test_exposure_details_as_cameras_write_them( string $key, $value, string $expected ): void {

		$this->assertSame( $expected, ImageTaxonomies::formatExif( $key, $value ) );
	}

	public static function exposures(): array {

		return [
			[ 'aperture', '8', 'f/8' ],
			[ 'aperture', '1.4', 'f/1.4' ],
			[ 'shutter_speed', '0.05', '1/20 s' ],
			[ 'shutter_speed', '0.4', '0.4 s' ],
			[ 'shutter_speed', '2', '2 s' ],
			[ 'shutter_speed', '1.3', '1.3 s' ],
			[ 'focal_length', '4.28', '4 mm' ],
			[ 'iso', '0', '' ],
			[ 'aperture', '', '' ],
			[ 'camera', ' ', '' ],
		];
	}

	public function test_exif_widget_reads_its_own_details_and_closes_its_markup(): void {

		Functions\when( 'apply_filters' )->returnArg( 2 );

		$widget = ( new \ReflectionClass( ExifDisplayWidget::class ) )->newInstanceWithoutConstructor();
		$args   = [ 'before_widget' => '<section>', 'after_widget' => '</section>', 'before_title' => '<h2>', 'after_title' => '</h2>' ];

		ob_start();
		$widget->widget( $args, [ 'title' => 'Exposure', 'keys' => 'aperture, ISO' ] );
		$this->assertSame(
			'<section><h2>Exposure</h2><div class="display-exif-widget display-taxonomy-terms-widget"><div class="container"><div class="label">Aperture: </div><div class="terms">f/2.8</div></div><div class="container"><div class="label">ISO: </div><div class="terms">400</div></div></div></section>',
			ob_get_clean()
		);

		// Saved without a list: its defaults, iso, aperture and camera.
		$this->assertSame( [ 'exif:iso', 'exif:aperture', 'exif:camera' ], ExifDisplayWidget::rows( '' ) );
	}

	public function test_exif_widget_shows_nothing_off_an_image_page(): void {

		Functions\when( 'get_the_ID' )->justReturn( 8 );

		$widget = ( new \ReflectionClass( ExifDisplayWidget::class ) )->newInstanceWithoutConstructor();

		ob_start();
		$widget->widget( [ 'before_widget' => '<section>', 'after_widget' => '</section>' ], [ 'keys' => 'iso' ] );
		$this->assertSame( '', ob_get_clean() );
	}
}
