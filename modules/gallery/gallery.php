<?php

namespace PhotoPress\modules\gallery;
use photopress_module;

/**
 * Child Pages Module
 *
 * Adds a dynamic Gutenberg block that displays a gallery of child pages.
 */
class gallery extends photopress_module {
		
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