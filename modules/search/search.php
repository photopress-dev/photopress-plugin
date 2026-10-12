<?php

namespace PhotoPress\modules\search;

use photopress_util;
use photopress_module;

/**
 * Search Module
 *
 * The Pages & Search settings tab: whether images have pages of their own
 * (attachment pages) and image taxonomies have archive pages. Both are on
 * until turned off. Searching images is to come here too.
 *
 * Attachment pages are WordPress's own setting (wp_attachment_pages_enabled,
 * since 6.4), which this switch decides: off, an image's page redirects to
 * its file, and links to it go to the file. Taxonomy archive pages are left
 * out when the metadata module registers the image taxonomies; their terms
 * are still shown, unlinked. While each is on, block themes without their
 * own get PhotoPress's template for those pages
 * (modules/gallery/ImageTemplates.php).
 *
 * And images in search results, found by their titles, captions, alt text
 * and terms, with Relevanssi or without (ImageSearch).
 */
class search extends photopress_module {

	public $label = 'Pages & Search';

	const OPTION = 'photopress_core_search';

	/**
	 * Whether a switch of this tab is on: its saved value, or on when it was
	 * never saved. Read from the option itself, so that other modules can
	 * ask while WordPress is starting, before this one has registered its
	 * defaults.
	 *
	 * @param string $key     attachment_pages, taxonomy_pages, images_in_search
	 *                        or posts_in_search.
	 * @param bool   $default When it was never saved.
	 */
	public static function enabled( $key, $default = true ) {

		$saved = get_option( self::OPTION );

		return is_array( $saved ) && array_key_exists( $key, $saved ) ? ! empty( $saved[ $key ] ) : $default;
	}

	public function definePublicHooks() {

		add_filter( 'pre_option_wp_attachment_pages_enabled', [ self::class, 'attachmentPagesOption' ] );

		ImageSearch::addHooks();
		PageCache::addHooks();
		TermChanges::addHooks();
		add_action( 'admin_enqueue_scripts', [ self::class, 'shareStatus' ], 20 );

		if ( ! self::enabled( 'attachment_pages' ) ) {
			add_filter( 'attachment_link', [ self::class, 'linkToFile' ], 10, 2 );
		}
	}

	/**
	 * pre_option_wp_attachment_pages_enabled: WordPress's setting, as the
	 * Attachment pages switch has it.
	 */
	public static function attachmentPagesOption() {

		return self::enabled( 'attachment_pages' ) ? '1' : '0';
	}

	/**
	 * attachment_link, with attachment pages off: the file, where WordPress
	 * would send the visitor anyway (redirect_canonical()).
	 */
	public static function linkToFile( $link, $post_id ) {

		return wp_get_attachment_url( $post_id ) ?: $link;
	}

	/**
	 * admin_enqueue_scripts: for the settings page, the state of Proxy Cache
	 * Purge and Relevanssi.
	 */
	public static function shareStatus() {

		if ( ! wp_script_is( 'photopress-options-script', 'registered' ) && ! wp_script_is( 'photopress-options-script', 'enqueued' ) ) {
			return;
		}

		$relevanssi = [ 'plugin' => photopress_util::pluginState( Relevanssi::active(), [ 'relevanssi/', 'relevanssi-premium/' ] ) ];
		$cache = [ 'plugin' => photopress_util::pluginState( PageCache::purges(), [ 'varnish-http-purge/' ] ) ];

		wp_add_inline_script( 'photopress-options-script', 'window.photopressRelevanssi = ' . wp_json_encode( $relevanssi ) . '; window.photopressPageCache = ' . wp_json_encode( $cache ) . ';', 'before' );
	}


	public function registerOptions() {

		return [

			'attachment_pages' => [
				'default_value' => true,
				'field'         => [
					'type'          => 'boolean',
					'title'         => 'Attachment pages',
					'page_name'     => 'search',
					'section'       => 'general',
					'description'   => 'Give each image a page of its own. Off, an image\'s page address goes to its file.',
					'label_for'     => 'Attachment pages',
					'error_message' => '',
				],
			],

			'images_in_search' => [
				'default_value' => false,
				'field'         => [
					'type'          => 'boolean',
					'title'         => 'Images in search results',
					'page_name'     => 'search',
					'section'       => 'general',
					'description'   => 'Find images when visitors search the site, by their titles, captions, alt text and terms.',
					'label_for'     => 'Images in search results',
					'error_message' => '',
				],
			],

			'posts_in_search' => [
				'default_value' => true,
				'field'         => [
					'type'          => 'boolean',
					'title'         => 'Posts and pages in search results',
					'page_name'     => 'search',
					'section'       => 'general',
					'description'   => 'With images in search results, find posts and pages too.',
					'label_for'     => 'Posts and pages in search results',
					'error_message' => '',
				],
			],

			'taxonomy_pages' => [
				'default_value' => true,
				'field'         => [
					'type'          => 'boolean',
					'title'         => 'Image taxonomy pages',
					'page_name'     => 'search',
					'section'       => 'general',
					'description'   => 'Give each term of an image taxonomy, such as a keyword or a camera, an archive page of its images.',
					'label_for'     => 'Image taxonomy pages',
					'error_message' => '',
				],
			],
		];
	}

	public function registerSettingsPages() {

		return [

			'search' => [

				'parent_slug'         => 'photopress-core-base',
				'title'               => 'Pages & Search',
				'menu_title'          => 'Pages & Search',
				'required_capability' => 'manage_options',
				'menu_slug'           => 'photopress-search',
				'description'         => 'Whether images and image taxonomy terms have pages of their own.',
				'sections'            => [
					'general' => [
						'id'          => 'general',
						'title'       => 'General',
						'description' => '',
					],
				],
				'noPhpRender'         => true,
			],
		];
	}
}
