<?php
/**
 * PhotoPress deleted: its settings and records (options and transients),
 * on each site of a network. The images, their terms and their metadata
 * stay, as do other PhotoPress plugins' settings (photopress_seo_*,
 * photopress_pro_*). Action Scheduler's every-minute WP-Cron event goes too,
 * unless an active plugin still uses Action Scheduler.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/** The options PhotoPress keeps, by name. */
const PHOTOPRESS_UNINSTALL_OPTIONS = [
	'photopress_jobs',
	'photopress_scheduled',
	'photopress_version',
	'photopress_reprocess_queue',
	'photopress_taxonomy_rewrites',
	'photopress_flush_rewrite_rules',
	'photopress_old_people',
	'photopress_images_assumed_quality',
	'photopress_purge_after_jobs',
	'photopress_search_term_changes',
	'photopress_sitemap_seen',
	'photopress_cdn_pending',
	'photopress_cdn_last',
];

/** The transients it keeps. */
const PHOTOPRESS_UNINSTALL_TRANSIENTS = [
	'photopress_cdn_distribution',
	'photopress_aws_role_credentials',
	'photopress_cdn_all_cleared',
];

function photopress_uninstall_site() {

	global $wpdb;

	foreach ( PHOTOPRESS_UNINSTALL_OPTIONS as $name ) {
		delete_option( $name );
	}

	foreach ( PHOTOPRESS_UNINSTALL_TRANSIENTS as $name ) {
		delete_transient( $name );
	}

	// The settings of each module (photopress_core_<module>), each job with
	// its lock (photopress_job_<id>, photopress_job_<id>_lock), and random
	// galleries' kept IDs.
	foreach ( [ 'photopress_core_', 'photopress_job_', '_transient_photopress_gallery_ids_', '_transient_timeout_photopress_gallery_ids_' ] as $prefix ) {
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $names as $name ) {
			delete_option( $name );
		}
	}

	// Another active plugin's copy of Action Scheduler runs its queue from
	// this event; without one, nothing would.
	if ( ! class_exists( 'ActionScheduler', false ) ) {
		wp_clear_scheduled_hook( 'action_scheduler_run_queue', [ 'WP Cron' ] );
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $site_id ) {
		switch_to_blog( $site_id );
		photopress_uninstall_site();
		restore_current_blog();
	}
} else {
	photopress_uninstall_site();
}
