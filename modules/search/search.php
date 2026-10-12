<?php

namespace PhotoPress\modules\search;
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
	 * @param string $key attachment_pages or taxonomy_pages.
	 */
	public static function enabled( $key ) {

		$saved = get_option( self::OPTION );

		return ! is_array( $saved ) || ! array_key_exists( $key, $saved ) || ! empty( $saved[ $key ] );
	}

	public function definePublicHooks() {

		add_filter( 'pre_option_wp_attachment_pages_enabled', [ self::class, 'attachmentPagesOption' ] );

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
