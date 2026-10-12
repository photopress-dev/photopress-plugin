<?php

namespace PhotoPress\Tests;

use Brain\Monkey\Functions;
use PhotoPress\jobs\Jobs;
use PhotoPress\modules\search\ImageSearch;

/**
 * Images in search results: Relevanssi's settings as PhotoPress has them,
 * where Image Search goes in the template hierarchy, and the search SQL
 * without Relevanssi.
 */
final class ImageSearchTest extends TestCase {

	/** The Pages & Search settings saved. */
	private array $saved = [];

	protected function setUp(): void {

		parent::setUp();

		// set() and get() need nothing else of WordPress.
		require_once ABSPATH . WPINC . '/class-wp-query.php';

		$saved = &$this->saved;

		Functions\stubs( [
			'get_option'             => static function ( $name, $default = false ) use ( &$saved ) {
				return 'photopress_core_search' === $name ? $saved : $default;
			},
			// photos_keywords and pp_genre for images; pp_client for the
			// admin only; category for posts.
			'get_object_taxonomies'  => [ 'photos_keywords', 'pp_client', 'pp_genre' ],
			'get_taxonomy'           => static fn( $name ) => (object) [ 'object_type' => 'category' === $name ? [ 'post' ] : [ 'attachment' ], 'public' => 'pp_client' !== $name ],
			'get_post_type'          => static fn( $id ) => 7 === $id ? 'attachment' : 'post',
		] );
	}

	public function test_relevanssi_always_indexes_the_image_taxonomies_visitors_see(): void {

		$this->assertSame( [ 'category', 'photos_keywords', 'pp_genre' ], ImageSearch::relevanssiTaxonomies( [ 'category', 'photos_keywords' ] ) );
		$this->assertSame( [ 'photos_keywords', 'pp_genre' ], ImageSearch::relevanssiTaxonomies( '' ), 'never saved' );
	}

	public function test_relevanssi_indexes_images_only_while_they_are_in_search(): void {

		$this->assertSame( [ 'post', 'page' ], ImageSearch::relevanssiPostTypes( [ 'post', 'page' ] ), 'off by default' );

		$this->saved = [ 'images_in_search' => true ];
		$this->assertSame( [ 'post', 'page', 'attachment' ], ImageSearch::relevanssiPostTypes( [ 'post', 'page' ] ) );
		$this->assertSame( [ 'attachment' ], ImageSearch::relevanssiPostTypes( [ 'attachment' ] ) );
	}

	public function test_relevanssi_indexes_an_images_alt_text(): void {

		$this->assertSame( [ 'credit', '_wp_attachment_image_alt' ], ImageSearch::relevanssiAltText( [ 'credit' ], 7 ) );
		$this->assertSame( [ 'credit' ], ImageSearch::relevanssiAltText( [ 'credit' ], 8 ), 'not for posts' );
	}

	public function test_image_search_goes_before_the_themes_search_on_the_image_search_only(): void {

		global $wp_query;

		Functions\when( 'wp_is_block_theme' )->justReturn( true );
		$wp_query = new \WP_Query();

		$this->assertSame( [ 'search.php', 'index.php' ], ImageSearch::addTemplate( [ 'search.php', 'index.php' ] ), 'not an image search' );

		$wp_query->set( ImageSearch::FLAG, true );
		$this->assertSame( [ 'search-images.php', 'search.php', 'index.php' ], ImageSearch::addTemplate( [ 'search.php', 'index.php' ] ) );

		Functions\when( 'wp_is_block_theme' )->justReturn( false );
		$this->assertSame( [ 'search.php' ], ImageSearch::addTemplate( [ 'search.php' ] ), 'a classic theme keeps its own' );

		$wp_query = null;
	}

	public function test_an_image_is_found_by_its_terms_and_alt_text_word_by_word(): void {

		global $wpdb;

		Functions\stubs( [
			'is_user_logged_in' => false,
			'apply_filters'     => static fn( $hook, $value ) => $value,
		] );

		$wpdb = new class() {
			public $posts = 'wp_posts';
			public $postmeta = 'wp_postmeta';
			public $term_relationships = 'wp_term_relationships';
			public $term_taxonomy = 'wp_term_taxonomy';
			public $terms = 'wp_terms';
			public function esc_like( $text ) { return addcslashes( $text, '_%\\\\' ); }
			public function prepare( $query, ...$args ) { return vsprintf( str_replace( '%s', "'%s'", $query ), $args ); }
		};

		$query = new \WP_Query();
		$query->set( ImageSearch::FLAG, true );
		$query->set( 'search_terms', [ 'sunset', '-fog' ] );

		$sql = ImageSearch::searchSql( ' AND (core search) ', $query );

		// Each word in the text, the alt text, or an image taxonomy's term
		// names; one to leave out, NOT.
		$this->assertStringContainsString( "wp_posts.post_title LIKE '%sunset%'", $sql );
		$this->assertStringContainsString( "meta_key = '_wp_attachment_image_alt' AND photopress_alt.meta_value LIKE '%sunset%'", $sql );
		$this->assertStringContainsString( "photopress_tt.taxonomy IN ( 'photos_keywords', 'pp_genre' ) AND photopress_t.name LIKE '%sunset%'", $sql );
		$this->assertStringContainsString( "NOT (wp_posts.post_title LIKE '%fog%'", $sql );
		$this->assertStringContainsString( ") AND NOT (", $sql );
		// Only images among attachments; no password protected posts for visitors.
		$this->assertStringContainsString( "post_mime_type LIKE 'image/%'", $sql );
		$this->assertStringContainsString( "post_password = ''", $sql );
		$this->assertStringNotContainsString( 'core search', $sql );

		// Another search is core's.
		$this->assertSame( ' AND (core search) ', ImageSearch::searchSql( ' AND (core search) ', new \WP_Query() ) );

		$wpdb = null;
	}

