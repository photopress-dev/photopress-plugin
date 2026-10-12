<?php

namespace PhotoPress\modules\gallery;

/**
 * The pages a Child Pages Gallery is on, announced when one of their child
 * pages changes in a way it shows. The gallery is filled when the page is
 * viewed (ImageTemplates::childPageItems()), so there is nothing in it to
 * change; a copy of the page kept elsewhere, as by a page cache, is stale.
 *
 * A child page published, unpublished, trashed or deleted; moved to another
 * parent (both are announced); renamed, given a new slug or a new place in
 * the page order; given another featured image; or, without one, its
 * content changed, which may give its gallery another first image. Its
 * parent is announced, with photopress_post_changed, when the parent shows
 * its child pages: a Child Pages Gallery or the legacy Child Pages block in
 * its content, or in a template edited in the Site Editor.
 */
class ChildPagesGallery {

	/** What shows child pages, in a block's markup. */
	const MARKERS = [ '"photopressSource":"children"', '<!-- wp:photopress/childpages' ];

	/** Whether an edited template shows child pages: looked up once a request. */
	private static $inTemplates = null;

	public static function addHooks() {

		add_action( 'wp_after_insert_post', [ self::class, 'pageSaved' ], 10, 4 );
		add_action( 'deleted_post', [ self::class, 'pageDeleted' ], 10, 2 );

		foreach ( [ 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ] as $hook ) {
			add_action( $hook, [ self::class, 'metaChanged' ], 10, 3 );
		}
	}

	/** wp_after_insert_post: a page saved, as it was before when updated. */
	public static function pageSaved( $post_id, $post, $update = false, $before = null ) {

		if ( ! $post instanceof \WP_Post || ! is_post_type_hierarchical( $post->post_type ) || wp_is_post_revision( $post ) ) {
			return;
		}

		$before    = $before instanceof \WP_Post ? $before : null;
		$shown     = 'publish' === $post->post_status;
		$was_shown = $before && 'publish' === $before->post_status;

		if ( ! $shown && ! $was_shown ) {
			return;
		}

		if ( $before && (int) $before->post_parent !== (int) $post->post_parent ) {
			self::announce( [ $before->post_parent, $post->post_parent ] );
			return;
		}

		$changed = ! $before || $shown !== $was_shown;

		foreach ( [ 'post_title', 'post_name', 'menu_order' ] as $field ) {
			$changed = $changed || (string) $before->$field !== (string) $post->$field;
		}

		if ( $changed || ( $before->post_content !== $post->post_content && ! has_post_thumbnail( $post ) ) ) {
			self::announce( [ $post->post_parent ] );
		}
	}

	/**
	 * deleted_post: a published page gone. WordPress has moved its child
	 * pages up to its parent, which is announced for both.
	 */
	public static function pageDeleted( $post_id, $post = null ) {

		if ( $post instanceof \WP_Post && is_post_type_hierarchical( $post->post_type ) && 'publish' === $post->post_status ) {
			self::announce( [ $post->post_parent ] );
		}
	}

	/** added_post_meta, updated_post_meta, deleted_post_meta: a page's featured image. */
	public static function metaChanged( $meta_ids, $object_id, $meta_key ) {

		if ( '_thumbnail_id' !== $meta_key ) {
			return;
		}

		$post = get_post( $object_id );

		if ( $post && is_post_type_hierarchical( $post->post_type ) && 'publish' === $post->post_status ) {
			self::announce( [ $post->post_parent ] );
		}
	}

	/** These pages, those that show their child pages, announced as changed. */
	private static function announce( array $parents ) {

		foreach ( array_unique( array_filter( array_map( 'intval', $parents ) ) ) as $parent ) {

			if ( self::showsChildPages( $parent ) ) {
				/** This action is documented in modules/gallery/GalleryAdd.php */
				do_action( 'photopress_post_changed', $parent, [] );
			}
		}
	}

	/**
	 * Whether a page shows its child pages: in its content, or in any
	 * template edited in the Site Editor (one may be the page's).
	 */
	public static function showsChildPages( $page ) {

		return self::hasMarker( (string) get_post_field( 'post_content', $page ) ) || self::inEditedTemplates();
	}

	/** Whether a template or template part edited in the Site Editor shows child pages; once a request. */
	private static function inEditedTemplates() {

		if ( null === self::$inTemplates ) {

			self::$inTemplates = false;

			foreach ( get_posts( [ 'post_type' => [ 'wp_template', 'wp_template_part' ], 'post_status' => 'publish', 'numberposts' => 100 ] ) as $template ) {

				if ( self::hasMarker( $template->post_content ) ) {
					self::$inTemplates = true;
					break;
				}
			}
		}

		return self::$inTemplates;
	}

	private static function hasMarker( $content ) {

		foreach ( self::MARKERS as $marker ) {

			if ( false !== strpos( $content, $marker ) ) {
				return true;
			}
		}

		return false;
	}
}
