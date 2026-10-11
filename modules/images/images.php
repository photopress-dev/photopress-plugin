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
	 * What an image's sizes were last made with (see recordFor()).
	 */
	const MADE_META = '_photopress_sizes_made';

	/**
	 * Before MADE_META: a hash of the settings an image was made with (see
	 * legacySignature()). Read once, to convert, then deleted.
	 */
	const SIGNATURE_META = '_photopress_sizes_signature';

	const JOB = 'images.regenerate';

	public function definePublicHooks() {

		add_filter( 'wp_editor_set_quality', [ self::class, 'quality' ], 10, 2 );
		add_filter( 'intermediate_image_sizes_advanced', [ self::class, 'enabledSizes' ] );
		add_action( 'rest_api_init', [ self::class, 'registerRoutes' ] );
		add_filter( 'wp_generate_attachment_metadata', [ self::class, 'markUpToDate' ], 10, 3 );

		Jobs::register( self::JOB, [
			'label'         => __( 'Regenerate image sizes' ),
			'description'   => __( 'Makes every image\'s sizes again from its original, with the sizes and quality set here.' ),
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
	 * What an image's sizes depend on: the quality, the big image threshold
	 * (0 when off) and the sizes made, each with its width, height and crop.
	 * $given: quality and disabled_sizes to use instead of those saved, for
	 * the images a change would affect.
	 */
	public static function settings( $given = [] ) {

		$disabled = array_key_exists( 'disabled_sizes', $given )
			? array_filter( array_map( 'trim', is_array( $given['disabled_sizes'] ) ? $given['disabled_sizes'] : explode( ',', (string) $given['disabled_sizes'] ) ), 'strlen' )
			: self::disabledSizes();
		$quality = array_key_exists( 'quality', $given ) ? (int) $given['quality'] : (int) pp_api::getOption( 'core', 'images', 'quality' );
		$sizes = [];

		foreach ( array_diff_key( (array) wp_get_registered_image_subsizes(), array_flip( $disabled ) ) as $name => $size ) {
			$sizes[ $name ] = self::dimensions( $size );
		}
		ksort( $sizes );

		return [
			'quality'   => $quality >= 1 && $quality <= 100 ? $quality : 82,
			'threshold' => (int) apply_filters( 'big_image_size_threshold', 2560, [ 0, 0 ], '', 0 ),
			'sizes'     => $sizes,
		];
	}

	/**
	 * A size as [ width, height, crop ], crop as WordPress takes it: false,
	 * true, or where to crop from, as [ x, y ].
	 */
	private static function dimensions( $size ) {

		$crop = $size['crop'] ?? false;

		return [ (int) ( $size['width'] ?? 0 ), (int) ( $size['height'] ?? 0 ), is_array( $crop ) ? array_values( $crop ) : (bool) $crop ];
	}

	/**
	 * What an image was made with, from its metadata: the settings, the
	 * longest side of its original (for the threshold), and for each size,
	 * whether it was made (not when the image is smaller than the size).
	 */
	public static function recordFor( $id, $meta, $settings ) {

		$long = max( (int) ( $meta['width'] ?? 0 ), (int) ( $meta['height'] ?? 0 ) );

		if ( ! empty( $meta['original_image'] ) ) {
			$original = wp_getimagesize( trailingslashit( dirname( (string) get_attached_file( $id, true ) ) ) . $meta['original_image'] );
			$long = $original ? max( (int) $original[0], (int) $original[1] ) : 0;
		}

		$sizes = [];
		foreach ( $settings['sizes'] as $name => $size ) {
			$sizes[ $name ] = array_merge( $size, [ isset( $meta['sizes'][ $name ] ) ] );
		}

		return [
			'quality'   => $settings['quality'],
			'threshold' => $settings['threshold'],
			'long'      => $long,
			'sizes'     => $sizes,
		];
	}

	/**
	 * What an image needs for its sizes to be as $settings say, from its
	 * record: null for nothing; 'full' for every size made again, when the
	 * quality (for JPEG and WebP) or what the threshold does to it changed,
	 * or there is no record; or the sizes to make (new, or with other
	 * dimensions) and to drop (made, and now turned off).
	 *
	 * @return null|string|array
	 */
	public static function work( $record, $mime, $settings ) {

		if ( ! is_array( $record ) || ! isset( $record['sizes'] ) ) {
			return 'full';
		}

		if ( in_array( $mime, [ 'image/jpeg', 'image/webp' ], true ) && (int) $record['quality'] !== $settings['quality'] ) {
			return 'full';
		}

		// Scaled down to a -scaled copy before, or now: the sizes are made
		// from it. Unknown (0), any change to the threshold counts.
		$long = (int) ( $record['long'] ?? 0 );
		$before = (int) $record['threshold'];
		$now = $settings['threshold'];
		$scaled = static fn( $threshold ) => $threshold > 0 && ( ! $long || $long > $threshold ) ? $threshold : 0;

		if ( $before !== $now && $scaled( $before ) !== $scaled( $now ) ) {
			return 'full';
		}

		$make = [];
		foreach ( $settings['sizes'] as $name => $size ) {
			$had = $record['sizes'][ $name ] ?? null;
			if ( ! $had || array_slice( $had, 0, 3 ) !== $size ) {
				$make[] = $name;
			}
		}

		$drop = [];
		foreach ( $record['sizes'] as $name => $had ) {
			if ( ! isset( $settings['sizes'][ $name ] ) && ! empty( $had[3] ) ) {
				$drop[] = $name;
			}
		}

		return $make || $drop ? [ 'make' => $make, 'drop' => $drop ] : null;
	}

	/**
	 * An image's record. One with a hash from before records, of the
	 * settings as they are now, gets the record of them.
	 */
	public static function recordOf( $id ) {

		$record = get_post_meta( $id, self::MADE_META, true );

		if ( ! is_array( $record ) && '' !== (string) get_post_meta( $id, self::SIGNATURE_META, true ) && get_post_meta( $id, self::SIGNATURE_META, true ) === self::legacySignature() ) {
			$record = self::recordFor( $id, wp_get_attachment_metadata( $id ), self::settings() );
			update_post_meta( $id, self::MADE_META, $record );
			delete_post_meta( $id, self::SIGNATURE_META );
		}

		return is_array( $record ) ? $record : null;
	}

	/**
	 * The hash images were marked with before records: of the sizes made,
	 * the quality and the threshold, as they are now.
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
	 * wp_generate_attachment_metadata: an image uploaded now is made with the
	 * settings as they are.
	 */
	public static function markUpToDate( $metadata, $id, $context = 'create' ) {

		if ( 'create' === $context && ! empty( $metadata['sizes'] ) ) {
			update_post_meta( $id, self::MADE_META, self::recordFor( $id, $metadata, self::settings() ) );
		}

		return $metadata;
	}

	/**
	 * GET /photopress/v1/image-sizes: every registered size, whether it is
	 * made, and how many images need their sizes made again.
	 *
	 * POST /photopress/v1/image-sizes/scope: how many images would need their
	 * sizes made again with the settings given (quality, disabled_sizes).
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

		$settings = array_intersect_key( (array) $request->get_param( 'settings' ), array_flip( [ 'quality', 'disabled_sizes' ] ) );

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
			'sizes'    => $sizes,
			'images'   => self::countImages(),
			'outdated' => self::countImages( [ 'outdated' => true ] ),
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
	 * work, from its record, without reading anything else.
	 *
	 * @return array<int, bool>
	 */
	private static function rows( $after, $limit, $args, $judge = true ) {

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_mime_type, m.meta_value AS made, s.meta_value AS signature FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
			LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
			WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%%'" . self::onlyIds( $args, 'p.ID' ) . ' AND p.ID > %d ORDER BY p.ID LIMIT %d',
			self::MADE_META,
			self::SIGNATURE_META,
			(int) $after,
			(int) $limit
		) );

		$settings = $judge ? self::settings( (array) ( $args['settings'] ?? [] ) ) : null;
		$current = $judge ? self::settings() : null;
		$legacy = $judge ? self::legacySignature() : null;
		$out = [];

		foreach ( $rows as $row ) {
			if ( ! $judge ) {
				$out[ (int) $row->ID ] = true;
				continue;
			}
			$record = maybe_unserialize( $row->made );
			// Marked with the hash of the settings as they are: made with
			// them, every size (whether it was is not known), the original's
			// size not known.
			if ( ! is_array( $record ) && $row->signature === $legacy ) {
				$record = [
					'quality'   => $current['quality'],
					'threshold' => $current['threshold'],
					'long'      => 0,
					'sizes'     => array_map( static fn( $size ) => array_merge( $size, [ true ] ), $current['sizes'] ),
				];
			}
			$out[ (int) $row->ID ] = null !== self::work( $record, $row->post_mime_type, $settings );
		}

		return $out;
	}

	private static function onlyIds( $args, $column = 'ID' ) {

		$ids = array_filter( array_map( 'intval', (array) ( $args['ids'] ?? [] ) ) );

		return $ids ? " AND $column IN (" . implode( ',', $ids ) . ')' : '';
	}

	/**
	 * Brings an image's sizes up to date with the settings, doing only what
	 * its record says it needs (see work()): nothing; dropping sizes turned
	 * off from its metadata (their files stay); making sizes new to it; or,
	 * with $args['all'] too, making every size again from its original
	 * upload as WordPress does on upload (a -scaled copy is made again, or
	 * not, by the threshold as it is now).
	 *
	 * @return true|WP_Error
	 */
	public static function regenerate( $id, $args = [] ) {

		$settings = self::settings();
		$work = empty( $args['all'] ) ? self::work( self::recordOf( $id ), get_post_mime_type( $id ), $settings ) : 'full';

		if ( null === $work ) {
			return true;
		}

		$meta = wp_get_attachment_metadata( $id );

		if ( ! is_array( $meta ) ) {
			$work = 'full';
		}

		$original = wp_get_original_image_path( $id );

		if ( ( 'full' === $work || $work['make'] ) && ( ! $original || ! is_readable( $original ) ) ) {
			return new WP_Error( 'photopress_regenerate_missing', __( 'The original file is not on this server.' ) );
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		self::limitImagick();

		// WordPress saves the metadata as it makes each size; Offload Media
		// waits for the finished set, then uploads it.
		add_filter( 'as3cf_pre_update_attachment_metadata', '__return_true' );

		if ( 'full' === $work ) {
			// From the original: WordPress makes the -scaled copy again from
			// it if it is over the threshold, and points the image at that.
			if ( get_attached_file( $id, true ) !== $original ) {
				update_attached_file( $id, $original );
			}
			$meta = wp_generate_attachment_metadata( $id, $original );
		} else {
			foreach ( array_merge( $work['make'], $work['drop'] ) as $name ) {
				unset( $meta['sizes'][ $name ] );
			}
			if ( $work['make'] ) {
				// WordPress makes the sizes its metadata lacks (all those the
				// image is large enough for, turned off or not): these only.
				wp_update_attachment_metadata( $id, $meta );
				$only = static fn( $missing ) => array_intersect_key( (array) $missing, array_flip( $work['make'] ) );
				add_filter( 'wp_get_missing_image_subsizes', $only );
				$meta = wp_update_image_subsizes( $id );
				remove_filter( 'wp_get_missing_image_subsizes', $only );
			}
		}

		remove_filter( 'as3cf_pre_update_attachment_metadata', '__return_true' );

		if ( empty( $meta ) || ! is_array( $meta ) ) {
			return new WP_Error( 'photopress_regenerate_failed', __( 'WordPress could not make this image\'s sizes.' ) );
		}

		wp_update_attachment_metadata( $id, $meta );
		update_post_meta( $id, self::MADE_META, self::recordFor( $id, $meta, $settings ) );
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
