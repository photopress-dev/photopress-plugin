<?php

namespace PhotoPress\modules\metadata;

/**
 * The metadata of an image, as shown by the Image Metadata block (once
 * Image Taxonomies, photopress/image-taxonomies) and the two PhotoPress
 * widgets: its taxonomy terms, and the exposure details WordPress read from
 * its file (exif:aperture and the like). All produce this markup; the class
 * names are the taxonomy widget's, which the plugin's CSS and themes style.
 */
class ImageTaxonomies {

	/**
	 * Rows from the file's EXIF data, as WordPress stores it in the image's
	 * metadata (image_meta), by their key in a list of rows.
	 */
	const EXIF_PREFIX = 'exif:';

	/**
	 * The exposure details a list of rows can name, with their labels.
	 *
	 * @return string[] image_meta key => label.
	 */
	public static function exifFields() {

		return [
			'camera'            => __( 'Camera model', 'photopress' ),
			'aperture'          => __( 'Aperture', 'photopress' ),
			'shutter_speed'     => __( 'Shutter speed', 'photopress' ),
			'iso'               => __( 'ISO', 'photopress' ),
			'focal_length'      => __( 'Focal length', 'photopress' ),
			'created_timestamp' => __( 'Date taken', 'photopress' ),
		];
	}

	/**
	 * An exposure detail as it is written: f/2.8, 1/250 s, 50 mm.
	 *
	 * @param string $key   image_meta key.
	 * @param mixed  $value Its stored value.
	 * @return string Plain text, empty when there is nothing to show.
	 */
	public static function formatExif( $key, $value ) {

		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		if ( '' === $value || ( is_numeric( $value ) && 0.0 === (float) $value ) ) {
			return '';
		}

		// Without trailing zeros: 2.8, 8, 50.
		$number = static function ( $n, $places ) {
			$text = number_format( (float) $n, $places, '.', '' );
			return false === strpos( $text, '.' ) ? $text : rtrim( rtrim( $text, '0' ), '.' );
		};

		switch ( $key ) {

			case 'aperture':
				return 'f/' . $number( $value, 1 );

			case 'shutter_speed':
				// A fraction of a second as cameras show it, 1/250, where it is one.
				$seconds = (float) $value;
				$per     = 1 / $seconds;
				return $seconds < 1 && abs( $per - round( $per ) ) < 0.1 ? sprintf( '1/%d s', round( $per ) ) : $number( $seconds, 1 ) . ' s';

			case 'focal_length':
				return $number( $value, 0 ) . ' mm';

			case 'iso':
				return (string) (int) $value;

			case 'created_timestamp':
				// WordPress stores the camera's clock time as if it were UTC.
				return wp_date( get_option( 'date_format' ), (int) $value, new \DateTimeZone( 'UTC' ) );

			default:
				return $value;
		}
	}

	/**
	 * Every public taxonomy registered for attachments, in registration
	 * order. Shown when no taxonomies are chosen.
	 *
	 * @return string[]
	 */
	public static function allTaxonomies() {

		return array_values( array_filter( get_object_taxonomies( 'attachment', 'names' ), 'is_taxonomy_viewable' ) );
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
	 * One .container row per taxonomy that has terms for the post, and per
	 * exposure detail its file gave.
	 *
	 * @param int      $post_id     The image (attachment) or post.
	 * @param string[] $taxonomies  Taxonomy names and exif: keys, in display
	 *                              order; empty for every taxonomy.
	 * @param bool     $link        Link each term to its archive.
	 * @param bool     $show_labels Show the label before the terms or value.
	 * @return string HTML, empty when there is nothing to show.
	 */
	public static function renderRows( $post_id, array $taxonomies, $link = true, $show_labels = true ) {

		if ( ! $post_id ) {
			return '';
		}

		$taxonomies = $taxonomies ? $taxonomies : self::allTaxonomies();
		$rows = '';
		$exif = null;

		foreach ( $taxonomies as $name ) {

			if ( 0 === strpos( $name, self::EXIF_PREFIX ) ) {

				$key    = substr( $name, strlen( self::EXIF_PREFIX ) );
				$fields = self::exifFields();

				if ( null === $exif ) {
					$meta = 'attachment' === get_post_type( $post_id ) ? wp_get_attachment_metadata( $post_id ) : [];
					$exif = is_array( $meta['image_meta'] ?? null ) ? $meta['image_meta'] : [];
				}

				$value = isset( $fields[ $key ] ) ? self::formatExif( $key, $exif[ $key ] ?? '' ) : '';

				if ( '' !== $value ) {
					$label = $show_labels ? '<div class="label">' . esc_html( $fields[ $key ] ) . ': </div>' : '';
					$rows .= '<div class="container">' . $label . '<div class="terms">' . esc_html( $value ) . '</div></div>';
				}

				continue;
			}

			// A taxonomy for the admin only is never shown to visitors.
			if ( ! taxonomy_exists( $name ) || ! is_taxonomy_viewable( $name ) ) {
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
	 * render_callback of the photopress/image-taxonomies block, Image
	 * Metadata.
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
