<?php

namespace PhotoPress\modules\seo;

defined( 'ABSPATH' ) || exit;

use photopress_module;
use PhotoPress\modules\search\ImageSitemap;
use PhotoPress\modules\search\PageCache;

/**
 * SEO Module
 *
 * The SEO settings tab: the sitemap in use, which PhotoPress lists image and
 * image taxonomy term pages in (modules/search, which decides whether they
 * have pages), and archives a photography site rarely needs, author, date
 * and post format archives, each to be turned off (Archives).
 *
 * Changing a switch empties the page cache, as the Pages & Search tab's do
 * (PageCache::settingsChanged()), so archives turned off are not still
 * served from it.
 */
class seo extends photopress_module {

	public $label = 'SEO';

	const OPTION = 'photopress_core_seo';

	/**
	 * Whether a switch of this tab is on: its saved value, or its default
	 * when it was never saved. Read from the option itself, so other code
	 * can ask before this module has registered its defaults.
	 *
	 * @param string $key     archives_off, author_archives_off,
	 *                        date_archives_off or format_archives_off.
	 * @param bool   $default When it was never saved.
	 */
	public static function enabled( $key, $default = true ) {

		$saved = get_option( self::OPTION );

		if ( is_array( $saved ) && array_key_exists( $key, $saved ) ) {
			return ! empty( $saved[ $key ] );
		}

		return $default;
	}

	public function definePublicHooks() {

		Archives::addHooks();
		add_action( 'add_option_' . self::OPTION, static fn( $option, $value ) => PageCache::settingsChanged( [], $value ), 10, 2 );
		add_action( 'update_option_' . self::OPTION, [ PageCache::class, 'settingsChanged' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ self::class, 'shareStatus' ], 20 );
	}

	/** admin_enqueue_scripts: for the settings page, the sitemap in use. */
	public static function shareStatus() {

		if ( ! wp_script_is( 'photopress-options-script', 'registered' ) && ! wp_script_is( 'photopress-options-script', 'enqueued' ) ) {
			return;
		}

		wp_add_inline_script( 'photopress-options-script', 'window.photopressSitemap = ' . wp_json_encode( ImageSitemap::inUse() ) . ';', 'before' );
	}

	public function registerOptions() {

		return [

			'archives_off' => [
				'default_value' => false,
				'field'         => [
					'type'          => 'boolean',
					'title'         => 'Unneeded archives',
					'page_name'     => 'seo',
					'section'       => 'general',
					'description'   => 'Disable archives a photography site rarely needs. Their addresses redirect to the home page.',
					'label_for'     => 'Unneeded archives',
					'error_message' => '',
				],
			],

			'author_archives_off' => [
				'default_value' => true,
				'field'         => [
					'type'          => 'boolean',
					'title'         => 'Author archives',
					'page_name'     => 'seo',
					'section'       => 'general',
					'description'   => 'Each author\'s posts, at an address that shows the author\'s login name.',
					'label_for'     => 'Author archives',
					'error_message' => '',
				],
			],

			'date_archives_off' => [
				'default_value' => true,
				'field'         => [
					'type'          => 'boolean',
					'title'         => 'Date archives',
					'page_name'     => 'seo',
					'section'       => 'general',
					'description'   => 'Posts by year, month and day.',
					'label_for'     => 'Date archives',
					'error_message' => '',
				],
			],

			'format_archives_off' => [
				'default_value' => true,
				'field'         => [
					'type'          => 'boolean',
					'title'         => 'Post format archives',
					'page_name'     => 'seo',
					'section'       => 'general',
					'description'   => 'Posts of one format, such as Gallery or Image.',
					'label_for'     => 'Post format archives',
					'error_message' => '',
				],
			],
		];
	}

	public function registerSettingsPages() {

		return [

			'seo' => [

				'parent_slug'         => 'photopress-core-base',
				'title'               => 'SEO',
				'menu_title'          => 'SEO',
				'required_capability' => 'manage_options',
				'menu_slug'           => 'photopress-core-seo',
				'description'         => 'The sitemap, and archives a photography site rarely needs.',
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
