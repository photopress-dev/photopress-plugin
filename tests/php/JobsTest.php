<?php

namespace PhotoPress\Tests;

use Brain\Monkey\Functions;
use PhotoPress\jobs\Jobs;

/**
 * The job runner, with options and Action Scheduler kept in memory.
 */
final class JobsTest extends TestCase {

	private array $options = [];

	/** Jobs whose next batch is pending in Action Scheduler, by ID. */
	private array $queued = [];

	/** The job whose batch Action Scheduler is running, if any. */
	private ?string $running = null;

	protected function setUp(): void {

		parent::setUp();

		$this->options = [];
		$this->queued = [];
		$this->running = null;
		$options = &$this->options;
		$queued = &$this->queued;
		$running = &$this->running;

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
			// As Action Scheduler: a running action counts as scheduled.
			'as_has_scheduled_action' => static function ( $hook, $args ) use ( &$queued, &$running ) { return in_array( $args[0], $queued, true ) || $args[0] === $running; },
			'as_get_scheduled_actions' => static function ( $query ) use ( &$queued, &$running ) {
				$ids = 'in-progress' === ( $query['status'] ?? '' ) ? array_filter( [ $running ] ) : $queued;
				return array_values( isset( $query['args'] ) ? array_intersect( $ids, [ $query['args'][0] ] ) : $ids );
			},
			'as_unschedule_all_actions' => static function ( $hook, $args ) use ( &$queued ) { $queued = array_values( array_diff( $queued, [ $args[0] ] ) ); },
			'as_schedule_single_action' => static function ( $when, $hook, $args ) use ( &$queued ) { $queued[] = $args[0]; return 1; },
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

	private function registerPaced( array &$finished ): void {

		Jobs::register( 'test.paced', [
			'label'       => 'Paced',
			'count'       => static fn() => 5,
			'items'       => static fn( $after, $limit ) => array_slice( array_values( array_filter( [ 1, 2, 3, 4, 5 ], static fn( $i ) => $i > (int) $after ) ), 0, $limit ),
			'process'     => static fn() => true,
			'batch_items' => 2,
			'pace'        => true,
			'finish'      => static function ( $job ) use ( &$finished ) { $finished[] = $job['id']; },
		] );
	}

	public function test_a_paced_job_rests_between_batches_and_is_not_run_early(): void {

		$finished = [];
		$this->registerPaced( $finished );
		\Brain\Monkey\Filters\expectApplied( 'photopress_job_max_load' )->andReturn( PHP_INT_MAX );

		$job = Jobs::start( 'test.paced' );

		$this->assertTrue( Jobs::runBatch( $job['id'] ) );
		$job = Jobs::get( $job['id'] );
		$this->assertSame( 2, $job['done'] );
		$this->assertGreaterThan( time(), $job['next'], 'resting after its batch' );

		// Resting: neither the scheduler nor a watching settings page runs it.
		$this->assertFalse( Jobs::runBatch( $job['id'] ) );
		$this->assertSame( 2, Jobs::get( $job['id'] )['done'] );

		// Rested.
		$job['next'] = time() - 1;
		$this->options[ Jobs::OPTION_PREFIX . $job['id'] ] = $job;
		$this->assertTrue( Jobs::runBatch( $job['id'] ) );
		$this->assertSame( 4, Jobs::get( $job['id'] )['done'] );
		$this->assertSame( [], $finished );
	}

	public function test_a_paced_job_waits_while_the_server_is_busy_and_finishes_once(): void {

		$finished = [];
		$this->registerPaced( $finished );

		// Busy: no load is low enough.
		\Brain\Monkey\Filters\expectApplied( 'photopress_job_max_load' )->andReturn( -1 );

		$job = Jobs::start( 'test.paced' );

		$this->assertFalse( Jobs::runBatch( $job['id'] ) );
		$job = Jobs::get( $job['id'] );
		$this->assertSame( 0, $job['done'] );
		$this->assertSame( 'busy', $job['waiting'] );
		$this->assertGreaterThanOrEqual( time() + Jobs::BUSY_WAIT_SECONDS - 1, $job['next'] );
		$this->assertContains( $job['id'], $this->queued, 'checks again later' );
	}

	public function test_the_finish_callback_runs_when_a_job_is_done(): void {

		$finished = [];
		$this->registerPaced( $finished );
		\Brain\Monkey\Filters\expectApplied( 'photopress_job_max_load' )->andReturn( PHP_INT_MAX );

		$job = Jobs::start( 'test.paced' );

		for ( $i = 0; $i < 5 && 'done' !== Jobs::get( $job['id'] )['status']; $i++ ) {
			$current = Jobs::get( $job['id'] );
			$current['next'] = 0;
			$this->options[ Jobs::OPTION_PREFIX . $job['id'] ] = $current;
			Jobs::runBatch( $job['id'] );
		}

		$this->assertSame( 'done', Jobs::get( $job['id'] )['status'] );
		$this->assertSame( [ $job['id'] ], $finished );
	}

	/** Action Scheduler running the next pending batch, as a queue run does. */
	private function runQueued(): bool {

		$id = array_shift( $this->queued );

		if ( null === $id ) {
			return false;
		}

		$this->running = $id;
		Jobs::runBatch( $id );
		$this->running = null;

		return true;
	}

	public function test_a_job_runs_batch_after_batch_through_action_scheduler_alone(): void {

		Jobs::register( 'test.pairs', [
			'count'       => static fn() => 5,
			'items'       => static fn( $after, $limit ) => array_slice( array_values( array_filter( [ 1, 2, 3, 4, 5 ], static fn( $i ) => $i > (int) $after ) ), 0, $limit ),
			'process'     => static fn() => true,
			'batch_items' => 2,
		] );
		$job = Jobs::start( 'test.pairs' );

		// Each batch, run by Action Scheduler, queues the next: before, it
		// found itself running and queued none.
		$runs = 0;
		while ( $this->runQueued() && $runs < 10 ) {
			$runs++;
		}

		$this->assertSame( 3, $runs );
		$this->assertSame( [ 'done', 5 ], [ Jobs::get( $job['id'] )['status'], Jobs::get( $job['id'] )['done'] ] );
	}

	public function test_a_job_left_without_its_next_batch_is_given_one_unless_a_batch_holds_it(): void {

		$job = Jobs::start( 'test.items' );
		$this->queued = [];

		// A batch at work: left to it.
		$this->options[ 'photopress_job_' . $job['id'] . '_lock' ] = time();
		$this->assertSame( 1, Jobs::recover() );
		$this->assertSame( [], $this->queued );

		// It died.
		$this->options[ 'photopress_job_' . $job['id'] . '_lock' ] = time() - Jobs::LOCK_SECONDS - 1;
		$this->assertSame( 1, Jobs::recover() );
		$this->assertSame( [ $job['id'] ], $this->queued );

		// Already queued: not twice.
		Jobs::recover();
		$this->assertSame( [ $job['id'] ], $this->queued );
	}

	public function test_a_job_whose_type_is_gone_is_cancelled_saying_why(): void {

		$job = Jobs::start( 'test.items' );
		$this->options[ 'photopress_job_' . $job['id'] ]['type'] = 'test.gone';

		$this->assertSame( 0, Jobs::recover() );
		$cancelled = Jobs::get( $job['id'] );
		$this->assertSame( 'cancelled', $cancelled['status'] );
		$this->assertStringContainsString( 'not available', end( $cancelled['errors'] )['message'] );
		$this->assertSame( [], $this->queued );
	}

	public function test_a_batch_keeps_its_lock_while_its_items_take_long(): void {

		$seen = [];
		$options = &$this->options;
		Jobs::register( 'test.slow', [
			'count'   => static fn() => 2,
			'items'   => static fn( $after ) => $after ? [] : [ 1, 2 ],
			'process' => static function ( $item ) use ( &$options, &$seen ) {
				foreach ( $options as $key => $value ) {
					if ( str_ends_with( $key, '_lock' ) ) {
						$seen[] = $value;
						// As if the item before took longer than the lock lasts.
						$options[ $key ] = time() - Jobs::LOCK_SECONDS - 10;
					}
				}
				return true;
			},
		] );
		$job = Jobs::start( 'test.slow' );
		Jobs::runBatch( $job['id'] );

		$this->assertCount( 2, $seen );
		$this->assertGreaterThanOrEqual( time() - 1, $seen[1], 'refreshed after the first item' );
	}
}

