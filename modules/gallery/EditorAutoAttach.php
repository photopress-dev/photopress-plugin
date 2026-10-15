<?php

namespace PhotoPress\modules\gallery;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the block editor from attaching images to a post when it is saved.
 *
 * From WordPress 7.2 (Gutenberg 24.0, PR #81977), saving a post in the block
 * editor attaches each unattached image in its Image and Gallery blocks to
 * the post. Attaching an image moves its attachment page from /image/ to
 * /post/image/, and the old URL is then not found, with no redirect.
 *
 * The photopress_editor_auto_attach filter, returning true, lets the editor
 * attach images again.
 */
class EditorAutoAttach {

	/** The block editor setting that turns the attaching on or off. */
	const SETTING = 'autoAttachMediaEnabled';

	public static function addHooks() {

		add_filter( 'block_editor_settings_all', [ self::class, 'setting' ] );
	}

	/**
	 * block_editor_settings_all: the setting is off unless the
	 * photopress_editor_auto_attach filter turns it on.
	 */
	public static function setting( $settings ) {

		if ( is_array( $settings ) ) {
			$settings[ self::SETTING ] = (bool) apply_filters( 'photopress_editor_auto_attach', false );
		}

		return $settings;
	}
}
