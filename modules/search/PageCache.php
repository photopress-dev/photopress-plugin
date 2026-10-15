<?php

namespace PhotoPress\modules\search;

defined( 'ABSPATH' ) || exit;

use PhotoPress\modules\metadata\ImageTaxonomies;

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
 *   while attachment pages are off, nor its terms' REST API addresses,
 *   which page caches pass by and visitors cannot read;
 * - an event to empty the cache once PhotoPress is updated, so pages cached
 *   by the earlier version do not ask for its scripts, and when a switch of
 *   the Pages & Search tab changes, which decide what pages the site has
 *   and what its search finds;
 * - during a PhotoPress job's batch (photopress_job_batch_started and
 *   _ended), nothing for the items it changes: once the job has ended, the
 *   cache emptied, if any of its batches changed a page. Each batch would
 *   otherwise have emptied it, being over Proxy Cache Purge's limit.
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

	/** The version of PhotoPress the cache was last emptied for (versionChanged()). */
	const VERSION = 'photopress_version';

	/** URLs to purge with a post, by ID, while its event runs. */
	private static $extra = [];

	/** Posts and images purged in this request, by ID. */
	private static $purged = [];

	/** Whether the cache was emptied in this request. */
	private static $emptied = false;

	/** The jobs whose batches held back purges, by ID: the cache is emptied when each ends. */
	const AFTER_JOBS = 'photopress_purge_after_jobs';

	/** Whether a job's batch is running in this request. */
	private static $inBatch = false;

	/** Whether this request's batch held back a purge. */
	private static $heldBack = false;

	public static function addHooks() {

		add_filter( 'varnish_http_purge_events', [ self::class, 'events' ] );
		add_filter( 'varnish_http_purge_events_full', [ self::class, 'fullEvents' ] );
		add_filter( 'varnish_http_purge_valid_post_statuses', [ self::class, 'statuses' ], 10, 2 );
		add_filter( 'vhp_purge_urls', [ self::class, 'urls' ], 10, 2 );

		add_action( 'photopress_post_changed', [ self::class, 'postChanged' ], 10, 2 );
		add_action( 'photopress_image_terms_changed', [ self::class, 'imageTermsChanged' ], 10, 2 );
		add_action( 'photopress_term_images_changed', [ self::class, 'purgeAll' ] );
		add_action( 'attachment_updated', [ self::class, 'imageEdited' ], 10, 3 );

		add_action( 'wp_loaded', [ self::class, 'versionChanged' ] );
		add_action( 'add_option_' . search::OPTION, static fn( $option, $value ) => self::settingsChanged( [], $value ), 10, 2 );
		add_action( 'update_option_' . search::OPTION, [ self::class, 'settingsChanged' ], 10, 2 );

		add_action( 'photopress_job_batch_started', [ self::class, 'batchStarted' ] );
		add_action( 'photopress_job_batch_ended', [ self::class, 'batchEnded' ] );
		add_action( 'photopress_job_ended', [ self::class, 'jobEnded' ] );
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

	/**
	 * varnish_http_purge_valid_post_statuses: an image's, inherit; not
	 * during a job's batch, whose purges wait for the job's end.
	 */
	public static function statuses( $statuses, $post_id = 0 ) {

		$statuses = (array) $statuses;

		if ( $post_id && ! self::$inBatch && wp_attachment_is_image( $post_id ) && ! in_array( 'inherit', $statuses, true ) ) {
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

			$urls = array_diff( $urls, self::termRestUrls( $post_id ) );

			if ( search::enabled( 'taxonomy_pages' ) ) {

				foreach ( ImageTaxonomies::shownImageTaxonomies() as $taxonomy ) {

					$terms = get_the_terms( $post_id, $taxonomy );

					foreach ( is_array( $terms ) ? $terms : [] as $term ) {

						$url = ImageTaxonomies::archiveUrl( $term );

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
	 * The REST API addresses of an image's image taxonomy terms, as Proxy
	 * Cache Purge adds them to its purge: page caches pass the REST API by,
	 * and only editors may read these. Each would count toward the limit
	 * over which it empties the whole cache.
	 *
	 * @return string[]
	 */
	private static function termRestUrls( $post_id ) {

		$urls = [];

		foreach ( ImageTaxonomies::shownImageTaxonomies() as $taxonomy ) {

			$object = get_taxonomy( $taxonomy );
			$base   = $object && ! empty( $object->rest_base ) ? $object->rest_base : $taxonomy;
			$terms  = get_the_terms( $post_id, $taxonomy );

			foreach ( is_array( $terms ) ? $terms : [] as $term ) {
				$urls[] = get_rest_url() . 'wp/v2/' . $base . '/' . $term->term_id . '/';
			}
		}

		return $urls;
	}

	/** photopress_job_batch_started: purges held back until the batch ends. */
	public static function batchStarted( $job ) {

		self::$inBatch  = true;
		self::$heldBack = false;
	}

	/**
	 * photopress_job_batch_ended: a job whose batch held back purges is
	 * noted, for the cache to be emptied when it ends; at once, if it was
	 * cancelled meanwhile.
	 */
	public static function batchEnded( $job ) {

		$held = self::$heldBack;
		self::$inBatch  = false;
		self::$heldBack = false;

		if ( ! $held || empty( $job['id'] ) ) {
			return;
		}

		if ( 'cancelled' === ( $job['status'] ?? '' ) ) {
			self::purgeAll();
			return;
		}

		$after = (array) get_option( self::AFTER_JOBS, [] );
		$after[ $job['id'] ] = true;
		update_option( self::AFTER_JOBS, $after, false );
	}

	/** photopress_job_ended: the cache emptied, if the job's batches held back purges. */
	public static function jobEnded( $job ) {

		$after = (array) get_option( self::AFTER_JOBS, [] );

		if ( empty( $job['id'] ) || ! isset( $after[ $job['id'] ] ) ) {
			return;
		}

		unset( $after[ $job['id'] ] );
		$after ? update_option( self::AFTER_JOBS, $after, false ) : delete_option( self::AFTER_JOBS );
		self::purgeAll();
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

		// Held back until the job ends, when the whole cache is emptied.
		if ( self::$inBatch ) {
			self::$heldBack = true;
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

		if ( self::imagePages() ) {
			self::emptyCache();
		}
	}

	/**
	 * add_ and update_option_photopress_core_search: the whole cache
	 * emptied when a switch of the Pages & Search tab changed. Pages that
	 * were turned off, such as an author archive, would otherwise still be
	 * served from it, as would sitemaps that listed them.
	 *
	 * @param mixed $before
	 * @param mixed $after
	 */
	public static function settingsChanged( $before, $after ) {

		$before = is_array( $before ) ? $before : [];
		$after  = is_array( $after ) ? $after : [];

		foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $key ) {

			if ( ! empty( $before[ $key ] ) !== ! empty( $after[ $key ] ) ) {
				self::emptyCache();
				return;
			}
		}
	}

	/** The whole cache emptied, once a request, by Proxy Cache Purge. */
	private static function emptyCache() {

		if ( ! self::$emptied && self::purges() ) {
			self::$emptied = true;
			do_action( self::ALL );
		}
	}

	/**
	 * wp_loaded, on the first request after PhotoPress is updated: the cache
	 * emptied, so no page cached by the earlier version asks for scripts and
	 * styles that version had and this one does not.
	 */
	public static function versionChanged() {

		if ( ! defined( 'PHOTOPRESS_CORE_VERSION' ) || get_option( self::VERSION ) === PHOTOPRESS_CORE_VERSION ) {
			return;
		}

		update_option( self::VERSION, PHOTOPRESS_CORE_VERSION, true );

		if ( ! self::$emptied && self::purges() ) {
			self::$emptied = true;
			do_action( self::ALL );
		}
	}

	/** Whether images or image taxonomy terms have pages. */
	private static function imagePages() {

		return search::enabled( 'attachment_pages' ) || search::enabled( 'taxonomy_pages' );
	}
}
