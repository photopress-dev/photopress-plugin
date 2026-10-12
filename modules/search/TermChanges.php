<?php

namespace PhotoPress\modules\search;

use PhotoPress\jobs\Jobs;

/**
 * Changes to images' terms, from WordPress's hooks, as PhotoPress's own:
 * an image joining or leaving a term of an image taxonomy, and a term
 * renamed, given a new slug, or deleted. Neither Relevanssi nor Proxy Cache
 * Purge does anything when an image's terms change or a term is renamed,
 * and a deleted term leaves each of its images in turn.
 *
 *     do_action( 'photopress_image_terms_changed', int $id, array $context )
 *
 * An image's terms changed, or one of them was renamed or deleted: what
 * shows its terms is stale. $context: 'archives', the addresses of the
 * archives of those terms (before and after a rename), also stale; 'job',
 * true from the job below, for a term with many images.
 *
 *     do_action( 'photopress_term_images_changed', array $images )
 *
 * A term renamed or deleted with more images than are done in a request
 * (NOW). $images: 'terms' (term_taxonomy_ids) or 'images' (IDs).
 *
 *     apply_filters( 'photopress_term_images_job', bool $needed )
 *
 * Whether those images are needed one by one, in a job, each with
 * photopress_image_terms_changed and 'job' true.
 */
class TermChanges {

	/** The job for the images of renamed and deleted terms. */
	const JOB = 'search.terms';

	/** Terms and images waiting for the job running to finish. */
	const WAITING = 'photopress_search_term_changes';

	/** Images done in the request; a term with more has a job. */
	const NOW = 25;

	/** Terms before an edit, by term_taxonomy_id: name, slug, archive. */
	private static $before = [];

	/** Terms being deleted, by term_taxonomy_id: their archive. */
	private static $deleting = [];

	public static function addHooks() {

		add_action( 'added_term_relationship', [ self::class, 'imageTermsChanged' ], 10, 3 );
		add_action( 'deleted_term_relationships', [ self::class, 'imageTermsChanged' ], 10, 3 );
		add_action( 'edit_terms', [ self::class, 'termEditing' ], 10, 2 );
		add_action( 'edited_term', [ self::class, 'termEdited' ], 10, 3 );
		add_action( 'pre_delete_term', [ self::class, 'termDeleting' ], 10, 2 );
		add_action( 'delete_term', [ self::class, 'termDeleted' ], 10, 5 );

		Jobs::register( self::JOB, [
			'label'         => __( 'Refresh the images of renamed and deleted terms', 'photopress' ),
			'description'   => __( 'Refreshes, as in the search index, the images of image taxonomy terms that were renamed or deleted.', 'photopress' ),
			'count'         => [ self::class, 'countImages' ],
			'items'         => [ self::class, 'nextImages' ],
			'process'       => [ self::class, 'refreshImage' ],
			'finish'        => [ self::class, 'startWaiting' ],
			'batch_seconds' => 10,
			'batch_items'   => self::NOW,
			'pace'          => true,
		] );
	}

	/**
	 * added_term_relationship, deleted_term_relationships: an image joining a
	 * term or leaving terms, as when it is edited or PhotoPress reprocesses
	 * it. A term being deleted is done at delete_term, with all its images.
	 */
	public static function imageTermsChanged( $object_id, $tt_ids, $taxonomy ) {

		if ( ! has_action( 'photopress_image_terms_changed' ) || ! in_array( $taxonomy, ImageSearch::taxonomies(), true ) || 'attachment' !== get_post_type( $object_id ) || self::deleting( $tt_ids ) ) {
			return;
		}

		$archives = [];

		foreach ( array_map( 'intval', (array) $tt_ids ) as $tt_id ) {

			$term = get_term_by( 'term_taxonomy_id', $tt_id, $taxonomy );

			if ( $term ) {
				$archives[] = self::archive( $term );
			}
		}

		do_action( 'photopress_image_terms_changed', (int) $object_id, [ 'archives' => array_values( array_filter( $archives ) ), 'job' => false ] );
	}

	/** edit_terms: the term as it was. */
	public static function termEditing( $term_id, $taxonomy ) {

		$term = self::imageTerm( $term_id, $taxonomy );

		if ( $term ) {
			self::$before[ (int) $term->term_taxonomy_id ] = [ $term->name, $term->slug, self::archive( $term ) ];
		}
	}

	/** edited_term: renamed, or a new slug. */
	public static function termEdited( $term_id, $tt_id, $taxonomy ) {

		$tt_id = (int) $tt_id;

		if ( ! isset( self::$before[ $tt_id ] ) ) {
			return;
		}

		[ $name, $slug, $archive ] = self::$before[ $tt_id ];
		unset( self::$before[ $tt_id ] );
		$term = get_term( (int) $term_id, $taxonomy );

		if ( ! $term || is_wp_error( $term ) || ( $term->name === $name && $term->slug === $slug ) ) {
			return;
		}

		self::changed( [ 'terms' => [ $tt_id ] ], [ $archive, self::archive( $term ) ] );
	}

	/** pre_delete_term: the term's archive, while it still has one. */
	public static function termDeleting( $term_id, $taxonomy ) {

		$term = self::imageTerm( $term_id, $taxonomy );

		if ( $term ) {
			self::$deleting[ (int) $term->term_taxonomy_id ] = self::archive( $term );
		}
	}

