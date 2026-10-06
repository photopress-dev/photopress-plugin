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
	
	/**
	 * Whether the lightbox has been queued for the footer on this request.
	 */
	private $lightboxQueued = false;

	public function definePublicHooks() {
		
		if ( pp_api::getOption( 'core', 'slideshow', 'enable' ) ) {
		
			add_filter( 'render_block', [$this, 'render_slideshow'], 10, 2 );
		}
	}
	
	/**
	 * Marks a gallery that has the slideshow turned on, and queues the
	 * lightbox and its assets. Applies to the legacy photopress/gallery block
	 * (linkToSlideshow) and to core/gallery (photopressSlideshow).
	 */
	public function render_slideshow( $block_content, $block ) {

		$attrs = $block['attrs'] ?? [];

		if ( 'photopress/gallery' === $block['blockName'] ) {

			// The attribute is saved as false once the toggle is switched off.
			$enabled = ! empty( $attrs['linkToSlideshow'] );
			$gallery = [ 'class_name' => 'photopress-gallery' ];

		} elseif ( 'core/gallery' === $block['blockName'] ) {

			// Any core gallery, with or without a PhotoPress layout; its items
			// are prepared by modules/gallery.
			$enabled = ! empty( $attrs['photopressSlideshow'] );
			$gallery = [ 'tag_name' => 'figure', 'class_name' => 'wp-block-gallery' ];

		} else {

			return $block_content;
		}

		if ( ! $enabled ) {
			return $block_content;
		}

		// Only galleries with this class open the slideshow when clicked.
		$p = new \WP_HTML_Tag_Processor( $block_content );

		if ( ! $p->next_tag( $gallery ) ) {
			return $block_content;
		}

		$p->add_class( 'photopress-has-slideshow' );

		// One lightbox per page, shared by every slideshow gallery on it.
		if ( ! $this->lightboxQueued ) {

			$this->lightboxQueued = true;
			$this->enqueueAssets();
			add_action( 'wp_footer', [ $this, 'printLightbox' ] );
		}

		return $p->get_updated_html();
	}

	public function printLightbox() {

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
			
			if ( is_array( $option ) ) {
				
				$option = wp_json_encode( $option );
			} 
			
			$args_dom .= sprintf( ' data-%s="%s"', esc_attr( strtolower( $arg ) ), esc_attr( (string) $option ) );
		}
		
		echo '<div class="lightbox" id="lightbox-gallery">';
		echo '<div class="photopress-slideshow"' . $args_dom . '></div>';
		echo '<a class="lightbox__close" href="#" role="button">' . esc_html__( 'Close', 'photopress' ) . '</a>';
		echo '</div>';
	}
	
	private function enqueueAssets() {
				
		wp_register_style( 
			'owl', 
			plugins_url('assets/css/owl.carousel.min.css',
			__FILE__),
			[],
			PHOTOPRESS_CORE_VERSION 
		 );
		 
		wp_enqueue_style( 'owl' );
		
		wp_enqueue_script(
			'owl',
			plugins_url( 'assets/js/owl.carousel.min.js' , __FILE__ ),
			[ 'jquery', ],
			PHOTOPRESS_CORE_VERSION,
			true
		);
		
		$asset = \photopress_util::getBuildAsset( 'press-navigation.build' );
		
		wp_enqueue_script(
			'photopress-press-navigation',
			plugins_url( 'dist/press-navigation.build.js', dirname( dirname( __FILE__ ) ) ),
			array_merge( $asset['dependencies'], [ 'photopress' ] ),
			$asset['version'],
			true
		);
		
		wp_enqueue_script(
			'photopress-slideshow',
			plugins_url( 'assets/js/slideshow.js' , __FILE__ ),
			[ 'jquery', 'imagesloaded', 'owl', 'photopress', 'photopress-press-navigation' ],
			PHOTOPRESS_CORE_VERSION,
			true
		);
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
