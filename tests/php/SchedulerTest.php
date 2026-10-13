<?php

namespace PhotoPress\Tests;

use Brain\Monkey\Functions;
use PhotoPress\jobs\Scheduler;

final class SchedulerTest extends TestCase {

	/** The site's options, as the scheduler reads and writes them. */
	private array $options = [];

	protected function setUp(): void {

		parent::setUp();

		$this->options = [];
		$this->others( false );

		Functions\when( 'get_option' )->alias( fn( $key, $default = false ) => $this->options[ $key ] ?? $default );
		Functions\when( 'update_option' )->alias( function ( $key, $value ) {
			$this->options[ $key ] = $value;
			return true;
		} );
	}

	private function others( bool $others ): void {

		( new \ReflectionProperty( Scheduler::class, 'others' ) )->setValue( null, $others );
	}

	public function test_loaded_from_the_start_only_while_work_is_scheduled_or_another_plugin_loads_it(): void {

		$this->options[ Scheduler::STATE ] = 'idle';
		$this->assertFalse( Scheduler::neededFromStart( false ), 'idle, and PhotoPress alone' );
		$this->assertTrue( Scheduler::neededFromStart( true ), 'another plugin bundles it: each copy registered, the newest used' );

		$this->options[ Scheduler::STATE ] = 'pending';
		$this->assertTrue( Scheduler::neededFromStart( false ) );

		unset( $this->options[ Scheduler::STATE ] );
		$this->assertTrue( Scheduler::neededFromStart( false ), 'a site updated from an earlier version may have work queued' );
	}

	public function test_scheduling_notes_that_work_is_pending(): void {

		Functions\stubs( [ 'as_has_scheduled_action' => false ] );
		$this->options[ Scheduler::STATE ] = 'idle';

		$this->assertTrue( Scheduler::ensure() );
		$this->assertSame( 'pending', $this->options[ Scheduler::STATE ] );
	}

	public function test_idle_once_none_of_photopresss_actions_is_left_and_its_cron_event_cleared(): void {

		$this->options[ Scheduler::STATE ] = 'pending';
		$left = [ 'pending' => [ 5 ], 'in-progress' => [] ];
		Functions\when( 'as_get_scheduled_actions' )->alias( static function ( $args ) use ( &$left ) {
			return 'photopress' === $args['group'] ? $left[ $args['status'] ] : [];
		} );
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( 'action_scheduler_run_queue', [ 'WP Cron' ] );

		Scheduler::settle();
		$this->assertSame( 'pending', $this->options[ Scheduler::STATE ], 'an action is pending' );

		$left['pending'] = [];
		Scheduler::settle();
		$this->assertSame( 'idle', $this->options[ Scheduler::STATE ] );
	}

	public function test_another_plugins_cron_event_is_left_alone(): void {

		$this->others( true );
		Functions\stubs( [ 'as_get_scheduled_actions' => [] ] );
		Functions\expect( 'wp_clear_scheduled_hook' )->never();

		Scheduler::settle();
		$this->assertSame( 'idle', $this->options[ Scheduler::STATE ] );
	}

	public function test_a_left_over_cron_event_is_cleared_when_nothing_would_run_it(): void {

		Functions\stubs( [ 'wp_next_scheduled' => 1700000000 ] );
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( 'action_scheduler_run_queue', [ 'WP Cron' ] );

		Scheduler::clearCron();
	}
}
