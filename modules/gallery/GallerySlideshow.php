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
		$ids     = $gallery ? self::imageIds( $gallery, $post_id ) : [];

		if ( ! $ids ) {
			return '';
		}

		$size     = sanitize_key( $attributes['sizeSlug'] ?? 'large' ) ?: 'large';
		$captions = ! empty( $attributes['showCaptions'] );
		$offset   = max( 0, (int) ( $attributes['maxHeightOffset'] ?? 0 ) );
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

			$img = self::prepareImage( $img, 0 === $position, $offset );

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

		$caption_side = in_array( $attributes['captionPosition'] ?? '', [ 'left', 'right' ], true ) ? $attributes['captionPosition'] : 'below';
		$caption_max  = (int) ( $attributes['captionMaxWidth'] ?? 75 );

		$wrapper = get_block_wrapper_attributes( [
			'class'                     => 'is-effect-' . ( 'fade' === ( $attributes['effect'] ?? '' ) ? 'fade' : 'slide' ) . ' has-captions-' . $caption_side,
			'style'                     => sprintf(
				'--pp-slideshow-offset:%dpx;--pp-slideshow-caption-padding:%dpx',
				$offset,
				max( 0, (int) ( $attributes['captionPadding'] ?? 0 ) )
			) . ( $caption_max > 0 && $caption_max < 100 ? sprintf( ';--pp-slideshow-caption-max-width:%d%%', $caption_max ) : '' ),
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
	 *
	 * sizes says how wide the image is shown, which the browser picks from
	 * srcset by: at most the window's width on narrow screens, otherwise at
	 * most the height a slide allows times the image's shape. Never less
	 * than the width it is shown at, so the file is never too small; the
	 * front end replaces it with the measured width.
	 */
	private static function prepareImage( $html, $is_first, $offset = 0 ) {

		$p = new WP_HTML_Tag_Processor( $html );

		if ( ! $p->next_tag( 'img' ) ) {
			return $html;
		}

		$width  = (int) $p->get_attribute( 'width' );
		$height = (int) $p->get_attribute( 'height' );

		if ( $width && $height ) {
			$p->set_attribute( 'style', sprintf( 'aspect-ratio:%d/%d', $width, $height ) );
			$p->set_attribute( 'sizes', sprintf( '(max-width: 782px) 100vw, calc((100vh - %dpx) * %.4F)', $offset, $width / $height ) );
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
	 * render_block_core/gallery: hides a gallery that a Gallery Slideshow
	 * block on the same post shows with "Hide the gallery" on. Done here,
	 * rather than by the slideshow, so it does not matter which of the two
	 * comes first; and in the markup, so the gallery never shows while the
	 * page loads.
	 */
	public static function hideSourceGallery( $content, $block ) {

		$anchor = self::anchorOf( $block );
		$post_id = (int) get_the_ID();

		if ( '' === $anchor || ! $post_id || ! in_array( $anchor, self::hiddenGalleries( $post_id ), true ) ) {
			return $content;
		}

		$p = new WP_HTML_Tag_Processor( $content );

		if ( ! $p->next_tag() ) {
			return $content;
		}

		// core/gallery sets display: flex, which overrides the hidden
		// attribute; the class carries a display: none.
		$p->set_attribute( 'hidden', true );
		$p->add_class( 'photopress-hidden-gallery' );

		return $p->get_updated_html();
	}

	/**
	 * Anchors of the galleries that the post's Gallery Slideshow blocks hide.
	 *
	 * @return string[]
	 */
	public static function hiddenGalleries( $post_id ) {

		static $cache = [];

		if ( ! isset( $cache[ $post_id ] ) ) {

			$post = get_post( $post_id );
			$cache[ $post_id ] = ( $post && has_block( 'photopress/gallery-slideshow', $post ) )
				? self::collectHidden( parse_blocks( $post->post_content ) )
				: [];
		}

		return $cache[ $post_id ];
	}

	private static function collectHidden( array $blocks ) {

		$anchors = [];

		foreach ( $blocks as $block ) {

			if ( 'photopress/gallery-slideshow' === ( $block['blockName'] ?? '' ) && ! empty( $block['attrs']['hideGallery'] ) && ! empty( $block['attrs']['galleryAnchor'] ) ) {
				$anchors[] = (string) $block['attrs']['galleryAnchor'];
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$anchors = array_merge( $anchors, self::collectHidden( $block['innerBlocks'] ) );
			}
		}

		return $anchors;
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

	public static function anchorOf( array $block ) {

		if ( ! empty( $block['attrs']['anchor'] ) ) {
			return (string) $block['attrs']['anchor'];
		}

		$p = new WP_HTML_Tag_Processor( (string) ( $block['innerHTML'] ?? '' ) );

		return $p->next_tag() ? (string) $p->get_attribute( 'id' ) : '';
	}

	/**
	 * Attachment ids of a gallery's images, in gallery order.
	 *
	 * A dynamic gallery (WordPress 7.1) keeps no images in the post; core
	 * resolves its source when it renders, and is asked the same way here.
	 *
	 * @param array $gallery The parsed core/gallery block.
	 * @param int   $post_id The post it is in, for a dynamic gallery.
	 * @return int[]
	 */
	public static function imageIds( array $gallery, $post_id = 0 ) {

		if ( ! empty( $gallery['attrs']['dynamicContent'] ) ) {

			if ( ! $post_id || ! function_exists( 'block_core_gallery_resolve_dynamic_source' ) ) {
				return [];
			}

			// Core reads the post from the block's context.
			$block = (object) [ 'context' => [ 'postId' => (int) $post_id ] ];

			return array_values( array_unique( array_filter( array_map( 'intval', (array) block_core_gallery_resolve_dynamic_source( $gallery['attrs']['dynamicContent'], $block ) ) ) ) );
		}

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
