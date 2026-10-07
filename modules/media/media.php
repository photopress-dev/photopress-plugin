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

	public $label = 'Media';

	/**
	 * The Cache-Control header when none is set: browsers and a CDN check
	 * back after a day, so a file replaced under the same URL shows within
	 * a day; for an hour after that they may serve their copy while they
	 * check. Offload Media's own is a year.
	 */
	const DEFAULT_CACHE_CONTROL = 'max-age=86400, stale-while-revalidate=3600';

	public function definePublicHooks() {

		MediaRest::addHooks();

		add_filter( 'as3cf_object_meta', [ $this, 'setCacheControl' ] );
	}

	/**
	 * as3cf_object_meta: the Cache-Control header of each file Offload Media
	 * uploads.
	 */
	public function setCacheControl( $args ) {

		if ( is_array( $args ) ) {
			$args['CacheControl'] = self::cacheControl( pp_api::getOption( 'core', 'media', 'cache_control' ) );
		}

		return $args;
	}

	/**
	 * A Cache-Control value as a header can carry it: directives such as
	 * max-age=86400, separated by commas. Anything else gives the default.
	 */
	public static function cacheControl( $value ) {

		$value = trim( (string) $value );

		return preg_match( '/^[a-z-]+(=\d+)?(\s*,\s*[a-z-]+(=\d+)?)*$/i', $value ) ? $value : self::DEFAULT_CACHE_CONTROL;
	}

	public function registerOptions() {

		return [

			'cache_control' => [

				'default_value' => self::DEFAULT_CACHE_CONTROL,
				'field'         => [
					'type'          => 'text',
					'title'         => 'Cache-Control for offloaded files',
					'page_name'     => 'media',
					'section'       => 'general',
					'description'   => 'With WP Offload Media: how long browsers and a CDN may keep an image before checking back, as a Cache-Control header. A replaced image keeps its URL, so this is how long an old copy can still be shown. Offload Media\'s own value is a year (max-age=31536000).',
					'label_for'     => 'Cache-Control for offloaded files',
					'error_message' => '',
				],
			],
		];
	}

	public function registerSettingsPages() {

		return [

			'media' => [

				'parent_slug'         => 'photopress-core-base',
				'title'               => 'Media',
				'menu_title'          => 'Media',
				'required_capability' => 'manage_options',
				'menu_slug'           => 'photopress-media',
				'description'         => 'Settings for replacing images and for offloaded files.',
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
