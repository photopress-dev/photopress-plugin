<?php

namespace PhotoPress\modules\media;

use PhotoPress\jobs\Jobs;
use pp_api;
use WP_Error;

/**
 * Clears a replaced image from the CloudFront distribution in front of WP
 * Offload Media, so the new file shows at once under the same URL.
 *
 * The distribution is the one serving Offload Media's delivery domain (a
 * distribution with it as an alias).
 * Paths of replaced images are collected and sent as one invalidation a few
 * seconds later (DELAY), so replacing many images makes a few invalidations,
 * not one each. CloudFront allows 15 wildcard paths in progress at once; a
 * larger batch is widened to folder wildcards, or the whole distribution.
 */
class CdnInvalidator {

	const HOOK = 'photopress_cdn_invalidate';

	const PENDING_OPTION = 'photopress_cdn_pending';

	const LAST_OPTION = 'photopress_cdn_last';

	/**
	 * The distribution found for the delivery domain, remembered for a day
	 * (a transient), so a change in CloudFront is picked up without a
	 * setting to keep in step with Offload Media.
	 */
	const DISTRIBUTION_TRANSIENT = 'photopress_cdn_distribution';

	/**
	 * Seconds to wait for more replacements before invalidating.
	 */
	const DELAY = 15;

	const MAX_WILDCARDS = 15;

	const DELETE_HOOK = 'photopress_offload_delete_objects';

	/**
	 * How long a removed file stays in the bucket: longer than any page
	 * cache keeps a page that may still show it.
	 */
	const DELETE_DELAY = 2 * 86400; // two days

	/**
	 * Offload Media's main object, as its as3cf_init action passes it.
	 */
	protected static $as3cf = null;

	public static function addHooks() {

		add_action( 'as3cf_init', [ self::class, 'setOffloadMedia' ] );
		add_action( 'photopress_attachment_file_replaced', [ self::class, 'queue' ], 20 );
		add_action( self::HOOK, [ self::class, 'flush' ] );
		add_action( 'photopress_attachment_files_removed', [ self::class, 'scheduleDeletion' ], 10, 2 );
		add_action( self::DELETE_HOOK, [ self::class, 'deleteObjects' ], 10, 4 );
	}

	public static function setOffloadMedia( $as3cf ) {

		self::$as3cf = $as3cf;
	}

	/**
	 * Offload Media's delivery domain, or '' when it serves no CDN domain.
	 */
	public static function domain() {

		if ( ! self::$as3cf || ! method_exists( self::$as3cf, 'get_setting' ) || ! self::$as3cf->get_setting( 'serve-from-s3' ) ) {
			return '';
		}

		return self::$as3cf->get_setting( 'enable-delivery-domain' ) ? (string) self::$as3cf->get_setting( 'delivery-domain' ) : '';
	}

	/**
	 * The distribution to invalidate: the one serving Offload Media's
	 * delivery domain (remembered per domain).
	 *
	 * @return array|null|WP_Error id; null when there is none.
	 */
	public static function distribution() {

		$domain = self::domain();

		if ( '' === $domain ) {
			return null;
		}

		$known = get_transient( self::DISTRIBUTION_TRANSIENT );

		if ( is_array( $known ) && ( $known['domain'] ?? '' ) === $domain ) {
			return $known['id'] ? [ 'id' => $known['id'] ] : null;
		}

		$id = CloudFront::findDistribution( $domain );

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		// Not found is remembered too: the domain is not a CloudFront one.
		set_transient( self::DISTRIBUTION_TRANSIENT, [ 'domain' => $domain, 'id' => (string) $id ], DAY_IN_SECONDS );

		return $id ? [ 'id' => $id ] : null;
	}

