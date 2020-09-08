<?php

namespace PhotoPress\modules\metadata;
use WP_Widget;

class ExifDisplayWidget extends WP_Widget {
	
	function __construct() {
	
		/* Widget settings. */
		$widget_ops = [ 
		
			'classname' => 'ExifDisplayWidget', 
			'description' => __("Display's the EXIF info of an image. Can only be used on single image or attachment pages.", 'photopress'),
			'customize_selective_refresh' => true 
		];

		/* Widget control settings. */
		$control_ops = array('width' => 300);
		
		/* Create the widget. */
		parent::__construct('ExifDisplayWidget', __('Display Image Exif (PhotoPress)', 'photopress'), $widget_ops, $control_ops);
	}
	
	function widget( $args, $instance ) {
		
		global $post;
		
		extract( $args );
		
		if ( ! isset( $keys ) || empty( $keys ) ) {
			
			$keys = 'iso,aperture,camera';
		}
		
		$keys = explode(',', $keys);
	 	
		/* User-selected settings. */
		//$title = apply_filters('widget_title', $instance['title'] );
		
		/* Before widget (defined by themes). */
		echo $before_widget;

		//$meta = papt_getMetaData($post->ID);
		$meta = wp_get_attachment_metadata( $post->ID );
		$meta = $meta['image_meta'];
		//print_r($meta);
		$html = '';
		
		$values = array_intersect_key( $meta, array_flip( $keys ) );
		
		if ( $values ) {
		
			$html .= '<div class="display-exif-widget">';	
		
			foreach ( $values as $key => $value ) {
					
					$value = trim( $value );
					
					if ( $value ) {
					
						$html .= '<div class="container"><div class="label">'. $key .': </div><div class="terms">' . $value . '</div></div>';	
					}	
			}
		
			$html .= '<div>';
		}
		
		if ( $values && $html ) {
		
			if ( ! empty( $instance['title'] ) ) {
				echo $args['before_title'] . apply_filters( 'widget_title', $instance['title'] ). $args['after_title'];
			}	
			echo $html;
			/* After widget (defined by themes). */
			echo $after_widget;
		} else {
			
			echo '<!-- Widget had nothing to output. -->';
			echo $after_widget;
		}
	}
	
	function update( $new_instance, $old_instance ) {
		$instance = $old_instance;

		/* Strip tags (if needed) and update the widget settings. */
		$instance['title'] = strip_tags( $new_instance['title'] );
		$instance['keys'] = strip_tags( $new_instance['keys'] );

		return $instance;
	}
	
	function form( $instance ) {

		/* Set up some default widget settings. */
		$defaults = array( 'title' => 'Example' );
		$instance = wp_parse_args( (array) $instance, $defaults ); ?>
		
		<p>
			<label for="<?php echo $this->get_field_id( 'title' ); ?>">Title:</label><BR>
			<input id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" value="<?php echo $instance['title']; ?>" style="width:100%;" />
		</p>
		
		<p>
			<label for="<?php echo $this->get_field_id( 'keys' ); ?>">Meta Data Keys (optional):</label><BR>
			<input id="<?php echo $this->get_field_id( 'keys' ); ?>" name="<?php echo $this->get_field_name( 'keys' ); ?>" value="<?php echo $instance['keys']; ?>" style="width:100%;" />
		</p>

		<?php
	}
}
	

?>