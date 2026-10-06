<?php

namespace PhotoPress\modules\gallery;

use WP_HTML_Tag_Processor;

/**
 * The Gallery Slideshow block: an inline slideshow of the images in a
 * core/gallery on the same page, chosen by the gallery's HTML anchor.
 *
 * The images are read from the post at render time, so the slideshow always
 * matches its gallery. The front end (src/frontend/gallery-slideshow.js) adds
 * the navigation, and makes a click on a gallery image show that image here.
 */
class GallerySlideshow {

	/**
	 * Slides beyond the first carry their image in data-src/data-srcset and
	 * are loaded by the script as they come near, rather than all at once.
	 */
	const PLACEHOLDER = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

	/**
	 * render_callback of photopress/gallery-slideshow.
	 */
	public static function render( $attributes, $content = '', $block = null ) {

		$post_id = ( $block && ! empty( $block->context['postId'] ) ) ? (int) $block->context['postId'] : (int) get_the_ID();
		$anchor  = (string) ( $attributes['galleryAnchor'] ?? '' );
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( '' === $anchor || ! $post ) {
			return '';
		}

		$gallery = self::findGallery( parse_blocks( $post->post_content ), $anchor );
		$ids     = $gallery ? self::imageIds( $gallery ) : [];

		if ( ! $ids ) {
			return '';
		}

		$size     = sanitize_key( $attributes['sizeSlug'] ?? 'large' ) ?: 'large';
		$captions = ! empty( $attributes['showCaptions'] );
		$slides   = '';
		$position = 0;

		foreach ( $ids as $id ) {

			$img = wp_get_attachment_image( $id, $size, false, [
				'class'    => 'photopress-gallery-slideshow__image',
				'loading'  => 0 === $position ? 'eager' : false,
				'decoding' => 'async',
			] );

			if ( ! $img ) {
				continue;
			}

			$img = self::prepareImage( $img, 0 === $position );

			$caption = $captions ? wp_get_attachment_caption( $id ) : '';
			$caption = $caption ? '<figcaption class="photopress-gallery-slideshow__caption wp-element-caption">' . wp_kses_post( $caption ) . '</figcaption>' : '';

			$slides .= sprintf(
				'<figure class="photopress-gallery-slideshow__slide%s" data-id="%d" role="group" aria-roledescription="%s">%s%s</figure>',
				0 === $position ? ' is-current' : '',
				$id,
				esc_attr__( 'slide', 'photopress' ),
				$img,
				$caption
			);

			$position++;
		}

		if ( ! $position ) {
			return '';
		}

		$wrapper = get_block_wrapper_attributes( [
			'class'                     => 'is-effect-' . ( 'fade' === ( $attributes['effect'] ?? '' ) ? 'fade' : 'slide' ),
			'style'                     => sprintf( '--pp-slideshow-offset:%dpx', max( 0, (int) ( $attributes['maxHeightOffset'] ?? 0 ) ) ),
			'data-gallery'              => $anchor,
			'data-autoplay'             => ! empty( $attributes['autoplay'] ) ? '1' : '0',
			'data-delay'                => (string) max( 1, (float) ( $attributes['delay'] ?? 3 ) ),
			'data-gallery-navigation'   => ! empty( $attributes['galleryNavigation'] ) ? '1' : '0',
			'data-scroll-behavior'      => 'smooth' === ( $attributes['scrollBehavior'] ?? '' ) ? 'smooth' : 'instant',
			'role'                      => 'region',
			'aria-roledescription'      => __( 'slideshow', 'photopress' ),
			'aria-label'                => __( 'Slideshow', 'photopress' ),
			'tabindex'                  => '0',
		] );

		$controls = sprintf(
			'<button type="button" class="photopress-gallery-slideshow__prev" aria-label="%s"></button>'
			. '<button type="button" class="photopress-gallery-slideshow__next" aria-label="%s"></button>'
			. '<button type="button" class="photopress-gallery-slideshow__return" aria-label="%s" hidden></button>'
			. '<p class="photopress-gallery-slideshow__status screen-reader-text" aria-live="polite"></p>',
			esc_attr__( 'Previous image', 'photopress' ),
			esc_attr__( 'Next image', 'photopress' ),
			esc_attr__( 'Return to gallery image', 'photopress' )
		);

		return sprintf( '<div %s><div class="photopress-gallery-slideshow__track">%s</div>%s</div>', $wrapper, $slides, $controls );
	}

	/**
	 * Fixes the image's shape with aspect-ratio, so slides size themselves
	 * before their image loads, and defers the image of every slide but the
	 * first.
	 */
	private static function prepareImage( $html, $is_first ) {

		$p = new WP_HTML_Tag_Processor( $html );

		if ( ! $p->next_tag( 'img' ) ) {
			return $html;
		}

		$width  = (int) $p->get_attribute( 'width' );
		$height = (int) $p->get_attribute( 'height' );

		if ( $width && $height ) {
			$p->set_attribute( 'style', sprintf( 'aspect-ratio:%d/%d', $width, $height ) );
		}

		if ( ! $is_first ) {

			foreach ( [ 'src', 'srcset' ] as $name ) {

				$value = $p->get_attribute( $name );

				if ( is_string( $value ) && '' !== $value ) {
					$p->set_attribute( 'data-' . $name, $value );
					$p->remove_attribute( $name );
				}
			}

			$p->set_attribute( 'src', self::PLACEHOLDER );
		}

		return $p->get_updated_html();
	}

	/**
	 * The core/gallery whose anchor is $anchor, at any depth.
	 *
	 * The anchor is not in the block comment: WordPress keeps it as the id
	 * attribute of the gallery's markup, so it is read from there.
	 */
	public static function findGallery( array $blocks, $anchor ) {

		foreach ( $blocks as $block ) {

			if ( 'core/gallery' === ( $block['blockName'] ?? '' ) && self::anchorOf( $block ) === $anchor ) {
				return $block;
			}

			if ( ! empty( $block['innerBlocks'] ) ) {

				$found = self::findGallery( $block['innerBlocks'], $anchor );

				if ( $found ) {
					return $found;
				}
			}
		}

		return null;
	}

	private static function anchorOf( array $block ) {

		if ( ! empty( $block['attrs']['anchor'] ) ) {
			return (string) $block['attrs']['anchor'];
		}

		$p = new WP_HTML_Tag_Processor( (string) ( $block['innerHTML'] ?? '' ) );

		return $p->next_tag() ? (string) $p->get_attribute( 'id' ) : '';
	}

	/**
	 * Attachment ids of a gallery's images, in gallery order.
	 *
	 * @return int[]
	 */
	public static function imageIds( array $gallery ) {

		$ids = [];

		foreach ( $gallery['innerBlocks'] ?? [] as $image ) {

			if ( 'core/image' === ( $image['blockName'] ?? '' ) && ! empty( $image['attrs']['id'] ) ) {
				$ids[] = (int) $image['attrs']['id'];
			}
		}

		// Galleries from before WordPress 5.9 list their images in ids.
		if ( ! $ids && ! empty( $gallery['attrs']['ids'] ) ) {
			$ids = array_map( 'intval', (array) $gallery['attrs']['ids'] );
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}
}
