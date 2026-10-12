<?php

namespace PhotoPress\Tests;

use Brain\Monkey\Functions;
use PhotoPress\jobs\Jobs;
use PhotoPress\modules\search\ImageSearch;
use PhotoPress\modules\search\PageCache;
use PhotoPress\modules\search\RenamedTerms;

/**
 * An image taxonomy term renamed or given a new slug: its archive cleared at
 * the old address and the new, and its images indexed again in Relevanssi
 * and their pages cleared, a few at the end of the request and more in a job.
 */
final class RenamedTermsTest extends TestCase {

	private array $options = [];

	/** Terms by term_id: name and slug. */
	private array $terms = [];

	/** Image IDs Relevanssi indexed. */
	private array $indexed = [];

	protected function setUp(): void {

		global $wpdb;

		parent::setUp();

		require_once __DIR__ . '/fixtures/plugins/VarnishPurger.php';
		\VarnishPurger::$purged = [];

		$this->options = [ 'photopress_core_search' => [ 'images_in_search' => true ] ];
		$this->terms = [ 5 => [ 'Ojai', 'ojai' ], 6 => [ 'Big Sur', 'big-sur' ] ];
		$this->indexed = [];
		$options = &$this->options;
		$terms = &$this->terms;
		$indexed = &$this->indexed;

		Functions\stubs( [
			'get_option'              => static function ( $key, $default = false ) use ( &$options ) { return $options[ $key ] ?? $default; },
			'update_option'           => static function ( $key, $value ) use ( &$options ) { $options[ $key ] = $value; return true; },
			'add_option'              => static function ( $key, $value ) use ( &$options ) {
				if ( isset( $options[ $key ] ) ) {
					return false;
				}
				$options[ $key ] = $value;
				return true;
			},
			'delete_option'           => static function ( $key ) use ( &$options ) { unset( $options[ $key ] ); return true; },
			// term_taxonomy_id is term_id + 26.
			'get_term'                => static function ( $id, $taxonomy ) use ( &$terms ) {
				return (object) [ 'term_taxonomy_id' => 26 + $id, 'taxonomy' => $taxonomy, 'name' => $terms[ $id ][0], 'slug' => $terms[ $id ][1] ];
			},
			'get_term_link'           => static fn( $term ) => "https://example.com/keywords/{$term->slug}/",
			'is_taxonomy_viewable'    => true,
			'get_permalink'           => static fn( $id ) => "https://example.com/image-$id/",
			'get_object_taxonomies'   => [ 'photos_keywords', 'pp_client' ],
			'get_taxonomy'            => static fn( $name ) => (object) [ 'object_type' => [ 'attachment' ], 'public' => 'pp_client' !== $name ],
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

		// The images of each term, by term_taxonomy_id.
		$wpdb = new class() {
			public $posts = 'wp_posts';
			public $term_relationships = 'wp_term_relationships';
			public array $images = [ 31 => [ 4, 9, 12 ], 32 => [] ];
			public function prepare( $query, ...$args ) { return [ $query, $args ]; }
			private function of( $query ) {
				preg_match( '/IN \( ([\d,]+) \)/', is_array( $query ) ? $query[0] : $query, $in );
				$ids = [];
				foreach ( explode( ',', $in[1] ) as $tt_id ) {
					$ids = array_merge( $ids, $this->images[ (int) $tt_id ] ?? [] );
				}
				sort( $ids );
				return array_values( array_unique( $ids ) );
			}
			public function get_col( $prepared ) {
				[ , [ $after, $limit ] ] = $prepared;
				return array_slice( array_values( array_filter( $this->of( $prepared ), static fn( $id ) => $id > $after ) ), 0, $limit );
			}
			public function get_var( $query ) { return count( $this->of( $query ) ); }
		};

		Jobs::register( RenamedTerms::JOB, [
			'label'   => 'Renamed',
			'count'   => [ RenamedTerms::class, 'countImages' ],
			'items'   => [ RenamedTerms::class, 'nextImages' ],
			'process' => [ RenamedTerms::class, 'refreshImage' ],
			'finish'  => [ RenamedTerms::class, 'startWaiting' ],
			'batch_items' => RenamedTerms::NOW,
		] );
	}

	protected function tearDown(): void {

		global $wpdb;

		$wpdb = null;
		parent::tearDown();
	}

	/** Edits a term as wp_update_term() does: its hooks around the change. */
	private function edit( int $term_id, string $name, ?string $slug = null ): void {

		RenamedTerms::termEditing( $term_id, 'photos_keywords' );
		$this->terms[ $term_id ] = [ $name, $slug ?? $this->terms[ $term_id ][1] ];
		RenamedTerms::termEdited( $term_id, 26 + $term_id, 'photos_keywords' );
	}

	/** What the end of the request did: images indexed, pages cleared. */
	private function shutdown(): array {

		ImageSearch::indexChanged();
		PageCache::purge();
		$done = [ $this->indexed, \VarnishPurger::$purged ];
		$this->indexed = [];
		\VarnishPurger::$purged = [];

		return $done;
	}

	public function test_a_term_edited_but_not_renamed_needs_nothing(): void {

		$this->edit( 5, 'Ojai' );
		RenamedTerms::termEditing( 5, 'pp_client' );
		RenamedTerms::termEdited( 5, 31, 'pp_client' );

		$this->assertSame( [ [], [] ], $this->shutdown() );
	}

	public function test_renamed_its_images_are_indexed_and_its_pages_cleared_at_the_end_of_the_request(): void {

		$this->edit( 5, 'Ojai, California' );

		$this->assertSame( [
			[ 4, 9, 12 ],
			[ 'https://example.com/keywords/ojai/?vhp-regex', 'https://example.com/image-4/', 'https://example.com/image-9/', 'https://example.com/image-12/' ],
		], $this->shutdown() );
	}

	public function test_a_new_slug_clears_the_archive_at_both_addresses_and_only_while_there_are_pages(): void {

		// Images not in search, no image pages: the archives alone.
		$this->options['photopress_core_search'] = [ 'attachment_pages' => false ];
		$this->edit( 5, 'Ojai', 'ojai-valley' );
		$this->assertSame( [ [], [ 'https://example.com/keywords/ojai/?vhp-regex', 'https://example.com/keywords/ojai-valley/?vhp-regex' ] ], $this->shutdown() );

		// No archives either: nothing.
		$this->options['photopress_core_search'] = [ 'attachment_pages' => false, 'taxonomy_pages' => false ];
		$this->edit( 5, 'Ojai', 'ojai' );
		$this->assertSame( [ [], [] ], $this->shutdown() );
	}

	public function test_a_term_with_more_images_has_a_job_and_one_renamed_while_it_runs_waits(): void {

		global $wpdb;

		$wpdb->images = [ 31 => range( 1, 40 ), 32 => [ 50, 51 ] ];

		$this->edit( 5, 'Ojai Valley' );
		[ $indexed, $purged ] = $this->shutdown();
		$this->assertSame( [ [], [ 'https://example.com/keywords/ojai/?vhp-regex' ] ], [ $indexed, $purged ], 'its images not in the request' );

		$job = Jobs::recent( RenamedTerms::JOB )[0];
		$this->assertSame( [ [ 'terms' => [ 31 ] ], 40 ], [ $job['args'], $job['total'] ] );

		// Another, with few images, while the job runs: at once. One with
		// more: after the job.
		$this->edit( 6, 'Big Sur Coast' );
		$this->assertSame( [ 50, 51 ], $this->shutdown()[0] );
		$wpdb->images[32] = range( 60, 90 );
		$this->edit( 6, 'Big Sur' );
		$this->shutdown();
		$this->assertSame( [ 32 ], $this->options[ RenamedTerms::WAITING ] );

		while ( Jobs::runBatch( $job['id'] ) ) {
			// A batch at a time.
		}
		[ $indexed, $purged ] = $this->shutdown();
		$this->assertSame( range( 1, 40 ), $indexed );
		$this->assertContains( 'https://example.com/image-40/', $purged );
		$this->assertSame( 'done', Jobs::get( $job['id'] )['status'] );

		$next = Jobs::recent( RenamedTerms::JOB )[0];
		$this->assertSame( [ [ 'terms' => [ 32 ] ], 31 ], [ $next['args'], $next['total'] ] );
		$this->assertArrayNotHasKey( RenamedTerms::WAITING, $this->options );
	}
}
