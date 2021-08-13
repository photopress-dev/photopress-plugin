<?php

namespace PhotoPress\modules\slideshow;
use photopress_module;
use pp_api;

/**
 * Resize Module
 *
 *
 */
class slideshow extends photopress_module {
	
	var $label = 'Slideshow';
	
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
		
		$args = [
			
			'showThumbnails',
			'showCaptions',
			'thumbnailHeight',
			'detail_position',
			'detail_components',
			'showTitleInCaption',
			'showDescriptionInCaption',
			'showAttachmentLink',
			'attachmentLinkText'
		];
		
		$args_dom = '';
		
		foreach ( $args as $arg ) {
			
			$option = pp_api::getOption('core', 'slideshow', $arg );
			
			if (is_array( $option ) ) {
				
				$option = esc_attr( json_encode( $option ) );
			} 
			
			$args_dom .= sprintf( ' data-%s="%s"', $arg, $option );
		}
		
		//add_action( 'wp_enqueue_scripts', array( $this, 'public_scripts' ) );
		
		// output slideshow html scafolding
		$o = [];
		
		$o[] = $block_content;
		
		$o[] = '<div class="lightbox" id="lightbox-gallery">';
		
			$o[] = '<div class="photopress-slideshow" '. $args_dom . ' ></div>';
			
			$o[] = '<a class="lightbox__close">Close</a>';
			
		$o[] = '</div>';
		
		return implode( " \n ", $o );
	}
	
	public function public_scripts( $content ) {
				
		if ( ! is_admin() && has_block( 'photopress/gallery' ) ) { 
		
			wp_register_style( 
				'owl', 
				plugins_url('assets/css/owl.carousel.min.css',
				__FILE__),
				[],
				PHOTOPRESS_CORE_VERSION 
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
				[ 'jquery', ],
				PHOTOPRESS_CORE_VERSION
			);
			
/*
			wp_enqueue_script(
				'flickity',
				plugins_url( 'assets/js/flickity.pkgd.min.js' , __FILE__ ),
				[ 'jquery', ],
				PHOTOPRESS_CORE_VERSION
			);
*/
			
			wp_enqueue_script(
				'photopress-slideshow',
				plugins_url( 'assets/js/slideshow.js' , __FILE__ ),
				[ 'jquery', 'imagesloaded', 'owl', 'photopress' ],
				PHOTOPRESS_CORE_VERSION
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
			
			'showThumbnails'				=> array(
			
				'default_value'							=> true,
				'field'									=> array(
					'type'									=> 'boolean',
					'title'									=> 'Thumbnail Navigation ',
					'page_name'								=> 'gallery-slideshow',
					'section'								=> 'general',
					'description'							=> 'Display thumbnail navigation.',
					'label_for'								=> 'Display thumbnail navigation.',
					'error_message'							=> 'You must select On or Off.'		
				)				
			),
			
			'showCaptions'				=> array(
			
				'default_value'							=> true,
				'field'									=> array(
					'type'									=> 'boolean',
					'title'									=> 'Captions',
					'page_name'								=> 'gallery-slideshow',
					'section'								=> 'general',
					'description'							=> 'Display captions in slideshow.',
					'label_for'								=> 'Display captions in slideshow.',
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
			
			'thumbnailHeight'				=> array(
				'default_value'							=> 120,
				'field'									=> array(
					'type'									=> 'integer',
					'title'									=> 'Thumbnail Height',
					'page_name'								=> 'gallery-slideshow',
					'section'								=> 'general',
					'description'							=> 'Height of thumbnails.',
					'label_for'								=> 'Height of thumbnails.'		
				)							
			),
			
			'showTitleInCaption'				=> array(
			
				'default_value'							=> false,
				'field'									=> array(
					'type'									=> 'boolean',
					'title'									=> 'Show Image Title',
					'page_name'								=> 'gallery-slideshow',
					'section'								=> 'general',
					'description'							=> 'Display the image title as part of the caption info.',
					'label_for'								=> 'Display the image title as part of the caption info.',
					'error_message'							=> 'You must select On or Off.'		
				)				
			),
			
			'showDescriptionInCaption'				=> array(
			
				'default_value'							=> false,
				'field'									=> array(
					'type'									=> 'boolean',
					'title'									=> 'Show Image Description',
					'page_name'								=> 'gallery-slideshow',
					'section'								=> 'general',
					'description'							=> 'Display the image description as part of the caption info.',
					'label_for'								=> 'Display the image description as part of the caption info.',
					'error_message'							=> 'You must select On or Off.'		
				)				
			),
			
			'showAttachmentLink'				=> array(
			
				'default_value'							=> false,
				'field'									=> array(
					'type'									=> 'boolean',
					'title'									=> 'Show link to attachment page',
					'page_name'								=> 'gallery-slideshow',
					'section'								=> 'general',
					'description'							=> 'Display link to attachment page for the image.',
					'label_for'								=> 'Display link to attachment page for the image.',
					'error_message'							=> 'You must select On or Off.'		
				)				
			),
			
			'attachmentLinkText'				=> array(
			
				'default_value'							=> 'Read More...',
				'field'									=> array(
					'type'									=> 'text',
					'title'									=> 'text for Show link to attachment page',
					'page_name'								=> 'gallery-slideshow',
					'section'								=> 'general',
					'description'							=> 'text for link to attachment page for the image.',
					'label_for'								=> 'Text for link to attachment page for the image.',
					'error_message'							=> 'You must select On or Off.'		
				)				
			),
			
			
		);
		
	}
	
	public function registerSettingsPages() {
		
		$pages = array();
		
		$pages['gallery-slideshow'] = array(
			
			'parent_slug'					=> 'photopress-core-base',
			'title'							=> 'Slideshow Gallery',
			'menu_title'					=> 'Slideshow',
			'required_capability'			=> 'manage_options',
			'menu_slug'						=> 'photopress-gallery-slideshow',
			'description'					=> 'Settings that control the slideshow gallery format.',
			'sections'						=> array(
				'general'						=> array(
					'id'							=> 'general',
					'title'							=> 'General',
					'description'					=> 'The following settings control how your images displayed in a slideshow gallery.'
				)
			),
			'noPhpRender'					=> true
		);
		
		return $pages;
	}
}

?>
