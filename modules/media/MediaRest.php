<?php

namespace PhotoPress\modules\media;

use WP_Error;
use WP_REST_Attachments_Controller;
use WP_REST_Request;
use WP_REST_Server;

/**
 * POST /photopress/v1/media/<id>/file gives an image a new file, for
 * publishing tools (such as the Capture One plugin) that keep images on the
 * site up to date. The body is the file, as for POST /wp/v2/media (raw with
 * Content-Disposition, or multipart "file"), and the response is the image as
 * GET /wp/v2/media/<id> returns it.
 *
 * Everything else such a tool needs is core: finding an earlier upload by
 * file name (GET /wp/v2/media?search=, which searches file names too),
 * uploading new images, and setting title, caption and alt text. Core cannot
 * change the file of an image that is in use: 7.1's sideload and finalize
 * endpoints are for the editor's upload flow, keep the old file as the
 * original, and leave thumbnails to the client.
 *
 * Replacing keeps the attachment (its ID, attachment page, title, caption,
 * galleries and featured image uses) and the file's name, as Enable Media
 * Replace does: the old files are deleted and the new one takes their name,
 * with new sizes. Posts are pointed at sizes whose names changed with the
 * image's shape.
 *
 * With WP Offload Media, the image is removed from the bucket and forgotten
 * before the new files are written, so Offload Media offloads it afresh,
 * under a new versioned folder: new URLs, which no CDN has cached. That is
 * what Offload Media does itself when an attachment is deleted. It has no
 * public API for this, so the calls are guarded: if its classes change, the
 * file is still replaced and the response reports that the bucket was not
 * handled.
 */
class MediaRest {

	const REST_NAMESPACE = 'photopress/v1';

	/**
	 * WP Offload Media's main object, as its as3cf_init action passes it.
	 */
	protected static $as3cf = null;

	public static function addHooks() {

		add_action( 'rest_api_init', [ self::class, 'registerRoutes' ] );
		add_action( 'as3cf_init', [ self::class, 'setOffloadMedia' ] );
	}

	public static function setOffloadMedia( $as3cf ) {

		self::$as3cf = $as3cf;
	}

