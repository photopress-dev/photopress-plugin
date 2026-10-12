<?php

namespace PhotoPress\modules\metadata;
use WP_Widget;

/**
 * "Display Image Exif (PhotoPress)": the exposure details of the image on an
 * image (attachment) page. Kept for the widgets already placed and for the
 * classic widgets screen; in the widgets block editor it is not offered, and
 * a placed one can be transformed into the Image Metadata block, which shows
 * the same rows (src/blocks/image-taxonomies/transforms.js).
 */
class ExifDisplayWidget extends WP_Widget {

	/**
	 * Keys shown by a widget saved without a list.
	 */
	const DEFAULT_KEYS = 'iso,aperture,camera';

	function __construct() {

		$widget_ops = [

			'classname' => 'ExifDisplayWidget',
			'description' => __( 'Displays the EXIF info of an image. Can only be used on single image or attachment pages.', 'photopress' ),
			'customize_selective_refresh' => true,
			// Exposes the settings to the widgets block editor, so a placed
			// widget can be transformed into the Image Metadata block.
			'show_instance_in_rest' => true,
		];

		parent::__construct( 'ExifDisplayWidget', __( 'Display Image Exif (PhotoPress)', 'photopress' ), $widget_ops, [ 'width' => 300 ] );
	}

	/**
	 * The widget's keys, image_meta keys such as iso, as rows of the Image
	 * Metadata block.
	 *
	 * @return string[]
	 */
	public static function rows( $keys ) {

		$keys = ImageTaxonomies::parseList( '' !== trim( (string) $keys ) ? $keys : self::DEFAULT_KEYS );

		return array_map( static fn( $key ) => ImageTaxonomies::EXIF_PREFIX . strtolower( $key ), $keys );
	}

	function widget( $args, $instance ) {

		$post_id = get_the_ID();
		$html    = $post_id ? ImageTaxonomies::renderRows( $post_id, self::rows( $instance['keys'] ?? '' ), false, true ) : '';

		if ( '' === $html ) {
			return;
		}

		echo $args['before_widget'];

		if ( ! empty( $instance['title'] ) ) {
			echo $args['before_title'] . apply_filters( 'widget_title', $instance['title'] ) . $args['after_title'];
		}

		echo '<div class="display-exif-widget display-taxonomy-terms-widget">' . $html . '</div>';
		echo $args['after_widget'];
	}

	function update( $new_instance, $old_instance ) {

		$instance = $old_instance;

		$instance['title'] = sanitize_text_field( $new_instance['title'] ?? '' );
		$instance['keys']  = sanitize_text_field( $new_instance['keys'] ?? '' );

		return $instance;
	}

	function form( $instance ) {

		$instance = wp_parse_args( (array) $instance, [ 'title' => '', 'keys' => self::DEFAULT_KEYS ] );
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'photopress' ); ?></label><br>
			<input id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" value="<?php echo esc_attr( $instance['title'] ); ?>" style="width:100%;" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'keys' ) ); ?>"><?php esc_html_e( 'Details, separated by commas:', 'photopress' ); ?></label><br>
			<input id="<?php echo esc_attr( $this->get_field_id( 'keys' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'keys' ) ); ?>" value="<?php echo esc_attr( $instance['keys'] ); ?>" style="width:100%;" />
			<small><?php echo esc_html( implode( ', ', array_keys( ImageTaxonomies::exifFields() ) ) ); ?></small>
		</p>
		<?php
	}
}
