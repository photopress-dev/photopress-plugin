<?php

namespace PhotoPress\modules\media;
use photopress_module;
use pp_api;

/**
 * Media Module
 *
 * A REST route that lets publishing tools give an image a new file, keeping
 * the image (see MediaRest), and the cache lifetime of files WP Offload
 * Media uploads.
 */
class media extends photopress_module {

	public $label = 'Offload Media';

	/**
	 * How long browsers and a CDN may keep an offloaded file before checking
	 * back (max-age), and for how long after that they may still show their
	 * copy while they check (stale-while-revalidate), in seconds. A file
	 * replaced under the same URL shows within the first. Offload Media's own
	 * is a year.
	 */
	const DEFAULT_CACHE_SECONDS = 86400;

	const DEFAULT_STALE_SECONDS = 3600;

	public function definePublicHooks() {

		MediaRest::addHooks();
		CdnInvalidator::addHooks();

		add_filter( 'as3cf_object_meta', [ $this, 'setCacheControl' ] );
		add_action( 'rest_api_init', [ $this, 'registerRoutes' ] );
	}

	/**
	 * as3cf_object_meta: the Cache-Control header of each file Offload Media
	 * uploads, from the two settings.
	 */
	public function setCacheControl( $args ) {

		if ( is_array( $args ) ) {
			$args['CacheControl'] = self::cacheControl(
				pp_api::getOption( 'core', 'media', 'cache_seconds' ),
				pp_api::getOption( 'core', 'media', 'stale_seconds' )
			);
		}

		return $args;
	}

	/**
	 * The Cache-Control header for the two durations, in seconds. A missing
	 * or invalid one is its default; a stale period of 0 is left out.
	 */
	public static function cacheControl( $cache_seconds, $stale_seconds ) {

		$cache = is_numeric( $cache_seconds ) && (int) $cache_seconds > 0 ? (int) $cache_seconds : self::DEFAULT_CACHE_SECONDS;
		$stale = is_numeric( $stale_seconds ) && (int) $stale_seconds >= 0 ? (int) $stale_seconds : self::DEFAULT_STALE_SECONDS;

		return 'max-age=' . $cache . ( $stale ? ', stale-while-revalidate=' . $stale : '' );
	}

	/**
	 * For the Offload Media settings page: GET /photopress/v1/cdn, the
	 * storage, delivery and invalidation status; POST /cdn/invalidate, clear
	 * now.
	 */
	public function registerRoutes() {

		$admin = static fn() => current_user_can( 'manage_options' );

		register_rest_route( 'photopress/v1', '/cdn', [
			'methods'             => 'GET',
			'callback'            => static fn() => CdnInvalidator::status(),
			'permission_callback' => $admin,
		] );

		// Invalidate now: the images waiting to be cleared, or everything.
		register_rest_route( 'photopress/v1', '/cdn/invalidate', [
			'methods'             => 'POST',
			'callback'            => static function ( $request ) {
				$result = 'all' === $request['scope'] ? CdnInvalidator::invalidateAll() : CdnInvalidator::flush();
				return is_wp_error( $result ) ? $result : CdnInvalidator::status();
			},
			'permission_callback' => $admin,
			'args'                => [ 'scope' => [ 'type' => 'string', 'enum' => [ 'pending', 'all' ], 'default' => 'pending' ] ],
		] );
	}

	public function registerOptions() {

		return [

			'cache_seconds' => [

				'default_value' => self::DEFAULT_CACHE_SECONDS,
				'field'         => [
					'type'          => 'integer',
					'title'         => 'Keep images for',
					'page_name'     => 'media',
					'section'       => 'general',
					'description'   => 'With WP Offload Media: how long browsers and a CDN may keep an image before checking back, in seconds. A replaced image keeps its URL, so this is how long an old copy can still be shown where it was not cleared.',
					'label_for'     => 'Keep images for',
					'error_message' => '',
				],
			],

			'stale_seconds' => [

				'default_value' => self::DEFAULT_STALE_SECONDS,
				'field'         => [
					'type'          => 'integer',
					'title'         => 'Then show the old copy while checking for',
					'page_name'     => 'media',
					'section'       => 'general',
					'description'   => 'After that, how long the old copy may still be shown while a new one is fetched, in seconds, so nobody waits on the check.',
					'label_for'     => 'Then show the old copy while checking for',
					'error_message' => '',
				],
			],

			'delete_replaced_objects' => [

				'default_value' => false,
				'field'         => [
					'type'          => 'boolean',
					'title'         => 'Delete replaced files from the bucket',
					'page_name'     => 'media',
					'section'       => 'general',
					'description'   => 'When a replacement gives an image\'s sizes new names (new dimensions or file type), delete the old files from the bucket two days later. Offload Media itself leaves them there.',
					'label_for'     => 'Delete replaced files from the bucket',
					'error_message' => '',
				],
			],
		];
	}

	public function registerSettingsPages() {

		return [

			'media' => [

				'parent_slug'         => 'photopress-core-base',
				'title'               => 'Offload Media',
				'menu_title'          => 'Offload Media',
				'required_capability' => 'manage_options',
				'menu_slug'           => 'photopress-media',
				'description'         => 'WP Offload Media: where images are stored and served, their cache lifetime, and clearing replaced images from CloudFront.',
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
