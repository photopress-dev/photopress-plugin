<?php

namespace PhotoPress\modules\images;
use photopress_module;
use pp_api;
use PhotoPress\jobs\Jobs;
use PhotoPress\modules\media\CdnInvalidator;
use WP_Error;

/**
 * Images Module
 *
 * The quality WordPress saves JPEG and WebP images at, which of the
 * registered image sizes are made, and a background job that makes each
 * image's sizes again after either changes.
 */
class images extends photopress_module {

	public $label = 'Image Sizes';

	/**
	 * JPEG and WebP quality, out of 100. WordPress's own is 82, which shows
	 * on detailed photographs; at 92 the difference from the original is not
	 * visible, at well under half the size of 100.
	 */
	const DEFAULT_QUALITY = 92;

	/**
	 * The settings an image's sizes were last made with (see signature()).
	 */
	const SIGNATURE_META = '_photopress_sizes_signature';

	const JOB = 'images.regenerate';

	public function definePublicHooks() {

		add_filter( 'wp_editor_set_quality', [ self::class, 'quality' ], 10, 2 );
		add_filter( 'intermediate_image_sizes_advanced', [ self::class, 'enabledSizes' ] );
		add_action( 'rest_api_init', [ self::class, 'registerRoutes' ] );

		Jobs::register( self::JOB, [
			'label'         => __( 'Regenerate image sizes' ),
			'description'   => __( 'Makes every image\'s sizes again from its original, with the sizes and quality set here.' ),
			'count'         => [ self::class, 'countImages' ],
			'items'         => [ self::class, 'nextImages' ],
			'process'       => [ self::class, 'regenerate' ],
			// Image processing is the heaviest work a site does: a few images
			// at a time, resting between batches, and not while the server is
			// busy.
			'batch_seconds' => 10,
			'batch_items'   => 5,
			'pace'          => true,
			'finish'        => [ self::class, 'finished' ],
		] );
	}

	/**
	 * wp_editor_set_quality: the quality setting, for JPEG and WebP. Other
	 * formats keep WordPress's quality, as theirs is not on the same scale.
	 */
	public static function quality( $quality, $mime_type = '' ) {

		if ( ! in_array( $mime_type, [ 'image/jpeg', 'image/webp' ], true ) ) {
			return $quality;
		}

		$setting = (int) pp_api::getOption( 'core', 'images', 'quality' );

		return $setting >= 1 && $setting <= 100 ? $setting : $quality;
	}

	/**
	 * The sizes turned off, by name.
	 *
	 * @return string[]
	 */
	public static function disabledSizes() {

		$list = (string) pp_api::getOption( 'core', 'images', 'disabled_sizes' );

		return array_values( array_filter( array_map( 'trim', explode( ',', $list ) ), 'strlen' ) );
	}

	/**
	 * intermediate_image_sizes_advanced: the sizes WordPress makes for an
	 * image, less those turned off. They stay registered, so this page can
	 * list them and themes can still ask for them by name (they get the
	 * nearest size there is).
	 */
	public static function enabledSizes( $sizes ) {

		return array_diff_key( (array) $sizes, array_flip( self::disabledSizes() ) );
	}

	/**
	 * What an image's sizes depend on: the sizes made, the quality and the
	 * big image threshold. An image whose sizes were made with these is up
	 * to date.
	 */
	public static function signature() {

		$sizes = self::enabledSizes( wp_get_registered_image_subsizes() );
		ksort( $sizes );

		return md5( wp_json_encode( [
			'sizes'     => $sizes,
			'quality'   => self::quality( 82, 'image/jpeg' ),
			'threshold' => (int) apply_filters( 'big_image_size_threshold', 2560, [ 0, 0 ], '', 0 ),
		] ) );
	}

