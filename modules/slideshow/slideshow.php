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
		
			//add_action( 'wp_enqueue_scripts', [ $this, 'public_scripts' ] );
			add_filter( 'the_content', [ $this, 'public_scripts' ] );
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
		if (! isset( $block['attrs']['linkToSlideshow'] ) ) {
			
			return $block_content;
		}
		
		//add_action( 'wp_enqueue_scripts', array( $this, 'public_scripts' ) );
		
		// output slideshow html scafolding
		$o = [];
		
		$o[] = $block_content;
		
		$o[] = '<div class="lightbox" id="lightbox-gallery">';
		
			$o[] = '<div class="photopress-slideshow">';
				
				$o[] = '<div class="panels">';
				
					$o[] ='<div class="nav-control left"><i class="arrow left"></i></div>';
					$o[] ='<div class="center"><div class="loader-circle"></div></div>';
					$o[] ='<div class="nav-control right"><i class="arrow right"></i></div>';
					
				$o[] = '</div>';
				
				$o[] = '<div class="thumbnails"><div class="thumbnail-list owl-carousel"></div></div>';
					
			$o[] = '</div>';
			
			$o[] = '<a class="lightbox__close">Close</a>';
			
		$o[] = '</div>';
		
		return implode( $o, " \n " );
	}
	
	public function public_scripts( $content ) {
				
		if ( has_block( 'photopress/gallery' ) ) { 
		
			wp_register_style( 
				'owl', 
				plugins_url('assets/css/owl.carousel.min.css',
				__FILE__) 
			 );
			 
		    wp_enqueue_style( 'owl' );
			
/*
			wp_register_style( 
				'flickity', 
				plugins_url('assets/css/flickity.css',
				__FILE__) 
			 );
			 
		    wp_enqueue_style( 'flickity' );		
*/
			
			wp_enqueue_script(
				'owl',
				plugins_url( 'assets/js/owl.carousel.min.js' , __FILE__ ),
				[ 'jquery', ]
			);
			
/*
			wp_enqueue_script(
				'flickity',
				plugins_url( 'assets/js/flickity.pkgd.min.js' , __FILE__ ),
				[ 'jquery', ]
			);
*/
			
			wp_enqueue_script(
				'photopress-slideshow',
				plugins_url( 'assets/js/slideshow.js' , __FILE__ ),
				[ 'jquery', 'imagesloaded', 'owl' ]
			);
		}
		
		return $content;
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