<?php
/**
 * Blocks Initializer
 *
 * Enqueue CSS/JS of all the blocks.
 *
 * @since   1.0.0
 * @package CGB
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue Gutenberg block assets for both frontend + backend.
 *
 * Assets enqueued:
 * 1. blocks.style.build.css - Frontend + Backend.
 * 2. blocks.build.js - Backend.
 * 3. blocks.editor.build.css - Backend.
 *
 * @uses {wp-blocks} for block type registration & related functions.
 * @uses {wp-element} for WP Element abstraction — structure of blocks.
 * @uses {wp-i18n} to internationalize the block's text.
 * @uses {wp-editor} for WP editor styles.
 * @since 1.0.0
 */
function photopress_block_cgb_block_assets() { // phpcs:ignore
	// Register block styles for both frontend + backend.
	wp_register_style(
		'photopress_block-cgb-style-css', // Handle.
		plugins_url( 'dist/blocks.style.build.css', dirname( __FILE__ ) ), // Block style CSS.
		is_admin() ? array( 'wp-editor' ) : null, // Dependency to include the CSS after it.
		null // filemtime( plugin_dir_path( __DIR__ ) . 'dist/blocks.style.build.css' ) // Version: File modification time.
	);

	// Register block editor script for backend.
	wp_register_script(
		'photopress_block-cgb-block-js', // Handle.
		plugins_url( '/dist/blocks.build.js', dirname( __FILE__ ) ), // Block.build.js: We register the block here. Built with Webpack.
		array( 'wp-blocks', 'wp-i18n', 'wp-element', 'wp-editor' ), // Dependencies, defined above.
		null, // filemtime( plugin_dir_path( __DIR__ ) . 'dist/blocks.build.js' ), // Version: filemtime — Gets file modification time.
		true // Enqueue the script in the footer.
	);

	// Register block editor styles for backend.
	wp_register_style(
		'photopress_block-cgb-block-editor-css', // Handle.
		plugins_url( 'dist/blocks.editor.build.css', dirname( __FILE__ ) ), // Block editor CSS.
		array( 'wp-edit-blocks' ), // Dependency to include the CSS after it.
		null // filemtime( plugin_dir_path( __DIR__ ) . 'dist/blocks.editor.build.css' ) // Version: File modification time.
	);

	// WP Localized globals. Use dynamic PHP stuff in JavaScript via `cgbGlobal` object.
	wp_localize_script(
		'photopress_block-cgb-block-js',
		'cgbGlobal', // Array containing dynamic data for a JS Global.
		[
			'pluginDirPath' => plugin_dir_path( __DIR__ ),
			'pluginDirUrl'  => plugin_dir_url( __DIR__ ),
			// Add more data here that you want to access from `cgbGlobal` object.
		]
	);

	/**
	 * Register Gutenberg block on server-side.
	 *
	 * Register the block on server-side to ensure that the block
	 * scripts and styles for both frontend and backend are
	 * enqueued when the editor loads.
	 *
	 * @link https://wordpress.org/gutenberg/handbook/blocks/writing-your-first-block-type#enqueuing-block-scripts
	 * @since 1.16.0
	 */
	register_block_type(
		'cgb/block-photopress-block', array(
			// Enqueue blocks.style.build.css on both frontend & backend.
			'style'         => 'photopress_block-cgb-style-css',
			// Enqueue blocks.build.js in the editor only.
			'editor_script' => 'photopress_block-cgb-block-js',
			// Enqueue blocks.editor.build.css in the editor only.
			'editor_style'  => 'photopress_block-cgb-block-editor-css',
		)
	);
	
	register_block_type(
		'photopress/childpages', [
			// Enqueue blocks.style.build.css on both frontend & backend.
			'style'         => 'photopress_block-cgb-style-css',
			// Enqueue blocks.build.js in the editor only.
			'editor_script' => 'photopress_block-cgb-block-js',
			// Enqueue blocks.editor.build.css in the editor only.
			'editor_style'  => 'photopress_block-cgb-block-editor-css',
			'render_callback' => 'test',
			'attributes' => [
                'padding' => [
                    'type' => 'integer',
                    'default'	=> 10
                ],
                'imageSize'	=> [
	              		'type'	=> 'string',
	              		'default'	=> 'medium'  
                ],
                'className' => [
                    'type' => 'string',
                ],
                'foo'	=> [
	                'type'	=> 'string',
	                'default'	=> 'bar'
                ]
            ]
		]
	);
	
	add_filter( 'rest_page_query', function( $args, $request ){
    if ( $request->get_param( 'post_parent' ) ) {
        //$args['post_parent'] = $meta_key;
        
        $args['post_parent'] = $request->get_param( 'post_parent' );
    }
    
    return $args;
}, 10, 2 );
}

function test($attrs, $content) {
	
	// get post id
	
	$id = get_the_ID();
	
	// determin image size
	$size = pp_get_image_sizes( $attrs['imageSize']);
	
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
		$content .= "	<div class=\"wp-block-photopress-childpages__image\">";
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

/**
 * Get information about available image sizes
 */
function pp_get_image_sizes( $size = '' ) {
    $wp_additional_image_sizes = wp_get_additional_image_sizes();
 
    $sizes = array();
    $get_intermediate_image_sizes = get_intermediate_image_sizes();
 
    // Create the full array with sizes and crop info
    foreach( $get_intermediate_image_sizes as $_size ) {
        if ( in_array( $_size, array( 'thumbnail', 'medium', 'large' ) ) ) {
            $sizes[ $_size ]['width'] = get_option( $_size . '_size_w' );
            $sizes[ $_size ]['height'] = get_option( $_size . '_size_h' );
            $sizes[ $_size ]['crop'] = (bool) get_option( $_size . '_crop' );
        } elseif ( isset( $wp_additional_image_sizes[ $_size ] ) ) {
            $sizes[ $_size ] = array( 
                'width' => $wp_additional_image_sizes[ $_size ]['width'],
                'height' => $wp_additional_image_sizes[ $_size ]['height'],
                'crop' =>  $wp_additional_image_sizes[ $_size ]['crop']
            );
        }
    }
 
    // Get only 1 size if found
    if ( $size ) {
        if( isset( $sizes[ $size ] ) ) {
            return $sizes[ $size ];
        } else {
            return false;
        }
    }
    return $sizes;
}

// Hook: Block assets.
add_action( 'init', 'photopress_block_cgb_block_assets' );
