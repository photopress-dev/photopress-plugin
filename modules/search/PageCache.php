<?php

namespace PhotoPress\modules\search;

/**
 * The site's page cache, through Proxy Cache Purge's documented hooks. It
 * purges a post's pages, its terms' archives and the home page when the post
 * is saved or deleted, but leaves out images (their status is inherit), and
 * does nothing when a post's terms change.
 *
 * PhotoPress gives it:
 *
 * - images, among the statuses it purges (varnish_http_purge_valid_post_statuses),
 *   so an image deleted is purged by Proxy Cache Purge itself;
 * - an event for an image (varnish_http_purge_events), fired at the end of a
 *   request in which an image was edited or its terms changed
 *   (photopress_image_terms_changed, from TermChanges);
 * - an event to empty the cache (varnish_http_purge_events_full), for a
 *   renamed or deleted term with more images than are worth purging one by
 *   one (photopress_term_images_changed);
 * - for an image's purge (vhp_purge_urls): every page of its image taxonomy
 *   terms' archives, and of terms it left, while image taxonomy pages are on,
 *   and not its own page while attachment pages are off.
 *
 * Proxy Cache Purge decides the rest: how it sends them, whether by WP-Cron,
 * and when there are so many it empties the cache instead.
 *
 * The Pages & Search tab shows whether Proxy Cache Purge is active.
 */
class PageCache {

	/** Proxy Cache Purge's event for an image, with its ID. */
	const IMAGE = 'photopress_purge_image';

	/** Proxy Cache Purge's event to empty the cache. */
	const ALL = 'photopress_purge_all';

	/** Images to purge at the end of the request, by ID. */
	private static $images = [];

	/** Archives to purge with an image, by image ID: URLs. */
	private static $archives = [];

	/** Whether to empty the cache at the end of the request. */
	private static $all = false;

	public static function addHooks() {

		add_filter( 'varnish_http_purge_events', [ self::class, 'events' ] );
		add_filter( 'varnish_http_purge_events_full', [ self::class, 'fullEvents' ] );
		add_filter( 'varnish_http_purge_valid_post_statuses', [ self::class, 'statuses' ], 10, 2 );
		add_filter( 'vhp_purge_urls', [ self::class, 'urls' ], 10, 2 );

		add_action( 'photopress_image_terms_changed', [ self::class, 'imageTermsChanged' ], 10, 2 );
		add_action( 'photopress_term_images_changed', [ self::class, 'purgeAll' ] );
		add_action( 'attachment_updated', [ self::class, 'imageEdited' ], 10, 3 );
	}

	/**
	 * Whether Proxy Cache Purge purges for PhotoPress: it listens for the
	 * image event, from its init on.
	 */
	public static function purges() {

		return false !== has_action( self::IMAGE );
	}

	/** varnish_http_purge_events: the image event, and emptying the cache. */
	public static function events( $events ) {

		return array_merge( (array) $events, [ self::IMAGE, self::ALL ] );
	}

	/** varnish_http_purge_events_full: emptying the cache, which has no post. */
	public static function fullEvents( $events ) {

		return array_merge( (array) $events, [ self::ALL ] );
	}

	/** varnish_http_purge_valid_post_statuses: an image's, inherit. */
	public static function statuses( $statuses, $post_id = 0 ) {

		$statuses = (array) $statuses;

		if ( $post_id && wp_attachment_is_image( $post_id ) && ! in_array( 'inherit', $statuses, true ) ) {
			$statuses[] = 'inherit';
		}

		return $statuses;
	}

	/**
	 * vhp_purge_urls: the URLs of the request's purges so far, as an image's
	 * are added. Its image taxonomy terms' archives become every page of
	 * them, with the archives of terms it left, while image taxonomy pages
	 * are on; its own page is left out while attachment pages are off.
	 */
	public static function urls( $urls, $post_id = 0 ) {

		$urls = (array) $urls;

		if ( ! $post_id || ! wp_attachment_is_image( $post_id ) ) {
			return $urls;
		}

		if ( ! search::enabled( 'attachment_pages' ) ) {
			$urls = array_diff( $urls, [ get_permalink( $post_id ) ] );
		}

		if ( search::enabled( 'taxonomy_pages' ) ) {

			$archives = self::$archives[ $post_id ] ?? [];

			foreach ( ImageSearch::taxonomies() as $taxonomy ) {

				$terms = get_the_terms( $post_id, $taxonomy );

				foreach ( is_array( $terms ) ? $terms : [] as $term ) {

					$url = self::archive( $term );

					if ( $url ) {
						$urls       = array_diff( $urls, [ $url ] );
						$archives[] = $url . '?vhp-regex';
					}
				}
			}

			$urls = array_merge( $urls, $archives );
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * photopress_image_terms_changed: an image's pages, with the archives of
	 * the terms that changed. Not from TermChanges's job: the cache was
	 * emptied when it started.
	 */
	public static function imageTermsChanged( $id, $context = [] ) {

		if ( empty( $context['job'] ) ) {
			self::purgeImage( (int) $id, (array) ( $context['archives'] ?? [] ) );
		}
	}

	/** attachment_updated: an image's title, caption, description or slug changed. */
	public static function imageEdited( $id, $after, $before ) {

		if ( ! self::purges() ) {
			return;
		}

		foreach ( [ 'post_title', 'post_excerpt', 'post_content', 'post_name' ] as $field ) {

			if ( $after->$field !== $before->$field ) {
				self::purgeImage( (int) $id );
				return;
			}
		}
	}

	/**
	 * An image's pages at the end of the request, with these archives (every
	 * page of each), while there are image or taxonomy pages.
	 */
	public static function purgeImage( $id, array $archives = [] ) {

		if ( ! self::pages() || ! wp_attachment_is_image( $id ) ) {
			return;
		}

		self::$images[ $id ] = true;

		if ( search::enabled( 'taxonomy_pages' ) ) {

			foreach ( $archives as $url ) {
				self::$archives[ $id ][] = strtok( $url, '?' ) . '?vhp-regex';
			}
		}

		self::later();
	}

	/**
	 * photopress_term_images_changed: the whole cache emptied at the end of
	 * the request, while there are pages.
	 */
	public static function purgeAll() {

		if ( self::pages() ) {
			self::$all = true;
			self::later();
		}
	}

	/** A term's archive address, while it has one; or ''. */
	private static function archive( $term ) {

		if ( ! is_taxonomy_viewable( $term->taxonomy ) ) {
			return '';
		}

		$url = get_term_link( $term );

		return is_wp_error( $url ) ? '' : $url;
	}

	/**
	 * shutdown, before Proxy Cache Purge sends the request's purges: the
	 * events for the images, or emptying the cache.
	 */
	public static function fire() {

		$images = array_keys( self::$images );
		$all    = self::$all;
		self::$images = [];
		self::$all    = false;

		if ( $all ) {
			do_action( self::ALL );
		} else {
			foreach ( $images as $id ) {
				do_action( self::IMAGE, $id );
			}
		}

		self::$archives = [];
	}

	/** Whether images or terms have pages to purge. */
	private static function pages() {

		return self::purges() && ( search::enabled( 'attachment_pages' ) || search::enabled( 'taxonomy_pages' ) );
	}

	/** fire() at the end of the request, once. */
	private static function later() {

		if ( false === has_action( 'shutdown', [ self::class, 'fire' ] ) ) {
			add_action( 'shutdown', [ self::class, 'fire' ], 5 );
		}
	}
}
