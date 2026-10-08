<?php

namespace PhotoPress\modules\media;

use PhotoPress\modules\gallery\GalleryAdd;
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
 * GET /photopress/v1/media/limits gives the size limit WordPress applies
 * to uploads, for checking exports before uploading them.
 *
 * Everything else such a tool needs is core: finding an earlier upload by
 * file name (GET /wp/v2/media?search=, which searches file names too),
 * uploading new images, and setting fields. Core cannot change the file of an
 * image that is in use.
 *
 * The image keeps its ID, attachment page and file name, so its URLs do not
 * change: a new file of the same type overwrites the original, and its sizes
 * are regenerated over the old ones. Only a new shape or size (size names
 * carry dimensions) or a new file type gives files new names; then the old
 * files are deleted and stored links to them are rewritten. Title and caption
 * follow the new file as on upload.
 *
 * Caches: each step fires the hooks WordPress fires for an edit, so page cache
 * plugins purge the pages concerned. Offload Media re-uploads the files to the
 * same keys when the metadata is saved; the cache lifetime it gives files is
 * set by MediaOffload. _photopress_replaced_at records when, for a CDN
 * invalidation.
 */
class MediaRest {

	const REST_NAMESPACE = 'photopress/v1';

	/**
	 * When the image's file was last replaced (Unix time).
	 */
	const REPLACED_AT_META_KEY = '_photopress_replaced_at';

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
				'sync_embedded' => [
					'description' => __( 'Give every image block and gallery image showing this image its current alt text and caption, replacing what is there.' ),
					'type'        => 'boolean',
					'default'     => false,
				],
				GalleryAdd::FIELD   => GalleryAdd::schema()['arg_options'] + array_diff_key( GalleryAdd::schema(), [ 'arg_options' => 1, 'context' => 1 ] ),
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/media/limits', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ self::class, 'limits' ],
			'permission_callback' => [ self::class, 'canUpload' ],
		] );
	}

	public static function canUpload() {

		return current_user_can( 'upload_files' );
	}

	/**
	 * GET /photopress/v1/media/limits: the size limit WordPress applies to
	 * uploaded images, so that a publishing tool can check its exports
	 * against the live value before uploading them.
	 *
	 * Core lists image_size_threshold in the REST index (WordPress 7.1), but
	 * only while client-side media processing is on; this answers either way.
	 */
	public static function limits() {

		return rest_ensure_response( [
			'image_size_threshold' => self::imageSizeThreshold(),
		] );
	}

	/**
	 * Images wider or taller than this are scaled down to it on upload; 0
	 * when the site turns that off. Asked as WP_REST_Server::get_index()
	 * asks, for no image in particular.
	 */
	public static function imageSizeThreshold() {

		return max( 0, (int) apply_filters( 'big_image_size_threshold', 2560, [ 0, 0 ], '', 0 ) );
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
	 * Gives an image a new file, keeping its name where the file type allows.
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

		// 1. What the file is, from its contents rather than its name. Checked
		// before anything is written.
		$type = wp_check_filetype_and_ext( $upload['tmp_name'], $upload['name'] );

		if ( empty( $type['type'] ) || 0 !== strpos( $type['type'], 'image/' ) || ! wp_getimagesize( $upload['tmp_name'] ) ) {
			@unlink( $upload['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'rest_upload_invalid_type', __( 'Sorry, you are not allowed to upload this file type.' ), [ 'status' => 400 ] );
		}

		$basedir = wp_get_upload_dir()['basedir'];
		$old_meta = wp_get_attachment_metadata( $id, true );
		$old_meta = is_array( $old_meta ) ? $old_meta : [];
		$old_files = self::attachmentFiles( $old_file, $old_meta );

		// Their URLs as served now (Offload Media's, when it serves them),
		// for anything that keeps copies elsewhere.
		$old_url_dir = dirname( (string) wp_get_attachment_url( $id ) );
		$old_urls = array_map( static fn( $relative ) => $old_url_dir . '/' . wp_basename( $relative ), $old_files );

		// 2. The name. The original (before WordPress scaled it) keeps its
		// name; another type keeps the base name with its own extension, or
		// the next free name if a file that is not this image's has it.
		$original = $old_files['original'] ?? $old_files['full'];
		$name = pathinfo( $original, PATHINFO_FILENAME );
		$extension = $type['ext'] ?: strtolower( pathinfo( $upload['name'], PATHINFO_EXTENSION ) );
		$dir = dirname( path_join( $basedir, $original ) );
		$same_type = get_post_mime_type( $id ) === $type['type'];
		$target = $same_type ? path_join( $basedir, $original ) : path_join( $dir, "{$name}.{$extension}" );
		$own = array_map( static fn( $relative ) => path_join( $basedir, $relative ), $old_files );

		if ( ! $same_type && file_exists( $target ) && ! in_array( $target, $own, true ) ) {
			$target = path_join( $dir, wp_unique_filename( $dir, "{$name}.{$extension}" ) );
		}

		// 3. The file, written next to the old ones under a temporary name.
		// Through wp_handle_sideload() so the upload checks and the filters
		// other plugins hook (licence embedding among them) run as for any
		// upload.
		$subdir = self::uploadSubdir( $original );
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

		// Page caches: the image's pages as they are now, before its terms
		// can change, so that a term it loses is purged too.
		self::announceEdit( $id );

		// 4. Into place: over the original when the type is the same, in one
		// step, so its URL never misses a file.
		if ( ! @rename( $saved['file'], $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			wp_delete_file( $saved['file'] );
			return new WP_Error( 'rest_upload_unknown_error', __( 'The file could not be moved into place.' ), [ 'status' => 500 ] );
		}

		update_attached_file( $id, $target );

		if ( ! $same_type ) {
			wp_update_post( [ 'ID' => $id, 'post_mime_type' => $type['type'] ] );
		}

		// 5. The sizes, and -scaled for large images, over the old ones where
		// the names are the same. WordPress saves the metadata as it goes;
		// Offload Media waits for the finished set, then uploads it.
		add_filter( 'as3cf_pre_update_attachment_metadata', '__return_true' );
		$new_meta = wp_generate_attachment_metadata( $id, $target );
		remove_filter( 'as3cf_pre_update_attachment_metadata', '__return_true' );

		wp_update_attachment_metadata( $id, $new_meta );
		clean_attachment_cache( $id );

		$new_meta = wp_get_attachment_metadata( $id, true );
		$new_meta = is_array( $new_meta ) ? $new_meta : [];
		$new_files = self::attachmentFiles( get_attached_file( $id, true ), $new_meta );

		// 6. This image's files that are not in the new set: sizes renamed by
		// new dimensions, a -scaled no longer needed, the old type's original,
		// and copies kept by WordPress's image editor.
		$gone = array_diff( array_unique( array_values( $old_files ) ), array_values( $new_files ) );
		$backups = get_post_meta( $id, '_wp_attachment_backup_sizes', true );

		foreach ( is_array( $backups ) ? $backups : [] as $backup ) {
			if ( ! empty( $backup['file'] ) ) {
				$gone[] = ltrim( self::uploadSubdir( $original ) . '/' . wp_basename( $backup['file'] ), '/' );
			}
		}

		foreach ( array_unique( $gone ) as $relative ) {
			wp_delete_file( path_join( $basedir, $relative ) );
		}

		delete_post_meta( $id, '_wp_attachment_backup_sizes' );

		$removed_urls = array_values( array_intersect_key( $old_urls, array_flip( array_keys( array_intersect( $old_files, $gone ) ) ) ) );

		if ( $removed_urls ) {

			/**
			 * Fires after a replacement deleted files of the image that the new
			 * file does not have (renamed sizes, the old type's original).
			 *
			 * @param int      $id   Attachment ID.
			 * @param string[] $urls Their URLs, as they were served.
			 */
			do_action( 'photopress_attachment_files_removed', $id, $removed_urls );
		}

		// 7. Stored links to files that were renamed or removed.
		$replacements = self::fileReplacements( $old_files, $new_files, self::sizeWidths( $old_meta ), self::sizeWidths( $new_meta ) );
		$rewritten = $request['update_references'] ? self::updateReferences( self::stem( $name ), $replacements ) : [];

		// 8. Title, caption and description follow the file.
		$text_changed = self::updateText( $id, $target, $name );

		// 9. Taxonomies and alt text, when asked (the metadata module), and
		// anything else hooked here.
		$reprocess = (bool) $request['reprocess_metadata'];
		$alt_before = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );

		/**
		 * Fires after an image was given a new file.
		 *
		 * @param int      $id           Attachment ID.
		 * @param string[] $replacements Old upload-relative paths mapped to new ones, for files whose names changed.
		 * @param int[]    $rewritten    IDs of posts whose stored links were rewritten.
		 * @param array    $options      reprocess_metadata: whether the client asked
		 *                               for the new file's metadata to be read.
		 */
		do_action( 'photopress_attachment_file_replaced', $id, $replacements, $rewritten, [ 'reprocess_metadata' => $reprocess ] );

		$text_changed = $text_changed || (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) !== $alt_before;

		// 10. Every placement of the image, when asked.
		$synced = $request['sync_embedded'] ? self::syncEmbedded( $id ) : [];

		update_post_meta( $id, self::REPLACED_AT_META_KEY, time() );

		// Page caches: the image's pages again, its files, and the posts that
		// changed or show text that changed.
		$file_urls = [];
		$baseurl = wp_get_upload_dir()['baseurl'];

		foreach ( array_unique( array_merge( array_values( $old_files ), array_values( $new_files ) ) ) as $relative ) {
			$file_urls[] = $baseurl . '/' . $relative;
		}

		// Its parent post too: a dynamic gallery there shows the image without
		// a link to it in the content.
		$parent = (int) get_post_field( 'post_parent', $id );
		$posts = array_merge( $rewritten, $synced, $text_changed ? self::postsShowing( $id, self::stem( $name ) ) : [], $parent ? [ $parent ] : [] );
		self::announceEdit( $id, $file_urls );

		foreach ( array_unique( $posts ) as $post_id ) {
			self::announceEdit( (int) $post_id );
		}

		// The gallery, when asked. Never by attaching: the image is published.
		$gallery = ! empty( $request[ GalleryAdd::FIELD ] ) ? GalleryAdd::add( $id, $request[ GalleryAdd::FIELD ], false ) : null;

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
			'posts_updated'        => array_values( array_unique( array_merge( $rewritten, $synced ) ) ),
			'text_updated'         => $text_changed,
			'metadata_reprocessed' => $reprocess,
			'embedded_synced'      => $synced,
		];

		if ( null !== $gallery ) {
			$data[ GalleryAdd::FIELD ] = $gallery;
		}

		$response->set_data( $data );

		return $response;
	}

	/**
	 * Fires edit_post for a post, as WordPress does when one is edited, so
	 * page cache plugins purge its pages; nothing is saved. WordPress does
	 * not fire it for attachments itself, so their pages would not be
	 * purged otherwise.
	 *
	 * @param int      $id    Post ID.
	 * @param string[] $files URLs of files to purge with it, through Proxy
	 *                        Cache Purge's vhp_purge_urls filter; other plugins
	 *                        ignore it.
	 */
	public static function announceEdit( $id, array $files = [] ) {

		$post = get_post( $id );

		if ( ! $post ) {
			return;
		}

		$add_files = static function ( $urls, $post_id = 0 ) use ( $id, $files ) {
			return (int) $post_id === (int) $id ? array_merge( (array) $urls, $files ) : $urls;
		};

		if ( $files ) {
			add_filter( 'vhp_purge_urls', $add_files, 10, 2 );
		}

		do_action( 'edit_post', $id, $post );

		if ( $files ) {
			remove_filter( 'vhp_purge_urls', $add_files, 10 );
		}
	}

	/**
	 * Sets the image's title and caption from the new file by WordPress's
	 * upload rules (wp_read_image_metadata(), which PhotoPress also has read
	 * XMP), and its description from the metadata module's description
	 * template when one is set. A blank title is the file name, as on upload;
	 * a blank caption or description clears it.
	 *
	 * @return bool Whether anything changed.
	 */
	protected static function updateText( $id, $file, $name ) {

		$meta = wp_read_image_metadata( $file );
		$meta = is_array( $meta ) ? $meta : [];
		$post = get_post( $id );

		$title = trim( (string) ( $meta['title'] ?? '' ) );

		if ( '' === $title || is_numeric( sanitize_title( $title ) ) ) {
			$title = sanitize_text_field( $name );
		}

		$fields = [
			'post_title'   => $title,
			'post_excerpt' => trim( (string) ( $meta['caption'] ?? '' ) ),
		];

		$description = apply_filters( 'photopress_attachment_description', null, $id, $file );

		if ( null !== $description ) {
			$fields['post_content'] = (string) $description;
		}

		$changes = [];

		foreach ( $fields as $field => $value ) {
			if ( $value !== (string) $post->$field ) {
				$changes[ $field ] = $value;
			}
		}

		if ( ! $changes ) {
			return false;
		}

		wp_update_post( [ 'ID' => $id ] + $changes );

		return true;
	}

	/**
	 * Gives the image blocks showing the image (on their own or in a
	 * gallery) its current alt text and caption.
	 *
	 * @return int[] IDs of the posts updated.
	 */
	protected static function syncEmbedded( $id ) {

		global $wpdb;

		$alt = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
		$caption = (string) get_post( $id )->post_excerpt;
		$updated = [];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$posts = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, post_content FROM {$wpdb->posts}
			WHERE post_type NOT IN ( 'revision', 'attachment' ) AND post_content LIKE %s",
			'%' . $wpdb->esc_like( 'wp-image-' . $id ) . '%'
		) );

		foreach ( $posts as $post ) {

			$blocks = parse_blocks( $post->post_content );
			$changed = self::syncBlocks( $blocks, $id, $alt, $caption );

			if ( ! $changed ) {
				continue;
			}

			// As for the link rewrite: not an edit of the post, so no new
			// modified date or revision.
			$wpdb->update( $wpdb->posts, [ 'post_content' => serialize_blocks( $blocks ) ], [ 'ID' => $post->ID ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			clean_post_cache( $post->ID );
			$updated[] = (int) $post->ID;
		}

		return $updated;
	}

	/**
	 * syncEmbedded() for a list of parsed blocks, at any depth.
	 *
	 * @return bool Whether any block changed.
	 */
	public static function syncBlocks( array &$blocks, $id, $alt, $caption ) {

		$changed = false;

		foreach ( $blocks as &$block ) {

			if ( 'core/image' === ( $block['blockName'] ?? '' ) && (int) ( $block['attrs']['id'] ?? 0 ) === (int) $id ) {

				$html = self::imageBlockWith( $block['innerHTML'], $alt, $caption );

				if ( $html !== $block['innerHTML'] ) {
					$block['innerHTML'] = $html;
					$block['innerContent'] = [ $html ];
					$changed = true;
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && self::syncBlocks( $block['innerBlocks'], $id, $alt, $caption ) ) {
				$changed = true;
			}
		}

		return $changed;
	}

	/**
	 * An image block's markup with the given alt text and caption, as the
	 * block itself saves them: alt on the img, the caption in a figcaption
	 * at the end of the figure, none when it is empty.
	 */
	public static function imageBlockWith( $html, $alt, $caption ) {

		$p = new \WP_HTML_Tag_Processor( $html );

		if ( $p->next_tag( 'img' ) ) {
			$p->set_attribute( 'alt', $alt );
			$html = $p->get_updated_html();
		}

		$html = preg_replace( '#<figcaption\b[^>]*>.*?</figcaption>#s', '', $html );

		if ( '' !== trim( $caption ) ) {
			$figcaption = '<figcaption class="wp-element-caption">' . wp_kses_post( $caption ) . '</figcaption>';
			$html = preg_replace( '#</figure>(\s*)$#', $figcaption . '</figure>$1', $html, 1 );
		}

		return $html;
	}

	/**
	 * Posts that show the image: in their content (as wp-image-<id>, or a
	 * link to one of its files) or as their featured image.
	 *
	 * @return int[]
	 */
	protected static function postsShowing( $id, $stem ) {

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			WHERE post_type NOT IN ( 'revision', 'attachment' ) AND ( post_content LIKE %s OR post_content LIKE %s )
			UNION SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s",
			'%' . $wpdb->esc_like( 'wp-image-' . $id ) . '%',
			'%' . $wpdb->esc_like( $stem ) . '%',
			(string) $id
		) ) );
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
	 * Points stored links at the new files: post content, excerpts and meta
	 * (the image's own rows are skipped), comment, term and user meta, and
	 * options, as Enable Media Replace does.
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

		// The rest of WordPress's tables that hold values: comment, term and
		// user meta, and options (block widgets, theme settings such as the
		// site logo, plugin settings).
		$tables = [
			[ $wpdb->commentmeta, 'meta_id', 'comment_id', 'meta_value', 'comment_meta' ],
			[ $wpdb->termmeta, 'meta_id', 'term_id', 'meta_value', 'term_meta' ],
			[ $wpdb->usermeta, 'umeta_id', 'user_id', 'meta_value', 'user_meta' ],
			[ $wpdb->options, 'option_id', 'option_name', 'option_value', 'options' ],
		];

		foreach ( $tables as [ $table, $key, $object, $column, $cache_group ] ) {

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT {$key} AS row_id, {$object} AS object, {$column} AS value FROM {$table} WHERE {$column} LIKE %s", $like ) );

			foreach ( $rows as $row ) {

				$value = self::rewriteValue( $row->value, $replacements );

				if ( $value === $row->value ) {
					continue;
				}

				$wpdb->update( $table, [ $column => $value ], [ $key => $row->row_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

				if ( 'options' === $cache_group ) {
					wp_cache_delete( $row->object, 'options' );
					wp_cache_delete( 'alloptions', 'options' );
				} else {
					wp_cache_delete( $row->object, $cache_group );
				}
			}
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
