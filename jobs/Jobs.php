<?php

namespace PhotoPress\jobs;

use WP_Error;

/**
 * Long-running jobs over many items (images, posts), run in the background in
 * batches so no request times out, with progress that settings pages show.
 *
 * Batches are run by Action Scheduler (bundled; shared with any other plugin
 * that bundles it): each batch processes items for up to BATCH_SECONDS and
 * queues the next, one batch at a time per job. A settings page showing a
 * running job also runs a batch when it asks for progress and none is
 * running, so a job moves on even where WP-Cron does not run.
 *
 * A job type is registered by the module that owns it:
 *
 *   Jobs::register( 'metadata.reprocess', [
 *       'label'   => 'Re-read image metadata',
 *       'count'   => fn( $args ) => 240,                       // items in all
 *       'items'   => fn( $after, $limit, $args ) => [ 12, 15 ], // next items after the cursor
 *       'process' => fn( $item, $args ) => true,               // true, or a WP_Error
 *   ] );
 *
 * Items are scalars (IDs) in a stable order; the cursor is the last one done.
 *
 * A heavy job (image processing) can also give:
 *
 *   'batch_seconds' => 10,           // time budget of a batch
 *   'batch_items'   => 5,            // items asked for per batch
 *   'pace'          => true,         // rest between batches as long as each
 *                                    // took, and wait while the server is busy
 *   'finish'        => fn( $job ) => …, // when the job is done
 */
class Jobs {

	const GROUP = 'photopress';

	const BATCH_HOOK = 'photopress_job_batch';

	const OPTION_PREFIX = 'photopress_job_';

	const INDEX_OPTION = 'photopress_jobs';

	const BATCH_SECONDS = 20;

	const BATCH_ITEMS = 50;

	/**
	 * A batch whose lock is older than this is taken to have died.
	 */
	const LOCK_SECONDS = 120;

	/**
	 * Errors kept per job, the most recent ones.
	 */
	const MAX_ERRORS = 50;

	/**
	 * A paced job waiting for a busy server checks again this much later.
	 */
	const BUSY_WAIT_SECONDS = 60;

	protected static $types = [];

	public static function addHooks() {

		add_action( self::BATCH_HOOK, [ self::class, 'runBatch' ] );
		add_action( 'rest_api_init', [ JobsRest::class, 'registerRoutes' ] );
	}

	public static function register( $type, array $definition ) {

		self::$types[ $type ] = $definition + [
			'label'         => $type,
			'description'   => '',
			'batch_seconds' => self::BATCH_SECONDS,
			'batch_items'   => self::BATCH_ITEMS,
			'pace'          => false,
			'finish'        => null,
		];
	}

	public static function types() {

		return self::$types;
	}

	/**
	 * Starts a job. One job of a type runs at a time.
	 *
	 * @return array|WP_Error The job.
	 */
	public static function start( $type, array $args = [] ) {

		if ( ! isset( self::$types[ $type ] ) ) {
			return new WP_Error( 'photopress_job_unknown', __( 'Unknown job type.' ), [ 'status' => 400 ] );
		}

		foreach ( self::recent( $type ) as $job ) {
			if ( in_array( $job['status'], [ 'queued', 'running' ], true ) ) {
				return new WP_Error( 'photopress_job_running', __( 'A job of this type is already running.' ), [ 'status' => 409, 'job' => $job ] );
			}
		}

		$id = substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 );
		$job = [
			'id'       => $id,
			'type'     => $type,
			'label'    => self::$types[ $type ]['label'],
			'args'     => $args,
			'total'    => (int) call_user_func( self::$types[ $type ]['count'], $args ),
			'done'     => 0,
			'failed'   => 0,
			'errors'   => [],
			'cursor'   => null,
			'status'   => 'queued',
			'created'  => time(),
			'updated'  => time(),
			'finished' => null,
			'user'     => get_current_user_id(),
			// A paced job's next batch is not due before this.
			'next'     => 0,
			'waiting'  => '',
		];

