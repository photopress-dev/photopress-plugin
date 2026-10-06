<?php

namespace PhotoPress\Tests;

use Brain\Monkey\Functions;
use PhotoPress\modules\metadata\ImageTaxonomyRest;
use PhotoPress\modules\metadata\TermsController;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The image taxonomies are in REST for editors only. Each gate is checked for
 * a visitor (nothing of ours visible) and for an editor (untouched).
 */
final class ImageTaxonomyRestTest extends TestCase {

	private bool $editor = false;

	protected function setUp(): void {

		parent::setUp();

		$ours = [
			'photos_city' => (object) [ 'name' => 'photos_city', 'rest_base' => '', 'rest_namespace' => 'wp/v2', 'rest_controller_class' => TermsController::class ],
			'pp_person'   => (object) [ 'name' => 'pp_person', 'rest_base' => 'people', 'rest_namespace' => 'wp/v2', 'rest_controller_class' => TermsController::class ],
		];
		$other = (object) [ 'name' => 'media_folder', 'rest_base' => '', 'rest_namespace' => 'wp/v2', 'rest_controller_class' => 'WP_REST_Terms_Controller' ];

		Functions\stubs( [
			'current_user_can'      => fn( $cap ) => 'edit_posts' === $cap && $this->editor,
			'get_object_taxonomies' => static fn() => $ours + [ 'media_folder' => $other ],
			'get_taxonomy'          => static fn( $name ) => $ours[ $name ] ?? $other,
			// Used by WP_REST_Response::remove_link(); WordPress's implementation.
			'wp_list_filter'        => static fn( $list, $args = [], $operator = 'AND' ) => ( new \WP_List_Util( $list ) )->filter( $args, $operator ),
		] );
	}

	public static function visitorAndEditor(): array {

		return [ 'visitor' => [ false ], 'editor' => [ true ] ];
	}

	private static function endpoints(): array {

		$media = [ [ 'methods' => 'GET', 'args' => [ 'photos_city' => [], 'photos_city_exclude' => [], 'people' => [], 'people_exclude' => [], 'search' => [] ] ] ];

		return [
			'/wp/v2/photos_city'                    => [ [ 'methods' => 'GET' ] ],
			'/wp/v2/photos_city/(?P<id>[\d]+)'      => [ [ 'methods' => 'GET' ] ],
			'/wp/v2/people'                         => [ [ 'methods' => 'GET' ] ],
			'/wp/v2/people/(?P<id>[\d]+)'           => [ [ 'methods' => 'GET' ] ],
			'/wp/v2/media_folder'                   => [ [ 'methods' => 'GET' ] ],
			'/wp/v2/media'                          => $media,
			'/wp/v2/search'                         => [ [ 'methods' => 'GET', 'args' => [ 'subtype' => [ 'items' => [ 'enum' => [ 'category', 'photos_city', 'pp_person', 'any' ] ] ] ] ] ],
		];
	}

	#[DataProvider( 'visitorAndEditor' )]
	public function test_routes_exist_for_editors_only( bool $editor ): void {

		$this->editor = $editor;
		$routes = ImageTaxonomyRest::removeRoutes( self::endpoints() );

		$this->assertSame( $editor, isset( $routes['/wp/v2/photos_city'] ) );
		$this->assertSame( $editor, isset( $routes['/wp/v2/people/(?P<id>[\d]+)'] ), 'by rest_base' );
		$this->assertTrue( isset( $routes['/wp/v2/media_folder'] ), "another plugin's taxonomy is left alone" );
		$this->assertSame( $editor, isset( $routes['/wp/v2/media'][0]['args']['people_exclude'] ) );
		$this->assertArrayHasKey( 'search', $routes['/wp/v2/media'][0]['args'] );
		$this->assertSame(
			$editor ? [ 'category', 'photos_city', 'pp_person', 'any' ] : [ 'category', 'any' ],
			$routes['/wp/v2/search'][0]['args']['subtype']['items']['enum']
		);
	}

	public function test_taxonomies_endpoint_hides_ours_from_visitors(): void {

		$list = new \WP_REST_Response( [ 'category' => [], 'photos_city' => [], 'pp_person' => [] ] );
		$out = ImageTaxonomyRest::hideTaxonomies( $list, null, new \WP_REST_Request( 'GET', '/wp/v2/taxonomies' ) );
		$this->assertSame( [ 'category' ], array_keys( $out->get_data() ) );

		Functions\when( 'rest_convert_error_to_response' )->alias( static fn( $e ) => new \WP_REST_Response( [ 'code' => $e->get_error_code() ], $e->get_error_data()['status'] ) );
		$one = ImageTaxonomyRest::hideTaxonomies( new \WP_REST_Response( [ 'slug' => 'pp_person' ] ), null, new \WP_REST_Request( 'GET', '/wp/v2/taxonomies/pp_person' ) );
		$this->assertSame( 404, $one->get_status() );

		$this->editor = true;
		$untouched = new \WP_REST_Response( [ 'category' => [], 'photos_city' => [] ] );
		$this->assertSame( $untouched, ImageTaxonomyRest::hideTaxonomies( $untouched, null, new \WP_REST_Request( 'GET', '/wp/v2/taxonomies' ) ) );
	}