	/** delete_term: with the IDs of the posts it had. */
	public static function termDeleted( $term_id, $tt_id, $taxonomy, $deleted_term, $object_ids ) {

		$tt_id = (int) $tt_id;

		if ( ! array_key_exists( $tt_id, self::$deleting ) ) {
			return;
		}

		$archive = self::$deleting[ $tt_id ];
		unset( self::$deleting[ $tt_id ] );
		$ids = array_filter( array_map( 'intval', (array) $object_ids ) );

		if ( $ids ) {
			self::changed( [ 'images' => $ids ], [ $archive ] );
		}
	}

	/**
	 * The images of these terms, or these images, changed with these
	 * archives: each announced, or, when there are more than a few, all
	 * together and, if needed, each in a job.
	 */
	private static function changed( array $args, array $archives ) {

		if ( ! has_action( 'photopress_image_terms_changed' ) && ! has_action( 'photopress_term_images_changed' ) ) {
			return;
		}

		$ids = self::nextImages( null, self::NOW + 1, $args );

		if ( count( $ids ) > self::NOW ) {

			do_action( 'photopress_term_images_changed', self::merge( [], $args ) );

			if ( apply_filters( 'photopress_term_images_job', false ) ) {
				self::startJob( $args );
			}

			return;
		}

		$archives = array_values( array_unique( array_filter( $archives ) ) );

		foreach ( $ids as $id ) {
			do_action( 'photopress_image_terms_changed', $id, [ 'archives' => $archives, 'job' => false ] );
		}
	}

	/** The job's work: an image, announced. */
	public static function refreshImage( $id ) {

		do_action( 'photopress_image_terms_changed', (int) $id, [ 'archives' => [], 'job' => true ] );

		return true;
	}

	/** The job for these terms and images, or, while one runs, after it. */
	public static function startJob( array $args ) {

		$job = Jobs::start( self::JOB, self::merge( [], $args ) );

		if ( is_wp_error( $job ) ) {
			update_option( self::WAITING, self::merge( (array) get_option( self::WAITING, [] ), $args ), false );
		}
	}

	/** The job's finish: the terms and images that waited while it ran. */
	public static function startWaiting() {

		$waiting = self::merge( [], (array) get_option( self::WAITING, [] ) );
		delete_option( self::WAITING );

		if ( $waiting ) {
			self::startJob( $waiting );
		}
	}

	/** The job's count: the images of its terms, and its images. */
	public static function countImages( $args ) {

		global $wpdb;

		$where = self::where( $args );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers.
		return $where ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$where}" ) : 0;
	}

	/** The job's items: the next of those images by ID. @return int[] */
	public static function nextImages( $after, $limit, $args ) {

		global $wpdb;

		$where = self::where( $args );

		if ( ! $where ) {
			return [];
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers.
		return array_map( 'intval', $wpdb->get_col( "SELECT p.ID FROM {$wpdb->posts} p WHERE {$where} AND " . $wpdb->prepare( 'p.ID > %d ORDER BY p.ID ASC LIMIT %d', (int) $after, (int) $limit ) ) );
	}

	/** Whether these are all terms being deleted. */
	private static function deleting( $tt_ids ) {

		$tt_ids = array_map( 'intval', (array) $tt_ids );

		return $tt_ids && ! array_diff( $tt_ids, array_keys( self::$deleting ) );
	}

	/**
	 * The images, for WHERE: of the terms (term_taxonomy_ids), and listed
	 * (IDs; those still images). Integers only.
	 */
	private static function where( $args ) {

		global $wpdb;

		$args = self::merge( [], (array) $args );
		$of   = [];

		if ( isset( $args['terms'] ) ) {
			$of[] = "p.ID IN ( SELECT tr.object_id FROM {$wpdb->term_relationships} tr WHERE tr.term_taxonomy_id IN ( " . implode( ',', $args['terms'] ) . ' ) )';
		}

		if ( isset( $args['images'] ) ) {
			$of[] = 'p.ID IN ( ' . implode( ',', $args['images'] ) . ' )';
		}

		return $of ? "p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%' AND ( " . implode( ' OR ', $of ) . ' )' : '';
	}

	/** Job args together: terms and images, each positive integers, once. */
	private static function merge( array $args, array $more ) {

		$merged = [];

		foreach ( [ 'terms', 'images' ] as $key ) {

			$ids = array_filter( array_map( 'intval', array_merge( (array) ( $args[ $key ] ?? [] ), (array) ( $more[ $key ] ?? [] ) ) ) );

			if ( $ids ) {
				$merged[ $key ] = array_values( array_unique( $ids ) );
			}
		}

		return $merged;
	}

	/** A term of an image taxonomy, or null. */
	private static function imageTerm( $term_id, $taxonomy ) {

		if ( ! in_array( $taxonomy, ImageSearch::taxonomies(), true ) ) {
			return null;
		}

		$term = get_term( (int) $term_id, $taxonomy );

		return $term && ! is_wp_error( $term ) ? $term : null;
	}

	/** A term's archive address, while it has one; or ''. */
	private static function archive( $term ) {

		if ( ! is_taxonomy_viewable( $term->taxonomy ) ) {
			return '';
		}

		$url = get_term_link( $term );

		return is_wp_error( $url ) ? '' : $url;
	}
}
