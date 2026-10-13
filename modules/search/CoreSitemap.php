<?php

namespace PhotoPress\modules\search;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress's sitemap (wp-sitemap.xml), through its documented filters. It
 * lists image taxonomy terms itself, and leaves them out while image
 * taxonomy pages are off (their taxonomies are then not viewable). It
 * leaves out images: PhotoPress adds them back (wp_sitemaps_post_types)
 * while attachment pages are on, as public images
 * (wp_sitemaps_posts_query_args).
 *
 * It cannot list an image's file with its page: WordPress's sitemap has no
 * image entries.
 */
class CoreSitemap {

	public static function addHooks() {

		add_filter( 'wp_sitemaps_post_types', [ self::class, 'postTypes' ] );
		add_filter( 'wp_sitemaps_posts_query_args', [ self::class, 'queryArgs' ], 10, 2 );
	}

	/** wp_sitemaps_post_types: images, while they have pages of their own. */
	public static function postTypes( $post_types ) {

		$attachment = get_post_type_object( 'attachment' );

		if ( is_array( $post_types ) && $attachment && ImageSitemap::listsImagePages() ) {
			$post_types['attachment'] = $attachment;
		}

		return $post_types;
	}

	/** wp_sitemaps_posts_query_args: for images, public images. */
	public static function queryArgs( $args, $post_type ) {

		return 'attachment' === $post_type ? ImageSitemap::imagesQuery( (array) $args ) : $args;
	}
}
