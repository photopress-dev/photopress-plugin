<?php

namespace PhotoPress\jobs;

/**
 * Action Scheduler, which runs PhotoPress's background work (Jobs, and the
 * CDN's invalidations and deletions), loaded on the requests that need it
 * rather than on every one:
 *
 * - from the start of a request (plugins_loaded), while PhotoPress has work
 *   scheduled, and where another plugin bundles Action Scheduler too, so
 *   that each copy is registered and the newest is the one used, as Action
 *   Scheduler intends;
 * - otherwise, when PhotoPress schedules work (ensure()): loaded after
 *   plugins_loaded, Action Scheduler starts itself, as it does in themes.
 *
 * When its queue has nothing of PhotoPress's left, it is not loaded again
 * until PhotoPress schedules more, and, with no other plugin using it, its
 * every-minute WP-Cron event is cleared; it schedules it again when it next
 * loads.
 */
class Scheduler {

	/** Action Scheduler's loader, as bundled with PhotoPress. */
	const FILE = __DIR__ . '/../vendor/woocommerce/action-scheduler/action-scheduler.php';

	/**
	 * 'pending' while PhotoPress has work scheduled, 'idle' once it has none.
	 * Until it is first written, as on a site updated from an earlier
	 * version, work is taken to be pending. Autoloaded: read on every
	 * request.
	 */
	const STATE = 'photopress_scheduled';

	/** Action Scheduler's WP-Cron event, and its arguments. */
	const CRON_HOOK = 'action_scheduler_run_queue';
	const CRON_ARGS = [ 'WP Cron' ];

	/** Whether another plugin had loaded Action Scheduler by plugins_loaded. */
	private static $others = false;

	/** At plugin load: the decision waits for every plugin to be loaded. */
	public static function boot() {

		add_action( 'plugins_loaded', [ self::class, 'pluginsLoaded' ], -1, 0 );
		add_action( 'action_scheduler_after_process_queue', [ self::class, 'settle' ] );
	}

	/**
	 * plugins_loaded, before Action Scheduler registers its copies (at 0)
	 * and starts the newest (at 1): PhotoPress's copy loaded if it is
	 * needed from the start.
	 */
	public static function pluginsLoaded() {

		// Each copy of Action Scheduler loads this class when its plugin loads.
		self::$others = class_exists( 'ActionScheduler_Versions', false );

		if ( self::neededFromStart( self::$others ) ) {
			require_once self::FILE;
			return;
		}

		if ( wp_doing_cron() ) {
			add_action( 'init', [ self::class, 'clearCron' ] );
		}
	}

	/**
	 * Whether PhotoPress's copy is to be loaded from the start of the
	 * request: another plugin's is loaded, or PhotoPress has work scheduled.
	 *
	 * @param bool $others Whether another plugin has loaded Action Scheduler.
	 */
	public static function neededFromStart( $others ) {

		return $others || ! self::idle();
	}

	/**
	 * Whether PhotoPress has no work scheduled.
	 */
	public static function idle() {

		return 'idle' === get_option( self::STATE );
	}

	/**
	 * Action Scheduler loaded and ready, for PhotoPress to schedule work
	 * with; noted as having work. False if it could not be.
	 */
	public static function ensure() {

		// Loaded after plugins_loaded, Action Scheduler starts itself, as it
		// does for themes; at once, as init has run.
		if ( ! self::ready() && did_action( 'init' ) && ! class_exists( 'ActionScheduler', false ) ) {
			require_once self::FILE;
		}

		if ( ! self::ready() ) {
			return false;
		}

		if ( 'pending' !== get_option( self::STATE ) ) {
			update_option( self::STATE, 'pending', true );
		}

		return true;
	}

	/** Whether Action Scheduler's functions, as PhotoPress uses them, can be called. */
	public static function ready() {

		return function_exists( 'as_has_scheduled_action' ) && ( ! class_exists( 'ActionScheduler', false ) || \ActionScheduler::is_initialized() );
	}

	/**
	 * Whether an action of PhotoPress's is waiting to run: pending, and not
	 * one that is running. as_has_scheduled_action() counts a running action
	 * too, so an action asking whether its next one is scheduled found
	 * itself, and never scheduled it.
	 *
	 * @param string     $hook
	 * @param array|null $args Its arguments, or any.
	 */
	public static function pending( $hook = '', $args = null ) {

		if ( ! self::ready() || ! function_exists( 'as_get_scheduled_actions' ) ) {
			return false;
		}

		$query = [ 'group' => Jobs::GROUP, 'status' => 'pending', 'per_page' => 1 ];

		if ( '' !== $hook ) {
			$query['hook'] = $hook;
		}

		if ( null !== $args ) {
			$query['args'] = $args;
		}

		return (bool) as_get_scheduled_actions( $query, 'ids' );
	}

	/**
	 * Whether any of PhotoPress's actions is pending or running.
	 */
	private static function busy() {

		return self::pending() || as_get_scheduled_actions( [ 'group' => Jobs::GROUP, 'status' => 'in-progress', 'per_page' => 1 ], 'ids' );
	}

	/**
	 * action_scheduler_after_process_queue: a job left without its next
	 * batch given one (Jobs::recover()); then, once nothing of PhotoPress's
	 * is unfinished, idle, and Action Scheduler's WP-Cron event cleared if
	 * no other plugin uses it.
	 */
	public static function settle() {

		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return;
		}

		if ( Jobs::recover() || self::busy() ) {
			return;
		}

		update_option( self::STATE, 'idle', true );

		// Work scheduled by another request since: pending again.
		if ( self::busy() ) {
			update_option( self::STATE, 'pending', true );
			return;
		}

		if ( ! self::$others ) {
			wp_clear_scheduled_hook( self::CRON_HOOK, self::CRON_ARGS );
		}
	}

	/**
	 * PhotoPress deactivated: its actions unscheduled, as nothing would run
	 * them, and Action Scheduler's WP-Cron event cleared if no other plugin
	 * uses it, or WP-Cron would run every minute for nothing. Unfinished jobs
	 * are kept; they go on when PhotoPress is activated again (Jobs::recover()).
	 */
	public static function deactivate() {

		if ( self::ready() && function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', [], Jobs::GROUP );
		}

		// Taken to be pending until a queue run finds otherwise.
		delete_option( self::STATE );

		if ( ! self::$others ) {
			wp_clear_scheduled_hook( self::CRON_HOOK, self::CRON_ARGS );
		}
	}

	/**
	 * init, in a WP-Cron request with Action Scheduler not loaded: its
	 * WP-Cron event, left from when it was, cleared, as nothing would run
	 * it. WP-Cron would otherwise run every minute for it.
	 */
	public static function clearCron() {

		if ( ! class_exists( 'ActionScheduler', false ) && wp_next_scheduled( self::CRON_HOOK, self::CRON_ARGS ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK, self::CRON_ARGS );
		}
	}
}
