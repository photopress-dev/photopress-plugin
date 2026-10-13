<?php

namespace PhotoPress\modules\media;

defined( 'ABSPATH' ) || exit;

/**
 * Enable Media Replace, when a site has it: the one place that knows it.
 *
 * Its replacement of an image's file is announced as PhotoPress's own, so
 * what follows PhotoPress's Replace File follows it too: the new file's
 * metadata read and its files invalidated at the CDN
 * (photopress_attachment_file_replaced), and the image, its files and the
 * posts that show it purged from the page cache (photopress_post_changed).
 *
 * Only its enable-media-replace-upload-done action, which it fires once a
 * replacement is done. Nothing is done twice: a replacement goes through it
 * or through PhotoPress's Replace File, never both, and its own cache
 * flushing (emr/cache/flush, and page cache plugins it knows by name) does
 * not reach Proxy Cache Purge.
 */
class EnableMediaReplace {

	public static function addHooks() {

		add_action( 'enable-media-replace-upload-done', [ self::class, 'replaced' ], 10, 3 );
	}

	/**
	 * enable-media-replace-upload-done: the image's file replaced. Versions
	 * before 4 passed the URLs alone.
	 */
	public static function replaced( $target_url, $source_url = '', $post_id = 0 ) {

		$id = (int) $post_id ?: attachment_url_to_postid( (string) $target_url );

		if ( ! $id || ! wp_attachment_is_image( $id ) ) {
			return;
		}

		$old = self::relative( $source_url );
		$new = self::relative( $target_url );
		$replacements = $old && $new && $old !== $new ? [ $old => $new ] : [];

		/** This action is documented in modules/media/MediaRest.php */
		do_action( 'photopress_attachment_file_replaced', $id, $replacements, [], [ 'reprocess_metadata' => true ] );

		$files = array_values( array_unique( array_filter( [ (string) $source_url, (string) $target_url ] ) ) );

		/** This action is documented in modules/gallery/GalleryAdd.php */
		do_action( 'photopress_post_changed', $id, [ 'files' => $files ] );

		// The posts that show it, and its parent: a dynamic gallery there
		// shows it without a link to it in the content.
		$parent = (int) get_post_field( 'post_parent', $id );

		foreach ( array_diff( array_unique( MediaRest::postsShowing( $id, MediaRest::stem( $target_url ) ) ), [ $parent ] ) as $post_id ) {
			/** This action is documented in modules/gallery/GalleryAdd.php */
			do_action( 'photopress_post_changed', $post_id, [] );
		}

		if ( $parent ) {
			/** This action is documented in modules/gallery/GalleryAdd.php */
			do_action( 'photopress_post_changed', $parent, [ 'gallery' => true ] );
		}
	}

	/** A file's path under the uploads directory, from its URL; or ''. */
	private static function relative( $url ) {

		$base = wp_get_upload_dir()['baseurl'] . '/';

		return $url && 0 === strpos( (string) $url, $base ) ? substr( (string) $url, strlen( $base ) ) : '';
	}
}
