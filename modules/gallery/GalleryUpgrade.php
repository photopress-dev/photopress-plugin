<?php

namespace PhotoPress\modules\gallery;

defined( 'ABSPATH' ) || exit;

use PhotoPress\jobs\Jobs;
use PhotoPress\jobs\Scheduler;

/**
 * Once a site, after an update (VERSION): a job over the posts with a legacy
 * Child Pages block or a Taxonomy Gallery that chooses its images. Each
 * legacy block becomes a Child Pages Gallery (LegacyChildPages), in an edit
 * of the post; each Taxonomy Gallery is recorded for the page cache
 * (TaxonomyGalleryPages), as a save would record it.
 *
 * Posts of every type with block content and every status but auto-draft:
 * templates, template parts and synced patterns too.
 */
class GalleryUpgrade {

	const JOB = 'gallery.upgrade';

	/** The upgrade done, by its VERSION. */
	const OPTION = 'photopress_gallery_upgrade';

	const VERSION = 1;

	public static function addHooks() {

		Jobs::register( self::JOB, [
			'label'       => __( 'Update galleries saved by earlier versions', 'photopress' ),
			'count'       => static fn() => self::count(),
			'items'       => static fn( $after, $limit ) => self::posts( (int) $after, (int) $limit ),
			'process'     => [ self::class, 'upgrade' ],
			'batch_items' => 20,
		] );

		add_action( 'wp_loaded', [ self::class, 'maybeStart' ] );
	}

	/** wp_loaded: the job started, once a site. */
	public static function maybeStart() {

		if ( (int) get_option( self::OPTION ) >= self::VERSION || ! Scheduler::ensure() ) {
			return;
		}

		$job = Jobs::start( self::JOB );

		if ( ! is_wp_error( $job ) || 'photopress_job_running' === $job->get_error_code() ) {
			update_option( self::OPTION, self::VERSION, true );
		}
	}

	/** The job's work: one post. */
	public static function upgrade( $id ) {

		$post = get_post( $id );

		if ( ! $post ) {
			return true;
		}

		if ( false !== strpos( $post->post_content, LegacyChildPages::MARKER ) ) {

			$converted = 0;
			$blocks    = LegacyChildPages::convertBlocks( parse_blocks( $post->post_content ), $converted );

			if ( $converted ) {

				// The post may have markup its author could save and this
				// request could not; only the legacy blocks are changed.
				// The save records its Taxonomy Galleries.
				kses_remove_filters();
				$saved = wp_update_post( wp_slash( [ 'ID' => $post->ID, 'post_content' => serialize_blocks( $blocks ) ] ), true );
				kses_init();

				return is_wp_error( $saved ) ? $saved : true;
			}
		}

		TaxonomyGalleryPages::record( $post );

		return true;
	}

	/** The posts the job has to look at. */
	public static function count() {

		global $wpdb;

		[ $where, $values ] = self::where();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where holds placeholders.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE $where", ...$values ) );
	}

	/**
	 * The next posts after this ID, in ID order.
	 *
	 * @return int[]
	 */
	public static function posts( $after, $limit ) {

		global $wpdb;

		[ $where, $values ] = self::where();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where holds placeholders.
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND $where ORDER BY ID ASC LIMIT %d",
			...array_merge( [ $after ], $values, [ max( 1, $limit ) ] )
		) ) );
	}

	/** The condition the job's posts meet, with its values. */
	private static function where() {

		global $wpdb;

		$types = array_values( array_diff( get_post_types_by_support( 'editor' ), [ 'attachment', 'revision' ] ) ) ?: [ 'post' ];
		$in    = implode( ', ', array_fill( 0, count( $types ), '%s' ) );

		return [
			"post_type IN ( $in ) AND post_status <> 'auto-draft' AND ( post_content LIKE %s OR post_content LIKE %s )",
			array_merge( $types, [
				'%' . $wpdb->esc_like( LegacyChildPages::MARKER ) . '%',
				'%' . $wpdb->esc_like( TaxonomyGalleryPages::MARKER ) . '%',
			] ),
		];
	}
}
