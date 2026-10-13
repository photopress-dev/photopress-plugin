<?php

namespace PhotoPress\modules\search;

defined( 'ABSPATH' ) || exit;

use PhotoPress\modules\metadata\metadata;

/**
 * Yoast SEO's sitemap, through its documented filters. It lists image
 * taxonomy terms itself, and media pages, while its own setting has them,
 * of images attached to a published post only.
 *
 * PhotoPress gives it:
 *
 * - no media pages while attachment pages are off, whose addresses are then
 *   the files (wpseo_sitemap_exclude_post_type), nor image taxonomy terms
 *   while image taxonomy pages are off (wpseo_sitemap_exclude_taxonomy);
 * - the images on each term's archive page (wpseo_sitemap_urlimages_term);
 * - a sitemap of unattached images' pages (wpseo_sitemaps_providers,
 *   YoastImagesSitemap), while it lists media pages.
 */
class YoastSitemap {

	public static function addHooks() {

		add_filter( 'wpseo_sitemap_exclude_post_type', [ self::class, 'excludePostType' ], 10, 2 );
		add_filter( 'wpseo_sitemap_exclude_taxonomy', [ self::class, 'excludeTaxonomy' ], 10, 2 );
		add_filter( 'wpseo_sitemap_urlimages_term', [ self::class, 'termImages' ], 10, 2 );
		add_filter( 'wpseo_sitemaps_providers', [ self::class, 'providers' ] );
	}

	/** wpseo_sitemap_exclude_post_type: images, while they have no pages of their own. */
	public static function excludePostType( $exclude, $post_type ) {

		return $exclude || ( 'attachment' === $post_type && ! ImageSitemap::listsImagePages() );
	}

	/** wpseo_sitemap_exclude_taxonomy: an image taxonomy without archive pages. */
	public static function excludeTaxonomy( $exclude, $taxonomy ) {

		return $exclude || ( metadata::areImageTaxonomies( [ $taxonomy ] ) && ! is_taxonomy_viewable( $taxonomy ) );
	}

	/** wpseo_sitemap_urlimages_term: the images on a term's archive page. */
	public static function termImages( $images, $term_id ) {

		foreach ( ImageSitemap::termImages( $term_id ) as $src ) {
			$images[] = [ 'src' => $src ];
		}

		return $images;
	}

	/** wpseo_sitemaps_providers: the sitemap of unattached images. */
	public static function providers( $providers ) {

		if ( interface_exists( '\WPSEO_Sitemap_Provider' ) ) {
			$providers[] = new YoastImagesSitemap();
		}

		return $providers;
	}
}