	public static function registerRoutes() {

		register_rest_route( self::REST_NAMESPACE, '/media/(?P<id>[\d]+)/file', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ self::class, 'replaceFile' ],
			'permission_callback' => [ self::class, 'canReplace' ],
			'args'                => [
				'id'                => [
					'type' => 'integer',
				],
				'update_references' => [
					'description' => __( 'Point posts that show the old files at the new ones.' ),
					'type'        => 'boolean',
					'default'     => true,
				],
				'reprocess_metadata' => [
					'description' => __( 'Read the new file\'s metadata as for an upload: the image taxonomies, and the alt text from its template. When false, they are left as they are.' ),
					'type'        => 'boolean',
					'default'     => true,
				],
			],
		] );
	}

	public static function canReplace( WP_REST_Request $request ) {

		return current_user_can( 'upload_files' ) && current_user_can( 'edit_post', (int) $request['id'] );
	}

	/**
	 * The name an image was uploaded under, from any of the names WordPress
	 * or this class give its file: photo.jpg, photo-scaled.jpg,
	 * photo-rotated.jpg and photo-e1712345678901.jpg (edited in WordPress)
	 * are all "photo". Clients matching core search results to a file name
	 * do the same, case-insensitively.
	 */
	public static function stem( $filename ) {

		$name = pathinfo( wp_basename( (string) $filename ), PATHINFO_FILENAME );

		do {
			$before = $name;
			$name = preg_replace( '/-(scaled|rotated|e\d{13})$/', '', $name );
		} while ( $name !== $before );

		return $name;
	}

	/**
	 * Gives an image a new file under its current name, then points posts at
	 * any sizes whose names changed.
	 */
	public static function replaceFile( WP_REST_Request $request ) {

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$id = (int) $request['id'];

		if ( 'attachment' !== get_post_type( $id ) || ! wp_attachment_is_image( $id ) ) {
			return new WP_Error( 'rest_post_invalid_id', __( 'Invalid image ID.' ), [ 'status' => 404 ] );
		}

		$old_file = get_attached_file( $id, true );

		if ( ! $old_file ) {
			return new WP_Error( 'photopress_no_file', __( 'The image has no file path.' ), [ 'status' => 500 ] );
		}

		$upload = self::uploadedFile( $request );

		if ( is_wp_error( $upload ) ) {
			return $upload;
		}

		// Checked before anything is written: a client sending the wrong kind
		// of file gets a 400, not the 500 of a failed upload.
		$type = wp_check_filetype_and_ext( $upload['tmp_name'], $upload['name'] );

		if ( empty( $type['type'] ) || 0 !== strpos( $type['type'], 'image/' ) ) {
			@unlink( $upload['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'rest_upload_invalid_type', __( 'Sorry, you are not allowed to upload this file type.' ), [ 'status' => 400 ] );
		}

		$old_meta = wp_get_attachment_metadata( $id, true );
		$old_meta = is_array( $old_meta ) ? $old_meta : [];
		$old_files = self::attachmentFiles( $old_file, $old_meta );

		// The name the image was uploaded under, before WordPress scaled it.
		$name = pathinfo( $old_files['original'] ?? $old_files['full'], PATHINFO_FILENAME );
		$extension = strtolower( pathinfo( $upload['name'], PATHINFO_EXTENSION ) );
		$subdir = self::uploadSubdir( $old_files['full'] );

		// 1. The new file, next to the old ones under a temporary name. Through
		// wp_handle_sideload() so the upload checks and the filters other
		// plugins hook (licence embedding among them) run as for any upload.
		$pin_dir = static function ( $uploads ) use ( $subdir ) {
			$uploads['subdir'] = $subdir;
			$uploads['path'] = $uploads['basedir'] . $subdir;
			$uploads['url'] = $uploads['baseurl'] . $subdir;
			return $uploads;
		};

		// A variable: wp_handle_sideload() takes the file by reference.
		$file = [
			'name'     => "{$name}-photopress-replacing.{$extension}",
			'tmp_name' => $upload['tmp_name'],
		];

		add_filter( 'upload_dir', $pin_dir );
		$saved = wp_handle_sideload( $file, [ 'test_form' => false ] );
		remove_filter( 'upload_dir', $pin_dir );

		if ( isset( $saved['error'] ) ) {
			@unlink( $upload['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'rest_upload_unknown_error', $saved['error'], [ 'status' => 500 ] );
		}

		if ( ! file_is_displayable_image( $saved['file'] ) ) {
			wp_delete_file( $saved['file'] );
			return new WP_Error( 'rest_upload_invalid_image', __( 'The file is not an image this site can display.' ), [ 'status' => 400 ] );
		}

		// 2. Out of the bucket, and forgotten by Offload Media. Before the old
		// files are touched: if the bucket refuses, nothing has changed.
		$offloaded = self::forgetOffloaded( $id );

		if ( is_wp_error( $offloaded ) ) {
			wp_delete_file( $saved['file'] );
			return new WP_Error( 'photopress_offload_failed', $offloaded->get_error_message(), [ 'status' => 502 ] );
		}

		// 3. The old files go, and the new one takes the image's name.
		// Sizes kept by WordPress's image editor; '' when there are none.
		$backup_sizes = get_post_meta( $id, '_wp_attachment_backup_sizes', true );
		wp_delete_attachment_files( $id, $old_meta, is_array( $backup_sizes ) ? $backup_sizes : [], $old_file );
		delete_post_meta( $id, '_wp_attachment_backup_sizes' );

		$target = path_join( dirname( $saved['file'] ), "{$name}.{$extension}" );

		if ( file_exists( $target ) ) {
			$target = path_join( dirname( $saved['file'] ), wp_unique_filename( dirname( $saved['file'] ), "{$name}.{$extension}" ) );
		}

		if ( ! @rename( $saved['file'], $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$target = $saved['file'];
		}

		update_attached_file( $id, $target );

		if ( get_post_mime_type( $id ) !== $saved['type'] ) {
			wp_update_post( [ 'ID' => $id, 'post_mime_type' => $saved['type'] ] );
		}

		// 4. The sizes, and -scaled for large images, as an upload makes them.
		// WordPress saves the metadata as it goes; Offload Media waits for the
		// finished set, as Enable Media Replace has it do, then offloads it.
		add_filter( 'as3cf_pre_update_attachment_metadata', '__return_true' );
		$new_meta = wp_generate_attachment_metadata( $id, $target );
		remove_filter( 'as3cf_pre_update_attachment_metadata', '__return_true' );

		wp_update_attachment_metadata( $id, $new_meta );
		clean_attachment_cache( $id );

		$new_meta = wp_get_attachment_metadata( $id, true );
		$new_meta = is_array( $new_meta ) ? $new_meta : [];
		$new_files = self::attachmentFiles( get_attached_file( $id, true ), $new_meta );

		// 5. Posts showing sizes whose names changed: a new shape, a smaller
		// image or another extension gives sizes other names.
		$replacements = self::fileReplacements( $old_files, $new_files, self::sizeWidths( $old_meta ), self::sizeWidths( $new_meta ) );
		$updated = $request['update_references'] ? self::updateReferences( self::stem( $name ), $replacements ) : [];
		$reprocess = (bool) $request['reprocess_metadata'];

		/**
		 * Fires after an image was given a new file.
		 *
		 * @param int      $id           Attachment ID.
		 * @param string[] $replacements Old upload-relative paths mapped to new ones, for files whose names changed.
		 * @param int[]    $updated      IDs of posts whose content was updated.
		 * @param array    $options      reprocess_metadata: whether the client asked
		 *                               for the new file's metadata to be read.
		 */
		do_action( 'photopress_attachment_file_replaced', $id, $replacements, $updated, [ 'reprocess_metadata' => $reprocess ] );

		// The image as core returns it, plus what changed.
		$get = new WP_REST_Request( 'GET', '/wp/v2/media/' . $id );
		$get->set_param( 'context', 'edit' );
		$response = rest_do_request( $get );

		if ( $response->is_error() ) {
			return $response;
		}

		$data = $response->get_data();
		$data['photopress_replaced'] = [
			'files'                => $replacements,
			'posts_updated'        => $updated,
			'metadata_reprocessed' => $reprocess,
			// offloaded: removed from the bucket and offloaded afresh;
			// not_offloaded: Offload Media is not active or did not have the
			// image; unsupported: it is active but its classes have changed.
			'offload'              => $offloaded,
		];
		$response->set_data( $data );

		return $response;
	}

	/**
	 * Removes an image from WP Offload Media's bucket and deletes its record
	 * of it, as Offload Media does when an attachment is deleted
	 * (Media_Library::delete_attachment). The next metadata update then
	 * offloads it as a new item, under a new versioned folder.
	 *
	 * @return string|WP_Error "offloaded", "not_offloaded" or "unsupported".
	 */
	protected static function forgetOffloaded( $id ) {

		$item_class = '\\DeliciousBrains\\WP_Offload_Media\\Items\\Media_Library_Item';
		$remove_class = '\\DeliciousBrains\\WP_Offload_Media\\Items\\Remove_Provider_Handler';

		if ( ! self::$as3cf ) {
			return 'not_offloaded';
		}

		if (
			! class_exists( $item_class ) || ! class_exists( $remove_class )
			|| ! method_exists( $item_class, 'get_by_source_id' ) || ! method_exists( $remove_class, 'get_item_handler_key_name' )
			|| ! method_exists( self::$as3cf, 'get_item_handler' )
		) {
			return 'unsupported';
		}

		$item = $item_class::get_by_source_id( $id );

		if ( ! $item ) {
			return 'not_offloaded';
		}

		$result = self::$as3cf->get_item_handler( $remove_class::get_item_handler_key_name() )->handle( $item, [ 'verify_exists_on_local' => false ] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$item->delete();

		return 'offloaded';
	}

	/**
	 * The uploaded file as a temporary file, from a multipart "file" field or
	 * the raw body, as POST /wp/v2/media accepts it.
	 *
	 * @return array|WP_Error With name and tmp_name.
	 */
	protected static function uploadedFile( WP_REST_Request $request ) {

		$files = $request->get_file_params();

		if ( ! empty( $files['file']['tmp_name'] ) ) {

			if ( ! empty( $files['file']['error'] ) || ! is_uploaded_file( $files['file']['tmp_name'] ) ) {
				return new WP_Error( 'rest_upload_unknown_error', __( 'The file was not uploaded.' ), [ 'status' => 400 ] );
			}

			// Moved out of PHP's upload folder so it is handled the same as a raw body.
			$tmp = wp_tempnam( $files['file']['name'] );

			if ( ! move_uploaded_file( $files['file']['tmp_name'], $tmp ) ) {
				return new WP_Error( 'rest_upload_unknown_error', __( 'The file could not be saved.' ), [ 'status' => 500 ] );
			}

			return [ 'name' => $files['file']['name'], 'tmp_name' => $tmp ];
		}

		$body = $request->get_body();

		if ( '' === $body ) {
			return new WP_Error( 'rest_upload_no_data', __( 'No data supplied.' ), [ 'status' => 400 ] );
		}

		$name = WP_REST_Attachments_Controller::get_filename_from_disposition( $request->get_header_as_array( 'content_disposition' ) );

		if ( ! $name ) {
			return new WP_Error( 'rest_upload_no_content_disposition', __( 'No Content-Disposition supplied.' ), [ 'status' => 400 ] );
		}

		$tmp = wp_tempnam( $name );

		if ( ! $tmp || false === file_put_contents( $tmp, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'rest_upload_unknown_error', __( 'The file could not be saved.' ), [ 'status' => 500 ] );
		}

		return [ 'name' => $name, 'tmp_name' => $tmp ];
	}

	/**
	 * An image's files as paths relative to the uploads folder, keyed by size
	 * name, plus "full" (the file shown) and "original" (the upload, when
	 * WordPress scaled or rotated it).
	 */
	public static function attachmentFiles( $file, array $meta ) {

		$full = _wp_relative_upload_path( $file );
		$dir = self::uploadSubdir( $full );
		$files = [ 'full' => $full ];

		if ( ! empty( $meta['original_image'] ) ) {
			$files['original'] = ltrim( $dir . '/' . wp_basename( $meta['original_image'] ), '/' );
		}

		foreach ( (array) ( $meta['sizes'] ?? [] ) as $size => $data ) {

			if ( ! empty( $data['file'] ) ) {
				$files[ $size ] = ltrim( $dir . '/' . wp_basename( $data['file'] ), '/' );
			}
		}

		return $files;
	}

	/**
	 * Width of each file of an image, keyed as attachmentFiles() keys them.
	 */
	public static function sizeWidths( array $meta ) {

		$widths = [ 'full' => (int) ( $meta['width'] ?? 0 ) ];

		foreach ( (array) ( $meta['sizes'] ?? [] ) as $size => $data ) {
			$widths[ $size ] = (int) ( $data['width'] ?? 0 );
		}

		return $widths;
	}

	/**
	 * Maps each old file to the new file of the same size. A size the new
	 * image does not have (it is smaller, or the size was registered since)
	 * maps to the new file closest to the old width, as a post that showed
	 * a 1024px image should get one close to 1024px rather than the full size.
	 */
	public static function fileReplacements( array $old_files, array $new_files, array $old_widths = [], array $new_widths = [] ) {

		$replacements = [];

		foreach ( $old_files as $size => $old ) {

			if ( isset( $new_files[ $size ] ) ) {
				$new = $new_files[ $size ];
			} elseif ( 'original' === $size || ! isset( $old_widths[ $size ] ) ) {
				$new = $new_files['original'] ?? $new_files['full'];
			} else {
				$new = $new_files['full'];
				$best = PHP_INT_MAX;

				foreach ( $new_widths as $new_size => $width ) {

					$distance = abs( $width - $old_widths[ $size ] );

					if ( isset( $new_files[ $new_size ] ) && $distance < $best ) {
						$best = $distance;
						$new = $new_files[ $new_size ];
					}
				}
			}

			if ( $old !== $new ) {
				$replacements[ $old ] = $new;
			}
		}

		return $replacements;
	}

	/**
	 * Matches a reference to the file at an upload-relative path (see
	 * rewriteReferences()); group 1 is everything before its name.
	 */
	protected static function referencePattern( $path ) {

		$separator = '(?:/|\\\\/)';
		$dir_pattern = '';

		foreach ( array_filter( explode( '/', self::uploadSubdir( $path ) ) ) as $part ) {
			$dir_pattern .= $separator . preg_quote( $part, '#' );
		}

		return '#(' . $dir_pattern . $separator . '(?:\d{6,14}' . $separator . ')?)' . preg_quote( wp_basename( $path ), '#' ) . '(?![\w.-])#';
	}

	/**
	 * Replaces references to old files in a piece of content.
	 *
	 * A reference is the path within the uploads folder (2024/05/photo.jpg),
	 * so local URLs, CDN URLs with the same path, offload URLs with a version
	 * folder (2024/05/12345678/photo.jpg) and JSON-escaped URLs in block
	 * attributes all match, and a photo.jpg in another month's folder or a
	 * longer name such as photo.jpg.webp does not.
	 */
	public static function rewriteReferences( $content, array $replacements ) {

		foreach ( $replacements as $old => $new ) {

			$basename = wp_basename( $new );

			$content = preg_replace_callback(
				self::referencePattern( $old ),
				static function ( $match ) use ( $basename ) {
					return $match[1] . $basename;
				},
				$content
			);
		}

		return $content;
	}

	/**
	 * Replaces references in a meta value: serialized data is unserialized
	 * first (a longer file name would break its string lengths), arrays and
	 * objects are walked, and JSON stored as a string -- page builders do
	 * this -- is a string like any other.
	 */
	public static function rewriteValue( $value, array $replacements ) {

		if ( is_string( $value ) && is_serialized( $value ) ) {

			$data = @unserialize( trim( $value ), [ 'allowed_classes' => false ] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

			// Something we cannot safely rebuild: left alone.
			if ( ( false === $data && 'b:0;' !== trim( $value ) ) || self::hasIncompleteObject( $data ) ) {
				return $value;
			}

			$rewritten = self::rewriteValue( $data, $replacements );

			return $rewritten === $data ? $value : serialize( $rewritten ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		}

		if ( is_string( $value ) ) {
			return self::rewriteReferences( $value, $replacements );
		}

		if ( is_array( $value ) ) {

			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::rewriteValue( $item, $replacements );
			}
		}

		return $value;
	}

	/**
	 * Objects of classes not allowed while unserializing come back as
	 * __PHP_Incomplete_Class, and would be saved back broken.
	 */
	protected static function hasIncompleteObject( $data ) {

		if ( is_object( $data ) ) {
			return true;
		}

		if ( is_array( $data ) ) {

			foreach ( $data as $item ) {

				if ( self::hasIncompleteObject( $item ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Points post content, excerpts and post meta at the new files. The
	 * image's own meta is not touched (attachments are skipped).
	 *
	 * Updated in the database rather than with wp_update_post(): swapping an
	 * image is not an edit, so posts keep their modified date, get no
	 * revision, and their content is not run through kses again.
	 *
	 * @param string   $stem          The image's name, to find candidate rows.
	 * @param string[] $replacements  Old upload-relative paths mapped to new ones.
	 * @return int[] IDs of the updated posts.
	 */
	protected static function updateReferences( $stem, array $replacements ) {

		global $wpdb;

		if ( ! $replacements ) {
			return [];
		}

		$like = '%' . $wpdb->esc_like( $stem ) . '%';
		$updated = [];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$posts = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, post_content, post_excerpt FROM {$wpdb->posts}
			WHERE post_type NOT IN ( 'revision', 'attachment' )
			AND ( post_content LIKE %s OR post_excerpt LIKE %s )",
			$like,
			$like
		) );

		foreach ( $posts as $post ) {

			$fields = [
				'post_content' => self::rewriteReferences( $post->post_content, $replacements ),
				'post_excerpt' => self::rewriteReferences( $post->post_excerpt, $replacements ),
			];


			if ( $fields['post_content'] === $post->post_content && $fields['post_excerpt'] === $post->post_excerpt ) {
				continue;
			}

			$wpdb->update( $wpdb->posts, $fields, [ 'ID' => $post->ID ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			clean_post_cache( $post->ID );
			$updated[] = (int) $post->ID;
		}

		// Page builders, custom fields and the like keep URLs in post meta.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$meta_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT m.meta_id, m.post_id, m.meta_value FROM {$wpdb->postmeta} m
			INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
			WHERE p.post_type NOT IN ( 'revision', 'attachment' )
			AND m.meta_value LIKE %s",
			$like
		) );

		foreach ( $meta_rows as $row ) {

			$value = self::rewriteValue( $row->meta_value, $replacements );

			if ( $value === $row->meta_value ) {
				continue;
			}

			$wpdb->update( $wpdb->postmeta, [ 'meta_value' => $value ], [ 'meta_id' => $row->meta_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			wp_cache_delete( (int) $row->post_id, 'post_meta' );
			$updated[] = (int) $row->post_id;
		}

		return array_values( array_unique( $updated ) );
	}

	/**
	 * "/2024/05" for "2024/05/photo.jpg", "" for a file at the top of uploads.
	 */
	protected static function uploadSubdir( $relative ) {

		$dir = dirname( $relative );

		return '.' === $dir || '' === $dir ? '' : '/' . trim( $dir, '/' );
	}
}
