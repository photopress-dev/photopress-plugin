<?php

if ( ! class_exists( 'photopress_module' ) ) {

	require_once( PHOTOPRESS_FRAMEWORK_PATH . 'class-module.php' );
}

/**
 * Child Pages Module
 *
 * Adds a dynamic Gutenberg block that displays a gallery of child pages.
 */
class photopress_core_gallery_module extends photopress_module {
	
	var $attrs = 'foo'; 	
	public function definePublicHooks() {
			
		register_block_type( 'photopress/gallery', [
				
			// Enqueue blocks.style.build.css on both frontend & backend.
			'style'         		=> 'photopress-frontend',
			// Enqueue blocks.build.js in the editor only.
			'editor_script' 		=> 'photopress-editor',
			// Enqueue blocks.editor.build.css in the editor only.
			'editor_style'  		=> 'photopress-editor'
		]);	
	}
}

?>