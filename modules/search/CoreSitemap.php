<?php

namespace PhotoPress\modules\search;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress's sitemap (wp-sitemap.xml), through its documented API. It
 * lists image taxonomy terms itself, and leaves them out while image
 * taxonomy pages are off (their taxonomies are then not viewable). It
 * leaves out images, and cannot list an image's file with its page: its
 * sitemaps have no image entries.
 *
 * PhotoPress adds a sitemap of its own to it (CoreImageSitemap): each
 * public image with the page it is shown on, as an image entry. Its index
 * lists it; PhotoPress serves it, at /photopress-image-sitemap-1.xml and on.
 */
class CoreSitemap {

	/** The version of the address's rewrite rule the rules were last built with. */
	const REWRITES = 'photopress_sitemap_rewrites';

	const REWRITES_VERSION = '1';

	public static function addHooks() {

		add_action( 'wp_sitemaps_init', [ self::class, 'register' ] );
		add_action( 'init', [ self::class, 'addRewriteRule' ] );
		add_filter( 'query_vars', [ self::class, 'queryVars' ] );
		add_action( 'parse_request', [ self::class, 'serve' ] );
	}

	/** wp_sitemaps_init: PhotoPress's image sitemap among WordPress's. */
	public static function register() {

		wp_register_sitemap_provider( CoreImageSitemap::NAME, new CoreImageSitemap() );
	}

	/**
	 * init: the image sitemap's address. The rewrite rules are built again
	 * once when the rule is new to them.
	 */
	public static function addRewriteRule() {

		add_rewrite_rule( '^' . CoreImageSitemap::QUERY_VAR . '-(\d+)\.xml$', 'index.php?' . CoreImageSitemap::QUERY_VAR . '=$matches[1]', 'top' );

		if ( self::REWRITES_VERSION === get_option( self::REWRITES ) ) {
			return;
		}

		// Once every plugin has added its rules, so the rules rebuilt have them.
		add_action( 'wp_loaded', static function () {
			update_option( self::REWRITES, self::REWRITES_VERSION );
			flush_rewrite_rules( false );
		} );
	}

	/** query_vars: the image sitemap's page. */
	public static function queryVars( $vars ) {

		$vars[] = CoreImageSitemap::QUERY_VAR;

		return $vars;
	}

	/**
	 * parse_request: a page of the image sitemap, served and the request
	 * ended.
	 *
	 * @param \WP $wp
	 */
	public static function serve( $wp ) {

		$xml = self::page( $wp );

		if ( null === $xml ) {
			return;
		}

		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex, follow' );
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it was built.
		exit;
	}

	/**
	 * The XML a request for the image sitemap is answered with, or null when
	 * it is not one. While WordPress's sitemap is off, or past the last
	 * page, the request is made a 404.
	 *
	 * @param \WP $wp
	 */
	public static function page( $wp ) {

		if ( ! isset( $wp->query_vars[ CoreImageSitemap::QUERY_VAR ] ) ) {
			return null;
		}

		$page = (int) $wp->query_vars[ CoreImageSitemap::QUERY_VAR ];
		$on   = function_exists( 'wp_sitemaps_get_server' ) && wp_sitemaps_get_server()->sitemaps_enabled();

		if ( ! $on || $page < 1 || $page > ( new CoreImageSitemap() )->get_max_num_pages() ) {
			unset( $wp->query_vars[ CoreImageSitemap::QUERY_VAR ] );
			$wp->query_vars['error'] = '404';
			return null;
		}

		return CoreImageSitemap::xml( $page );
	}
}