		self::save( $job );

		$index = (array) get_option( self::INDEX_OPTION, [] );
		array_unshift( $index, $id );

		// The last 20 jobs are kept; older ones are deleted.
		foreach ( array_slice( $index, 20 ) as $old ) {
			delete_option( self::OPTION_PREFIX . $old );
		}

		update_option( self::INDEX_OPTION, array_slice( $index, 0, 20 ), false );

		self::queueBatch( $id );

		return $job;
	}

	public static function get( $id ) {

		// Another request (a batch, a cancel) may have changed it since this
		// one read it.
		wp_cache_delete( self::OPTION_PREFIX . $id, 'options' );

		$job = get_option( self::OPTION_PREFIX . $id );

		return is_array( $job ) ? $job : null;
	}

	/**
	 * The most recent jobs, newest first, of a type or of all.
	 */
	public static function recent( $type = null ) {

		wp_cache_delete( self::INDEX_OPTION, 'options' );

		$jobs = [];

		foreach ( (array) get_option( self::INDEX_OPTION, [] ) as $id ) {

			$job = self::get( $id );

			if ( $job && ( null === $type || $job['type'] === $type ) ) {
				$jobs[] = $job;
			}
		}

		return $jobs;
	}

	public static function cancel( $id ) {

		$job = self::get( $id );

		if ( ! $job ) {
			return new WP_Error( 'photopress_job_not_found', __( 'No such job.' ), [ 'status' => 404 ] );
		}

		if ( in_array( $job['status'], [ 'queued', 'running' ], true ) ) {

			$job['status'] = 'cancelled';
			$job['finished'] = time();
			self::save( $job );

			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( self::BATCH_HOOK, [ $id ], self::GROUP );
			}
		}

		return self::get( $id );
	}

	/**
	 * Runs one batch of a job: items until the time or item budget is used,
	 * then queues the next batch, or marks the job done.
	 *
	 * @return bool Whether a batch ran (false: none due, or one is running).
	 */
	public static function runBatch( $id ) {

		$job = self::get( $id );

		if ( ! $job || ! in_array( $job['status'], [ 'queued', 'running' ], true ) || ! isset( self::$types[ $job['type'] ] ) ) {
			return false;
		}

		$type = self::$types[ $job['type'] ];

		// A paced job resting between batches.
		if ( time() < (int) ( $job['next'] ?? 0 ) ) {
			return false;
		}

		if ( ! self::lock( $id ) ) {
			return false;
		}

		// A paced job waits while the server is busy, rather than adding to it.
		if ( $type['pace'] && self::busy() ) {
			$job['next'] = time() + self::BUSY_WAIT_SECONDS;
			$job['waiting'] = 'busy';
			$job['updated'] = time();
			self::save( $job );
			self::unlock( $id );
			self::queueBatch( $id );
			return false;
		}

		$started = microtime( true );
		$deadline = $started + $type['batch_seconds'];

		$job['status'] = 'running';
		$job['waiting'] = '';
		$items = (array) call_user_func( $type['items'], $job['cursor'], $type['batch_items'], $job['args'] );

		foreach ( $items as $item ) {

			// Cancelled from elsewhere while this batch runs.
			$current = self::get( $id );

			if ( ! $current || 'cancelled' === $current['status'] ) {
				self::unlock( $id );
				return true;
			}

			try {
				$result = call_user_func( $type['process'], $item, $job['args'] );
			} catch ( \Throwable $e ) {
				$result = new WP_Error( 'photopress_job_exception', $e->getMessage() );
			}

			if ( is_wp_error( $result ) ) {
				$job['failed']++;
				$job['errors'][] = [ 'item' => $item, 'message' => $result->get_error_message() ];
				$job['errors'] = array_slice( $job['errors'], -self::MAX_ERRORS );
			} else {
				$job['done']++;
			}

			$job['cursor'] = $item;
			$job['updated'] = time();
			self::save( $job );

			if ( microtime( true ) > $deadline || self::lowOnMemory() ) {
				break;
			}
		}

		// Fewer items than asked for: none are left.
		$finished = count( $items ) < $type['batch_items'] && end( $items ) === $job['cursor'];

		if ( ! $items || $finished ) {
			$job['status'] = 'done';
			$job['finished'] = time();
			$job['total'] = max( $job['total'], $job['done'] + $job['failed'] );
		} elseif ( $type['pace'] ) {
			// At most half the time at work: rest as long as the batch took.
			$job['next'] = time() + (int) ceil( microtime( true ) - $started );
		}

		$job['updated'] = time();
		self::save( $job );
		self::unlock( $id );

		if ( 'running' === $job['status'] ) {
			self::queueBatch( $id );
		} elseif ( 'done' === $job['status'] && is_callable( $type['finish'] ) ) {
			call_user_func( $type['finish'], $job );
		}

		return true;
	}

	/**
	 * Whether the server is busy: its load average over the last minute is
	 * above 70% of its processors (filterable as photopress_job_max_load).
	 * Unknown, as on Windows, counts as not busy.
	 */
	public static function busy() {

		$load = function_exists( 'sys_getloadavg' ) ? sys_getloadavg() : false;

		if ( ! is_array( $load ) || ! isset( $load[0] ) ) {
			return false;
		}

		$max = (float) apply_filters( 'photopress_job_max_load', 0.7 * self::processors() );

		return (float) $load[0] > $max;
	}

	/**
	 * The number of processors, from /proc/cpuinfo where there is one.
	 */
	protected static function processors() {

		$info = @file_get_contents( '/proc/cpuinfo' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions

		return max( 1, $info ? preg_match_all( '/^processor\s*:/m', $info ) : 1 );
	}

	/**
	 * A job that a settings page is watching: runs a batch now if one is
	 * due and none is running, then returns the job.
	 */
	public static function nudge( $id ) {

		$job = self::get( $id );

		if ( $job && in_array( $job['status'], [ 'queued', 'running' ], true ) && time() - (int) $job['updated'] > 5 ) {
			self::runBatch( $id );
		}

		return self::get( $id );
	}

	protected static function queueBatch( $id ) {

		if ( ! function_exists( 'as_enqueue_async_action' ) || as_has_scheduled_action( self::BATCH_HOOK, [ $id ], self::GROUP ) ) {
			return;
		}

		$job = self::get( $id );
		$next = (int) ( $job['next'] ?? 0 );

		if ( $next > time() ) {
			as_schedule_single_action( $next, self::BATCH_HOOK, [ $id ], self::GROUP );
		} else {
			as_enqueue_async_action( self::BATCH_HOOK, [ $id ], self::GROUP );
		}
	}

	protected static function save( array $job ) {

		update_option( self::OPTION_PREFIX . $job['id'], $job, false );
	}

	/**
	 * One batch of a job at a time. add_option() fails if the row exists, so
	 * of two requests only one gets the lock.
	 */
	protected static function lock( $id ) {

		$key = self::OPTION_PREFIX . $id . '_lock';

		if ( add_option( $key, time(), '', false ) ) {
			return true;
		}

		wp_cache_delete( $key, 'options' );

		if ( time() - (int) get_option( $key ) > self::LOCK_SECONDS ) {
			update_option( $key, time(), false );
			return true;
		}

		return false;
	}

	protected static function unlock( $id ) {

		delete_option( self::OPTION_PREFIX . $id . '_lock' );
	}

	/**
	 * More than 80% of PHP's memory limit in use: stop the batch, so the next
	 * starts with a fresh process.
	 */
	protected static function lowOnMemory() {

		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );

		return $limit > 0 && memory_get_usage( true ) > 0.8 * $limit;
	}
}
