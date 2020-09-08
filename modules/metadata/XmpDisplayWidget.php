<?php 

namespace PhotoPress\modules\metadata;

use WP_Widget;
use pp_api;

class XmpDisplayWidget extends WP_Widget {
	
	function __construct() {
				
		/* Widget settings. */
		$widget_ops = [ 
			
			'classname' => 'XmpDisplayWidget', 
			'description' => "Display's the taxonomy terms of an image. Can only be used on single image or attachment pages.",
			'customize_selective_refresh' => true 
		];

		/* Widget control settings. */
		$control_ops = array();
		
		parent::__construct( 'XmpDisplayWidget', __('Display Taxonomies (PhotoPress)', 'photopress'), $widget_ops, $control_ops);
	}
	
	function widget( $args, $instance ) {
		
		global $post;
		
		extract( $args );
		
		/* User-selected settings. */
		//$title = apply_filters('widget_title', $instance['title'] );
		
		if ( ! array_key_exists('taxonomies', $instance ) || empty( $instance['taxonomies'] ) ) {
			
			$taxonomies = 'photos_keywords, photos_camera, photos_lens, photos_city, photos_state, photos_country, photos_people';
			
		} else {
			
			$taxonomies = $instance['taxonomies'];
		}
		
		$taxonomies = explode( ',', str_replace( ' ', '', $taxonomies ) );
		/* Before widget (defined by themes). */
		echo $before_widget;
		
		if ( ! empty( $instance['title'] ) ) {
			
			echo $args['before_title'] . apply_filters( 'widget_title', $instance['title'] ). $args['after_title'];
		}
		
		echo '<div class="display-taxonomy-terms-widget">';	
		
		foreach ( $taxonomies as $tax_name ) {
					
			if ( taxonomy_exists( $tax_name ) ) {
				
				$t = get_taxonomy($tax_name);
				
				echo get_the_term_list( $post->ID, $tax_name, '<div class="container"><div class="label">'. $t->label .': </div><div class="terms">', ', ', '</div></div>' );	
			}
		}
		
		echo '</div>';
		/* After widget (defined by themes). */
		echo $after_widget;
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
						'taxonomies' => 'photos_keywords, photos_camera, photos_lens, photos_city, photos_state, photos_country, photos_people'
					);
					
		$instance = wp_parse_args( (array) $instance, $defaults ); ?>
		
		<p>
			<label for="<?php echo $this->get_field_id( 'title' ); ?>">Title:</label>
			<input id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" value="<?php echo $instance['title']; ?>" placeholder="foo" style="width:100%;" />
		</p>
		
		<p>
			<label for="<?php echo $this->get_field_id( 'taxonomies' ); ?>">Image Taxonomies:</label>
			<input id="<?php echo $this->get_field_id( 'taxonomies' ); ?>" name="<?php echo $this->get_field_name( 'taxonomies' ); ?>" value="<?php echo $instance['taxonomies']; ?>" style="width:100%;" />
		</p>

		<?php
	}
}

?>