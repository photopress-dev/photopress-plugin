<?php

namespace PhotoPress\modules\gallery;

use PhotoPress\modules\metadata\metadata;

/**
 * Templates for block themes: image taxonomy archives (a keyword's, a
 * camera's) and image (attachment) pages. Registered in code from the files
 * in templates/; nothing is written to the theme or the database until the
 * template is edited in the Site Editor, which saves the edited copy for
 * the theme, and Reset there brings this one back.
 *
 * An archive of a taxonomy for images alone looks for taxonomy-attachment,
 * after taxonomy-{taxonomy}-{term} and taxonomy-{taxonomy} and before
 * taxonomy, as themes built on Frame (Ansel, Dorthea) do. An image page
 * already looks for attachment. A theme's own template of the same name, or
 * a more specific one, is used instead of these.
 *
 * Also the two blocks the templates are made of: Archive Images and
 * Attachment Image.
 */
class ImageTemplates {

	/**
	 * @param bool $register_templates The Gallery tab's Image templates switch.
	 */
	public static function addHooks( $register_templates ) {

		register_block_type( __DIR__ . '/blocks/archive-images', [
			'render_callback' => [ self::class, 'renderArchiveImages' ],
		] );
		register_block_type( __DIR__ . '/blocks/attachment-image', [
			'render_callback' => [ self::class, 'renderAttachmentImage' ],
		] );

		add_filter( 'taxonomy_template_hierarchy', [ self::class, 'addTaxonomyAttachment' ] );

		// register_block_template() is WordPress 6.7 and later.
		if ( $register_templates && function_exists( 'register_block_template' ) ) {
			self::registerTemplates();
		}
	}

	/**
	 * taxonomy_template_hierarchy: taxonomy-attachment before taxonomy, for
	 * the archives of taxonomies for images alone.
	 *
	 * @param string[] $templates
	 * @return string[]
	 */
	public static function addTaxonomyAttachment( $templates ) {

		$term = get_queried_object();

		if ( ! $term instanceof \WP_Term || ! metadata::areImageTaxonomies( [ $term->taxonomy ] ) ) {
			return $templates;
		}

		return self::withTaxonomyAttachment( (array) $templates );
	}

	/**
	 * The list with taxonomy-attachment.php before taxonomy.php (or last),
	 * unless a theme framework put it there already.
	 *
	 * @param string[] $templates
	 * @return string[]
	 */
	public static function withTaxonomyAttachment( array $templates ) {

		if ( in_array( 'taxonomy-attachment.php', $templates, true ) ) {
			return $templates;
		}

		$at = array_search( 'taxonomy.php', $templates, true );
		array_splice( $templates, false === $at ? count( $templates ) : $at, 0, [ 'taxonomy-attachment.php' ] );

		return $templates;
	}

	public static function registerTemplates() {

		$templates = [
			'taxonomy-attachment' => [
				'title'       => __( 'Image Archives', 'photopress' ),
				'description' => __( 'The archive pages of image taxonomies, such as a keyword’s or a camera’s: their images in a gallery layout. From PhotoPress.', 'photopress' ),
			],
			'attachment'          => [
				'title'       => __( 'Image Pages', 'photopress' ),
				'description' => __( 'An image’s own page: the image, its caption and description, its taxonomy terms and its exposure details. From PhotoPress.', 'photopress' ),
			],
		];

		foreach ( $templates as $slug => $args ) {

			$file = __DIR__ . '/templates/' . $slug . '.html';

			if ( is_readable( $file ) ) {
				register_block_template( 'photopress//' . $slug, $args + [ 'content' => (string) file_get_contents( $file ) ] );
			}
		}
	}

	/**
	 * render_callback of photopress/archive-images: the images of the main
	 * query, when it is an archive's, as a core/gallery block, so the
	 * PhotoPress layouts and slideshow apply as they do in a post, and links
	 * to the archive's other pages.
	 */
	public static function renderArchiveImages( $attributes ) {

		global $wp_query;

		if ( ! $wp_query instanceof \WP_Query || $wp_query->is_singular() ) {
			return '';
		}

		$ids = [];

		foreach ( (array) $wp_query->posts as $post ) {

			if ( $post instanceof \WP_Post && wp_attachment_is_image( $post ) ) {
				$ids[] = (int) $post->ID;
			}
		}

		if ( ! $ids ) {
			return '';
		}

		$gallery = do_blocks( self::galleryMarkup( $ids, $attributes ) );

		$pages = paginate_links( [
			'total'     => (int) $wp_query->max_num_pages,
			'current'   => max( 1, (int) get_query_var( 'paged' ) ),
			'prev_text' => __( 'Previous', 'photopress' ),
			'next_text' => __( 'Next', 'photopress' ),
		] );

		return sprintf(
			'<div %s>%s%s</div>',
			get_block_wrapper_attributes(),
			wp_filter_content_tags( $gallery ),
			$pages ? '<nav class="photopress-archive-images__pages" aria-label="' . esc_attr__( 'More images', 'photopress' ) . '">' . $pages . '</nav>' : ''
		);
	}

