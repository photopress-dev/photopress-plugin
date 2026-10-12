<?php

namespace PhotoPress\modules\gallery;

/**
 * Takes an image deleted from the media library out of the galleries that
 * show it, so they are not left with a broken image.
 *
 * A static gallery keeps its images in the post as image blocks: the deleted
 * image's block is removed, in an edit of the post (a revision, a new
 * modified date, and edit_post for page caches), whoever the post belongs
 * to. A gallery left with no images stays, empty. A dynamic gallery shows
 * the post's attached images, so it loses the image with the attachment;
 * the post is announced as changed (photopress_post_changed).
 *
 * Galleries in the format of WordPress before 5.9 (an ids attribute and no
 * image blocks) and the legacy photopress/gallery block are left as they
 * are: their markup is checked by the editor against what it would save.
 *
 * The images deleted in a request are removed at its end, with one search
 * of the posts for them all.
 */
class GalleryRemove {

	/**
	 * Images deleted in this request: the parent post of each, by ID.
	 *
	 * @var int[]
	 */
	protected static $deleted = [];

	public static function addHooks() {

		add_action( 'delete_attachment', [ self::class, 'imageDeleted' ] );
	}

	/**
	 * delete_attachment, before the image is gone.
	 */
	public static function imageDeleted( $id ) {

		if ( ! wp_attachment_is_image( $id ) ) {
			return;
		}

		// Early at the end of the request: before plugins that act then on
		// what it changed, as a page cache sends its purges.
		if ( ! self::$deleted ) {
			add_action( 'shutdown', [ self::class, 'removeDeleted' ], 1 );
		}

		self::$deleted[ (int) $id ] = (int) get_post_field( 'post_parent', $id );
	}

	/**
	 * shutdown: the images deleted in the request, out of their galleries.
	 */
	public static function removeDeleted() {

		$deleted = self::$deleted;
		self::$deleted = [];

		foreach ( array_unique( array_filter( $deleted ) ) as $parent ) {

			if ( GalleryAdd::hasDynamicGallery( $parent ) ) {
				GalleryAdd::postChanged( $parent );
			}
		}

		$ids = array_keys( $deleted );

		foreach ( self::postsWith( $ids ) as $post_id ) {
			self::removeFromPost( $post_id, $ids );
		}
	}

	/**
	 * IDs of the posts with a gallery whose content may show one of the
	 * images (wp-image-12 is also in wp-image-123; the blocks decide).
	 *
	 * @param int[] $ids
	 * @return int[]
	 */
	public static function postsWith( array $ids ) {

		global $wpdb;

		$found = [];

		foreach ( array_chunk( $ids, 50 ) as $chunk ) {

			$images = implode( ' OR ', array_fill( 0, count( $chunk ), 'post_content LIKE %s' ) );
			$likes  = array_map( static fn( $id ) => '%' . $wpdb->esc_like( 'wp-image-' . (int) $id ) . '%', $chunk );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $images is placeholders.
			$found = array_merge( $found, $wpdb->get_col( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type NOT IN ( 'revision', 'attachment' ) AND post_status <> 'auto-draft' AND post_content LIKE %s AND ( $images )",
				'%' . $wpdb->esc_like( '<!-- wp:gallery' ) . '%',
				...$likes
			) ) );
		}

		return array_values( array_unique( array_map( 'intval', $found ) ) );
	}

	/**
	 * Removes the images from the post's static galleries, in an edit of the
	 * post, when any of them shows one.
	 *
	 * @param int[] $ids
	 * @return int The number of image blocks removed.
	 */
	public static function removeFromPost( $post_id, array $ids ) {

		$post = get_post( $post_id );

		if ( ! $post ) {
			return 0;
		}

		$removed = 0;
		$blocks  = self::withoutImages( parse_blocks( $post->post_content ), $ids, $removed );

		if ( ! $removed ) {
			return 0;
		}

		// The post may have markup its author could save and the user who
		// deleted the image could not; only image blocks are taken out.
		kses_remove_filters();
		$saved = wp_update_post( wp_slash( [ 'ID' => $post->ID, 'post_content' => serialize_blocks( $blocks ) ] ), true );
		kses_init();

		return is_wp_error( $saved ) ? 0 : $removed;
	}

	/**
	 * The blocks with the images' blocks taken out of every static gallery,
	 * at any depth.
	 *
	 * @param array $blocks  Parsed blocks.
	 * @param int[] $ids     Attachment IDs.
	 * @param int   $removed Counts the image blocks removed.
	 */
	public static function withoutImages( array $blocks, array $ids, &$removed = 0 ) {

		$ids = array_map( 'intval', $ids );

		foreach ( $blocks as $index => $block ) {

			if ( 'core/gallery' === ( $block['blockName'] ?? '' ) ) {

				if ( empty( $block['attrs']['dynamicContent'] ) ) {
					$blocks[ $index ] = self::galleryWithout( $block, $ids, $removed );
				}

				continue;
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$blocks[ $index ]['innerBlocks'] = self::withoutImages( $block['innerBlocks'], $ids, $removed );
			}
		}

		return $blocks;
	}

	/**
	 * A static gallery without the images' blocks.
	 */
	protected static function galleryWithout( array $gallery, array $ids, &$removed ) {

		$kept = [];

		foreach ( $gallery['innerBlocks'] ?? [] as $inner ) {

			if ( 'core/image' === ( $inner['blockName'] ?? '' ) && in_array( self::imageId( $inner ), $ids, true ) ) {
				$removed++;
			} else {
				$kept[] = $inner;
			}
		}

		return count( $kept ) === count( $gallery['innerBlocks'] ?? [] ) ? $gallery : GalleryAdd::withInnerBlocks( $gallery, $kept );
	}

	/**
	 * An image block's attachment ID: its id, or its wp-image-<id> class
	 * should the id attribute be missing.
	 */
	protected static function imageId( array $image ) {

		if ( ! empty( $image['attrs']['id'] ) ) {
			return (int) $image['attrs']['id'];
		}

		return preg_match( '/\bwp-image-(\d+)\b/', (string) ( $image['innerHTML'] ?? '' ), $m ) ? (int) $m[1] : 0;
	}
}
