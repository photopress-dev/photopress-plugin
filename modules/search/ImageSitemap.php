<?php

namespace PhotoPress\modules\search;

use WP_Query;
use PhotoPress\modules\metadata\metadata;
use PhotoPress\modules\metadata\ImageTaxonomies;

/**
 * What PhotoPress lists in the site's sitemap, whichever builds it: the one
 * place that decides it. Each sitemap is given it through its own hooks:
 * WordPress's (CoreSitemap), Jetpack's (JetpackSitemap) and Yoast SEO's
 * (YoastSitemap). Each runs its hooks only while it builds its sitemap, so
 * only the one in use asks.
 *
 * - Image pages, while attachment pages are on: public images (unattached,
 *   or attached to a published post), as metadata::PUBLIC_IMAGES finds them.
 * - Image taxonomy terms' archive pages, while image taxonomy pages are on:
 *   terms with images (WordPress counts every image a term has).
 * - The images on a term's archive page: its first page, the newest.
 * - Where an image is shown when it has no page of its own: its first
 *   term's archive.
 *
 * The Pages & Search tab shows which sitemap is in use (inUse()).
 */
class ImageSitemap {

	/** When each sitemap was last built with PhotoPress's hooks, by name. */
	const SEEN = 'photopress_sitemap_seen';

	/** How long a sitemap counts as in use after it was built. */
	const IN_USE_FOR = 30 * DAY_IN_SECONDS;

	/** The archive addresses, once a request: Jetpack asks for each part. */
	private static $archives = null;

	/** Whether images have pages of their own to list. */
	public static function listsImagePages() {

		return search::enabled( 'attachment_pages' );
	}

	/**
	 * WP_Query arguments for public images, merged into $args: another
	 * sitemap's query, or one of PhotoPress's.
	 */
	public static function imagesQuery( array $args = [] ) {

		return array_merge( $args, [
			'post_type'             => 'attachment',
			'post_status'           => 'inherit',
			'post_mime_type'        => 'image',
			metadata::PUBLIC_IMAGES => true,
		] );
	}

	/**
	 * A page of public images, by ID, while attachment pages are on.
	 *
	 * @param array $args     More WP_Query arguments: post_parent 0 for
	 *                        unattached images.
	 * @param int   $page     From 1.
	 * @param int   $per_page
	 * @return \WP_Post[]
	 */
	public static function images( array $args, $page, $per_page ) {

		if ( ! self::listsImagePages() ) {
			return [];
		}

		return ( new WP_Query( self::imagesQuery( $args + [
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'posts_per_page'         => (int) $per_page,
			'paged'                  => max( 1, (int) $page ),
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'ignore_sticky_posts'    => true,
		] ) ) )->posts;
	}

	/** How many public images there are, while attachment pages are on. */
	public static function countImages( array $args = [] ) {

		if ( ! self::listsImagePages() ) {
			return 0;
		}

		$query = new WP_Query( self::imagesQuery( $args + [
			'fields'                 => 'ids',
			'posts_per_page'         => 1,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
		] ) );

		return (int) $query->found_posts;
	}

	/**
	 * The archive addresses of image taxonomy terms with images, while
	 * image taxonomy pages are on.
	 *
	 * @return string[]
	 */
	public static function termArchives() {

		if ( null !== self::$archives ) {
			return self::$archives;
		}

		self::$archives = [];

		foreach ( self::archivedTaxonomies() as $taxonomy ) {

			$terms = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => true, 'update_term_meta_cache' => false ] );

			foreach ( is_array( $terms ) ? $terms : [] as $term ) {

				$url = ImageTaxonomies::archiveUrl( $term );

				if ( '' !== $url ) {
					self::$archives[] = $url;
				}
			}
		}

		return self::$archives;
	}

	/**
	 * The files of the images a term's archive page shows first: the
	 * newest, as many as a page of posts.
	 *
	 * @return string[]
	 */
	public static function termImages( $term_id ) {

		$term = get_term( (int) $term_id );

		if ( ! $term instanceof \WP_Term || ! in_array( $term->taxonomy, self::archivedTaxonomies(), true ) ) {
			return [];
		}

		$ids = ( new WP_Query( self::imagesQuery( [
			'tax_query'              => [ [ 'taxonomy' => $term->taxonomy, 'field' => 'term_id', 'terms' => $term->term_id ] ],
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'posts_per_page'         => max( 1, (int) get_option( 'posts_per_page' ) ),
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		] ) ) )->posts;

		return array_values( array_filter( array_map( 'wp_get_attachment_url', $ids ) ) );
	}

	/**
	 * Where an image is shown when it has no page of its own and is not
	 * attached to a post: the archive of its first term, or ''.
	 */
	public static function pageFor( $image ) {

		foreach ( self::archivedTaxonomies() as $taxonomy ) {

			$terms = get_the_terms( $image, $taxonomy );

			foreach ( is_array( $terms ) ? $terms : [] as $term ) {

				$url = ImageTaxonomies::archiveUrl( $term );

				if ( '' !== $url ) {
					return $url;
				}
			}
		}

		return '';
	}

	/** The image taxonomies with archive pages: none while image taxonomy pages are off. */
	private static function archivedTaxonomies() {

		return array_values( array_filter( ImageTaxonomies::shownImageTaxonomies(), 'is_taxonomy_viewable' ) );
	}

	/**
	 * A sitemap built with PhotoPress's hooks, noted at most once a day.
	 *
	 * @param string $sitemap jetpack or yoast.
	 */
	public static function seen( $sitemap ) {

		$seen = (array) get_option( self::SEEN, [] );

		if ( time() - (int) ( $seen[ $sitemap ] ?? 0 ) < DAY_IN_SECONDS ) {
			return;
		}

		$seen[ $sitemap ] = time();
		update_option( self::SEEN, $seen, false );
	}

	/**
	 * The sitemap in use: WordPress's, while it is on; otherwise the one
	 * last built with PhotoPress's hooks, in the last 30 days.
	 *
	 * @return array sitemap (core, jetpack, yoast, other, or none while
	 *               search engines are discouraged), url, and built: when,
	 *               as "3 hours", for Jetpack's and Yoast SEO's.
	 */
	public static function inUse() {

		if ( ! get_option( 'blog_public' ) ) {
			return [ 'sitemap' => 'none' ];
		}

		if ( function_exists( 'wp_sitemaps_get_server' ) && wp_sitemaps_get_server()->sitemaps_enabled() ) {
			return [ 'sitemap' => 'core', 'url' => get_sitemap_url( 'index' ) ];
		}

		$seen = array_filter( (array) get_option( self::SEEN, [] ), static fn( $time ) => time() - (int) $time < self::IN_USE_FOR );

		if ( ! $seen ) {
			return [ 'sitemap' => 'other' ];
		}

		arsort( $seen );

		return [
			'sitemap' => (string) array_key_first( $seen ),
			// Each serves its index here, or sends visitors on to it.
			'url'     => home_url( '/sitemap.xml' ),
			'built'   => human_time_diff( (int) reset( $seen ) ),
		];
	}
}
