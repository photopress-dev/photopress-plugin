<?php

namespace PhotoPress\modules\search;

use PhotoPress\jobs\Jobs;

/**
 * An image taxonomy term renamed, or given a new slug. Neither Relevanssi nor
 * Proxy Cache Purge does anything then: Relevanssi's index keeps the old name
 * for the term's images, and the page cache keeps the term's archive and its
 * images' pages, which show the name and link to the archive.
 *
 * Through ImageSearch and PageCache, the term's archive pages are cleared, at
 * the old address and the new, and its images are indexed again and their
 * pages cleared: a few at the end of the request, more in a job.
 */
class RenamedTerms {

	/** The job for the images of renamed terms. */
	const JOB = 'search.renamed';

	/** Renamed terms waiting for the job running to finish. */
	const WAITING = 'photopress_search_renamed_terms';

	/** Images done at the end of the request; a term with more has a job. */
	const NOW = 25;

	/** Terms before an edit, by term_taxonomy_id: name, slug, archive. */
	private static $before = [];

	public static function addHooks() {

		if ( ! ImageSearch::relevanssi() && ! PageCache::purges() ) {
			return;
		}

		add_action( 'edit_terms', [ self::class, 'termEditing' ], 10, 2 );
		add_action( 'edited_term', [ self::class, 'termEdited' ], 10, 3 );

		Jobs::register( self::JOB, [
			'label'         => __( 'Refresh the images of renamed terms', 'photopress' ),
			'description'   => __( 'Indexes again in Relevanssi, and clears from the page cache, the images of image taxonomy terms that were renamed.', 'photopress' ),
			'count'         => [ self::class, 'countImages' ],
			'items'         => [ self::class, 'nextImages' ],
			'process'       => [ self::class, 'refreshImage' ],
			'finish'        => [ self::class, 'startWaiting' ],
			'batch_seconds' => 10,
			'batch_items'   => self::NOW,
			'pace'          => true,
		] );
	}

	/** edit_terms: the term as it was. */
	public static function termEditing( $term_id, $taxonomy ) {

		if ( ! in_array( $taxonomy, ImageSearch::taxonomies(), true ) ) {
			return;
		}

		$term = get_term( (int) $term_id, $taxonomy );

		if ( $term && ! is_wp_error( $term ) ) {
			$link = get_term_link( $term );
			self::$before[ (int) $term->term_taxonomy_id ] = [ $term->name, $term->slug, is_wp_error( $link ) ? '' : $link ];
		}
	}

	/** edited_term: renamed, or a new slug. */
	public static function termEdited( $term_id, $tt_id, $taxonomy ) {

		$tt_id = (int) $tt_id;

		if ( ! isset( self::$before[ $tt_id ] ) ) {
			return;
		}

		[ $name, $slug, $link ] = self::$before[ $tt_id ];
		unset( self::$before[ $tt_id ] );
		$term = get_term( (int) $term_id, $taxonomy );

		if ( ! $term || is_wp_error( $term ) || ( $term->name === $name && $term->slug === $slug ) ) {
			return;
		}

		if ( PageCache::purges() ) {

			if ( $link ) {
				PageCache::purgeArchiveAt( $link );
			}

			PageCache::purgeArchive( $term );
		}

		if ( ! self::refreshes() ) {
			return;
		}

		$ids = self::nextImages( null, self::NOW + 1, [ 'terms' => [ $tt_id ] ] );

		if ( count( $ids ) > self::NOW ) {
			self::startJob( [ $tt_id ] );
			return;
		}

		foreach ( $ids as $id ) {

			if ( ImageSearch::indexesImages() ) {
				ImageSearch::indexLater( $id );
			}

			if ( PageCache::purges() ) {
				PageCache::purgeImagePage( $id );
			}
		}
	}

	/** Whether a term's images need anything: an index or cached pages. */
	public static function refreshes() {

		return ImageSearch::indexesImages() || ( PageCache::purges() && search::enabled( 'attachment_pages' ) );
	}

	/** The job's work: an image indexed again and its page cleared. */
	public static function refreshImage( $id ) {

		if ( ImageSearch::indexesImages() ) {

			$indexed = ImageSearch::indexImage( $id );

			if ( is_wp_error( $indexed ) ) {
				return $indexed;
			}
		}

		if ( PageCache::purges() ) {
			PageCache::purgeImagePage( $id );
		}

		return true;
	}

	/** The job for these terms, or, while one runs, these terms after it. */
	public static function startJob( array $tt_ids ) {

		$job = Jobs::start( self::JOB, [ 'terms' => $tt_ids ] );

		if ( is_wp_error( $job ) ) {
			$waiting = array_map( 'intval', (array) get_option( self::WAITING, [] ) );
			update_option( self::WAITING, array_values( array_unique( array_merge( $waiting, $tt_ids ) ) ), false );
		}
	}

	/** The job's finish: the terms renamed while it ran. */
	public static function startWaiting() {

		$waiting = array_map( 'intval', (array) get_option( self::WAITING, [] ) );
		delete_option( self::WAITING );

		if ( $waiting ) {
			self::startJob( $waiting );
		}
	}

	/** The job's count: the images of its terms. */
	public static function countImages( $args ) {

		global $wpdb;

		$in = self::termIn( $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers.
		return $in ? (int) $wpdb->get_var( "SELECT COUNT( DISTINCT p.ID ) FROM {$wpdb->posts} p JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID WHERE tr.term_taxonomy_id IN ( {$in} ) AND p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'" ) : 0;
	}

	/** The job's items: the next images of its terms by ID. @return int[] */
	public static function nextImages( $after, $limit, $args ) {

		global $wpdb;

		$in = self::termIn( $args );

		if ( ! $in ) {
			return [];
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers.
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID WHERE tr.term_taxonomy_id IN ( {$in} ) AND p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%%' AND p.ID > %d ORDER BY p.ID ASC LIMIT %d",
			(int) $after,
			(int) $limit
		) ) );
	}

	/** The job's terms, for IN (). */
	private static function termIn( $args ) {

		return implode( ',', array_filter( array_map( 'intval', (array) ( $args['terms'] ?? [] ) ) ) );
	}
}
