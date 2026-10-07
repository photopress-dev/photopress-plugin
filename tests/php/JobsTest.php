<?php

namespace PhotoPress\Tests;

use Brain\Monkey\Functions;
use PhotoPress\jobs\Jobs;

/**
 * The job runner, with options and Action Scheduler kept in memory.
 */
final class JobsTest extends TestCase {

	private array $options = [];

	private array $queued = [];

	protected function setUp(): void {

		parent::setUp();

		$this->options = [];
		$this->queued = [];
		$options = &$this->options;
		$queued = &$this->queued;

		Functions\stubs( [
			// Closures with use (&...), not arrow functions: those capture by value.
			'get_option'            => static function ( $key, $default = false ) use ( &$options ) { return $options[ $key ] ?? $default; },
			'update_option'         => static function ( $key, $value ) use ( &$options ) { $options[ $key ] = $value; return true; },
			'add_option'            => static function ( $key, $value ) use ( &$options ) {
				if ( isset( $options[ $key ] ) ) {
					return false;
				}
				$options[ $key ] = $value;
				return true;
			},
			'delete_option'         => static function ( $key ) use ( &$options ) { unset( $options[ $key ] ); return true; },
			'wp_cache_delete'       => true,
			'wp_generate_uuid4'     => static fn() => bin2hex( random_bytes( 16 ) ),
			'get_current_user_id'   => 1,
			'wp_convert_hr_to_bytes' => static fn() => -1,
			'as_enqueue_async_action' => static function ( $hook, $args ) use ( &$queued ) { $queued[] = $args[0]; return 1; },
			'as_has_scheduled_action' => static function ( $hook, $args ) use ( &$queued ) { return in_array( $args[0], $queued, true ); },
			'as_unschedule_all_actions' => static function ( $hook, $args ) use ( &$queued ) { $queued = array_values( array_diff( $queued, [ $args[0] ] ) ); },
		] );

		// Five items; item 3 fails.
		Jobs::register( 'test.items', [
			'label'   => 'Test',
			'count'   => static fn() => 5,
			'items'   => static fn( $after, $limit ) => array_slice( array_values( array_filter( [ 1, 2, 3, 4, 5 ], static fn( $i ) => $i > (int) $after ) ), 0, $limit ),
			'process' => static fn( $item ) => 3 === $item ? new \WP_Error( 'x', 'Item 3 is broken' ) : true,
		] );
	}

	public function test_a_job_runs_to_the_end_and_records_failures(): void {

		$job = Jobs::start( 'test.items' );

		$this->assertSame( 'queued', $job['status'] );
		$this->assertSame( 5, $job['total'] );
		$this->assertSame( [ $job['id'] ], $this->queued, 'the first batch is queued' );

		$this->assertTrue( Jobs::runBatch( $job['id'] ) );
		$done = Jobs::get( $job['id'] );

		$this->assertSame( 'done', $done['status'] );
		$this->assertSame( [ 4, 1 ], [ $done['done'], $done['failed'] ] );
		$this->assertSame( [ [ 'item' => 3, 'message' => 'Item 3 is broken' ] ], $done['errors'] );
		$this->assertFalse( Jobs::runBatch( $job['id'] ), 'a finished job does not run' );
	}

	public function test_one_job_of_a_type_at_a_time(): void {

		Jobs::start( 'test.items' );

		$second = Jobs::start( 'test.items' );

		$this->assertInstanceOf( \WP_Error::class, $second );
		$this->assertSame( 'photopress_job_running', $second->get_error_code() );
	}

	public function test_a_cancelled_job_stops(): void {

		$job = Jobs::start( 'test.items' );
		Jobs::cancel( $job['id'] );

		$this->assertSame( [], $this->queued, 'its batch is unscheduled' );
		$this->assertFalse( Jobs::runBatch( $job['id'] ) );
		$this->assertSame( 0, Jobs::get( $job['id'] )['done'] );
	}

	public function test_one_batch_at_a_time(): void {

		$job = Jobs::start( 'test.items' );
		$this->options[ Jobs::OPTION_PREFIX . $job['id'] . '_lock' ] = time();

		$this->assertFalse( Jobs::runBatch( $job['id'] ), 'another batch holds the lock' );

		$this->options[ Jobs::OPTION_PREFIX . $job['id'] . '_lock' ] = time() - Jobs::LOCK_SECONDS - 1;
		$this->assertTrue( Jobs::runBatch( $job['id'] ), 'a lock older than LOCK_SECONDS is taken over' );
	}

	public function test_unknown_type(): void {

		$this->assertSame( 'photopress_job_unknown', Jobs::start( 'nope' )->get_error_code() );
	}
}
