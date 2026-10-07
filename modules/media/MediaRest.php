<?php

namespace PhotoPress\modules\media;

use WP_Error;
use WP_REST_Attachments_Controller;
use WP_REST_Request;
use WP_REST_Server;

/**
 * REST routes for publishing tools (such as the Capture One plugin) that keep
 * images on the site up to date:
 *
 *   GET  /photopress/v1/media?filename=IMG_1234.jpg
 *        Images whose file is that image, newest first.
 *
 *   POST /photopress/v1/media/<id>/file
 *        Gives an image a new file. The body is the file, as for
 *        POST /wp/v2/media (raw with Content-Disposition, or multipart "file").
 *
 * Core REST can upload images and edit their fields, but cannot change the
 * file of an image that is in use. Replacing it here keeps the attachment:
 * its ID, attachment page, title, caption, alt text, galleries and featured
 * image uses stay as they are. The file gets a new versioned name
 * (photo.jpg -> photo-v2.jpg), so a CDN or offload plugin serves new URLs
 * rather than a cached copy, and posts showing the old files are pointed at
 * the new ones.
 */
class MediaRest {

	const REST_NAMESPACE = 'photopress/v1';

	/**
	 * The version of an image's file, 2 after the first replacement.
	 */
	const VERSION_META_KEY = '_photopress_file_version';

	public static function addHooks() {

		add_action( 'rest_api_init', [ self::class, 'registerRoutes' ] );
	}

