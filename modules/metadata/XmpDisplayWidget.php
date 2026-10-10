<?php 

namespace PhotoPress\modules\metadata;

use WP_Widget;
use pp_api;

class XmpDisplayWidget extends WP_Widget {
	
	/**
	 * Default taxonomies of a widget saved without a list.
	 */
	const DEFAULT_TAXONOMIES = 'photos_keywords, photos_camera, photos_lens, photos_city, photos_state, photos_country, photos_people, pp_person';

	function __construct() {
				
		/* Widget settings. */
		$widget_ops = [ 
			
			'classname' => 'XmpDisplayWidget', 
			'description' => "Display's the taxonomy terms of an image. Can only be used on single image or attachment pages.",
			'customize_selective_refresh' => true,
			// Exposes the settings to the widgets block editor, so a placed
			// widget can be transformed into the Image Taxonomies block.
			'show_instance_in_rest' => true,
		];

		/* Widget control settings. */
		$control_ops = array();
		
		parent::__construct( 'XmpDisplayWidget', __('Display Taxonomies (PhotoPress)', 'photopress'), $widget_ops, $control_ops);
	}
	
	/**
	 * Same markup as before, now from ImageTaxonomies, which the Image
	 * Taxonomies block uses too.
	 */
	function widget( $args, $instance ) {
		
		$post_id = get_the_ID();
		
		if ( ! $post_id ) {
			return;
		}
		
		$taxonomies = ! empty( $instance['taxonomies'] ) ? $instance['taxonomies'] : self::DEFAULT_TAXONOMIES;
		
		echo $args['before_widget'];
		
		if ( ! empty( $instance['title'] ) ) {
			
			echo $args['before_title'] . apply_filters( 'widget_title', $instance['title'] ) . $args['after_title'];
		}
		
		echo '<div class="display-taxonomy-terms-widget">';
		echo ImageTaxonomies::renderRows( $post_id, ImageTaxonomies::parseList( $taxonomies ), true, true );
		echo '</div>';
		
		echo $args['after_widget'];
	}
	
	function update( $new_instance, $old_instance ) {
		
		$instance = $old_instance;

		/* Strip tags (if needed) and update the widget settings. */
		$instance['title'] = strip_tags( $new_instance['title'] );
		$instance['taxonomies'] = strip_tags( $new_instance['taxonomies'] );

		return $instance;
	}
	
	function form( $instance ) {

		/* Set up some default widget settings. */
		$defaults = array(  
						'title' => '',
						'taxonomies' => self::DEFAULT_TAXONOMIES
					);
					
		$instance = wp_parse_args( (array) $instance, $defaults ); ?>
		
		<p>
			<label for="<?php echo $this->get_field_id( 'title' ); ?>">Title:</label>
			<input id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" value="<?php echo esc_attr( $instance['title'] ); ?>" style="width:100%;" />
		</p>
		
		<p>
			<label for="<?php echo $this->get_field_id( 'taxonomies' ); ?>">Image Taxonomies:</label>
			<input id="<?php echo $this->get_field_id( 'taxonomies' ); ?>" name="<?php echo $this->get_field_name( 'taxonomies' ); ?>" value="<?php echo esc_attr( $instance['taxonomies'] ); ?>" style="width:100%;" />
		</p>

		<?php
	}
}

?>