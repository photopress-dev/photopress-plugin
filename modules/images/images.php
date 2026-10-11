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
	 * WordPress's big image threshold: an upload longer than this on a side
	 * gets a -scaled copy this size, shown as the image in place of the
	 * original.
	 */
	const DEFAULT_THRESHOLD = 2560;

	/**
	 * The quality an image's sizes were made at. Which sizes it has, and
	 * whether it was scaled down, are in WordPress's own metadata; the
	 * quality is not.
	 */
	const QUALITY_META = '_photopress_quality';

	/**
	 * The quality images without QUALITY_META are taken to have been made
	 * at: the setting when this was first needed.
	 */
	const ASSUMED_QUALITY_OPTION = 'photopress_images_assumed_quality';

	/**
	 * Before QUALITY_META (1.9.0): a hash of the settings an image was made
	 * with. Deleted when the image is regenerated.
	 */
	const SIGNATURE_META = '_photopress_sizes_signature';

	const JOB = 'images.regenerate';

	public function definePublicHooks() {

		add_filter( 'wp_editor_set_quality', [ self::class, 'quality' ], 10, 2 );
		add_filter( 'intermediate_image_sizes_advanced', [ self::class, 'enabledSizes' ] );
		add_filter( 'big_image_size_threshold', [ self::class, 'threshold' ] );
		add_action( 'rest_api_init', [ self::class, 'registerRoutes' ] );
		add_filter( 'wp_generate_attachment_metadata', [ self::class, 'markUpToDate' ], 10, 3 );

		Jobs::register( self::JOB, [
			'label'         => __( 'Regenerate image sizes' ),
			'description'   => __( 'Gives every image the sizes and quality set here, from its original.' ),
			// The images that need work, unless all.
			'count'         => static fn( $args ) => self::countImages( $args + [ 'outdated' => empty( $args['all'] ) ] ),
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
	 * big_image_size_threshold: the longest side set, or false (WordPress's
	 * off) when large uploads are not scaled down.
	 */
	public static function threshold( $threshold = self::DEFAULT_THRESHOLD ) {

		return self::thresholdOf( [] ) ?: false;
	}

	/**
	 * The threshold the settings give, 0 for off: $given's
	 * scale_large_uploads and big_image_threshold, or those saved. Never
	 * less than the longest side of the largest size made (with $given's
	 * disabled_sizes, or those saved): WordPress makes the sizes from the
	 * original, so a smaller copy would be smaller than the sizes offered
	 * beside it.
	 */
	private static function thresholdOf( $given ) {

		$setting = static fn( $key ) => array_key_exists( $key, $given ) ? $given[ $key ] : pp_api::getOption( 'core', 'images', $key );
		$scale = $setting( 'scale_large_uploads' );
		$longest = (int) $setting( 'big_image_threshold' );

		if ( null !== $scale && ! filter_var( $scale, FILTER_VALIDATE_BOOLEAN ) ) {
			return 0;
		}

		$largest = 0;
		foreach ( self::settings( array_intersect_key( $given, [ 'disabled_sizes' => 0 ] ) + [ 'scale_large_uploads' => false ] )['sizes'] as $size ) {
			$largest = max( $largest, (int) $size['width'], (int) $size['height'] );
		}

		return max( $longest > 0 ? $longest : self::DEFAULT_THRESHOLD, $largest );
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
	 * What new images get: the quality, the big image threshold (0 when
	 * off) and the sizes made, as WordPress describes them. $given: quality,
	 * disabled_sizes, scale_large_uploads and big_image_threshold to use
	 * instead of those saved, for the images a change would affect.
	 */
	public static function settings( $given = [] ) {

		$disabled = array_key_exists( 'disabled_sizes', $given )
			? array_filter( array_map( 'trim', is_array( $given['disabled_sizes'] ) ? $given['disabled_sizes'] : explode( ',', (string) $given['disabled_sizes'] ) ), 'strlen' )
			: self::disabledSizes();
		$quality = array_key_exists( 'quality', $given ) ? (int) $given['quality'] : (int) pp_api::getOption( 'core', 'images', 'quality' );

		return [
			'quality'   => $quality >= 1 && $quality <= 100 ? $quality : 82,
			'threshold' => array_intersect_key( $given, [ 'scale_large_uploads' => 0, 'big_image_threshold' => 0 ] )
				? self::thresholdOf( $given )
				: (int) apply_filters( 'big_image_size_threshold', self::DEFAULT_THRESHOLD, [ 0, 0 ], '', 0 ),
			'sizes'     => array_diff_key( (array) wp_get_registered_image_subsizes(), array_flip( $disabled ) ),
		];
	}

	/**
	 * The quality images made before PhotoPress recorded it are taken to
	 * have: the setting the first time this is asked.
	 */
	public static function assumedQuality() {

		$assumed = (int) get_option( self::ASSUMED_QUALITY_OPTION, 0 );

		if ( ! $assumed ) {
			$assumed = self::settings()['quality'];
			add_option( self::ASSUMED_QUALITY_OPTION, $assumed, '', false );
		}

		return $assumed;
	}

	/**
	 * What an image needs for its sizes to be as $settings say, from its
	 * metadata and the quality it was made at: 'full', every size made again
	 * from the original, when the quality (JPEG and WebP only) is another or
	 * the threshold scales it otherwise; 'missing', the sizes turned on that
	 * it lacks and its original is large enough for; or null, nothing.
	 *
	 * A size turned off is nothing to do: images keep the sizes they have,
	 * files and metadata together.
	 *
	 * @return string|null
	 */
	public static function work( $meta, $mime, $quality, $settings ) {

		if ( ! is_array( $meta ) || empty( $meta['width'] ) || empty( $meta['height'] ) ) {
			return 'full';
		}

		if ( in_array( $mime, [ 'image/jpeg', 'image/webp' ], true ) && (int) $quality !== $settings['quality'] ) {
			return 'full';
		}

		// A -scaled copy's longest side is the threshold it was made with.
		$long = max( (int) $meta['width'], (int) $meta['height'] );
		$scaled = ! empty( $meta['original_image'] ) && preg_match( '/-scaled\.[a-z0-9]+$/i', (string) ( $meta['file'] ?? '' ) );
		$threshold = $settings['threshold'];

		if ( $scaled ? $threshold !== $long : $threshold > 0 && $long > $threshold ) {
			return 'full';
		}

		// WordPress makes the sizes from the original, so one larger than a
		// -scaled copy is made when the original is large enough for it.
		$original = null;

		foreach ( $settings['sizes'] as $name => $size ) {
			if ( isset( $meta['sizes'][ $name ] ) ) {
				continue;
			}
			$fits = static fn( $dims ) => (bool) image_resize_dimensions( (int) $dims[0], (int) $dims[1], (int) $size['width'], (int) $size['height'], $size['crop'] ?? false );
			if ( $fits( [ $meta['width'], $meta['height'] ] ) ) {
				return 'missing';
			}
			if ( $scaled ) {
				$original = $original ?? self::originalSize( $meta );
				if ( $original && $fits( $original ) ) {
					return 'missing';
				}
			}
		}

		return null;
	}

	/**
	 * A scaled image's original's width and height, from its file's header,
	 * or [] when the file is not on this server.
	 */
	private static function originalSize( $meta ) {

		$dir = dirname( (string) $meta['file'] );
		$file = trailingslashit( wp_get_upload_dir()['basedir'] ) . ( '.' === $dir ? '' : $dir . '/' ) . wp_basename( $meta['original_image'] );
		$size = is_readable( $file ) ? wp_getimagesize( $file ) : false;

		return $size ? [ (int) $size[0], (int) $size[1] ] : [];
	}

	/**
	 * The quality an image was made at, as recorded or assumed.
	 */
	public static function qualityOf( $id ) {

		$quality = (int) get_post_meta( $id, self::QUALITY_META, true );

		return $quality ?: self::assumedQuality();
	}

	/**
	 * The hash images were marked with in 1.9.0.
	 */
	public static function legacySignature() {

		$sizes = self::enabledSizes( wp_get_registered_image_subsizes() );
		ksort( $sizes );

		return md5( wp_json_encode( [
			'sizes'     => $sizes,
			'quality'   => self::quality( 82, 'image/jpeg' ),
			'threshold' => (int) apply_filters( 'big_image_size_threshold', 2560, [ 0, 0 ], '', 0 ),
		] ) );
	}

	/**
	 * wp_generate_attachment_metadata: an image uploaded now is made at the
	 * quality as it is.
	 */
	public static function markUpToDate( $metadata, $id, $context = 'create' ) {

		if ( 'create' === $context && ! empty( $metadata['sizes'] ) ) {
			update_post_meta( $id, self::QUALITY_META, self::settings()['quality'] );
		}

		return $metadata;
	}

	/**
	 * Whether WP Offload Media removes files from this server once they are
	 * offloaded: then the originals are not here to make sizes from.
	 */
	public static function offloadRemovesLocalFiles() {

		global $as3cf;

		return is_object( $as3cf ) && method_exists( $as3cf, 'get_setting' ) && (bool) $as3cf->get_setting( 'remove-local-file' );
	}

	/**
	 * GET /photopress/v1/image-sizes: every registered size, whether it is
	 * made, and how many images need their sizes made again.
	 *
	 * POST /photopress/v1/image-sizes/scope: how many images would need their
	 * sizes made again with the settings given (quality, disabled_sizes,
	 * scale_large_uploads, big_image_threshold).
	 */
	public static function registerRoutes() {

		register_rest_route( 'photopress/v1', '/image-sizes', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'status' ],
			'permission_callback' => static fn() => current_user_can( 'manage_options' ),
		] );

		register_rest_route( 'photopress/v1', '/image-sizes/scope', [
			'methods'             => 'POST',
			'callback'            => [ self::class, 'scope' ],
			'permission_callback' => static fn() => current_user_can( 'manage_options' ),
			'args'                => [
				'settings' => [
					'type'    => 'object',
					'default' => [],
				],
			],
		] );
	}

	public static function scope( $request ) {

		$settings = array_intersect_key( (array) $request->get_param( 'settings' ), array_flip( [ 'quality', 'disabled_sizes', 'scale_large_uploads', 'big_image_threshold' ] ) );

		return [ 'affected' => self::countImages( [ 'outdated' => true, 'settings' => $settings ] ) ];
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
			'sizes'              => $sizes,
			'images'             => self::countImages(),
			'outdated'           => self::countImages( [ 'outdated' => true ] ),
			'localFilesRemoved'  => self::offloadRemovesLocalFiles(),
		];
	}

	/**
	 * The images: all of them, or those in $args['ids']; with
	 * $args['outdated'], only those that need work for their sizes to be as
	 * the settings say (or $args['settings'], as given to settings()).
	 */
	public static function countImages( $args = [] ) {

		global $wpdb;

		if ( empty( $args['outdated'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'" . self::onlyIds( $args ) );
		}

		$count = 0;
		$after = 0;

		while ( $rows = self::rows( $after, 1000, $args ) ) {
			$count += count( array_filter( $rows ) );
			$after = array_key_last( $rows );
		}

		return $count;
	}

	/**
	 * The next images after $after, by ID, for the job: those that need
	 * work, unless $args['all'].
	 */
	public static function nextImages( $after, $limit, $args = [] ) {

		if ( ! empty( $args['all'] ) ) {
			return array_keys( self::rows( $after, $limit, $args, false ) );
		}

		$ids = [];

		while ( count( $ids ) < $limit && ( $rows = self::rows( $after, 500, $args ) ) ) {
			$ids = array_merge( $ids, array_keys( array_filter( $rows ) ) );
			$after = array_key_last( $rows );
		}

		return array_slice( $ids, 0, $limit );
	}

	/**
	 * Up to $limit images after $after, by ID: for each, whether it needs
	 * work, from its metadata and quality (with $judge; otherwise true).
	 *
	 * @return array<int, bool>
	 */
	private static function rows( $after, $limit, $args, $judge = true ) {

		global $wpdb;

		$where = "p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%%'" . self::onlyIds( $args, 'p.ID' ) . ' AND p.ID > %d ORDER BY p.ID LIMIT %d';

		if ( ! $judge ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			return array_fill_keys( array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p WHERE $where", (int) $after, (int) $limit ) ) ), true );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_mime_type, a.meta_value AS meta, q.meta_value AS quality FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = '_wp_attachment_metadata'
			LEFT JOIN {$wpdb->postmeta} q ON q.post_id = p.ID AND q.meta_key = %s
			WHERE $where",
			self::QUALITY_META,
			(int) $after,
			(int) $limit
		) );

		$settings = self::settings( (array) ( $args['settings'] ?? [] ) );
		$assumed = self::assumedQuality();
		$out = [];

		foreach ( $rows as $row ) {
			$out[ (int) $row->ID ] = null !== self::work( maybe_unserialize( $row->meta ), $row->post_mime_type, (int) $row->quality ?: $assumed, $settings );
		}

		return $out;
	}

	private static function onlyIds( $args, $column = 'ID' ) {

		$ids = array_filter( array_map( 'intval', (array) ( $args['ids'] ?? [] ) ) );

		return $ids ? " AND $column IN (" . implode( ',', $ids ) . ')' : '';
	}

	/**
	 * Brings an image's sizes up to date with the settings, doing only what
	 * it needs (see work()): nothing; making the sizes turned on that it
	 * lacks; or, with $args['all'] too, making every size again from its
	 * original upload as WordPress does on upload (a -scaled copy is made
	 * again, or not, by the threshold as it is now). Sizes turned off that
	 * it has are kept, files and metadata.
	 *
	 * @return true|WP_Error
	 */
	public static function regenerate( $id, $args = [] ) {

		$settings = self::settings();
		$meta = wp_get_attachment_metadata( $id );
		$work = empty( $args['all'] ) ? self::work( $meta, get_post_mime_type( $id ), self::qualityOf( $id ), $settings ) : 'full';

		if ( null === $work ) {
			return true;
		}

		$original = wp_get_original_image_path( $id );

		if ( ! $original || ! is_readable( $original ) ) {
			return new WP_Error( 'photopress_regenerate_missing', self::offloadRemovesLocalFiles()
				? __( 'The original file is not on this server: Offload Media removed it.' )
				: __( 'The original file is not on this server.' ) );
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		self::limitImagick();

		// WordPress saves the metadata as it makes each size; Offload Media
		// waits for the finished set, then uploads it.
		add_filter( 'as3cf_pre_update_attachment_metadata', '__return_true' );

		if ( 'full' === $work ) {
			$before = is_array( $meta ) ? (array) ( $meta['sizes'] ?? [] ) : [];
			$dir = dirname( (string) get_attached_file( $id, true ) );

			// From the original: WordPress makes the -scaled copy again from
			// it if it is over the threshold, and points the image at that.
			if ( get_attached_file( $id, true ) !== $original ) {
				update_attached_file( $id, $original );
			}
			$meta = wp_generate_attachment_metadata( $id, $original );

			// The sizes it had that are not made now (turned off) stay, as
			// long as their files do: WordPress deletes an image's files by
			// its metadata.
			if ( is_array( $meta ) ) {
				foreach ( $before as $name => $size ) {
					if ( ! isset( $meta['sizes'][ $name ] ) && ! empty( $size['file'] ) && file_exists( $dir . '/' . $size['file'] ) ) {
						$meta['sizes'][ $name ] = $size;
					}
				}
			}
		} else {
			// WordPress makes the sizes its metadata lacks, turned off or
			// not: those turned on only.
			$only = static fn( $missing ) => self::enabledSizes( $missing );
			add_filter( 'wp_get_missing_image_subsizes', $only );
			$meta = wp_update_image_subsizes( $id );
			remove_filter( 'wp_get_missing_image_subsizes', $only );
		}

		remove_filter( 'as3cf_pre_update_attachment_metadata', '__return_true' );

		if ( empty( $meta ) || ! is_array( $meta ) ) {
			return new WP_Error( 'photopress_regenerate_failed', __( 'WordPress could not make this image\'s sizes.' ) );
		}

		wp_update_attachment_metadata( $id, $meta );
		update_post_meta( $id, self::QUALITY_META, $settings['quality'] );
		delete_post_meta( $id, self::SIGNATURE_META );

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

			'scale_large_uploads' => [

				'default_value' => true,
				'field'         => [
					'type'          => 'boolean',
					'title'         => 'Scale down large uploads',
					'page_name'     => 'images',
					'section'       => 'general',
					'description'   => 'Whether an upload longer than the threshold gets a scaled-down copy, shown in place of the original.',
					'label_for'     => 'Scale down large uploads',
					'error_message' => '',
				],
			],

			'big_image_threshold' => [

				'default_value' => self::DEFAULT_THRESHOLD,
				'field'         => [
					'type'          => 'integer',
					'title'         => 'Largest image shown',
					'page_name'     => 'images',
					'section'       => 'general',
					'description'   => 'The longest side, in pixels, an upload is scaled down to.',
					'label_for'     => 'Largest image shown',
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
