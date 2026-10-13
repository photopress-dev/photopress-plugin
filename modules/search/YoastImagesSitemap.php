<?php

namespace PhotoPress\modules\search;

defined( 'ABSPATH' ) || exit;

/**
 * Yoast SEO's sitemap of unattached images' pages (photopress-image-sitemap.xml),
 * which its own media sitemap leaves out, while it lists media pages:
 * Yoast SEO's own test of a post type it lists, made for attachments.
 * Loaded only once Yoast SEO asks for its sitemaps (YoastSitemap), when
 * its WPSEO_Sitemap_Provider is there to implement.
 */
class YoastImagesSitemap implements \WPSEO_Sitemap_Provider {

	/** Its name, in its addresses. */
	const TYPE = 'photopress-image';

	/** The images: unattached. */
	const IMAGES = [ 'post_parent' => 0 ];

	public function handles_type( $type ) {

		return self::TYPE === $type;
	}

	public function get_index_links( $max_entries ) {

		ImageSitemap::seen( 'yoast' );

		if ( ! self::listed() ) {
			return [];
		}

		$pages = (int) ceil( ImageSitemap::countImages( self::IMAGES ) / max( 1, (int) $max_entries ) );
		$links = [];

		for ( $page = 1; $page <= $pages; $page++ ) {
			// As Yoast SEO names its pages: the first without a number.
			$links[] = [ 'loc' => home_url( '/' . self::TYPE . '-sitemap' . ( $page > 1 ? $page : '' ) . '.xml' ) ];
		}

		return $links;
	}

	public function get_sitemap_links( $type, $max_entries, $current_page ) {

		if ( ! self::listed() ) {
			return [];
		}

		$links = [];

		foreach ( ImageSitemap::images( self::IMAGES, $current_page, $max_entries ) as $image ) {
			$links[] = [
				'loc'    => get_permalink( $image ),
				'mod'    => $image->post_modified_gmt,
				'images' => [ [ 'src' => wp_get_attachment_url( $image->ID ) ] ],
			];
		}

		return $links;
	}

	/**
	 * Whether Yoast SEO lists media pages: attachments are a post type it
	 * shows and indexes, and not excluded from its sitemap.
	 */
	private static function listed() {

		return \WPSEO_Post_Type::is_post_type_accessible( 'attachment' )
			&& \WPSEO_Post_Type::is_post_type_indexable( 'attachment' )
			/** This filter is documented in Yoast SEO's WPSEO_Post_Type_Sitemap_Provider::is_valid_post_type(). */
			&& ! apply_filters( 'wpseo_sitemap_exclude_post_type', false, 'attachment' );
	}
}
