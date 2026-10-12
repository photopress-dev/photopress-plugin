<?php

namespace PhotoPress\modules\childpages;
use photopress_module;

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
	 * render_callback: the published pages under the current one, in their
	 * page order, each as its featured image (with srcset, sizes and lazy
	 * loading from WordPress) and its title, linked to it.
	 */
	public function childpages_render( $attrs, $content ) {

		$parent = get_the_ID();

		if ( ! $parent ) {
			return '';
		}

		$children = get_posts( [
			'post_type'   => get_post_type( $parent ),
			'post_parent' => $parent,
			'post_status' => 'publish',
			'orderby'     => [ 'menu_order' => 'ASC', 'title' => 'ASC' ],
			'numberposts' => -1,
		] );

		if ( ! $children ) {
			return '';
		}

		$size    = (string) ( $attrs['imageSize'] ?? 'medium' );
		$classes = [ 'photopress-gallery-columns', 'align' . sanitize_html_class( (string) ( $attrs['align'] ?? '' ) ), 'columns-' . (int) ( $attrs['columns'] ?? 2 ) ];

		if ( $attrs['imageCrop'] ?? true ) {
			$classes[] = 'is-cropped';
		}

		$items = '';

		foreach ( $children as $child ) {

			$link  = esc_url( get_permalink( $child ) );
			$image = get_the_post_thumbnail( $child, $size, [ 'class' => 'wp-image wp-image-' . (int) get_post_thumbnail_id( $child ) ] );

			$items .= '<li class="photopress-gallery-item">'
				. ( $image ? '<figure class="photopress-gallery-item__figure"><a href="' . $link . '">' . $image . '</a></figure>' : '' )
				. '<div class="wp-block-photopress-childpages__content"><a href="' . $link . '">' . esc_html( get_the_title( $child ) ) . '</a></div>'
				. '</li>';
		}

		return '<figure class="photopress-gallery photopress-childpages">'
			. '<ul class="' . esc_attr( implode( ' ', $classes ) ) . '" style="--pp-gallery-gutter: ' . (int) ( $attrs['padding'] ?? 10 ) . 'px">'
			. $items
			. '</ul></figure>';
	}
}

?>