	/**
	 * The path to invalidate for an image: its folder on the CDN and the
	 * name it was uploaded under, with a wildcard for its sizes and -scaled
	 * (and the old file type, if that changed).
	 */
	public static function pathFor( $id ) {

		$url = wp_get_attachment_url( $id );
		$domain = self::domain();

		if ( ! $url || '' === $domain || strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== strtolower( $domain ) ) {
			return null;
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$name = MediaRest::stem( wp_get_original_image_path( $id ) ?: $path );

		return trailingslashit( dirname( $path ) ) . $name . '*';
	}

	/**
	 * photopress_attachment_file_replaced: queues the image's path.
	 */
	public static function queue( $id ) {

		$path = self::pathFor( $id );

		if ( ! $path ) {
			return;
		}

		$pending = (array) get_option( self::PENDING_OPTION, [] );
		$pending[] = $path;
		update_option( self::PENDING_OPTION, array_values( array_unique( $pending ) ), false );

		if ( function_exists( 'as_schedule_single_action' ) && ! as_has_scheduled_action( self::HOOK, [], Jobs::GROUP ) ) {
			as_schedule_single_action( time() + self::DELAY, self::HOOK, [], Jobs::GROUP );
		}
	}

	/**
	 * Sends the queued paths as one invalidation. On failure they are queued
	 * again and retried in five minutes.
	 *
	 * @return string|WP_Error|null The invalidation's ID; null if nothing was queued.
	 */
	public static function flush() {

		wp_cache_delete( self::PENDING_OPTION, 'options' );
		$pending = (array) get_option( self::PENDING_OPTION, [] );

		if ( ! $pending ) {
			return null;
		}

		delete_option( self::PENDING_OPTION );

		$result = self::invalidatePaths( self::batch( $pending ) );

		update_option( self::LAST_OPTION, [
			'time'         => time(),
			'paths'        => count( $pending ),
			'invalidation' => is_wp_error( $result ) ? null : $result,
			'error'        => is_wp_error( $result ) ? $result->get_error_message() : null,
		], false );

		// Queued again unless there is nothing to send them to.
		if ( is_wp_error( $result ) && 'photopress_cdn_none' !== $result->get_error_code() ) {

			$again = array_values( array_unique( array_merge( (array) get_option( self::PENDING_OPTION, [] ), $pending ) ) );
			update_option( self::PENDING_OPTION, $again, false );

			if ( function_exists( 'as_schedule_single_action' ) && ! as_has_scheduled_action( self::HOOK, [], Jobs::GROUP ) ) {
				as_schedule_single_action( time() + 5 * MINUTE_IN_SECONDS, self::HOOK, [], Jobs::GROUP );
			}
		}

		return $result;
	}

	/**
	 * Paths for one invalidation: as they are, up to the wildcard limit;
	 * beyond it, one wildcard per folder; beyond that, everything.
	 *
	 * @param string[] $paths
	 * @return string[]
	 */
	public static function batch( array $paths ) {

		$paths = array_values( array_unique( $paths ) );

		if ( count( $paths ) <= self::MAX_WILDCARDS ) {
			return $paths;
		}

		$folders = array_values( array_unique( array_map( static fn( $path ) => trailingslashit( dirname( $path ) ) . '*', $paths ) ) );

		return count( $folders ) <= self::MAX_WILDCARDS ? $folders : [ '/*' ];
	}

	/**
	 * photopress_attachment_files_removed: a replacement renamed files
	 * (new dimensions or type) and deleted the old ones from this server.
	 * Offload Media keeps their copies in the bucket but no longer knows
	 * them. With the delete_replaced_objects setting on, they are deleted
	 * after DELETE_DELAY, once no cached page can still show them.
	 *
	 * @param int      $id   Attachment ID.
	 * @param string[] $urls The removed files' URLs, as they were served.
	 */
	public static function scheduleDeletion( $id, array $urls ) {

		// Off unless the "Delete replaced files from the bucket" setting is on.
		if ( ! pp_api::getOption( 'core', 'media', 'delete_replaced_objects' ) ) {
			return;
		}

		if ( ! self::$as3cf || ! $urls || ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		$bucket = (string) self::$as3cf->get_setting( 'bucket' );
		$region = (string) self::$as3cf->get_setting( 'region' );
		$keys = array_values( array_filter( array_map( static fn( $url ) => self::objectKey( $url, $bucket ), $urls ) ) );

		if ( '' !== $bucket && $keys ) {
			as_schedule_single_action( time() + self::DELETE_DELAY, self::DELETE_HOOK, [ $bucket, $region, $keys, 1 ], Jobs::GROUP );
		}
	}

	/**
	 * The bucket key of a file served from the bucket or the delivery
	 * domain: its path. Null for a URL served from elsewhere (this server).
	 */
	public static function objectKey( $url, $bucket ) {

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$domain = strtolower( self::domain() );

		if ( '' === $host || ( $host !== $domain && 0 !== strpos( $host, strtolower( $bucket ) . '.s3' ) ) ) {
			return null;
		}

		$key = ltrim( rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) ), '/' );

		return '' === $key ? null : $key;
	}