	/**
	 * GET /photopress/v1/image-sizes: every registered size, whether it is
	 * made, and how many images need their sizes made again.
	 */
	public static function registerRoutes() {

		register_rest_route( 'photopress/v1', '/image-sizes', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'status' ],
			'permission_callback' => static fn() => current_user_can( 'manage_options' ),
		] );
	}

	public static function status() {

		$disabled = self::disabledSizes();
		$core = [ 'thumbnail', 'medium', 'medium_large', 'large', '1536x1536', '2048x2048' ];
		$sizes = [];

		foreach ( wp_get_registered_image_subsizes() as $name => $size ) {
			$sizes[] = [
				'name'    => $name,
				'width'   => (int) $size['width'],
				'height'  => (int) $size['height'],
				'crop'    => ! empty( $size['crop'] ),
				'core'    => in_array( $name, $core, true ),
				'enabled' => ! in_array( $name, $disabled, true ),
			];
		}

		return [
			'sizes'    => $sizes,
			'images'   => self::countImages(),
			'outdated' => self::countImages() - self::countUpToDate(),
		];
	}

	/**
	 * The images, for the job: all of them, or those in $args['ids'].
	 */
	public static function countImages( $args = [] ) {

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'" . self::onlyIds( $args ) );
	}

	private static function onlyIds( $args ) {

		$ids = array_filter( array_map( 'intval', (array) ( $args['ids'] ?? [] ) ) );

		return $ids ? ' AND ID IN (' . implode( ',', $ids ) . ')' : '';
	}

	protected static function countUpToDate() {

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = %s WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%%'",
			self::SIGNATURE_META,
			self::signature()
		) );
	}

	/**
	 * The next images after $after, by ID, for the job.
	 */
	public static function nextImages( $after, $limit, $args = [] ) {

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%%'" . self::onlyIds( $args ) . " AND ID > %d ORDER BY ID LIMIT %d",
			(int) $after,
			(int) $limit
		) ) );
	}

	/**
	 * Makes an image's sizes again from its original upload, as WordPress
	 * does on upload, with the current sizes, quality and big image
	 * threshold (a -scaled copy is made again too, or not, by the threshold
	 * as it is now). Sizes no longer made are left out of its metadata; their
	 * files stay. An image already made with these settings is skipped,
	 * unless $args['all'].
	 *
	 * @return true|WP_Error
	 */
	public static function regenerate( $id, $args = [] ) {

		$signature = self::signature();

		if ( empty( $args['all'] ) && get_post_meta( $id, self::SIGNATURE_META, true ) === $signature ) {
			return true;
		}

		$original = wp_get_original_image_path( $id );

		if ( ! $original || ! is_readable( $original ) ) {
			return new WP_Error( 'photopress_regenerate_missing', __( 'The original file is not on this server.' ) );
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		self::limitImagick();

		// From the original: WordPress makes the -scaled copy again from it if
		// it is over the threshold, and points the image at that.
		if ( get_attached_file( $id, true ) !== $original ) {
			update_attached_file( $id, $original );
		}

		// WordPress saves the metadata as it makes each size; Offload Media
		// waits for the finished set, then uploads it.
		add_filter( 'as3cf_pre_update_attachment_metadata', '__return_true' );
		$meta = wp_generate_attachment_metadata( $id, $original );
		remove_filter( 'as3cf_pre_update_attachment_metadata', '__return_true' );

		if ( empty( $meta ) || ! is_array( $meta ) ) {
			return new WP_Error( 'photopress_regenerate_failed', __( 'WordPress could not make this image\'s sizes.' ) );
		}

		wp_update_attachment_metadata( $id, $meta );
		update_post_meta( $id, self::SIGNATURE_META, $signature );

		return true;
	}

	/**
	 * ImageMagick uses every processor and as much memory as it likes by
	 * default. For the job: one thread, so a processor stays free for the
	 * site's visitors, and its memory capped, past which it uses disk. The
	 * limits are ImageMagick's own, for this PHP process; PHP's memory_limit
	 * does not see ImageMagick's memory.
	 */
	protected static function limitImagick() {

		if ( ! class_exists( 'Imagick' ) || ! is_callable( [ 'Imagick', 'setResourceLimit' ] ) ) {
			return;
		}

		foreach ( [
			'Imagick::RESOURCETYPE_THREAD' => 1,
			'Imagick::RESOURCETYPE_MEMORY' => 256 * 1024 * 1024,
			'Imagick::RESOURCETYPE_MAP'    => 512 * 1024 * 1024,
		] as $type => $limit ) {
			if ( defined( $type ) ) {
				\Imagick::setResourceLimit( constant( $type ), $limit );
			}
		}

		$dir = self::temporaryDir();

		if ( $dir && is_callable( [ 'Imagick', 'setRegistry' ] ) ) {
			\Imagick::setRegistry( 'temporary-path', $dir );
		}
	}

	/**
	 * Where ImageMagick puts what does not fit in its memory: a folder in
	 * uploads, which is on disk, rather than the system's temporary folder,
	 * which on many servers is in memory (tmpfs), and would put it back in
	 * memory. Closed to the web. Null if it cannot be made.
	 */
	protected static function temporaryDir() {

		$uploads = wp_upload_dir( null, false );

		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return null;
		}

		$dir = trailingslashit( $uploads['basedir'] ) . 'photopress-tmp';

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return null;
		}

		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			@file_put_contents( $dir . '/.htaccess', "Require all denied\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			@file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}

		return wp_is_writable( $dir ) ? $dir : null;
	}

	/**
	 * When the job is done: the files under their old names in a CDN's
	 * cache are cleared, with one invalidation for them all.
	 */
	public static function finished( $job ) {

		if ( class_exists( CdnInvalidator::class ) && '' !== CdnInvalidator::domain() ) {
			CdnInvalidator::invalidateAll();
		}
	}

	public function registerOptions() {

		return [

			'quality' => [

				'default_value' => self::DEFAULT_QUALITY,
				'field'         => [
					'type'          => 'integer',
					'title'         => 'Image quality',
					'page_name'     => 'images',
					'section'       => 'general',
					'description'   => 'The quality JPEG and WebP sizes are saved at, out of 100.',
					'label_for'     => 'Image quality',
					'error_message' => '',
				],
			],

			'disabled_sizes' => [

				'default_value' => '',
				'field'         => [
					'type'          => 'comma_separated_list',
					'title'         => 'Sizes not made',
					'page_name'     => 'images',
					'section'       => 'general',
					'description'   => 'The registered image sizes WordPress does not make, by name.',
					'label_for'     => 'Sizes not made',
					'error_message' => '',
				],
			],
		];
	}

	public function registerSettingsPages() {

		return [

			'images' => [

				'parent_slug'         => 'photopress-core-base',
				'title'               => 'Image Sizes',
				'menu_title'          => 'Image Sizes',
				'required_capability' => 'manage_options',
				'menu_slug'           => 'photopress-images',
				'description'         => 'The image sizes WordPress makes and the quality it saves them at.',
				'sections'            => [
					'general' => [
						'id'          => 'general',
						'title'       => 'General',
						'description' => '',
					],
				],
				'noPhpRender'         => true,
			],
		];
	}
}
