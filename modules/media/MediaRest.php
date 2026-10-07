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
 * original, and leave thumbnails to the client. Replacing it here keeps the attachment:
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
	 * photo-rotated.jpg, photo-e1712345678901.jpg (edited in WordPress) and
	 * photo-v3.jpg are all "photo". Clients matching core search results to
	 * a file name do the same, case-insensitively.
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

		// A variable: wp_handle_sideload() takes the file by reference.
		$file = [
			'name'     => "{$stem}-v{$version}.{$extension}",
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
		$dimensions = self::fileDimensions( $new_files, $new_meta );
		$updated = $request['update_references'] ? self::updateReferences( $stem, $replacements, $dimensions ) : [];
		$reprocess = (bool) $request['reprocess_metadata'];

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
		];
		$response->set_data( $data );

		return $response;
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
	 * Width and height of each new file, keyed by its upload-relative path.
	 *
	 * @return array<string, int[]>
	 */
	public static function fileDimensions( array $files, array $meta ) {

		$dimensions = [];

		if ( isset( $files['full'], $meta['width'], $meta['height'] ) ) {
			$dimensions[ $files['full'] ] = [ (int) $meta['width'], (int) $meta['height'] ];
		}

		foreach ( (array) ( $meta['sizes'] ?? [] ) as $size => $data ) {

			if ( isset( $files[ $size ], $data['width'], $data['height'] ) ) {
				$dimensions[ $files[ $size ] ] = [ (int) $data['width'], (int) $data['height'] ];
			}
		}

		// The original is not in the metadata, only its name.
		if ( isset( $files['original'] ) ) {

			$size = wp_getimagesize( path_join( wp_get_upload_dir()['basedir'], $files['original'] ) );

			if ( $size ) {
				$dimensions[ $files['original'] ] = [ (int) $size[0], (int) $size[1] ];
			}
		}

		return array_filter( $dimensions, static fn( $d ) => $d[0] > 0 && $d[1] > 0 );
	}

	/**
	 * Corrects the width and height attributes of img tags showing one of the
	 * new files, when the new image has another shape: the width the author
	 * gave is kept and the height follows the new proportions (or the
	 * reverse, for a tag with only a height). Without this a re-cropped image
	 * is stretched to the old shape.
	 *
	 * @param string               $content
	 * @param array<string, int[]> $dimensions New upload-relative paths => [ width, height ].
	 */
	public static function fixImageDimensions( $content, array $dimensions ) {

		if ( ! $dimensions || false === stripos( $content, '<img' ) ) {
			return $content;
		}

		$p = new \WP_HTML_Tag_Processor( $content );

		while ( $p->next_tag( 'img' ) ) {

			$src = (string) $p->get_attribute( 'src' );
			$shape = null;

			foreach ( $dimensions as $path => $size ) {

				if ( self::refersTo( $src, $path ) ) {
					$shape = $size;
					break;
				}
			}

			if ( ! $shape ) {
				continue;
			}

			$width = $p->get_attribute( 'width' );
			$height = $p->get_attribute( 'height' );

			if ( is_string( $width ) && ctype_digit( $width ) && (int) $width > 0 ) {
				$p->set_attribute( 'height', (string) max( 1, (int) round( (int) $width * $shape[1] / $shape[0] ) ) );
			} elseif ( is_string( $height ) && ctype_digit( $height ) && (int) $height > 0 ) {
				$p->set_attribute( 'width', (string) max( 1, (int) round( (int) $height * $shape[0] / $shape[1] ) ) );
			}
		}

		return $p->get_updated_html();
	}

	/**
	 * Whether a URL is of the file at an upload-relative path, by the same
	 * rule as rewriteReferences().
	 */
	protected static function refersTo( $url, $path ) {

		return (bool) preg_match( self::referencePattern( $path ), $url );
	}

	/**
	 * Matches a reference to the file at an upload-relative path; group 1 is
	 * everything before its name.
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
	 * @param array    $dimensions    New upload-relative paths => [ width, height ],
	 *                                to correct img tags (see fixImageDimensions()).
	 * @return int[] IDs of the updated posts.
	 */
	protected static function updateReferences( $stem, array $replacements, array $dimensions = [] ) {

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

			// Only tags this replacement changed: their files are the new ones.
			$changed = array_intersect_key( $dimensions, array_flip( $replacements ) );

			foreach ( [ 'post_content', 'post_excerpt' ] as $field ) {

				if ( $fields[ $field ] !== $post->$field ) {
					$fields[ $field ] = self::fixImageDimensions( $fields[ $field ], $changed );
				}
			}

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
