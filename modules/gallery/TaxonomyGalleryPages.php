<?php

namespace PhotoPress\modules\gallery;

defined( 'ABSPATH' ) || exit;

use PhotoPress\modules\metadata\ImageTaxonomies;

/**
 * The posts a Taxonomy Gallery that chooses its own images is on, announced
 * when the images it shows may have changed. The gallery is filled when the
 * page is viewed (ImageTemplates::queryArgs()), so a copy of the page kept
 * elsewhere, as by a page cache, is stale.
 *
 * Each post's galleries are recorded when it is saved, in META, one row a
 * gallery: "<taxonomy>:<term ID>" for one showing a term's images, "latest"
 * for one showing the latest. The posts with a term's row are announced
 * (photopress_post_changed) when an image joins or leaves the term, or such
 * an image is edited or deleted; those with "latest" when any image is
 * added, edited or deleted.
 *
 * Not during a job's batch: what it changed empties the page cache when
 * the job ends (PageCache). Only posts of viewable types are recorded: a
 * gallery in a template,
 * template part or synced pattern has no page of its own to announce. A
 * Taxonomy Gallery on an image taxonomy's archive template shows the
 * archive's images, and the archive is purged with the image.
 */
class TaxonomyGalleryPages {

	const META = '_photopress_gallery_terms';

	const LATEST = 'latest';

	/** What marks a Taxonomy Gallery that chooses its images, in a block's markup. */
	const MARKER = '"photopressSource":"query"';

	/** Posts announced for one change, at most. */
	const MAX_POSTS = 200;

	/** Whether a job's batch is running: the page cache is emptied when it ends. */
	private static $inBatch = false;

	public static function addHooks() {

		add_action( 'photopress_job_batch_started', static function () { self::$inBatch = true; } );
		add_action( 'photopress_job_batch_ended', static function () { self::$inBatch = false; } );
		add_action( 'wp_after_insert_post', [ self::class, 'postSaved' ], 10, 2 );
		add_action( 'photopress_image_terms_changed', [ self::class, 'imageTermsChanged' ], 10, 2 );
		add_action( 'add_attachment', [ self::class, 'imageAdded' ] );
		add_action( 'attachment_updated', [ self::class, 'imageEdited' ], 10, 3 );
		add_action( 'delete_attachment', [ self::class, 'imageDeleted' ] );
	}

	/** wp_after_insert_post: the post's Taxonomy Galleries recorded. */
	public static function postSaved( $post_id, $post ) {

		if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) || 'attachment' === $post->post_type ) {
			return;
		}

		self::record( $post );
	}

	/**
	 * Records what the post's Taxonomy Galleries show, written only when it
	 * changed. A post of a type with no pages records nothing.
	 *
	 * @param \WP_Post $post
	 * @return string[] The keys recorded.
	 */
	public static function record( $post ) {

		$keys = is_post_type_viewable( $post->post_type ) && false !== strpos( $post->post_content, self::MARKER )
			? self::keysOf( parse_blocks( $post->post_content ) )
			: [];

		$had = array_map( 'strval', (array) get_post_meta( $post->ID, self::META ) );
		sort( $had );

		if ( $had !== $keys ) {

			delete_post_meta( $post->ID, self::META );

			foreach ( $keys as $key ) {
				add_post_meta( $post->ID, self::META, $key );
			}
		}

		return $keys;
	}

	/**
	 * The keys of the Taxonomy Galleries in these blocks, at any depth,
	 * sorted, once each.
	 *
	 * @return string[]
	 */
	public static function keysOf( array $blocks ) {

		$keys = [];

		foreach ( $blocks as $block ) {

			$attrs = (array) ( $block['attrs'] ?? [] );

			if ( 'core/gallery' === ( $block['blockName'] ?? '' ) && 'query' === ( $attrs['photopressSource'] ?? '' ) ) {
				$keys[] = self::key( (string) ( $attrs['photopressTaxonomy'] ?? '' ), (int) ( $attrs['photopressTerm'] ?? 0 ) );
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$keys = array_merge( $keys, self::keysOf( $block['innerBlocks'] ) );
			}
		}

		$keys = array_values( array_unique( $keys ) );
		sort( $keys );

		return $keys;
	}

	/** A gallery's key: as ImageTemplates::queryArgs() reads its taxonomy and term. */
	public static function key( $taxonomy, $term ) {

		return '' === $taxonomy || ! $term ? self::LATEST : $taxonomy . ':' . (int) $term;
	}

	/**
	 * photopress_image_terms_changed: the posts showing the terms that
	 * changed. Not from TermChanges's job: the cache was emptied when it
	 * started.
	 */
	public static function imageTermsChanged( $id, $context = [] ) {

		if ( empty( $context['job'] ) ) {
			self::announce( (array) ( $context['terms'] ?? [] ) );
		}
	}

	/** add_attachment: the posts showing the latest images. */
	public static function imageAdded( $id ) {

		if ( wp_attachment_is_image( $id ) ) {
			self::announce( [ self::LATEST ] );
		}
	}

	/** attachment_updated: an image's title, caption or slug changed. */
	public static function imageEdited( $id, $after, $before ) {

		foreach ( [ 'post_title', 'post_excerpt', 'post_name' ] as $field ) {

			if ( $after->$field !== $before->$field ) {
				self::announce( array_merge( [ self::LATEST ], self::termKeys( $id ) ) );
				return;
			}
		}
	}

	/** delete_attachment, before the image is gone. */
	public static function imageDeleted( $id ) {

		if ( wp_attachment_is_image( $id ) ) {
			self::announce( array_merge( [ self::LATEST ], self::termKeys( $id ) ) );
		}
	}

	/**
	 * The keys of an image's terms in the image taxonomies shown.
	 *
	 * @return string[]
	 */
	public static function termKeys( $id ) {

		$keys = [];

		foreach ( ImageTaxonomies::shownImageTaxonomies() as $taxonomy ) {

			$terms = get_the_terms( $id, $taxonomy );

			foreach ( is_array( $terms ) ? $terms : [] as $term ) {
				$keys[] = self::key( $taxonomy, $term->term_id );
			}
		}

		return $keys;
	}

	/**
	 * The posts recorded with any of these keys, published, announced as
	 * changed.
	 *
	 * @param string[] $keys
	 */
	public static function announce( array $keys ) {

		$keys = array_values( array_unique( array_filter( array_map( 'strval', $keys ) ) ) );

		if ( ! $keys || self::$inBatch ) {
			return;
		}

		$posts = get_posts( [
			'post_type'        => array_values( array_filter( get_post_types(), 'is_post_type_viewable' ) ),
			'post_status'      => 'publish',
			'fields'           => 'ids',
			'numberposts'      => self::MAX_POSTS,
			'no_found_rows'    => true,
			'suppress_filters' => true,
			'meta_query'       => [ [ 'key' => self::META, 'value' => $keys, 'compare' => 'IN' ] ],
		] );

		foreach ( $posts as $post_id ) {
			/** This action is documented in modules/gallery/GalleryAdd.php */
			do_action( 'photopress_post_changed', (int) $post_id, [] );
		}
	}
}
