<?php

namespace PhotoPress\modules\search;

defined( 'ABSPATH' ) || exit;

/**
 * PhotoPress's image sitemap, one of WordPress's sitemaps (wp-sitemap.xml):
 * each public image with the page it is shown on (ImageSitemap::imageEntries()),
 * as Google's image entries. WordPress's own sitemaps cannot have them: its
 * renderer writes loc, lastmod, changefreq and priority only.
 *
 * So it is registered as one of WordPress's sitemaps through its documented
 * API (wp_register_sitemap_provider()), and its index lists it, but at an
 * address of PhotoPress's, which PhotoPress serves itself (CoreSitemap::serve()).
 */
class CoreImageSitemap extends \WP_Sitemaps_Provider {

	/** Its name among WordPress's sitemaps. */
	const NAME = 'photopress';

	/** The query variable of its address, holding the page number. */
	const QUERY_VAR = 'photopress-image-sitemap';

	const IMAGE_NS = 'http://www.google.com/schemas/sitemap-image/1.1';

	public function __construct() {

		$this->name        = self::NAME;
		$this->object_type = 'image';
	}

	/**
	 * Nothing for WordPress to render: its renderer would leave out the
	 * images. Its pages are served at their own address instead (url()).
	 */
	public function get_url_list( $page_num, $object_subtype = '' ) {

		return [];
	}

	public function get_max_num_pages( $object_subtype = '' ) {

		return (int) ceil( ImageSitemap::countPublicImages() / self::perPage() );
	}

	/** Its address in WordPress's index: PhotoPress's, not WordPress's. */
	public function get_sitemap_url( $name, $page ) {

		return self::url( (int) $page );
	}

	/** Images a page, as many as WordPress puts in one of its sitemaps. */
	public static function perPage() {

		return max( 1, (int) wp_sitemaps_get_max_urls( 'image' ) );
	}

	/** A page's address: /photopress-image-sitemap-2.xml, or with plain permalinks ?photopress-image-sitemap=2. */
	public static function url( $page ) {

		global $wp_rewrite;

		$page = max( 1, (int) $page );

		if ( $wp_rewrite && $wp_rewrite->using_permalinks() ) {
			return home_url( '/' . self::QUERY_VAR . "-$page.xml" );
		}

		return add_query_arg( self::QUERY_VAR, $page, home_url( '/' ) );
	}

	/** A page of it, as XML. */
	public static function xml( $page ) {

		return self::xmlFor( ImageSitemap::imageEntries( $page, self::perPage() ) );
	}

	/**
	 * These entries as an image sitemap's XML.
	 *
	 * @param array[] $entries loc, images and lastmod (ImageSitemap::imageEntries()).
	 */
	public static function xmlFor( array $entries ) {

		$urlset = new \SimpleXMLElement( '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="' . self::IMAGE_NS . '"/>' );

		foreach ( $entries as $entry ) {

			$url = $urlset->addChild( 'url' );
			// esc_url(), as WordPress's renderer: SimpleXML does not escape "&".
			$url->addChild( 'loc', esc_url( $entry['loc'] ) );

			if ( '' !== $entry['lastmod'] ) {
				$url->addChild( 'lastmod', wp_date( DATE_W3C, strtotime( $entry['lastmod'] . ' UTC' ) ) );
			}

			foreach ( $entry['images'] as $file ) {
				$url->addChild( 'image:image', null, self::IMAGE_NS )->addChild( 'image:loc', esc_url( $file ), self::IMAGE_NS );
			}
		}

		return (string) $urlset->asXML();
	}
}
