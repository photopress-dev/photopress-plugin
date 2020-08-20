<?php

namespace PhotoPress\modules\childpages;
use photopress_module;
use photopress_util;

/**
 * Child Pages Module
 *
 * Adds a dynamic Gutenberg block that displays a gallery of child pages.
 */
class childpages extends photopress_module {
		
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
                'columns' 				=> [
                    'type' 					=> 'integer',
                    'default'				=> 2
                ],
                'imageSize'				=> [
	              		'type'				=> 'string',
	              		'default'			=> 'medium'  
                ],
                'className' 			=> [
                    'type' 					=> 'string',
                ],
                'imageCrop' 				=> [
                    'type' 					=> 'boolean',
                    'default'				=> true
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
		
		$align = $attrs['align'];
		$columns = $attrs['columns'];
		$padding= $attrs['padding'];
		$imageCrop = '';
		//print_r($attrs);
		if ( array_key_exists('imageCrop', $attrs) && $attrs['imageCrop'] ) {
			
			$imageCrop = 'is-cropped';	
		}
		
		// loop
		$content = "";
		$content .= '<figure class="photopress-gallery photopress-childpages">';
		$content .= '<ul class="photopress-gallery-columns align'. $align . ' columns-' . $columns . ' ' . $imageCrop .'" style="--pp-gallery-gutter: '. $padding. 'px">';
					
			
		
		foreach ($children as $k => $child ) {
			
			$link = get_permalink($k);
			$fi_link = get_the_post_thumbnail_url( $k, $attrs['imageSize'] );
			$fi_id = get_post_thumbnail_id( $k );
			
			$content .= '<li class="photopress-gallery-item">';
			
			$content .= '	<figure class="photopress-gallery-item__figure">';
			
			$content .=	"		<a href=\"$link\">";
			
			$content .= "			<img class=\"wp-image wp-image-$fi_id\" src=\"$fi_link\" sizes=\"( (100vw - 0 ) / $columns ) * 1)\" />";
			
			$content .= "		</a>";
															
			$content .= "	</figure>";	
			
			$content .= "		<div class=\"wp-block-photopress-childpages__content\">";							
			
			$content .=	"			<a href=\"$link\" target=\"_blank\" rel=\"noreferrer noopener\" alt=\"\">";
			
			$content .= 				$child->post_title;
			
			$content .= "			</a>";										
			
			$content .= "		</div>";
			
			$content .= "</li>";	
		}
		
		$content .= "</figure>";	
												
		return $content;
		
	}

}

?>