	public static function registerRoutes() {

		register_rest_route( self::REST_NAMESPACE, '/media', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ self::class, 'findByFilename' ],
			'permission_callback' => [ self::class, 'canUpload' ],
			'args'                => [
				'filename' => [
					'description' => __( 'File name of the image, with or without its extension.' ),
					'type'        => 'string',
					'required'    => true,
					'minLength'   => 1,
				],
			],
		] );

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
			],
		] );
	}

	public static function canUpload() {

		return current_user_can( 'upload_files' );
	}

	public static function canReplace( WP_REST_Request $request ) {

		return current_user_can( 'upload_files' ) && current_user_can( 'edit_post', (int) $request['id'] );
	}

	/**
	 * The name an image was uploaded under, from any of the names WordPress
	 * or this class give its file: photo.jpg, photo-scaled.jpg,
	 * photo-rotated.jpg, photo-e1712345678901.jpg (edited in WordPress) and
	 * photo-v3.jpg are all "photo". Compared case-insensitively.
	 */
	public static function baseName( $filename ) {

		return mb_strtolower( self::stem( $filename ) );
	}

	/**
	 * baseName() keeping the case: "Photo" for Photo-v2-scaled.jpg.
	 */
	public static function stem( $filename ) {

		$name = pathinfo( wp_basename( (string) $filename ), PATHINFO_FILENAME );

		do {
			$before = $name;
			$name = preg_replace( '/-(scaled|rotated|e\d{13}|v\d+)$/', '', $name );
		} while ( $name !== $before );

		return $name;
	}

	/**
	 * Images whose file is the given image, newest first, limited to those
	 * the current user may edit.
	 */
	public static function findByFilename( WP_REST_Request $request ) {

		$base = self::baseName( $request['filename'] );

		if ( '' === $base ) {
			return new WP_Error( 'rest_invalid_param', __( 'Invalid file name.' ), [ 'status' => 400 ] );
		}

		// LIKE narrows the candidates; baseName() decides.
		$ids = get_posts( [
			'post_type'      => 'attachment',
			'post_status'    => 'any',
			'post_mime_type' => 'image',
			'posts_per_page' => 100,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'meta_query'     => [
				[
					'key'     => '_wp_attached_file',
					'value'   => $base,
					'compare' => 'LIKE',
				],
			],
		] );

		$matches = [];

		foreach ( $ids as $id ) {

			$file = (string) get_post_meta( $id, '_wp_attached_file', true );

			if ( self::baseName( $file ) !== $base || ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}

			$matches[] = [
				'id'           => (int) $id,
				'file'         => $file,
				'source_url'   => wp_get_attachment_url( $id ),
				'link'         => get_permalink( $id ),
				'date_gmt'     => get_post_time( 'Y-m-d\TH:i:s', true, $id ),
				'modified_gmt' => get_post_modified_time( 'Y-m-d\TH:i:s', true, $id ),
			];
		}

		return rest_ensure_response( $matches );
	}

	/**
	 * Gives an image a new file under a versioned name, then points posts at
	 * the new files.
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

		$old_meta = wp_get_attachment_metadata( $id, true );
		$old_meta = is_array( $old_meta ) ? $old_meta : [];
		$old_files = self::attachmentFiles( $old_file, $old_meta );
		$stem = self::stem( $old_files['original'] ?? $old_files['full'] );
		$version = max( 2, (int) get_post_meta( $id, self::VERSION_META_KEY, true ) + 1 );
		$extension = strtolower( pathinfo( $upload['name'], PATHINFO_EXTENSION ) );

		// Into the image's own folder rather than this month's. Through
		// wp_handle_sideload() so the upload checks, unique names and the
		// filters other plugins hook (licence embedding, offloading) all run
		// as for any upload.
		$subdir = self::uploadSubdir( $old_files['full'] );
		$pin_dir = static function ( $uploads ) use ( $subdir ) {
			$uploads['subdir'] = $subdir;
			$uploads['path'] = $uploads['basedir'] . $subdir;
			$uploads['url'] = $uploads['baseurl'] . $subdir;
			return $uploads;
		};

		add_filter( 'upload_dir', $pin_dir );
		$saved = wp_handle_sideload(
			[
				'name'     => "{$stem}-v{$version}.{$extension}",
				'tmp_name' => $upload['tmp_name'],
			],
			[ 'test_form' => false ]
		);
		remove_filter( 'upload_dir', $pin_dir );

		if ( isset( $saved['error'] ) ) {
			@unlink( $upload['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'rest_upload_unknown_error', $saved['error'], [ 'status' => 500 ] );
		}

		if ( ! file_is_displayable_image( $saved['file'] ) ) {
			wp_delete_file( $saved['file'] );
			return new WP_Error( 'rest_upload_invalid_image', __( 'The file is not an image this site can display.' ), [ 'status' => 400 ] );
		}

		update_attached_file( $id, $saved['file'] );
		wp_update_post( [ 'ID' => $id, 'post_mime_type' => $saved['type'] ] );

		// Generates the sizes, and -scaled for large images, as an upload does.
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $saved['file'] ) );
		update_post_meta( $id, self::VERSION_META_KEY, $version );
		clean_attachment_cache( $id );

		$new_meta = wp_get_attachment_metadata( $id, true );
		$new_meta = is_array( $new_meta ) ? $new_meta : [];
		$new_files = self::attachmentFiles( get_attached_file( $id, true ), $new_meta );
		$replacements = self::fileReplacements( $old_files, $new_files, self::sizeWidths( $old_meta ), self::sizeWidths( $new_meta ) );
		$updated = $request['update_references'] ? self::updateReferences( $stem, $replacements ) : [];

		/**
		 * Filters whether the replaced files are kept.
		 *
		 * Kept by default: pages cached with the old URLs keep showing an image,
		 * and offload plugins have copies of them anyway.
		 *
		 * @param bool $keep Whether to keep the old files.
		 * @param int  $id   Attachment ID.
		 */
		if ( ! apply_filters( 'photopress_keep_replaced_files', true, $id ) ) {

			$basedir = wp_get_upload_dir()['basedir'];

			foreach ( array_diff( array_unique( $old_files ), $new_files ) as $relative ) {
				wp_delete_file( path_join( $basedir, $relative ) );
			}
		}

		/**
		 * Fires after an image was given a new file.
		 *
		 * @param int      $id           Attachment ID.
		 * @param string[] $replacements Old upload-relative paths mapped to the new ones.
		 * @param int[]    $updated      IDs of posts whose content was updated.
		 */
		do_action( 'photopress_attachment_file_replaced', $id, $replacements, $updated );

		return rest_ensure_response( [
			'id'            => $id,
			'source_url'    => wp_get_attachment_url( $id ),
			'link'          => get_permalink( $id ),
			'files'         => $replacements,
			'posts_updated' => $updated,
		] );
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
	 * Replaces references to old files in a piece of content.
	 *
	 * A reference is the path within the uploads folder (2024/05/photo.jpg),
	 * so local URLs, CDN URLs with the same path, offload URLs with a version
	 * folder (2024/05/12345678/photo.jpg) and JSON-escaped URLs in block
	 * attributes all match, and a photo.jpg in another month's folder or a
	 * longer name such as photo.jpg.webp does not.
	 */
	public static function rewriteReferences( $content, array $replacements ) {

		$separator = '(?:/|\\\\/)';

		foreach ( $replacements as $old => $new ) {

			$dir = self::uploadSubdir( $old );
			$dir_pattern = '';

			foreach ( array_filter( explode( '/', $dir ) ) as $part ) {
				$dir_pattern .= $separator . preg_quote( $part, '#' );
			}

			$pattern = '#(' . $dir_pattern . $separator . '(?:\d{6,14}' . $separator . ')?)' . preg_quote( wp_basename( $old ), '#' ) . '(?![\w.-])#';
			$basename = wp_basename( $new );

			$content = preg_replace_callback(
				$pattern,
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
