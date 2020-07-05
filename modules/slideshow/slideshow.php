<?php

if ( ! class_exists( 'photopress_module' ) ) {

	require_once( PHOTOPRESS_FRAMEWORK_PATH . 'class-module.php' );
}

/**
 * Resize Module
 *
 *
 */
class photopress_core_slideshow_module extends photopress_module {

	public function definePublicHooks() {
		
		if ( pp_api::getOption( 'core', 'slideshow', 'enable' ) ) {
		
			add_action( 'wp_enqueue_scripts', array( $this, 'public_scripts' ) );
			// configure gallery HTML
			
			add_filter( 'render_block', [$this, 'render_slideshow'], 10, 3);
		}
	}
	
	public function render_slideshow( $block_content, $block ) {
    	
    	// check to make sure we are dealing with a gallery block
		if( "photopress/gallery" !== $block['blockName'] ) {
		
			return $block_content;
		}
		
		// check to see if the showSlideshow attr is set on the block
		if (  isset( $block['attrs']['showSlideshow'] ) ) {
			
			return $block_content;
		}
		
		// output slideshow html scafolding
		$o = [];
		
		$o[] = $block_content;
		
		$o[] = '<div class="lightbox" id="lightbox-gallery">';
		
			$o[] = '<div class="photopress-slideshow">';
				
				$o[] = '<div class="panels">';
				
					$o[] ='<div class="left-panel"></div>';
					$o[] ='<div class="center-panel"></div>';
					$o[] ='<div class="right-panel"></div>';
					
				$o[] = '</div>';
				
				$o[] = '<div class="thumbnails"></div>';
						
			$o[] = '</div>';
			
			$o[] = '<a class="lightbox__close">Close</a>';
			
		$o[] = '</div>';
		
		return implode( $o, " \n " );
	}
	
	public function public_scripts() {
				
/*
		wp_register_style( 
			'pp-slideshow', 
			plugins_url('css/pp-slideshow.css',
			__FILE__) 
		 );
		 
	    wp_enqueue_style( 'pp-slideshow' );
				
		wp_enqueue_script(
			'photopress-gallery-slideshow',
			plugins_url( 'modules/slideshow/assets/js/slideshow.js' , __FILE__ ),
			[ 'jquery', 'photopress' ]
		);
*/
		
	}
	
		
	public function registerOptions() {		

		return array(
		
			'enable'				=> array(
			
				'default_value'							=> true,
				'field'									=> array(
					'type'									=> 'boolean',
					'title'									=> 'Enable Slideshows ',
					'page_name'								=> 'gallery-slideshow',
					'section'								=> 'general',
					'description'							=> 'Enable gallery slideshows.',
					'label_for'								=> 'Enable gallery slideshows.',
					'error_message'							=> 'You must select On or Off.'		
				)				
			),
			
			'clickStart'				=> array(
			
				'default_value'							=> true,
				'field'									=> array(
					'type'									=> 'boolean',
					'title'									=> 'Click Start Mode',
					'page_name'								=> 'gallery-slideshow',
					'section'								=> 'general',
					'description'							=> 'Click to Start the Slideshow',
					'label_for'								=> 'Gallery Slideshow Click Start mode',
					'error_message'							=> 'You must select On or Off.'		
				)				
			),
			
			
			'clickStartSelector'				=> array(
			
				'default_value'							=> '.gallery-icon',
				'field'									=> array(
					'type'									=> 'text',
					'title'									=> 'Click Start Selector',
					'page_name'								=> 'gallery-slideshow',
					'section'								=> 'general',
					'description'							=> 'DOM Element to click on to start the slideshow.',
					'label_for'								=> 'Click Start Selector',
					'error_message'							=> ''		
				)				
			),
			
			'detail_position'				=> array(
			
				'default_value'							=> 'bottom',
				'field'									=> array(
					'type'									=> 'select',
					'options'								=> array('bottom', 'right'),
					'title'									=> 'Slide Details Position',
					'page_name'								=> 'gallery-slideshow',
					'section'								=> 'general',
					'description'							=> 'The position of the details box on the slide (e.g. "bottom" or "right").',
					'label_for'								=> 'Slide Details Position',
					'error_message'							=> ''		
				)				
			),
			
			'detail_components'				=> array(
			
				'default_value'							=> array('title' => true, 'caption' => false),
				'field'									=> array(
					'type'									=> 'on_off_array',
					'options'								=> array(
																	'title'			=> 'Image Title',
																	'caption'		=> 'Image Caption',
																	'description'	=> 'Description'
																),
					'title'									=> 'Slide Detail Components',
					'page_name'								=> 'gallery-slideshow',
					'section'								=> 'general',
					'description'							=> 'Select the details you wish to display on each slide.',
					'label_for'								=> 'Slide Detail Components'
				)				
			),
			
			'thumbnail_height'				=> array(
				'default_value'							=> 120,
				'field'									=> array(
					'type'									=> 'integer',
					'title'									=> 'Thumbnail Height',
					'page_name'								=> 'gallery-slideshow',
					'section'								=> 'general',
					'description'							=> 'Height of thumbnails.',
					'label_for'								=> 'Height of thumbnails.'		
				)							
			)
		
		);
		
	}
	
	public function registerSettingsPages() {
		
		$pages = array();
		
		$pages['gallery-slideshow'] = array(
			
			'parent_slug'					=> 'photopress-core-base',
			'title'							=> 'Slideshow Gallery',
			'menu_title'					=> 'Slideshow',
			'required_capability'			=> 'manage_options',
			'menu_slug'						=> 'photopress-pro-gallery-slideshow',
			'description'					=> 'Settings that control the slideshow gallery format.',
			'sections'						=> array(
				'general'						=> array(
					'id'							=> 'general',
					'title'							=> 'General',
					'description'					=> 'The following settings control how your images displayed in a slideshow gallery.'
				)
			)
		);
		
		return $pages;
	}
}

?>