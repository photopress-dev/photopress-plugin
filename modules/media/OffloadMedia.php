<?php

namespace PhotoPress\modules\media;

defined( 'ABSPATH' ) || exit;

/**
 * WP Offload Media, when a site has it: the one place that knows it.
 *
 * Documented: its settings, by the keys of the AS3CF_SETTINGS constant
 * (settings constants), read here for its credentials. What PhotoPress
 * needs beyond that it does not document, and is used as its own add-ons
 * use it:
 *
 * - the as3cf_init action, with its main object, whose get_setting() reads
 *   a setting by those same keys;
 * - as3cf_object_meta, each object's upload request (its Cache-Control);
 * - as3cf_pre_update_attachment_metadata, which holds its upload while
 *   WordPress saves the metadata size by size;
 * - the AWS SDK it bundles (SDK), for CloudFront and for files it no longer
 *   tracks (CloudFront).
 *
 * Should any of these change, only this class does.
 */
class OffloadMedia {

	/** The namespace of the AWS SDK it bundles. */
	const SDK = '\\DeliciousBrains\\WP_Offload_Media\\Aws3\\Aws\\';

	/** Its main object, as as3cf_init passes it. */
	private static $as3cf = null;

	public static function addHooks() {

		add_action( 'as3cf_init', [ self::class, 'init' ] );
	}

	/** as3cf_init: its main object. */
	public static function init( $as3cf ) {

		self::$as3cf = is_object( $as3cf ) && method_exists( $as3cf, 'get_setting' ) ? $as3cf : null;
	}

	/** Whether it is active. */
	public static function active() {

		return null !== self::$as3cf;
	}

	/**
	 * One of its settings, by its AS3CF_SETTINGS key (bucket, region,
	 * serve-from-s3, remove-local-file and the like); null while it is not
	 * active.
	 */
	public static function setting( $key ) {

		return self::$as3cf ? self::$as3cf->get_setting( $key ) : null;
	}

	/** The domain it serves files from on a CDN, or ''. */
	public static function deliveryDomain() {

		if ( ! self::setting( 'serve-from-s3' ) || ! self::setting( 'enable-delivery-domain' ) ) {
			return '';
		}

		return (string) self::setting( 'delivery-domain' );
	}

	/** Whether it removes files from this server once they are offloaded. */
	public static function removesLocalFiles() {

		return (bool) self::setting( 'remove-local-file' );
	}

	/**
	 * Its credentials, from wp-config.php: AS3CF_SETTINGS, or the AS3CF_AWS_*
	 * or AWS_* constants.
	 *
	 * @return array|null key, secret.
	 */
	public static function credentials() {

		$settings = defined( 'AS3CF_SETTINGS' ) ? maybe_unserialize( AS3CF_SETTINGS ) : [];

		if ( ! empty( $settings['access-key-id'] ) && ! empty( $settings['secret-access-key'] ) ) {
			return [ 'key' => $settings['access-key-id'], 'secret' => $settings['secret-access-key'] ];
		}

		foreach ( [ 'AS3CF_AWS', 'AWS' ] as $prefix ) {
			if ( defined( "{$prefix}_ACCESS_KEY_ID" ) && defined( "{$prefix}_SECRET_ACCESS_KEY" ) ) {
				return [ 'key' => constant( "{$prefix}_ACCESS_KEY_ID" ), 'secret' => constant( "{$prefix}_SECRET_ACCESS_KEY" ) ];
			}
		}

		return null;
	}

	/**
	 * Makes an image's sizes with its upload held until they are all made:
	 * WordPress saves the metadata as it makes each size; it then uploads
	 * the finished set once, when the caller saves it.
	 *
	 * @param callable $make Makes the sizes; its result is returned.
	 */
	public static function withUploadHeld( callable $make ) {

		add_filter( 'as3cf_pre_update_attachment_metadata', '__return_true' );

		try {
			return $make();
		} finally {
			remove_filter( 'as3cf_pre_update_attachment_metadata', '__return_true' );
		}
	}

	/**
	 * Each object's upload request filtered: the callback is given its
	 * arguments (Bucket, Key, CacheControl and the like) and returns them.
	 *
	 * @param callable $filter
	 */
	public static function filteringUploads( callable $filter ) {

		add_filter( 'as3cf_object_meta', $filter );
	}

	/** Whether it (or its Pro version) is "active", "inactive" (installed) or "missing". */
	public static function pluginState() {

		return \photopress_util::pluginState( self::active(), [ 'amazon-s3-and-cloudfront/', 'amazon-s3-and-cloudfront-pro/' ] );
	}
}