	/**
	 * The scheduled deletion. Tried three times, an hour apart.
	 */
	public static function deleteObjects( $bucket, $region, $keys, $attempt = 1 ) {

		$result = CloudFront::deleteObjects( $bucket, $region, (array) $keys );

		if ( is_wp_error( $result ) && $attempt < 3 && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + HOUR_IN_SECONDS, self::DELETE_HOOK, [ $bucket, $region, $keys, $attempt + 1 ], Jobs::GROUP );
		}

		return $result;
	}

	/**
	 * Whether WP Offload Media (or its Pro version) is "active", "inactive"
	 * (installed) or "missing".
	 */
	public static function pluginState() {

		if ( self::$as3cf ) {
			return 'active';
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( array_keys( get_plugins() ) as $file ) {
			if ( 0 === strpos( $file, 'amazon-s3-and-cloudfront/' ) || 0 === strpos( $file, 'amazon-s3-and-cloudfront-pro/' ) ) {
				return 'inactive';
			}
		}

		return 'missing';
	}

	/**
	 * Invalidates paths on the distribution serving the delivery domain. If
	 * CloudFront no longer has the one found before (it was deleted and
	 * made again for the same domain), it is looked up again, once.
	 *
	 * @return string|WP_Error The invalidation's ID.
	 */
	protected static function invalidatePaths( array $paths ) {

		for ( $attempt = 1; $attempt <= 2; $attempt++ ) {

			$distribution = self::distribution();

			if ( ! is_array( $distribution ) ) {
				return is_wp_error( $distribution ) ? $distribution : new WP_Error( 'photopress_cdn_none', __( 'No CloudFront distribution found for the delivery domain.' ) );
			}

			$result = CloudFront::invalidate( $distribution['id'], $paths );

			if ( ! is_wp_error( $result ) || 'NoSuchDistribution' !== ( $result->get_error_data()['aws_code'] ?? '' ) ) {
				return $result;
			}

			delete_transient( self::DISTRIBUTION_TRANSIENT );
		}

		return $result;
	}

	/**
	 * Invalidates the whole distribution: one wildcard path.
	 *
	 * @return string|WP_Error The invalidation's ID.
	 */
	public static function invalidateAll() {

		$result = self::invalidatePaths( [ '/*' ] );

		update_option( self::LAST_OPTION, [
			'time'         => time(),
			'paths'        => 0,
			'all'          => true,
			'invalidation' => is_wp_error( $result ) ? null : $result,
			'error'        => is_wp_error( $result ) ? $result->get_error_message() : null,
		], false );

		return $result;
	}

	/**
	 * What the Offload Media settings page shows.
	 */
	public static function status() {

		$distribution = self::$as3cf ? self::distribution() : null;
		$credentials = CloudFront::credentials();

		$setting = static fn( $key ) => self::$as3cf && method_exists( self::$as3cf, 'get_setting' ) ? self::$as3cf->get_setting( $key ) : null;

		return [
			'offload_media' => (bool) self::$as3cf,
			'plugin'        => self::pluginState(),
			'storage'       => self::$as3cf ? [
				'provider'          => $setting( 'provider' ),
				'bucket'            => $setting( 'bucket' ),
				'region'            => $setting( 'region' ),
				'object_prefix'     => $setting( 'enable-object-prefix' ) ? $setting( 'object-prefix' ) : '',
				'object_versioning' => (bool) $setting( 'object-versioning' ),
				'copy_to_s3'        => (bool) $setting( 'copy-to-s3' ),
				'serve_from_s3'     => (bool) $setting( 'serve-from-s3' ),
				'remove_local_file' => (bool) $setting( 'remove-local-file' ),
			] : null,
			'domain'        => self::domain(),
			'distribution'  => is_array( $distribution ) ? $distribution : null,
			'error'         => is_wp_error( $distribution ) ? $distribution->get_error_message() : null,
			'credentials'   => $credentials ? $credentials['source'] : null,
			'pending'       => count( (array) get_option( self::PENDING_OPTION, [] ) ),
			'last'          => get_option( self::LAST_OPTION ) ?: null,
		];
	}
}
