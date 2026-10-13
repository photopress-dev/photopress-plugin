<?php

namespace PhotoPress\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PhotoPress\jobs\Jobs;
use PhotoPress\modules\search\TermChanges;

/**
 * Changes to images' terms, announced with PhotoPress's own hooks: an image
 * joining or leaving a term, and a term renamed, given a new slug, or
 * deleted, its images each in the request, or, with more, all together and
 * each in a job.
 */
final class TermChangesTest extends TestCase {

	private array $options = [];

	/** Terms by term_id: name and slug. */
	private array $terms = [];

	/**
	 * What was announced: [ 'changed', image ID, context ], or [ 'many',
	 * images ].
	 */
	private array $announced = [];

	/** Whether a listener needs the job. */
	private bool $jobNeeded = true;

	protected function setUp(): void {

		global $wpdb;

		parent::setUp();

		$this->options = [];
		$this->terms = [ 5 => [ 'Ojai', 'ojai' ], 6 => [ 'Big Sur', 'big-sur' ] ];
		$this->announced = [];
		$this->jobNeeded = true;
		$options = &$this->options;
		$terms = &$this->terms;
		$announced = &$this->announced;
		$jobNeeded = &$this->jobNeeded;

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
			'get_term_by'             => static function ( $field, $tt_id, $taxonomy ) use ( &$terms ) {
				return (object) [ 'taxonomy' => $taxonomy, 'slug' => $terms[ $tt_id - 26 ][1] ];
			},
			'get_term_link'           => static fn( $term ) => "https://example.com/keywords/{$term->slug}/",
			'is_taxonomy_viewable'    => true,
			'wp_attachment_is_image'  => static fn( $id ) => $id < 100,
			'get_object_taxonomies'   => [ 'photos_keywords', 'pp_client' ],
			'get_taxonomy'            => static fn( $name ) => (object) [ 'object_type' => [ 'attachment' ], 'public' => 'pp_client' !== $name ],
			'wp_cache_delete'         => true,
			'wp_generate_uuid4'       => static fn() => bin2hex( random_bytes( 16 ) ),
			'get_current_user_id'     => 1,
			'wp_convert_hr_to_bytes'  => -1,
			'as_enqueue_async_action' => 1,
			'as_has_scheduled_action' => false,
			'as_get_scheduled_actions' => [],
		] );

		// The images of each term, by term_taxonomy_id; posts that aren't images.
		$wpdb = new class() {
			public $posts = 'wp_posts';
			public $term_relationships = 'wp_term_relationships';
			public array $images = [ 31 => [ 4, 9, 12 ], 32 => [] ];
			public array $notImages = [ 100 ];
			public function prepare( $query, ...$args ) { return vsprintf( $query, $args ); }
			private function of( $query ) {
				$ids = [];
				if ( preg_match( '/term_taxonomy_id IN \( ([\d,]+) \)/', $query, $in ) ) {
					foreach ( explode( ',', $in[1] ) as $tt_id ) {
						$ids = array_merge( $ids, $this->images[ (int) $tt_id ] ?? [] );
					}
				}
				if ( preg_match( '/OR p\.ID IN \( ([\d,]+) \)|AND \( p\.ID IN \( ([\d,]+) \)/', $query, $in ) ) {
					$ids = array_merge( $ids, array_diff( array_map( 'intval', explode( ',', $in[1] ?: $in[2] ) ), $this->notImages ) );
				}
				sort( $ids );
				return array_values( array_unique( $ids ) );
			}
			public function get_col( $query ) {
				preg_match( '/p\.ID > (\d+) .* LIMIT (\d+)/', $query, $m );
				return array_slice( array_values( array_filter( $this->of( $query ), static fn( $id ) => $id > (int) $m[1] ) ), 0, (int) $m[2] );
			}
			public function get_var( $query ) { return count( $this->of( $query ) ); }
		};

		TermChanges::addHooks();

		// The job unpaced: batch after batch, without resting.
		Jobs::register( TermChanges::JOB, [
			'label'       => 'Terms',
			'count'       => [ TermChanges::class, 'countImages' ],
			'items'       => [ TermChanges::class, 'nextImages' ],
			'process'     => [ TermChanges::class, 'refreshImage' ],
			'finish'      => [ TermChanges::class, 'startWaiting' ],
			'batch_items' => TermChanges::NOW,
		] );

		// Listeners, as ImageSearch and PageCache are.
		add_action( 'photopress_image_terms_changed', 'listener' );
		add_action( 'photopress_term_images_changed', 'listener' );
		Actions\expectDone( 'photopress_image_terms_changed' )->zeroOrMoreTimes()->whenHappen( static function ( $id, $context ) use ( &$announced ) {
			$announced[] = [ 'changed', $id, $context ];
		} );
		Actions\expectDone( 'photopress_term_images_changed' )->zeroOrMoreTimes()->whenHappen( static function ( $images ) use ( &$announced ) {
			$announced[] = [ 'many', $images ];
		} );
		Filters\expectApplied( 'photopress_term_images_job' )->zeroOrMoreTimes()->andReturnUsing( static function () use ( &$jobNeeded ) {
			return $jobNeeded;
		} );
	}

	protected function tearDown(): void {

		global $wpdb;

		$wpdb = null;
		parent::tearDown();
	}

	/** What was announced since. */
	private function announced(): array {

		$announced = $this->announced;
		$this->announced = [];

		return $announced;
	}

	/** Each of these images announced, with these archives. */
	private static function changed( array $ids, array $slugs, bool $job = false ): array {

		$archives = array_map( static fn( $slug ) => "https://example.com/keywords/$slug/", $slugs );

		return array_map( static fn( $id ) => [ 'changed', $id, [ 'archives' => $archives, 'job' => $job ] ], $ids );
	}

	/** Edits a term as wp_update_term() does: its hooks around the change. */
	private function edit( int $term_id, string $name, ?string $slug = null ): void {

		TermChanges::termEditing( $term_id, 'photos_keywords' );
		$this->terms[ $term_id ] = [ $name, $slug ?? $this->terms[ $term_id ][1] ];
		TermChanges::termEdited( $term_id, 26 + $term_id, 'photos_keywords' );
	}

	/**
	 * Deletes a term as wp_delete_term() does: each of its posts leaving it,
	 * then delete_term with their IDs.
	 */
	private function delete( int $term_id, array $object_ids ): void {

		global $wpdb;

		$tt_id = 26 + $term_id;
		TermChanges::termDeleting( $term_id, 'photos_keywords' );

		foreach ( $object_ids as $id ) {
			TermChanges::imageTermsChanged( $id, [ $tt_id ], 'photos_keywords' );
		}

		$wpdb->images[ $tt_id ] = [];
		TermChanges::termDeleted( $term_id, $tt_id, 'photos_keywords', (object) [], $object_ids );
	}

	public function test_an_image_joining_a_term_or_leaving_terms(): void {

		TermChanges::imageTermsChanged( 7, 32, 'photos_keywords' );
		TermChanges::imageTermsChanged( 7, [ 31, 32 ], 'photos_keywords' );
		$this->assertSame( [ ...self::changed( [ 7 ], [ 'big-sur' ] ), ...self::changed( [ 7 ], [ 'ojai', 'big-sur' ] ) ], $this->announced() );

		// A taxonomy visitors don't see; a post.
		TermChanges::imageTermsChanged( 7, [ 40 ], 'pp_client' );
		TermChanges::imageTermsChanged( 101, 31, 'photos_keywords' );
		$this->assertSame( [], $this->announced() );
	}

	public function test_a_term_edited_but_not_renamed_is_not_announced(): void {

		$this->edit( 5, 'Ojai' );
		TermChanges::termEditing( 5, 'pp_client' );
		TermChanges::termEdited( 5, 31, 'pp_client' );

		$this->assertSame( [], $this->announced() );
	}

	public function test_renamed_or_a_new_slug_its_images_are_announced_with_its_archive_at_each_address(): void {

		$this->edit( 5, 'Ojai, California' );
		$this->assertSame( self::changed( [ 4, 9, 12 ], [ 'ojai' ] ), $this->announced() );

		$this->edit( 5, 'Ojai', 'ojai-valley' );
		$this->assertSame( self::changed( [ 4, 9, 12 ], [ 'ojai', 'ojai-valley' ] ), $this->announced() );
	}

	public function test_a_term_with_more_images_is_announced_together_with_a_job_and_one_renamed_while_it_runs_waits(): void {

		global $wpdb;

		$wpdb->images = [ 31 => range( 1, 40 ), 32 => [ 50, 51 ] ];

		$this->edit( 5, 'Ojai Valley' );
		$this->assertSame( [ [ 'many', [ 'terms' => [ 31 ] ] ] ], $this->announced() );

		$job = Jobs::recent( TermChanges::JOB )[0];
		$this->assertSame( [ [ 'terms' => [ 31 ] ], 40 ], [ $job['args'], $job['total'] ] );

		// Another, with few images, while the job runs: at once. One with
		// more: after the job.
		$this->edit( 6, 'Big Sur Coast' );
		$this->assertSame( self::changed( [ 50, 51 ], [ 'big-sur' ] ), $this->announced() );
		$wpdb->images[32] = range( 60, 90 );
		$this->edit( 6, 'Big Sur' );
		$this->assertSame( [ [ 'many', [ 'terms' => [ 32 ] ] ] ], $this->announced() );
		$this->assertSame( [ 'terms' => [ 32 ] ], $this->options[ TermChanges::WAITING ] );

		while ( Jobs::runBatch( $job['id'] ) ) {
			// A batch at a time.
		}
		$this->assertSame( self::changed( range( 1, 40 ), [], true ), $this->announced() );
		$this->assertSame( 'done', Jobs::get( $job['id'] )['status'] );

		$next = Jobs::recent( TermChanges::JOB )[0];
		$this->assertSame( [ [ 'terms' => [ 32 ] ], 31 ], [ $next['args'], $next['total'] ] );
		$this->assertArrayNotHasKey( TermChanges::WAITING, $this->options );
	}

	public function test_no_job_when_no_listener_needs_one(): void {

		global $wpdb;

		$wpdb->images[31] = range( 1, 40 );
		$this->jobNeeded = false;

		$this->edit( 5, 'Ojai Valley' );
		$this->assertSame( [ [ 'many', [ 'terms' => [ 31 ] ] ] ], $this->announced() );
		$this->assertSame( [], Jobs::recent( TermChanges::JOB ) );
	}

	public function test_deleted_its_images_are_announced_together_with_its_archive(): void {

		// 100 is a post, not an image.
		$this->delete( 5, [ 4, 9, 12, 100 ] );
		$this->assertSame( self::changed( [ 4, 9, 12 ], [ 'ojai' ] ), $this->announced() );

		// Deleted, an image leaving the term is an image leaving any term.
		TermChanges::imageTermsChanged( 4, [ 31 ], 'photos_keywords' );
		$this->assertCount( 1, $this->announced() );
	}

	public function test_deleted_with_more_images_they_are_announced_together_with_a_job(): void {

		global $wpdb;

		$wpdb->images = [ 31 => range( 1, 40 ), 32 => range( 50, 80 ) ];

		$this->delete( 5, range( 1, 40 ) );
		$this->assertSame( [ [ 'many', [ 'images' => range( 1, 40 ) ] ] ], $this->announced() );

		$job = Jobs::recent( TermChanges::JOB )[0];
		$this->assertSame( [ [ 'images' => range( 1, 40 ) ], 40 ], [ $job['args'], $job['total'] ] );

		// Renamed and deleted while it runs: one job after it, for both.
		$this->edit( 6, 'Big Sur Coast' );
		$this->delete( 6, range( 50, 80 ) );
		$this->assertSame( [ 'terms' => [ 32 ], 'images' => range( 50, 80 ) ], $this->options[ TermChanges::WAITING ] );

		while ( Jobs::runBatch( $job['id'] ) ) {
			// A batch at a time.
		}

		$next = Jobs::recent( TermChanges::JOB )[0];
		$this->assertSame( 31, $next['total'], 'the deleted term has no images left; its images listed' );
	}
}
