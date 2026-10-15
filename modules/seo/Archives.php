<?php

namespace PhotoPress\modules\seo;

defined( 'ABSPATH' ) || exit;

/**
 * Archives a photography site rarely needs, turned off with the SEO tab's
 * Unneeded archives switch (off by default) and one switch each,
 * all on once it is: author archives (/author/name/, whose address shows the
 * author's login name, as does ?author=1, which WordPress redirects there),
 * date archives, and post format archives.
 *
 * An archive turned off redirects permanently to the home page, with its
 * feeds and later pages, so search engines drop its addresses; Yoast SEO does
 * the same with its own switches. While those archives are off, WordPress's
 * sitemap and Yoast SEO's leave out authors and post formats (Jetpack's lists
 * neither, and no sitemap lists dates).
 */
class Archives {

	/** The archives, each with its switch in the SEO module's option. */
	const ARCHIVES = [
		'author' => 'author_archives_off',
		'date'   => 'date_archives_off',
		'format' => 'format_archives_off',
	];

	public static function addHooks() {

		// Before redirect_canonical(), at 10, which would send ?author=1 to the author's address first.
		add_action( 'template_redirect', [ self::class, 'redirect' ], 9 );
		add_filter( 'wp_sitemaps_add_provider', [ self::class, 'sitemapProvider' ], 10, 2 );
		add_filter( 'wp_sitemaps_taxonomies', [ self::class, 'sitemapTaxonomies' ] );
		add_filter( 'wpseo_sitemap_exclude_taxonomy', [ self::class, 'yoastExcludeTaxonomy' ], 10, 2 );

		// Only while needed: with this filter hooked, Yoast SEO reads every user in full.
		if ( self::off( 'author' ) ) {
			add_filter( 'wpseo_sitemap_exclude_author', '__return_empty_array' );
		}
	}

	/**
	 * Whether an archive is turned off: author, date or format.
	 *
	 * @param string $archive
	 */
	public static function off( $archive ) {

		return isset( self::ARCHIVES[ $archive ] )
			&& seo::enabled( 'archives_off', false )
			&& seo::enabled( self::ARCHIVES[ $archive ] );
	}

	/** Whether the request is for an archive turned off. */
	public static function requested() {

		return ( is_author() && self::off( 'author' ) )
			|| ( is_date() && self::off( 'date' ) )
			|| ( is_tax( 'post_format' ) && self::off( 'format' ) );
	}

	/** template_redirect: an archive turned off goes to the home page. */
	public static function redirect() {

		if ( ! self::requested() ) {
			return;
		}

		wp_safe_redirect( home_url( '/' ), 301, 'PhotoPress' );
		exit;
	}

	/**
	 * wp_sitemaps_add_provider: no users sitemap while author archives are
	 * off; it lists their addresses.
	 *
	 * @param \WP_Sitemaps_Provider|false $provider
	 * @param string                      $name
	 */
	public static function sitemapProvider( $provider, $name ) {

		return 'users' === $name && self::off( 'author' ) ? false : $provider;
	}

	/**
	 * wp_sitemaps_taxonomies: no post formats while their archives are off.
	 *
	 * @param \WP_Taxonomy[] $taxonomies By name.
	 */
	public static function sitemapTaxonomies( $taxonomies ) {

		if ( self::off( 'format' ) ) {
			unset( $taxonomies['post_format'] );
		}

		return $taxonomies;
	}

	/**
	 * wpseo_sitemap_exclude_taxonomy: Yoast SEO's sitemap leaves out post
	 * formats while their archives are off.
	 *
	 * @param bool   $exclude
	 * @param string $taxonomy
	 */
	public static function yoastExcludeTaxonomy( $exclude, $taxonomy ) {

		return $exclude || ( 'post_format' === $taxonomy && self::off( 'format' ) );
	}
}
