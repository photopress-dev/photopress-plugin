<?php

namespace PhotoPress\modules\gallery;

defined( 'ABSPATH' ) || exit;

/**
 * The legacy Child Pages block (photopress/childpages), removed in favor of
 * Child Pages Gallery. GalleryUpgrade turns each one saved in a post into a
 * Child Pages Gallery, with its columns, image size, crop, alignment and
 * spacing. One the upgrade did not reach (in a theme's template file, or
 * content imported later) is rendered as that gallery would be.
 */
class LegacyChildPages {

	const NAME = 'photopress/childpages';

	/** What marks the block, in a post's content. */
	const MARKER = '<!-- wp:photopress/childpages';

	public static function addHooks() {

		add_filter( 'pre_render_block', [ self::class, 'render' ], 10, 2 );
	}

	/** pre_render_block: the legacy block, as its Child Pages Gallery. */
	public static function render( $pre, $parsed ) {

		if ( null !== $pre || self::NAME !== ( $parsed['blockName'] ?? '' ) ) {
			return $pre;
		}

		return ImageTemplates::renderSourcedGallery( null, self::convert( $parsed ) );
	}

	/**
	 * The Child Pages Gallery for a legacy block, as the block editor saves
	 * one, and as the block's transform makes it (src/blocks/child_pages
	 * until 1.10). The legacy block's defaults were 2 columns, the medium
	 * size, cropped, and 10px between images.
	 *
	 * @param array $block The parsed legacy block.
	 * @return array The parsed core/gallery block.
	 */
	public static function convert( array $block ) {

		$old  = (array) ( $block['attrs'] ?? [] );
		$crop = false !== ( $old['imageCrop'] ?? true );

		$attrs = [
			'photopressSource' => 'children',
			'columns'          => max( 1, (int) ( $old['columns'] ?? 2 ) ),
			'sizeSlug'         => (string) ( $old['imageSize'] ?? '' ) ?: 'medium',
			'style'            => [ 'spacing' => [ 'blockGap' => max( 0, (int) ( $old['padding'] ?? 10 ) ) . 'px' ] ],
		];

		$classes = [ 'wp-block-gallery' ];

		if ( ! empty( $old['align'] ) ) {
			$attrs['align'] = (string) $old['align'];
			$classes[] = 'align' . sanitize_html_class( $attrs['align'] );
		}

		$classes[] = 'has-nested-images';
		$classes[] = 'columns-' . $attrs['columns'];

		if ( $crop ) {
			$classes[] = 'is-cropped';
		} else {
			$attrs['imageCrop'] = false;
		}

		if ( ! empty( $old['className'] ) ) {
			$attrs['className'] = (string) $old['className'];
			$classes[] = $attrs['className'];
		}

		$html = '<figure class="' . esc_attr( implode( ' ', $classes ) ) . '"></figure>';

		return [
			'blockName'    => 'core/gallery',
			'attrs'        => $attrs,
			'innerBlocks'  => [],
			'innerHTML'    => $html,
			'innerContent' => [ $html ],
		];
	}

	/**
	 * These blocks with each legacy block, at any depth, converted.
	 *
	 * @param array $blocks    Parsed blocks.
	 * @param int   $converted Counts the blocks converted.
	 */
	public static function convertBlocks( array $blocks, &$converted = 0 ) {

		foreach ( $blocks as $index => $block ) {

			if ( self::NAME === ( $block['blockName'] ?? '' ) ) {
				$blocks[ $index ] = self::convert( $block );
				++$converted;
				continue;
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$blocks[ $index ]['innerBlocks'] = self::convertBlocks( $block['innerBlocks'], $converted );
			}
		}

		return $blocks;
	}
}
