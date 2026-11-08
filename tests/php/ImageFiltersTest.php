<?php

namespace PhotoPress\Tests;

use Brain\Monkey\Functions;
use PhotoPress\modules\search\ImageFilters;

/**
 * The Image Filters block: the filters read from the query vars, the
 * addresses its links go to, the terms its menus list, and robots.txt.
 */
final class ImageFiltersTest extends TestCase {

	/** Query vars of the request. */
	private array $vars = [];

	/** The Pages & Search settings saved. */
	private array $saved = [ 'images_in_search' => true ];

	protected function setUp(): void {

		parent::setUp();

		$vars  = &$this->vars;
		$saved = &$this->saved;

		Functions\stubs( [
			'get_option'            => static function ( $name, $default = false ) use ( &$saved ) {
				return 'photopress_core_search' === $name ? $saved : $default;
			},
			// photos_people and pp_genre for images, pp_client for the admin
			// only, pp_hidden with no query var.
			'get_object_taxonomies' => [ 'photos_people', 'pp_client', 'pp_genre', 'pp_hidden' ],
			'get_taxonomy'          => static fn( $name ) => (object) [
				'object_type' => [ 'attachment' ],
				'public'      => 'pp_client' !== $name,
				'query_var'   => 'pp_hidden' === $name ? false : $name,
				'labels'      => (object) [ 'name' => ucfirst( $name ) ],
			],
			'get_query_var'         => static function ( $name ) use ( &$vars ) {
				return $vars[ $name ] ?? '';
			},
			'home_url'              => 'https://example.com/',
			'add_query_arg'         => static fn( $args, $url ) => $url . '?' . implode( '&', array_map( static fn( $k, $v ) => "$k=$v", array_keys( $args ), $args ) ),
		] );
	}

	public function test_it_offers_the_image_taxonomies_visitors_see_with_a_query_var_but_those_it_hides(): void {

		$this->assertSame( [ 'photos_people', 'pp_genre' ], ImageFilters::taxonomies() );
		$this->assertSame( [ 'photos_people' ], ImageFilters::taxonomies( [ 'pp_genre' ] ) );
	}

	public function test_image_filter_has_a_variation_per_taxonomy_offered(): void {

		$variations = ImageFilters::variations();

		$this->assertSame( [ 'photos_people', 'pp_genre' ], array_column( $variations, 'name' ) );
		$this->assertSame( [ 'Photos_people', 'Pp_genre' ], array_column( $variations, 'title' ) );
		$this->assertSame( [ 'taxonomy' => 'pp_genre' ], $variations[1]['attributes'] );
		$this->assertSame( [ 'taxonomy' ], $variations[1]['isActive'] );
	}

	public function test_the_filters_applied_are_the_query_vars_terms_however_joined(): void {

		$this->vars = [ 'photos_people' => 'ken-thompson+dennis-ritchie', 'pp_genre' => 'portrait,portrait' ];
		$this->assertSame( [ 'photos_people' => [ 'ken-thompson', 'dennis-ritchie' ], 'pp_genre' => [ 'portrait' ] ], ImageFilters::applied( [ 'photos_people', 'pp_genre' ] ) );

		$this->vars = [ 'photos_people' => 'ken-thompson dennis-ritchie' ];
		$this->assertSame( [ 'photos_people' => [ 'ken-thompson', 'dennis-ritchie' ] ], ImageFilters::applied( [ 'photos_people', 'pp_genre' ] ), 'a + read as a space' );

		$this->vars = [];
		$this->assertSame( [], ImageFilters::applied( [ 'photos_people' ] ) );
	}

	public function test_a_set_of_filters_has_one_address_in_the_order_of_the_taxonomies(): void {

		$taxonomies = [ 'photos_people', 'pp_genre' ];

		$this->assertSame(
			'https://example.com/?s=golden%20gate&photos_people=ken-thompson+dennis-ritchie&pp_genre=portrait',
			ImageFilters::url( 'golden gate', [ 'pp_genre' => [ 'portrait' ], 'photos_people' => [ 'ken-thompson', 'dennis-ritchie' ] ], $taxonomies )
		);
		$this->assertSame( 'https://example.com/?s=golden%20gate', ImageFilters::url( 'golden gate', [ 'pp_genre' => [] ], $taxonomies ), 'a taxonomy with none left' );
	}

	public function test_the_menus_list_terms_that_narrow_most_first_without_those_applied(): void {

		$term = static fn( $id, $taxonomy, $slug, $name ) => (object) [ 'term_id' => $id, 'taxonomy' => $taxonomy, 'slug' => $slug, 'name' => $name ];

		// Three images: Ken on two, Dennis on one; all three portraits; one
		// landscape; Brian applied, on all three.
		$terms = [
			$term( 1, 'photos_people', 'ken', 'Ken' ),
			$term( 1, 'photos_people', 'ken', 'Ken' ),
			$term( 2, 'photos_people', 'dennis', 'Dennis' ),
			$term( 3, 'photos_people', 'brian', 'Brian' ),
			$term( 3, 'photos_people', 'brian', 'Brian' ),
			$term( 3, 'photos_people', 'brian', 'Brian' ),
			$term( 4, 'pp_genre', 'portrait', 'Portrait' ),
			$term( 4, 'pp_genre', 'portrait', 'Portrait' ),
			$term( 4, 'pp_genre', 'portrait', 'Portrait' ),
			$term( 5, 'pp_genre', 'landscape', 'Landscape' ),
		];

		$tally = ImageFilters::tally( $terms, 3, [ 'photos_people' => [ 'brian' ] ] );

		$this->assertSame( [ 'Ken', 'Dennis' ], array_map( static fn( $row ) => $row['term']->name, $tally['photos_people'] ) );
		$this->assertSame( [ 2, 1 ], array_column( $tally['photos_people'], 'count' ) );
		// Portrait is on every image found, so it would not narrow.
		$this->assertSame( [ 'Landscape' ], array_map( static fn( $row ) => $row['term']->name, $tally['pp_genre'] ) );

		// Ties by name.
		$tally = ImageFilters::tally( [ $term( 2, 'photos_people', 'dennis', 'dennis' ), $term( 1, 'photos_people', 'ken', 'Ken' ), $term( 6, 'photos_people', 'brian', 'Brian' ) ], 3, [] );
		$this->assertSame( [ 'Brian', 'dennis', 'Ken' ], array_map( static fn( $row ) => $row['term']->name, $tally['photos_people'] ) );

		// Nothing that narrows: no menu.
		$this->assertSame( [], ImageFilters::tally( [ $term( 4, 'pp_genre', 'portrait', 'Portrait' ) ], 1, [] ) );
	}

	public function test_robots_txt_disallows_the_filter_query_vars_while_images_are_in_search(): void {

		$robots = ImageFilters::robots( "User-agent: *\nDisallow:\n", '1' );
		$this->assertStringContainsString( "User-agent: *\nDisallow:\n\n# PhotoPress", $robots );
		$this->assertStringContainsString( "Disallow: /*?*photos_people=\nDisallow: /*?*pp_genre=\n", $robots );
		$this->assertStringNotContainsString( 'pp_client', $robots );

		$this->assertSame( 'x', ImageFilters::robots( 'x', '0' ), 'a site asking not to be indexed' );

		$this->saved = [];
		$this->assertSame( 'x', ImageFilters::robots( 'x', '1' ), 'images not in search' );
	}
}