	public function test_a_renamed_terms_images_are_indexed_again_a_few_now_more_in_a_job(): void {

		global $wpdb;

		$options = [ 'photopress_core_search' => [ 'images_in_search' => true ] ];
		$name = 'Ojai';
		$images = [ 4, 9, 12 ];
		$indexed = [];

		Functions\stubs( [
			'get_option'              => static function ( $key, $default = false ) use ( &$options ) { return $options[ $key ] ?? $default; },
			'update_option'           => static function ( $key, $value ) use ( &$options ) { $options[ $key ] = $value; return true; },
			'delete_option'           => static function ( $key ) use ( &$options ) { unset( $options[ $key ] ); return true; },
			'add_option'              => static function ( $key, $value ) use ( &$options ) { if ( isset( $options[ $key ] ) ) { return false; } $options[ $key ] = $value; return true; },
			'get_term'                => static function ( $id ) use ( &$name ) { return (object) [ 'term_taxonomy_id' => 26 + $id, 'name' => $name ]; },
			'relevanssi_do_query'     => null,
			'relevanssi_get_custom_fields' => [],
			'relevanssi_index_doc'    => static function ( $id ) use ( &$indexed ) { $indexed[] = $id; },
			'wp_cache_delete'         => true,
			'wp_generate_uuid4'       => static fn() => bin2hex( random_bytes( 16 ) ),
			'get_current_user_id'     => 1,
			'wp_convert_hr_to_bytes'  => -1,
			'as_enqueue_async_action' => 1,
			'as_has_scheduled_action' => false,
		] );

		// The images of term_taxonomy_id 31, by ID.
		$wpdb = new class( $images ) {
			public $posts = 'wp_posts';
			public $term_relationships = 'wp_term_relationships';
			public function __construct( public array &$images ) {}
			public function prepare( $query, ...$args ) { return [ $query, $args ]; }
			public function get_col( $prepared ) {
				[ $query, [ $after, $limit ] ] = $prepared;
				return array_slice( array_values( array_filter( $this->images, static fn( $id ) => $id > $after ) ), 0, $limit );
			}
			public function get_var( $query ) { return count( $this->images ); }
		};

		// A description edited: nothing to index.
		ImageSearch::termEditing( 5, 'photos_keywords' );
		ImageSearch::termEdited( 5, 31, 'photos_keywords' );
		$this->assertFalse( has_action( 'shutdown', [ ImageSearch::class, 'indexChanged' ] ) );

		// Not an image taxonomy.
		ImageSearch::termEditing( 5, 'category' );
		$name = 'Ojai Valley';
		ImageSearch::termEdited( 5, 31, 'photos_keywords' );
		$this->assertFalse( has_action( 'shutdown', [ ImageSearch::class, 'indexChanged' ] ) );

		// Renamed, with three images: at the end of the request.
		ImageSearch::termEditing( 5, 'photos_keywords' );
		$name = 'Ojai, California';
		ImageSearch::termEdited( 5, 31, 'photos_keywords' );
		$this->assertNotFalse( has_action( 'shutdown', [ ImageSearch::class, 'indexChanged' ] ) );
		ImageSearch::indexChanged();
		$this->assertSame( $images, $indexed );

		// Renamed, with more than a batch: the term job.
		Jobs::register( ImageSearch::TERM_JOB, [
			'label'   => 'Terms',
			'count'   => [ ImageSearch::class, 'countTermImages' ],
			'items'   => [ ImageSearch::class, 'nextTermImages' ],
			'process' => [ ImageSearch::class, 'indexImage' ],
			'finish'  => [ ImageSearch::class, 'startRenamed' ],
		] );
		$wpdb->images = range( 1, 40 );
		$indexed = [];

		ImageSearch::termEditing( 5, 'photos_keywords' );
		$name = 'Ojai';
		ImageSearch::termEdited( 5, 31, 'photos_keywords' );
		$this->assertSame( [], $indexed, 'not in the request' );
		$job = Jobs::recent( ImageSearch::TERM_JOB )[0];
		$this->assertSame( [ [ 'terms' => [ 31 ] ], 40 ], [ $job['args'], $job['total'] ] );

		// Another renamed while it runs: after it.
		ImageSearch::termEditing( 6, 'photos_keywords' );
		$name = 'Ojai Valley';
		ImageSearch::termEdited( 6, 32, 'photos_keywords' );
		$this->assertSame( [ 32 ], $options[ ImageSearch::RENAMED ] );

		Jobs::runBatch( $job['id'] );
		Jobs::runBatch( $job['id'] );
		$this->assertSame( range( 1, 40 ), $indexed );
		$this->assertSame( 'done', Jobs::get( $job['id'] )['status'] );
		$this->assertSame( [ 'terms' => [ 32 ] ], Jobs::recent( ImageSearch::TERM_JOB )[0]['args'] );
		$this->assertArrayNotHasKey( ImageSearch::RENAMED, $options );

		$wpdb = null;
	}
}
