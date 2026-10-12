<?php

namespace PhotoPress\modules\search;

/**
 * The site's page cache, through Proxy Cache Purge's documented hooks. It
 * purges a post's pages, its terms' archives and the home page when the post
 * is saved or deleted, but leaves out images (their status is inherit), and
 * does nothing when a post changes without being saved or an image's terms
 * change.
 *
 * PhotoPress gives it:
 *
 * - images, among the statuses it purges (varnish_http_purge_valid_post_statuses),
 *   so an image deleted is purged by Proxy Cache Purge itself;
 * - an event for a post or image (varnish_http_purge_events), fired when
 *   what it shows changed: PhotoPress's photopress_post_changed (from the
 *   gallery and media modules) and photopress_image_terms_changed (from
 *   TermChanges), and an image's title, caption, description or slug edited;
 * - an event to empty the cache (varnish_http_purge_events_full), on
 *   photopress_term_images_changed: a renamed or deleted term with more
 *   images than are worth purging one by one;
 * - for those purges (vhp_purge_urls): files that changed with the post;
 *   for an image, every page of its image taxonomy terms' archives, and of
 *   terms it left, while image taxonomy pages are on, and not its own page
 *   while attachment pages are off.
 *
 * Proxy Cache Purge decides the rest: how and when it sends them, whether by
 * WP-Cron, and when there are so many it empties the cache instead.
 *
 * The Pages & Search tab shows whether Proxy Cache Purge is active.
 */
class PageCache {

	/** Proxy Cache Purge's event for a post or image, with its ID. */
	const POST = 'photopress_purge_post';

	/** Proxy Cache Purge's event to empty the cache. */
	const ALL = 'photopress_purge_all';

	/** URLs to purge with a post, by ID, while its event runs. */
	private static $extra = [];

	/** Posts and images purged in this request, by ID. */
	private static $purged = [];

	/** Whether the cache was emptied in this request. */
	private static $emptied = false;

	public static function addHooks() {

		add_filter( 'varnish_http_purge_events', [ self::class, 'events' ] );
		add_filter( 'varnish_http_purge_events_full', [ self::class, 'fullEvents' ] );
		add_filter( 'varnish_http_purge_valid_post_statuses', [ self::class, 'statuses' ], 10, 2 );
		add_filter( 'vhp_purge_urls', [ self::class, 'urls' ], 10, 2 );

		add_action( 'photopress_post_changed', [ self::class, 'postChanged' ], 10, 2 );
		add_action( 'photopress_image_terms_changed', [ self::class, 'imageTermsChanged' ], 10, 2 );
		add_action( 'photopress_term_images_changed', [ self::class, 'purgeAll' ] );
		add_action( 'attachment_updated', [ self::class, 'imageEdited' ], 10, 3 );
	}

	/**
	 * Whether Proxy Cache Purge purges for PhotoPress: it listens for the
	 * post event, from its init on.
	 */
	public static function purges() {

		return false !== has_action( self::POST );
	}

	/** varnish_http_purge_events: the post event, and emptying the cache. */
	public static function events( $events ) {

		return array_merge( (array) $events, [ self::POST, self::ALL ] );
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
	 * vhp_purge_urls: the URLs of the request's purges so far, as a post's
	 * are added: with the URLs given with its event. An image's image
	 * taxonomy terms' archives become every page of them, while image
	 * taxonomy pages are on; its own page is left out while attachment pages
	 * are off.
	 */
	public static function urls( $urls, $post_id = 0 ) {

		$urls = (array) $urls;

		if ( $post_id && wp_attachment_is_image( $post_id ) ) {

			if ( ! search::enabled( 'attachment_pages' ) ) {
				$urls = array_diff( $urls, [ get_permalink( $post_id ) ] );
			}

			if ( search::enabled( 'taxonomy_pages' ) ) {

				foreach ( ImageSearch::taxonomies() as $taxonomy ) {

					$terms = get_the_terms( $post_id, $taxonomy );

					foreach ( is_array( $terms ) ? $terms : [] as $term ) {

						$url = self::archive( $term );

						if ( $url ) {
							$urls   = array_diff( $urls, [ $url ] );
							$urls[] = $url . '?vhp-regex';
						}
					}
				}
			}
		}

		return array_values( array_unique( array_merge( $urls, self::$extra[ $post_id ] ?? [] ) ) );
	}

	/**
	 * photopress_post_changed: what a post or image shows changed, though
	 * WordPress did not save it as an edit. Its pages, with its files that
	 * changed.
	 */
	public static function postChanged( $id, $context = [] ) {

		self::purge( (int) $id, [], (array) ( $context['files'] ?? [] ) );
	}

	/**
	 * photopress_image_terms_changed: an image's pages, with the archives of
	 * the terms that changed. Not from TermChanges's job: the cache was
	 * emptied when it started.
	 */
	public static function imageTermsChanged( $id, $context = [] ) {

		if ( empty( $context['job'] ) ) {
			self::purge( (int) $id, (array) ( $context['archives'] ?? [] ) );
		}
	}

	/** attachment_updated: an image's title, caption, description or slug changed. */
	public static function imageEdited( $id, $after, $before ) {

		foreach ( [ 'post_title', 'post_excerpt', 'post_content', 'post_name' ] as $field ) {

			if ( $after->$field !== $before->$field ) {
				self::purge( (int) $id );
				return;
			}
		}
	}

	/**
	 * A post's or image's pages, through Proxy Cache Purge's event, with
	 * these archives (every page of each) and files. An image's, while
	 * images or terms have pages. Once a request, unless with more URLs.
	 */
	public static function purge( $id, array $archives = [], array $files = [] ) {

		if ( ! self::purges() ) {
			return;
		}

		$extra = $files;

		if ( wp_attachment_is_image( $id ) ) {

			if ( ! self::imagePages() ) {
				return;
			}

			if ( search::enabled( 'taxonomy_pages' ) ) {
				foreach ( $archives as $url ) {
					$extra[] = strtok( $url, '?' ) . '?vhp-regex';
				}
			}
		}

		if ( isset( self::$purged[ $id ] ) && ! $extra ) {
			return;
		}

		self::$purged[ $id ] = true;
		self::$extra[ $id ]  = array_values( array_unique( $extra ) );
		do_action( self::POST, $id );
		unset( self::$extra[ $id ] );
	}

	/**
	 * photopress_term_images_changed: the whole cache emptied, while images
	 * or terms have pages.
	 */
	public static function purgeAll() {

		if ( ! self::$emptied && self::purges() && self::imagePages() ) {
			self::$emptied = true;
			do_action( self::ALL );
		}
	}

	/** Whether images or image taxonomy terms have pages. */
	private static function imagePages() {

		return search::enabled( 'attachment_pages' ) || search::enabled( 'taxonomy_pages' );
	}

	/** A term's archive address, while it has one; or ''. */
	private static function archive( $term ) {

		if ( ! is_taxonomy_viewable( $term->taxonomy ) ) {
			return '';
		}

		$url = get_term_link( $term );

		return is_wp_error( $url ) ? '' : $url;
	}
}