	/**
	 * A core/gallery block of the images, as the editor would save it.
	 *
	 * @param int[] $ids
	 * @param array $attributes The Archive Images block's.
	 */
	public static function galleryMarkup( array $ids, array $attributes ) {

		$size    = (string) ( $attributes['sizeSlug'] ?? 'large' );
		$link_to = in_array( $attributes['linkTo'] ?? '', [ 'attachment', 'media', 'none' ], true ) ? $attributes['linkTo'] : 'attachment';
		$layout  = in_array( $attributes['layout'] ?? '', gallery::LAYOUTS, true ) ? $attributes['layout'] : '';
		$images  = [];

		foreach ( $ids as $id ) {

			$src = wp_get_attachment_image_url( $id, $size ) ?: wp_get_attachment_url( $id );

			if ( ! $src ) {
				continue;
			}

			$img  = sprintf( '<img src="%s" alt="%s" class="wp-image-%d"/>', esc_url( $src ), esc_attr( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ), $id );
			$href = 'media' === $link_to ? wp_get_attachment_url( $id ) : ( 'attachment' === $link_to ? get_attachment_link( $id ) : '' );

			if ( $href ) {
				$img = sprintf( '<a href="%s">%s</a>', esc_url( $href ), $img );
			}

			$caption = ! empty( $attributes['showCaptions'] ) ? (string) wp_get_attachment_caption( $id ) : '';

			if ( '' !== trim( $caption ) ) {
				$img .= '<figcaption class="wp-element-caption">' . wp_kses_post( $caption ) . '</figcaption>';
			}

			$images[] = sprintf(
				'<!-- wp:image %s --><figure class="wp-block-image size-%s">%s</figure><!-- /wp:image -->',
				wp_json_encode( [ 'id' => $id, 'sizeSlug' => $size, 'linkDestination' => $link_to ] ),
				esc_attr( $size ),
				$img
			);
		}

		$gallery_attrs = [
			'linkTo'                => $link_to,
			'sizeSlug'              => $size,
			'photopressSlideshow'   => ! empty( $attributes['slideshow'] ),
			'photopressColumnWidth' => max( 100, (int) ( $attributes['columnWidth'] ?? 300 ) ),
			'photopressRowHeight'   => max( 100, (int) ( $attributes['rowHeight'] ?? 300 ) ),
		];

		if ( $layout ) {
			$gallery_attrs['photopressLayout'] = $layout;
		}

		return sprintf(
			'<!-- wp:gallery %s --><figure class="wp-block-gallery has-nested-images columns-default is-cropped">%s</figure><!-- /wp:gallery -->',
			wp_json_encode( $gallery_attrs ),
			implode( '', $images )
		);
	}

	/**
	 * render_callback of photopress/attachment-image: the image of the page,
	 * with srcset, and its caption. The rest of the page's content is then
	 * shown without the image WordPress puts before an attachment's
	 * description (prepend_attachment), which would show it twice.
	 *
	 * @param array     $attributes
	 * @param string    $content
	 * @param \WP_Block $block
	 */
	public static function renderAttachmentImage( $attributes, $content = '', $block = null ) {

		$id = ( $block && ! empty( $block->context['postId'] ) ) ? (int) $block->context['postId'] : (int) get_queried_object_id();

		if ( ! $id || ! wp_attachment_is_image( $id ) ) {
			return '';
		}

		remove_filter( 'the_content', 'prepend_attachment' );

		$size = (string) ( $attributes['sizeSlug'] ?? 'large' );
		$img  = wp_get_attachment_image( $id, $size, false, [ 'class' => 'wp-image-' . $id ] );

		if ( ! $img ) {
			return '';
		}

		if ( ! empty( $attributes['linkToFile'] ) ) {
			$img = sprintf( '<a href="%s">%s</a>', esc_url( wp_get_attachment_url( $id ) ), $img );
		}

		$caption = ! empty( $attributes['showCaption'] ) ? (string) wp_get_attachment_caption( $id ) : '';

		return sprintf(
			'<figure %s>%s%s</figure>',
			get_block_wrapper_attributes( [ 'class' => 'size-' . sanitize_html_class( $size ) ] ),
			$img,
			'' !== trim( $caption ) ? '<figcaption class="wp-element-caption">' . wp_kses_post( $caption ) . '</figcaption>' : ''
		);
	}
}
