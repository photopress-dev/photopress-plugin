<?php

namespace PhotoPress\modules\gallery;

defined( 'ABSPATH' ) || exit;

use PhotoPress\modules\metadata\ImageTaxonomies;
use PhotoPress\modules\metadata\metadata;
use PhotoPress\modules\search\search;

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
 * a more specific one, is used instead of these. Each is there while its
 * kind of page is on in the Pages & Search tab.
 *
 * Also what the templates are made of: Taxonomy Gallery, a core/gallery
 * variation filled here with images (photopressSource "archive", the
 * archive page's; "query", those of a chosen taxonomy term, or the latest),
 * and the Attachment Image block. And Child Pages Gallery, the same
 * variation filled with the child pages of the page it is on
 * (photopressSource "children").
 */
class ImageTemplates {

	public static function addHooks() {

		add_filter( 'pre_render_block', [ self::class, 'renderSourcedGallery' ], 10, 2 );
		register_block_type( __DIR__ . '/blocks/attachment-image', [
			'render_callback' => [ self::class, 'renderAttachmentImage' ],
		] );

		add_filter( 'taxonomy_template_hierarchy', [ self::class, 'addTaxonomyAttachment' ] );

		// register_block_template() is WordPress 6.7 and later.
		if ( function_exists( 'register_block_template' ) ) {
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

	/**
	 * The templates for the kinds of page turned on in the Pages & Search
	 * tab: image taxonomy archives and image pages.
	 *
	 * @return string[] Template slugs.
	 */
	public static function enabledTemplates() {

		$enabled = [];

		if ( search::enabled( 'taxonomy_pages' ) ) {
			$enabled[] = 'taxonomy-attachment';
		}

		if ( search::enabled( 'attachment_pages' ) ) {
			$enabled[] = 'attachment';
		}

		return $enabled;
	}

	public static function registerTemplates() {

		$templates = array_intersect_key( [
			'taxonomy-attachment' => [
				'title'       => __( 'Image Archives', 'photopress' ),
				'description' => __( 'The archive pages of image taxonomies, such as a keyword’s or a camera’s: their images in a gallery layout. From PhotoPress.', 'photopress' ),
			],
			'attachment'          => [
				'title'       => __( 'Image Pages', 'photopress' ),
				'description' => __( 'An image’s own page: the image, its caption and description, its taxonomy terms and its exposure details. From PhotoPress.', 'photopress' ),
			],
		], array_flip( self::enabledTemplates() ) );

		foreach ( $templates as $slug => $args ) {

			$file = __DIR__ . '/templates/' . $slug . '.html';

			if ( is_readable( $file ) ) {
				register_block_template( 'photopress//' . $slug, $args + [ 'content' => (string) file_get_contents( $file ) ] );
			}
		}
	}

	/**
	 * The Taxonomy Gallery settings, which are not the rendered gallery's:
	 * its query, its heading, and its details under each image.
	 */
	const QUERY_ATTRIBUTES = [ 'photopressSource', 'photopressTaxonomy', 'photopressTerm', 'photopressCount', 'photopressOrder', 'photopressShowHeading', 'photopressHeading', 'photopressDetails' ];

	/** Where a gallery's images can come from when the page is viewed. */
	const SOURCES = [ 'archive', 'query', 'children' ];

	/**
	 * pre_render_block: a core/gallery with a photopressSource, as a gallery
	 * of the images it finds. Taxonomy Gallery: with "archive", the main
	 * query's when it is an archive's, with links to the archive's other
	 * pages; with "query", those queryArgs() gives. Child Pages Gallery,
	 * "children": an image for each child page (childPageItems()). The
	 * gallery is rendered as core renders one saved with those images, so
	 * the PhotoPress layouts, slideshow and hidden captions apply as they do
	 * in a post; with its heading above, when that is on. With no images,
	 * nothing.
	 *
	 * @param string|null $pre    Markup another filter rendered, or null.
	 * @param array       $parsed The parsed block.
	 */
	public static function renderSourcedGallery( $pre, $parsed ) {

		$source = $parsed['attrs']['photopressSource'] ?? '';

		if ( null !== $pre || 'core/gallery' !== ( $parsed['blockName'] ?? '' ) || ! in_array( $source, self::SOURCES, true ) ) {
			return $pre;
		}

		$attributes = (array) $parsed['attrs'];
		$pages      = '';

		if ( 'children' === $source ) {

			$items = self::childPageItems( $attributes );

		} elseif ( 'query' === $source ) {

			$args  = self::queryArgs( $attributes );
			$items = self::imageItems( $args ? array_map( 'intval', ( new \WP_Query( $args ) )->posts ) : [], $attributes );

		} else {

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

			$items = self::imageItems( $ids, $attributes );

			$links = $items ? paginate_links( [
				'total'     => (int) $wp_query->max_num_pages,
				'current'   => max( 1, (int) get_query_var( 'paged' ) ),
				'prev_text' => __( 'Previous', 'photopress' ),
				'next_text' => __( 'Next', 'photopress' ),
			] ) : '';

			if ( $links ) {
				$pages = '<nav class="photopress-archive-images__pages" aria-label="' . esc_attr__( 'More images', 'photopress' ) . '">' . $links . '</nav>';
			}
		}

		if ( ! $items ) {
			return '';
		}

		$heading = empty( $attributes['photopressShowHeading'] ) ? '' : trim( (string) ( $attributes['photopressHeading'] ?? __( 'Images', 'photopress' ) ) );

		return ( '' !== $heading ? '<h2 class="wp-block-heading photopress-gallery-heading">' . esc_html( $heading ) . '</h2>' : '' )
			. wp_filter_content_tags( do_blocks( self::galleryMarkup( $items, $attributes ) ) ) . $pages;
	}

	/**
	 * The child pages of the page being viewed, published, in page order
	 * (up to 100): each as its featured image, or else the first image of
	 * its first gallery (gallery::firstImage()), linked to it, with its
	 * title as the caption. One with neither is left out.
	 *
	 * @param array $attributes The gallery's.
	 * @return array[] As imageItems() gives them.
	 */
	public static function childPageItems( array $attributes ) {

		$parent = (int) ( get_the_ID() ?: get_queried_object_id() );

		if ( ! $parent || ! is_post_type_hierarchical( (string) get_post_type( $parent ) ) ) {
			return [];
		}

		$children = get_posts( [
			'post_type'   => get_post_type( $parent ),
			'post_parent' => $parent,
			'post_status' => 'publish',
			'orderby'     => [ 'menu_order' => 'ASC', 'title' => 'ASC' ],
			'numberposts' => 100,
		] );

		$items = [];

		foreach ( $children as $child ) {

			$id = (int) get_post_thumbnail_id( $child ) ?: gallery::firstImage( $child );

			if ( ! $id ) {
				continue;
			}

			$href  = (string) get_permalink( $child );
			$title = get_the_title( $child );

			$items[] = [
				'id'         => $id,
				'href'       => $href,
				'link'       => 'custom',
				'figcaption' => '' === $title ? '' : sprintf( '<figcaption class="wp-element-caption"><a href="%s">%s</a></figcaption>', esc_url( $href ), esc_html( $title ) ),
			];
		}

		return $items;
	}

	/**
	 * Images as gallery items, linked as the gallery says: to their page,
	 * their file, or not; captioned with their caption, or, with
	 * photopressDetails, their file name and title, as a search result.
	 *
	 * @param int[] $ids
	 * @param array $attributes The gallery's.
	 * @return array[] id, href, link (the image block's linkDestination) and figcaption.
	 */
	public static function imageItems( array $ids, array $attributes ) {

		$link_to = in_array( $attributes['linkTo'] ?? '', [ 'attachment', 'media', 'none' ], true ) ? $attributes['linkTo'] : 'attachment';
		$items   = [];

		foreach ( $ids as $id ) {

			$href = 'media' === $link_to ? wp_get_attachment_url( $id ) : ( 'attachment' === $link_to ? get_attachment_link( $id ) : '' );

			if ( ! empty( $attributes['photopressDetails'] ) ) {

				$title      = get_the_title( $id );
				$figcaption = sprintf(
					'<figcaption class="wp-element-caption photopress-details"><span class="photopress-details__file">%s</span>%s</figcaption>',
					esc_html( wp_basename( (string) get_attached_file( $id ) ) ),
					'' === $title ? '' : ( $href
						? sprintf( '<a class="photopress-details__title" href="%s">%s</a>', esc_url( $href ), esc_html( $title ) )
						: '<span class="photopress-details__title">' . esc_html( $title ) . '</span>' )
				);

			} else {

				$caption    = (string) wp_get_attachment_caption( $id );
				$figcaption = '' === trim( $caption ) ? '' : '<figcaption class="wp-element-caption">' . wp_kses_post( $caption ) . '</figcaption>';
			}

			$items[] = [ 'id' => (int) $id, 'href' => (string) $href, 'link' => $link_to, 'figcaption' => $figcaption ];
		}

		return $items;
	}

	/**
	 * The query of a Taxonomy Gallery set to "query": its number of
	 * public images, newest first or in the order chosen, with the chosen
	 * term of an image taxonomy (and the terms under it), or any.
	 *
	 * @param array $attributes The gallery's.
	 * @return array|null WP_Query arguments; null for a taxonomy visitors may
	 *                    not see, or one not for images.
	 */
	public static function queryArgs( array $attributes ) {

		$orders = [
			'date-desc' => [ 'date', 'DESC' ],
			'date-asc'  => [ 'date', 'ASC' ],
			'title'     => [ 'title', 'ASC' ],
			'rand'      => [ 'rand', 'ASC' ],
		];

		[ $orderby, $order ] = $orders[ $attributes['photopressOrder'] ?? '' ] ?? $orders['date-desc'];

		$args = [
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'post_mime_type'         => 'image',
			'posts_per_page'         => min( 100, max( 1, (int) ( $attributes['photopressCount'] ?? 12 ) ) ),
			'orderby'                => $orderby,
			'order'                  => $order,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			metadata::PUBLIC_IMAGES  => true,
		];

		$taxonomy = (string) ( $attributes['photopressTaxonomy'] ?? '' );
		$term     = (int) ( $attributes['photopressTerm'] ?? 0 );

		if ( '' === $taxonomy || ! $term ) {
			return $args;
		}

		if ( ! ImageTaxonomies::isShownImageTaxonomy( $taxonomy ) ) {
			return null;
		}

		$args['tax_query'] = [ [ 'taxonomy' => $taxonomy, 'field' => 'term_id', 'terms' => $term ] ];

		return $args;
	}

	/**
	 * The gallery as a core/gallery block saved with these items, as the
	 * editor would save it: its own attributes (layout, slideshow, alignment
	 * and so on) less the query, and an image block for each item, sized as
	 * the gallery says, with its link and caption.
	 *
	 * @param array[] $items      id, href, link and figcaption (imageItems()).
	 * @param array   $attributes The gallery's.
	 */
	public static function galleryMarkup( array $items, array $attributes ) {

		$size    = (string) ( $attributes['sizeSlug'] ?? 'large' );
		$link_to = in_array( $attributes['linkTo'] ?? '', [ 'attachment', 'media', 'none' ], true ) ? $attributes['linkTo'] : 'attachment';
		$images  = [];

		foreach ( $items as $item ) {

			$id  = (int) $item['id'];
			$src = wp_get_attachment_image_url( $id, $size ) ?: wp_get_attachment_url( $id );

			if ( ! $src ) {
				continue;
			}

			$img = sprintf( '<img src="%s" alt="%s" class="wp-image-%d"/>', esc_url( $src ), esc_attr( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ), $id );

			if ( '' !== (string) ( $item['href'] ?? '' ) ) {
				$img = sprintf( '<a href="%s">%s</a>', esc_url( $item['href'] ), $img );
			}

			$images[] = sprintf(
				'<!-- wp:image %s --><figure class="wp-block-image size-%s">%s</figure><!-- /wp:image -->',
				wp_json_encode( [ 'id' => $id, 'sizeSlug' => $size, 'linkDestination' => $item['link'] ?? 'none' ] ),
				esc_attr( $size ),
				$img . ( $item['figcaption'] ?? '' )
			);
		}

		$gallery_attrs = array_diff_key( $attributes, array_flip( self::QUERY_ATTRIBUTES ) );
		$gallery_attrs['linkTo']   = $link_to;
		$gallery_attrs['sizeSlug'] = $size;

		if ( isset( $gallery_attrs['photopressLayout'] ) && ! in_array( $gallery_attrs['photopressLayout'], gallery::LAYOUTS, true ) ) {
			unset( $gallery_attrs['photopressLayout'] );
		}

		// The classes the editor saves on the figure for these attributes.
		$classes = [ 'wp-block-gallery', 'has-nested-images', isset( $attributes['columns'] ) ? 'columns-' . (int) $attributes['columns'] : 'columns-default' ];

		if ( $attributes['imageCrop'] ?? true ) {
			$classes[] = 'is-cropped';
		}

		if ( ! empty( $attributes['align'] ) ) {
			$classes[] = 'align' . sanitize_html_class( $attributes['align'] );
		}

		if ( ! empty( $attributes['photopressDetails'] ) ) {
			$classes[] = 'photopress-has-details';
		}

		if ( ! empty( $attributes['className'] ) ) {
			$classes[] = trim( (string) $attributes['className'] );
		}

		return sprintf(
			'<!-- wp:gallery %s --><figure class="%s"%s>%s</figure><!-- /wp:gallery -->',
			wp_json_encode( $gallery_attrs ),
			esc_attr( implode( ' ', $classes ) ),
			! empty( $attributes['anchor'] ) ? ' id="' . esc_attr( $attributes['anchor'] ) . '"' : '',
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
