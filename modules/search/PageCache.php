<?php

namespace PhotoPress\modules\search;

/**
 * The site's page cache. Proxy Cache Purge (a Varnish or NGINX cache) clears
 * a post's pages when the post is saved, and nothing when an image is edited
 * (an image's status is inherit, which it leaves alone) or its terms change.
 * Through it, PhotoPress clears:
 *
 * - an image's page, when the image is edited or deleted or its terms
 *   change, while attachment pages are on;
 * - every page of an image taxonomy term's archive, when an image joins or
 *   leaves the term or is edited or deleted, while image taxonomy pages are
 *   on. A renamed term's are cleared by RenamedTerms.
 *
 * Other page cache plugins are named on the Pages & Search tab, and left to
 * their own rules.
 */
class PageCache {

	/** Pages to clear at the end of the request, by URL. */
	private static $urls = [];

	public static function addHooks() {

		if ( ! self::purges() ) {
			return;
		}

		add_action( 'added_term_relationship', [ self::class, 'termsChanged' ], 10, 3 );
		add_action( 'deleted_term_relationships', [ self::class, 'termsChanged' ], 10, 3 );
		add_action( 'attachment_updated', [ self::class, 'imageEdited' ], 10, 3 );
		add_action( 'delete_attachment', [ self::class, 'imageDeleted' ] );
	}

	/** Whether PhotoPress clears pages through Proxy Cache Purge. */
	public static function purges() {

		return class_exists( 'VarnishPurger' ) && is_callable( [ 'VarnishPurger', 'purge_url' ] );
	}

	/** The page cache plugin active, by name, or ''. */
	public static function plugin() {

		$plugins = [
			'Proxy Cache Purge'  => self::purges(),
			'WP Rocket'          => defined( 'WP_ROCKET_VERSION' ),
			'W3 Total Cache'     => defined( 'W3TC' ),
			'LiteSpeed Cache'    => defined( 'LSCWP_V' ),
			'WP Super Cache'     => defined( 'WPCACHEHOME' ),
			'WP Fastest Cache'   => class_exists( 'WpFastestCache' ),
			'Cache Enabler'      => class_exists( 'Cache_Enabler' ),
			'Breeze'             => defined( 'BREEZE_VERSION' ),
			'Nginx Helper'       => defined( 'NGINX_HELPER_BASENAME' ),
		];

		return (string) array_search( true, $plugins, true );
	}

	/**
	 * added_term_relationship, deleted_term_relationships: an image joining a
	 * term or leaving terms, as when it is edited or PhotoPress reprocesses
	 * it. Its page, and those terms' archives.
	 */
	public static function termsChanged( $object_id, $tt_ids, $taxonomy ) {

		if ( ! in_array( $taxonomy, ImageSearch::taxonomies(), true ) || 'attachment' !== get_post_type( $object_id ) ) {
			return;
		}

		self::purgeImagePage( $object_id );

		foreach ( array_map( 'intval', (array) $tt_ids ) as $tt_id ) {

			$term = get_term_by( 'term_taxonomy_id', $tt_id, $taxonomy );

			if ( $term ) {
				self::purgeArchive( $term );
			}
		}
	}

	/** attachment_updated: an image's title, caption, description or slug changed. */
	public static function imageEdited( $id, $after, $before ) {

		foreach ( [ 'post_title', 'post_excerpt', 'post_content', 'post_name' ] as $field ) {

			if ( $after->$field !== $before->$field ) {
				self::purgeImage( $id );
				return;
			}
		}
	}

	/** delete_attachment: before its terms are removed. */
	public static function imageDeleted( $id ) {

		if ( wp_attachment_is_image( $id ) ) {
			self::purgeImage( $id );
		}
	}

	/** An image's page, and the archives of its terms. */
	public static function purgeImage( $id ) {

		self::purgeImagePage( $id );

		foreach ( ImageSearch::taxonomies() as $taxonomy ) {

			$terms = get_the_terms( $id, $taxonomy );

			foreach ( is_array( $terms ) ? $terms : [] as $term ) {
				self::purgeArchive( $term );
			}
		}
	}

	/** An image's page, while images have pages. */
	public static function purgeImagePage( $id ) {

		if ( ! search::enabled( 'attachment_pages' ) ) {
			return;
		}

		$url = get_permalink( $id );

		if ( $url ) {
			self::queue( $url );
		}
	}

	/** Every page of a term's archive, while terms have archives. */
	public static function purgeArchive( $term ) {

		if ( ! is_taxonomy_viewable( $term->taxonomy ) ) {
			return;
		}

		$url = get_term_link( $term );

		if ( ! is_wp_error( $url ) ) {
			self::purgeArchiveAt( $url );
		}
	}

	/**
	 * Every page of the archive at this address: the archive, its pages and
	 * its feed, in one regex purge (Proxy Cache Purge's ?vhp-regex).
	 */
	public static function purgeArchiveAt( $url ) {

		if ( search::enabled( 'taxonomy_pages' ) ) {
			self::queue( strtok( $url, '?' ) . '?vhp-regex' );
		}
	}

	/** A page to clear at the end of the request, once. */
	public static function queue( $url ) {

		if ( ! self::$urls ) {
			add_action( 'shutdown', [ self::class, 'purge' ] );
		}

		self::$urls[ $url ] = true;
	}

	/** shutdown: the pages, cleared. */
	public static function purge() {

		$urls = array_keys( self::$urls );
		self::$urls = [];

		foreach ( $urls as $url ) {
			\VarnishPurger::purge_url( $url );
		}
	}
}