	public function test_term_search_leaves_ours_out_and_never_widens(): void {

		$this->assertSame( [ 'taxonomy' => [ 'category' ] ], ImageTaxonomyRest::limitTermSearch( [ 'taxonomy' => [ 'category', 'photos_city' ] ] ) );

		// Only ours asked for: an empty list would search every taxonomy.
		$this->assertSame( [ 'taxonomy' => [], 'include' => [ 0 ] ], ImageTaxonomyRest::limitTermSearch( [ 'taxonomy' => [ 'pp_person' ] ] ) );

		$this->editor = true;
		$this->assertSame( [ 'taxonomy' => [ 'pp_person' ] ], ImageTaxonomyRest::limitTermSearch( [ 'taxonomy' => [ 'pp_person' ] ] ) );
	}

	public function test_media_items_lose_our_terms_and_links(): void {

		$response = new \WP_REST_Response( [ 'id' => 7, 'photos_city' => [ 34 ], 'people' => [ 9 ], 'media_folder' => [ 2 ] ] );
		$response->add_link( 'https://api.w.org/term', 'https://x.test/wp-json/wp/v2/photos_city?post=7', [ 'taxonomy' => 'photos_city', 'embeddable' => true ] );
		$response->add_link( 'https://api.w.org/term', 'https://x.test/wp-json/wp/v2/media_folder?post=7', [ 'taxonomy' => 'media_folder', 'embeddable' => true ] );

		$out = ImageTaxonomyRest::stripMediaTerms( $response );

		$this->assertSame( [ 'id' => 7, 'media_folder' => [ 2 ] ], $out->get_data() );
		$this->assertSame( [ 'https://x.test/wp-json/wp/v2/media_folder?post=7' ], array_column( $out->get_links()['https://api.w.org/term'], 'href' ) );
	}

	public function test_media_term_filters_are_ignored_for_visitors(): void {

		$args = [ 'tax_query' => [ [ 'taxonomy' => 'photos_city', 'terms' => [ 34 ] ], [ 'taxonomy' => 'media_folder', 'terms' => [ 2 ] ], 'relation' => 'AND' ] ];

		$this->assertSame( [ 'tax_query' => [ [ 'taxonomy' => 'media_folder', 'terms' => [ 2 ] ], 'AND' ] ], ImageTaxonomyRest::ignoreMediaTermFilters( $args ) );

		$this->editor = true;
		$this->assertSame( $args, ImageTaxonomyRest::ignoreMediaTermFilters( $args ) );
	}

	public function test_media_schema_loses_our_properties_and_term_actions(): void {

		$schema = [
			'properties' => [ 'id' => [], 'photos_city' => [], 'people' => [], 'media_folder' => [] ],
			'links'      => [
				[ 'rel' => 'https://api.w.org/action-publish' ],
				[ 'rel' => 'https://api.w.org/action-assign-photos_city' ],
				[ 'rel' => 'https://api.w.org/action-create-people' ],
				[ 'rel' => 'https://api.w.org/action-assign-media_folder' ],
			],
		];

		$out = ImageTaxonomyRest::removeFromMediaSchema( $schema );

		$this->assertSame( [ 'id', 'media_folder' ], array_keys( $out['properties'] ) );
		$this->assertSame( [ 'https://api.w.org/action-publish', 'https://api.w.org/action-assign-media_folder' ], array_column( $out['links'], 'rel' ) );
	}

	public function test_archive_pages_do_not_advertise_a_rest_route(): void {

		$term = ( new \ReflectionClass( \WP_Term::class ) )->newInstanceWithoutConstructor();
		$term->taxonomy = 'photos_city';
		Functions\when( 'get_queried_object' )->justReturn( $term );

		$this->assertSame( '', ImageTaxonomyRest::noAlternateLink( '/wp/v2/photos_city/34' ) );

		$term->taxonomy = 'category';
		$this->assertSame( '/wp/v2/categories/1', ImageTaxonomyRest::noAlternateLink( '/wp/v2/categories/1' ) );
	}

	public function test_terms_controller_refuses_visitors(): void {

		Functions\when( 'rest_authorization_required_code' )->justReturn( 401 );
		$controller = ( new \ReflectionClass( TermsController::class ) )->newInstanceWithoutConstructor();
		$gate = new \ReflectionMethod( $controller, 'gate' );

		$denied = $gate->invoke( $controller, true );
		$this->assertInstanceOf( \WP_Error::class, $denied );
		$this->assertSame( 401, $denied->get_error_data()['status'] );

		$this->editor = true;
		$this->assertTrue( $gate->invoke( $controller, true ) );

		$parentError = new \WP_Error( 'rest_forbidden_context', 'no' );
		$this->assertSame( $parentError, $gate->invoke( $controller, $parentError ), "the parent's own refusal stands" );
	}
}
