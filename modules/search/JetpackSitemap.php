<?php

namespace PhotoPress\modules\search;

/**
 * Jetpack's sitemap (its Sitemaps module), through its documented filters.
 * It lists no taxonomy terms, and lists each image once in its image
 * sitemap, with the page of the post it is attached to, or, unattached, its
 * own page.
 *
 * PhotoPress gives it:
 *
 * - image taxonomy terms' archive pages, while image taxonomy pages are on,
 *   among its pages (jetpack_page_sitemap_other_urls);
 * - while attachment pages are on, each image with its own page
 *   (jetpack_sitemap_image_sitemap_item), so that every image's page is
 *   listed;
 * - while they are off, an unattached image with its first term's archive,
 *   where Jetpack would give its file as its page, or left out when it has
 *   none (jetpack_sitemap_image_skip_post).
 */
class JetpackSitemap {

	public static function addHooks() {

		add_filter( 'jetpack_page_sitemap_other_urls', [ self::class, 'otherUrls' ] );
		add_filter( 'jetpack_sitemap_image_skip_post', [ self::class, 'skipImage' ], 10, 2 );
		add_filter( 'jetpack_sitemap_image_sitemap_item', [ self::class, 'imageItem' ], 10, 2 );
	}

	/** jetpack_page_sitemap_other_urls: image taxonomy terms' archive pages. */
	public static function otherUrls( $urls ) {

		ImageSitemap::seen( 'jetpack' );

		return array_merge( (array) $urls, ImageSitemap::termArchives() );
	}

	/**
	 * jetpack_sitemap_image_skip_post: an unattached image without a page
	 * of its own or a term's archive. Jetpack gives the image as a row of
	 * its own query, not a WP_Post.
	 */
	public static function skipImage( $skip, $post ) {

		$id = is_object( $post ) ? (int) ( $post->ID ?? 0 ) : 0;

		if ( $skip || ! $id || wp_get_post_parent_id( $id ) || ImageSitemap::listsImagePages() ) {
			return $skip;
		}

		return '' === ImageSitemap::pageFor( $id );
	}

	/** jetpack_sitemap_image_sitemap_item: the page an image is listed with. */
	public static function imageItem( $item, $post_id ) {

		if ( ! is_array( $item ) || ! isset( $item['url'] ) ) {
			return $item;
		}

		if ( ImageSitemap::listsImagePages() ) {
			$item['url']['loc'] = get_permalink( $post_id ) ?: $item['url']['loc'];
		} elseif ( ! wp_get_post_parent_id( $post_id ) ) {
			$item['url']['loc'] = ImageSitemap::pageFor( $post_id ) ?: $item['url']['loc'];
		}

		return $item;
	}
}
