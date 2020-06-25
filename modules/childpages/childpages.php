<?php

if ( ! class_exists( 'photopress_module' ) ) {

	require_once( PHOTOPRESS_FRAMEWORK_PATH . 'class-module.php' );
}

/**
 * Child Pages Module
 *
 * Adds a dynamic Gutenberg block that displays a gallery of child pages.
 */
class photopress_core_childpages_module extends photopress_module {
		
	public function definePublicHooks() {
			
		register_block_type( 'photopress/childpages', [
				
			// Enqueue blocks.style.build.css on both frontend & backend.
			'style'         		=> 'photopress-frontend',
			// Enqueue blocks.build.js in the editor only.
			'editor_script' 		=> 'photopress-editor',
			// Enqueue blocks.editor.build.css in the editor only.
			'editor_style'  		=> 'photopress-editor',
			'render_callback' 		=> [ $this, 'childpages_render'],
			'attributes' 			=> [
                'padding' 				=> [
                    'type' 					=> 'integer',
                    'default'				=> 10
                ],
                'imageSize'				=> [
	              		'type'				=> 'string',
	              		'default'			=> 'medium'  
                ],
                'className' 			=> [
                    'type' 					=> 'string',
                ]
            ]
		]);
		
		// adds parent param into the REST query which isn't there by default for some reason.
		add_filter( 'rest_page_query', function( $args, $request ){
		    if ( $request->get_param( 'post_parent' ) ) {
		        //$args['post_parent'] = $meta_key;
		        
		        $args['post_parent'] = $request->get_param( 'post_parent' );
		    }
		    
		    return $args;
		}, 10, 2 );
				
	}
	
	/**
	 * Dynamic block render function
	 */
	public function childpages_render($attrs, $content) {
	
		// get post id
		
		$id = get_the_ID();
		
		// determin image size
		$size = photopress_util::get_image_sizes( $attrs['imageSize']);
		
		if ( $size['width'] > $size['height'] ) {
			
			$width = $size['width'] . 'px';
			$height = $size['width'] . 'px';
		} else {
			
			$width = $size['width'] . 'px';
			$height = $size['width'] . 'px';
		}
		
		// get child pages
		$children = get_children( $id );
		
		// loop
		$content = "";
		$content .= "<div class=\"wp-block-photopress-childpages__inner\">";
		
		foreach ($children as $k => $child ) {
			
			$content .= "<div class=\"wp-block-photopress-childpages__item\" style=\"padding: {$attrs['padding']} ; \">";
			$content .= "	<div id= \"item_$k\" class=\"wp-block-photopress-childpages__image\">";
			$link = get_permalink($k);
			$content .=	"		<a href=\"$link\" target=\"_blank\" rel=\"noreferrer noopener\" alt=\"\">";
			$fi_link = get_the_post_thumbnail_url( $k, $attrs['imageSize'] );
			$content .= "			<img src=\"$fi_link\" style= \"width: $width; height: $height \" />";
			$content .= "		</a>";
			$content .= "	</div>";										
																	
			$content .= "	<div class=\"wp-block-photopress-childpages__content\">";							
			$content .=	"		<a href=\"$link\" target=\"_blank\" rel=\"noreferrer noopener\" alt=\"\">";
			$content .= 			$child->post_title;
			$content .= "		</a>";										
			$content .= "	</div>";	
			$content .= "</div>";	
		}
		
		$content .= "</div>";	
												
		return $content;
		
	}

}

?>