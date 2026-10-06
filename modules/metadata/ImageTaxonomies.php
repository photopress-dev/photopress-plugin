<?php

namespace PhotoPress\modules\metadata;

/**
 * The taxonomy terms of an image, as shown by the Image Taxonomies block and
 * the "Display Taxonomies (PhotoPress)" widget. Both produce this markup; the
 * class names are the widget's, which the plugin's CSS and themes style.
 */
class ImageTaxonomies {

	/**
	 * Every taxonomy registered for attachments, in registration order. Shown
	 * when no taxonomies are chosen.
	 *
	 * @return string[]
	 */
	public static function allTaxonomies() {

		return array_values( get_object_taxonomies( 'attachment', 'names' ) );
	}

	/**
	 * Parses the widget's comma-separated taxonomy setting.
	 *
	 * @return string[]
	 */
	public static function parseList( $list ) {

		return array_values( array_filter( array_map( 'trim', explode( ',', (string) $list ) ), 'strlen' ) );
	}

	/**
	 * One .container row per taxonomy that has terms for the post.
	 *
	 * @param int      $post_id     The image (attachment) or post.
	 * @param string[] $taxonomies  Taxonomy names, in display order; empty for all.
	 * @param bool     $link        Link each term to its archive.
	 * @param bool     $show_labels Show the taxonomy label before its terms.
	 * @return string HTML, empty when there are no terms.
	 */
	public static function renderRows( $post_id, array $taxonomies, $link = true, $show_labels = true ) {

		if ( ! $post_id ) {
			return '';
		}

		$taxonomies = $taxonomies ? $taxonomies : self::allTaxonomies();
		$rows = '';

		foreach ( $taxonomies as $name ) {

			if ( ! taxonomy_exists( $name ) ) {
				continue;
			}

			$terms = get_the_terms( $post_id, $name );

			if ( ! $terms || is_wp_error( $terms ) ) {
				continue;
			}

			$names = [];

			foreach ( $terms as $term ) {

				if ( $link ) {

					$url = get_term_link( $term, $name );

					if ( ! is_wp_error( $url ) ) {
						$names[] = sprintf( '<a href="%s" rel="tag">%s</a>', esc_url( $url ), esc_html( $term->name ) );
						continue;
					}
				}

				$names[] = esc_html( $term->name );
			}

			$label = $show_labels
				? '<div class="label">' . esc_html( get_taxonomy( $name )->label ) . ': </div>'
				: '';

			$rows .= '<div class="container">' . $label . '<div class="terms">' . implode( ', ', $names ) . '</div></div>';
		}

		return $rows;
	}

	/**
	 * render_callback of the photopress/image-taxonomies block.
	 *
	 * @param array     $attributes Block attributes.
	 * @param string    $content    Unused; the block saves no markup.
	 * @param \WP_Block $block      Gives the post through context.
	 */
	public static function renderBlock( $attributes, $content = '', $block = null ) {

		$post_id = ( $block && ! empty( $block->context['postId'] ) ) ? (int) $block->context['postId'] : (int) get_the_ID();

		$rows = self::renderRows(
			$post_id,
			array_values( array_filter( (array) ( $attributes['taxonomies'] ?? [] ), 'is_string' ) ),
			! empty( $attributes['linkTerms'] ),
			! empty( $attributes['showLabels'] )
		);

		if ( '' === $rows ) {
			return '';
		}

		return sprintf(
			'<div %s>%s</div>',
			get_block_wrapper_attributes( [ 'class' => 'display-taxonomy-terms-widget' ] ),
			$rows
		);
	}
}
