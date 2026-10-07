<?php

namespace PhotoPress\modules\metadata;
use photopress_module;
use pp_api;
use photopress_util;

/**
 * Registers the Core Image Taxonomies
 *
 *
 */
class metadata extends photopress_module {
	
	public $label = 'Meta-data'; 
	
	public function definePublicHooks() {
		
		add_filter( 'max_srcset_image_width', [$this, 'setMaxSrcsetSize'], 10,2);
		
		// add additional meta-data to images
		add_filter( 'wp_read_image_metadata', [$this, 'storeMoreMetaData'], 10, 5);
		
		// the description of an image whose file was replaced (see MediaRest)
		add_filter( 'photopress_attachment_description', [ $this, 'descriptionForReplacedFile' ], 10, 3 );
		
		// add additional attributes to images
		//add_filter( 'wp_get_attachment_image_attributes', [$this, 'addAttributesToImages' ], 11, 2 );
		add_filter( 'render_block', [ $this, 'addAttributesToImagesInContent' ], 11, 3 );
		
		// embed license meta-data in all uploaded images even if it already exists.
		if ( pp_api::getOption('core', 'metadata', 'embed_licensor_enable') ) {
			
			add_filter( 'pre_move_uploaded_file', [ $this, 'embedLicense' ], 1, 4 );
		}
		
		add_filter( 'frame/attachment/image_markup', [ $this, 'addLicenseToImageMarkup'], 10, 2 );
		
		// stop wordpress from stripping image meta from resized images.
		add_filter ('image_strip_meta', function() {
			
			return pp_api::getOption( 'core', 'metadata', 'strip_metadata_from_resized_image');
		});

		
		// registers display widgets
		add_action( 'widgets_init', [ $this, 'registerWidgets' ] );
		
		// The block counterpart of the taxonomy widget, for block themes, which
		// have no widget areas.
		register_block_type( __DIR__ . '/blocks/image-taxonomies', [
			'render_callback' => [ ImageTaxonomies::class, 'renderBlock' ],
		] );
		ImageTaxonomyRest::addHooks();
		
		if ( pp_api::getOption( 'core', 'metadata', 'custom_taxonomies_enable' ) ) {
			
			// registers the actual taxonomies
			 $this->registerTaxonomies();
			
			//registers the attachment page sidebar
			register_sidebar(
			
				[
				  'name' => 'Image Page',
				  'id' => 'photopress-image-primary',
				  'description' => 'Widgets in this area will be shown on single image pages.',
				  'before_widget' => '<section id="%1$s" class="widget %2$s">',
				  'after_widget' => '</section>'
				]
			);
			
			/**
			 * Taxonomy handler for when new images are uploaded
			 */
			add_action('add_attachment', [ $this, 'addAttachment' ] );
			
			/**
			 * Handler for extracting meta data from image file and storing it as
			 * part of the Post's meta data.
			 */
			//add_filter('wp_generate_attachment_metadata', 'papt_storeNewMeta',1,2);
			
			add_action('enable-media-replace-upload-done', [ $this, 'updateAttachment' ], 1, 2 );

			/**
			 * Handler for when PhotoPress gives an image a new file (see
			 * MediaRest): its terms and alt text come from the new file,
			 * unless the client asked for them to be left alone.
			 */
			add_action( 'photopress_attachment_file_replaced', [ $this, 'fileReplaced' ], 10, 4 );
						
			// needed to show attachments on taxonomy pages
			add_filter( 'pre_get_posts', [ $this, 'makeImagesVisibleToTaxQueries' ] );
			
		}
	}	
	
	public function defineAdminHooks() {
		
	
	}
	
	/**
	 * Adds data-* attributes required by img tags in post HTML
	 * content. To be used by 'the_content' filter.
	 *
	 *
	 * @param string $content HTML content of the post
	 * @return string Modified HTML content of the post
	 */
	public function addAttributesToImagesInContent( $content, $block ) {
		
		$allowedBlocks = ['photopress/gallery', 'core/image'];
		
		if( ! in_array( $block['blockName'], $allowedBlocks ) ) {
			
			return $content;
  		}
		
		
		// Attachment ids from wp-image-N classes or data-id attributes.
		$p   = new \WP_HTML_Tag_Processor( $content );
		$ids = [];

		while ( $p->next_tag( 'img' ) ) {

			$id = $this->attachmentIdOf( $p );

			if ( $id ) {
				$ids[ $id ] = true;
			}
		}

		if ( empty( $ids ) ) {
			
			return $content;
		}
		
		$attachments = get_posts(
			
			[
				'include'          => array_keys( $ids ),
				'post_type'        => 'any',
				'post_status'      => 'any',
				'suppress_filters' => false,
			]
		);
		
		$attributes_by_id  = [];
		$licensable_images = [];
		
		foreach ( $attachments as $attachment ) {
			
			$attributes_by_id[ $attachment->ID ] = $this->addAttributesToImages( [], $attachment );
			
			// add licensable image
			$licensable_images[] = $attachment->ID;
		}

		/*
		 * Add each attribute only where the tag does not already have it, so
		 * values the block itself saved (a gallery image's data-caption, for
		 * one) win over the media library's. Splicing the attributes in front
		 * of the existing ones made the media library's win, as the first of
		 * two duplicate attributes is the one browsers keep.
		 */
		$p = new \WP_HTML_Tag_Processor( $content );

		while ( $p->next_tag( 'img' ) ) {

			$id = $this->attachmentIdOf( $p );

			if ( ! $id || empty( $attributes_by_id[ $id ] ) ) {
				continue;
			}

			foreach ( $attributes_by_id[ $id ] as $name => $value ) {

				if ( null === $p->get_attribute( $name ) ) {
					// The builder escapes its values; the processor escapes again.
					$p->set_attribute( $name, html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5 ) );
				}
			}
		}

		$content = $p->get_updated_html();
		
		// add licensable images
		$content .= $this->renderLicensingSchema( $licensable_images );
	
		return $content;
	}
	
	/**
	 * The attachment id of the img the processor is on, from its wp-image-N
	 * class or data-id attribute.
	 */
	private function attachmentIdOf( \WP_HTML_Tag_Processor $p ) {

		if ( preg_match( '/\bwp-image-(\d+)\b/', (string) $p->get_attribute( 'class' ), $m ) ) {
			return absint( $m[1] );
		}

		return absint( $p->get_attribute( 'data-id' ) );
	}

	public function addAttributesToImages( $attr, $attachment = null ) {
		
		$attachment_id = intval( $attachment->ID );
		
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
		
			return $attr;
		}

		$orig_file       = wp_get_attachment_image_src( $attachment_id, 'full' );
		$orig_file       = isset( $orig_file[0] ) ? $orig_file[0] : wp_get_attachment_url( $attachment_id );
		
		//$attachment       = get_post( $attachment_id );
		$attachment_title 	= wptexturize( $attachment->post_title );
		$attachment_caption = wptexturize( $attachment->post_excerpt );
		$attachment_desc  	= wpautop( wptexturize( $attachment->post_content ) );
		$attachment_url		= get_attachment_link( $attachment_id );
		
		$attr[ 'data-orig-file' ]         	= esc_attr( $orig_file );
		$attr[ 'data-attachment-url' ]		= esc_attr( $attachment_url );
		
		// failsafe needed by slideshow in case
		// guttenebrg does not set the caption for a gallery
		
		if ( ! array_key_exists('data-aspectratio', $attr) ) {
			
			$meta = wp_get_attachment_metadata( $attachment_id );
			$attr['data-aspectratio']  = isset( $meta['width'] ) ? round( intval( $meta['width'] ) / intval( $meta['height'] ), 2 ) : '';
		}
		
		if ( ! array_key_exists('data-caption', $attr) ) {
			
			$attr[ 'data-caption' ]     	    = esc_attr( htmlspecialchars( $attachment_caption ) );
		}
		
		if ( ! array_key_exists('data-image-title', $attr) ) {
		
			$attr[ 'data-image-title' ]       	= esc_attr( htmlspecialchars( $attachment_title ) );
		}
		
		if ( ! array_key_exists('data-image-description', $attr) ) {
			
			$attr[ 'data-image-description' ] 	= esc_attr( htmlspecialchars( $attachment_desc ) );	
		}
		
		$attr[ 'srcset']					= wp_get_attachment_image_srcset( $attachment_id );
		
		
		
		return $attr;
	}
	
	public function addLicenseToImageMarkup( $markup, $attachment_id ) {
		
		$markup .= $this->renderLicensingSchema( $attachment_id );
		
		return $markup;
	}
	
	public function renderLicensingSchema( $attachment_ids ) {
		
		$licensing_schema = $this->generateLicensingSchema( $attachment_ids );
		
		$content = "\n";
		$content .= sprintf('<script type="application/ld+json">%s</script>', json_encode( $licensing_schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		
		return $content;
	}
	
	// can take an array of Ids or a single id
	public function generateLicensingSchema( $attachment_ids = [] ) {
		
		$schema_block = [];
		
		if ( is_array( $attachment_ids ) ) {
			
			foreach ( $attachment_ids as $id ) {
				
				$schema_block[] = $this->generateImageLicenseSchema( $id );
			}
			
		} else {
			
			$schema_block = $this->generateImageLicenseSchema( $attachment_ids );
		}
		
		return $schema_block;
	}
	
	public function generateImageLicenseSchema( $attachment_id ) {
		
		$schema = [
			
			"@context"				=> "https://schema.org/",
			"@type"					=> "ImageObject",
			"contentUrl" 			=> wp_get_attachment_url( $attachment_id ),
			"license" 				=> pp_api::getOption( 'core', 'metadata', 'web_statement_of_rights'),
			"acquireLicensePage" 	=> pp_api::getOption( 'core', 'metadata', 'licensor_url')
			
		];
		
		return $schema;
	}
	
	public function storeMoreMetaData( $meta, $file, $image_type, $iptc, $exif ) {
		
		// WordPress takes the title and caption from IPTC or EXIF only. Files
		// with XMP but no IPTC give theirs from dc:title and dc:description,
		// on upload and when a file is replaced alike.
		if ( empty( $meta['title'] ) || empty( $meta['caption'] ) ) {
			
			$md = new XmpReader();
			$md->loadFromFile( $file );
			
			foreach ( [ 'title' => 'dc:title', 'caption' => 'dc:description' ] as $key => $tag ) {
				
				$value = $md->getXmp( $tag );
				
				if ( empty( $meta[ $key ] ) && is_string( $value ) && '' !== trim( $value ) ) {
					$meta[ $key ] = trim( $value );
				}
			}
		}
		
		//pp_api::debug($meta);
		//pp_api::debug($image_type);
		//pp_api::debug($iptc);
		//pp_api::debug($exif);
		
		if ( array_key_exists('Make', $exif) && $exif['Make'] && array_key_exists('Model', $exif) && $exif['Model'] ) {
		
			$meta['camera'] = $exif['Make'] . ' ' . $exif['Model'];
		}
		
		if ( array_key_exists( 'LightSource', $exif ) ) {
		
			$meta['LightSource'] = $this->lookupLightSource( $exif['LightSource'] ) ;
		}
		
		return $meta;
	}
	
	// Lookup the LightSource value
	// see: https://exiftool.org/TagNames/EXIF.html#LightSource
	public function lookupLightSource( $code ) {
		
		$values = [
			
			0 => 'Unknown',
			1 => 'Daylight',
			2 => 'Fluorescent',
			3 => 'Tungsten (incandescent light)',
			4 => 'Flash',
			9 => 'Fine weather',
			10 => 'Cloudy weather',
			11 => 'Shade',
			12 => 'Daylight fluorescent (D 5700 - 7100K)',
			13 => 'Day white fluorescent (N 4600 - 5400K)',
			14 => 'Cool white fluorescent (W 3900 - 4500K)',
			15 => 'White fluorescent (WW 3200 - 3700K)',
			17 => 'Standard light A',
			18 => 'Standard light B',
			19 => 'Standard light C',
			20 => 'D55',
			21 => 'D65',
			22 => 'D75',
			23 => 'D50',
			24 => 'ISO studio tungsten',
			255 => 'Other light source'
		];
		
		if (array_key_exists( $code, $values ) ) {
		
			return $values[ $code ]; 
		
		} else {
			
			return $values[0];
		}
	}
	
	public function registerWidgets() {
		
		register_widget( 'PhotoPress\modules\metadata\XmpDisplayWidget' );
		register_widget( 'PhotoPress\modules\metadata\ExifDisplayWidget' );
	}
		
	public function registerOptions() {	

		return [
		
			'custom_taxonomies_enable'				=> [
			
				'default_value'							=> true,
				'field'									=> [
					'type'									=> 'boolean',
					'title'									=> 'Enable Image Meta Data',
					'page_name'								=> 'metadata',
					'section'								=> 'general',
					'description'							=> 'Enable Image Meta Data.',
					'label_for'								=> 'Enable Image Meta Data.',
					'error_message'							=> 'You must select On or Off.'		
				]	
			],
			
			'embed_licensor_enable' => [
				
				'default_value'							=> false,
				'field'									=> [
					'type'									=> 'boolean',
					'title'									=> 'Embed License in image file.',
					'page_name'								=> 'metadata',
					'section'								=> 'general',
					'description'							=> 'Custom image taxonomies.',
					'label_for'								=> 'Custom image taxonomies.',
					'error_message'							=> ''		
				]	
			],
			
			'web_statement_of_rights'	=> [
				
				'default_value'							=> '',
				'field'									=> [
					'type'									=> 'url',
					'title'									=> 'Web Statement of Rights',
					'page_name'								=> 'metadata',
					'section'								=> 'general',
					'description'							=> 'The URL of the image license on the web.',
					'label_for'								=> 'The URL of the image license on the web.',
					'error_message'							=> ''		
				]	
			],
			
			'licensor_name'	=> [
				
				'default_value'							=> '',
				'field'									=> [
					'type'									=> 'text',
					'title'									=> 'Licensor Name',
					'page_name'								=> 'metadata',
					'section'								=> 'general',
					'description'							=> 'The legal name of the licensor of your images.',
					'label_for'								=> 'The legal name of the licensor of your images.',
					'error_message'							=> ''		
				]	
			],
			
			'licensor_url'	=> [
				
				'default_value'							=> '',
				'field'									=> [
					'type'									=> 'url',
					'title'									=> 'Licensor Url',
					'page_name'								=> 'metadata',
					'section'								=> 'general',
					'description'							=> 'The url where someone may obtain a license for your images.',
					'label_for'								=> 'The url where someone may obtain a license for your images.',
					'error_message'							=> ''		
				]	
			],
						
			'custom_taxonomies' => [
				
				'default_value'							=> $this->getDefaultTaxonomyDefinitions(),
				'field'									=> [
					'type'									=> 'none',
					'title'									=> 'Custom Meta Data Taxonomies',
					'page_name'								=> 'metadata',
					'section'								=> 'general',
					'description'							=> 'Custom image taxonomies.',
					'label_for'								=> 'Custom image taxonomies.',
					'error_message'							=> ''		
				]	
			],
			
			'custom_taxonomies_tag_delimiter'	=> [
				
				'default_value'							=> ':',
				'field'									=> [
					'type'									=> 'text',
					'title'									=> 'XMP Value Delimiter',
					'page_name'								=> 'metadata',
					'section'								=> 'general',
					'description'							=> 'Delimiter used to parse sub taxonomies from XMP values.',
					'label_for'								=> 'Delimiter used to parse sub taxonomies from XMP values',
					'error_message'							=> ''		
				]
			],
			
			'alt_text_enable'				=> [
			
				'default_value'							=> true,
				'field'									=> [
					'type'									=> 'boolean',
					'title'									=> 'Populate Alt Text With Meta-data ',
					'page_name'								=> 'metadata',
					'section'								=> 'general',
					'description'							=> 'Enable meta-data driven alt text.',
					'label_for'								=> 'Enable meta-data driven alt text.',
					'error_message'							=> 'You must select On or Off.'		
				]	
			],
			
			'alt_text_template'	=> [
				
				'default_value'							=> '[photoshop:Headline]. [photopress:stringOfKeywords].',
				'field'									=> [
					'type'									=> 'text',
					'title'									=> 'XMP template for Alt Text',
					'page_name'								=> 'metadata',
					'section'								=> 'general',
					'description'							=> 'The XMP tag template used to populate image alt text.',
					'label_for'								=> 'The XMP tag template used to populate image alt text.',
					'error_message'							=> ''		
				]
			],
			
			'description_template'	=> [
				
				'default_value'							=> '',
				'field'									=> [
					'type'									=> 'text',
					'title'									=> 'XMP template for Description',
					'page_name'								=> 'metadata',
					'section'								=> 'general',
					'description'							=> 'The XMP tag template used to set an image\'s description on upload and when its file is replaced, e.g. [photoshop:Headline]. Leave empty to leave descriptions alone.',
					'label_for'								=> 'The XMP tag template used to set image descriptions.',
					'error_message'							=> ''		
				]
			],
			
			// depricated
			'alt_text_tag'	=> [
				
				'default_value'							=> 'photoshop:Headline',
				'field'									=> [
					'type'									=> 'text',
					'title'									=> 'XMP Template for Alt Text',
					'page_name'								=> 'metadata',
					'section'								=> 'general',
					'description'							=> 'The XMP template used to populate image alt text.',
					'label_for'								=> 'The XMP template used to populate image alt text.',
					'error_message'							=> ''		
				]
			],
			
			'strip_metadata_from_resized_image'				=> [
			
				'default_value'							=> false,
				'field'									=> [
					'type'									=> 'boolean',
					'title'									=> 'Strip Meta-data From Resized Images',
					'page_name'								=> 'metadata',
					'section'								=> 'general',
					'description'							=> 'Strip the embedded meta-data from resized images generated by WordPress.',
					'label_for'								=> 'Strip the embedded meta-data from resized images generated by WordPress',
					'error_message'							=> 'You must select On or Off.'		
				]	
			],
						
		];
		
	}
	
	public function registerSettingsPages() {
		
		$pages = [];
		
		$pages['metadata'] = [
			
			'parent_slug'					=> 'photopress-core-base',
			'title'							=> 'Image Meta Data',
			'menu_title'					=> 'Image Meta Data',
			'required_capability'			=> 'manage_options',
			'menu_slug'						=> 'photopress-metadata',
			'description'					=> 'Settings that control the meta data of images.',
			'sections'						=> [
				'general'						=> [
					'id'							=> 'general',
					'title'							=> 'General',
					'description'					=> 'The following settings control how meta data of images.'
				]
			],
			'noPhpRender'						=> true
		];
		
		return $pages;
	}
	
	public function getDefaultTaxonomyDefinitions() {
		
		$taxonomies = [
			
			[
				'id'			=> 'photos_camera',
				'pluralLabel' 	=> 'cameras',
				'singularLabel'	=> 'camera',
				'tag'			=> 'photopress:camera',
				'parseTagValue'	=> false
			],
			
			[
				'id'			=> 'photos_lens',
				'pluralLabel'	=> 'lenses',
				'singularLabel'	=> 'lens',
				'tag'			=> 'aux:Lens',
				'parseTagValue'	=> false
			],
	
			[
				'id'			=> 'photos_city',
				'pluralLabel' 	=> 'cities',
				'singularLabel'	=> 'city',
				'tag'			=> 'photoshop:City',
				'parseTagValue'	=> false
			],
	
			[
				'id'			=> 'photos_state',
				'pluralLabel' 	=> 'states',
				'singularLabel'	=> 'state',
				'tag'			=> 'photoshop:State',
				'parseTagValue'	=> false
			],
			
			[
				'id'			=> 'photos_country',
				'pluralLabel' 	=> 'countries',
				'singularLabel'	=> 'country',
				'tag'			=> 'photoshop:Country',
				'parseTagValue'	=> false
			],
			
			[
				'id'			=> 'photos_people',
				'pluralLabel' 	=> 'people',
				'singularLabel'	=> 'person',
				'tag'			=> 'dc:subject',
				'parseTagValue'	=> true
			],
			
			[
				'id'			=> 'photos_keywords',
				'pluralLabel' 	=> 'keywords',
				'singularLabel'	=> 'keyword',
				'tag'			=> 'dc:subject',
				'parseTagValue'	=> false
			],
			
		];	
		
		return apply_filters( 'photopress/taxonomies/defaultDefinitions', $taxonomies );

	}
	
	public function registerTaxonomies() {
	
		$taxonomies = pp_api::getOption('core', 'metadata', 'custom_taxonomies');		
		//print_r($taxonomies);
		foreach ($taxonomies as $tax ) {
			
			$id = $tax[ 'id' ];
			$upper_plural = ucwords( $tax[ 'pluralLabel' ] );
			$upper_singular = ucwords( $tax[ 'singularLabel' ] );
			
			register_taxonomy( $id, 'attachment', array(
				
					'hierarchical' => false, 
					'labels' => array(
						
						'name'             				=> __( $upper_plural , 'taxonomy general name' ),
						'singular_name'     			=> __( $upper_singular, 'taxonomy singular name' ),
						'search_items'      			=> __( 'Search ' . $upper_plural ),
						'popular_items'					=> __( 'Popular ' . $upper_plural ),
						'all_items'         			=> __( 'All ' . $upper_plural ),
						'parent_item'       			=> null,
						'parent_item_colon' 			=> null,
						'edit_item'         			=> __( 'Edit ' . $upper_singular ),
						'update_item'       			=> __( 'Update ' . $upper_singular ),
						'add_new_item'      			=> __( 'Add New ' . $upper_singular  ),
						'new_item_name'     		 	=> __( 'New ' . $upper_singular . ' Name' ),
						'separate_items_with_commas' 	=> __( 'Separate ' . $tax[ 'pluralLabel' ] . ' with commas.' ),
						'add_or_remove_items'        	=> __( 'Add or remove ' . $upper_plural ),
						'choose_from_most_used'      	=> __( 'Choose from the most used '. $tax[ 'pluralLabel' ]),
						'not_found'                  	=> __( 'No ' . $tax[ 'pluralLabel' ] . ' found.' ),
						'menu_name'         			=> __( $upper_plural )
					),
					
					'query_var' => $id, 
					// In REST for the editor and core blocks, but only for users
					// who can edit posts; see ImageTaxonomyRest.
					'show_in_rest'          => true,
					'rest_controller_class' => TermsController::class,
					'rewrite' => array('slug' => strtolower( $tax[ 'singularLabel' ] ), 'ep_mask' => EP_PERMALINK  ),
					'update_count_callback'	=> '_update_generic_term_count',
					'show_admin_column' => true,
					'public'	=> true 
				)
			);
		}

	}
	
	public function addAttachment( $id ) {
		
		//extract metadata from file	
		$file = get_attached_file( $id );
		$md = new XmpReader();
		$md->loadFromFile( $file );
		
		// set the taxonomy terms
		$this->setTaxonomyTerms( $id, $md );
		
		// set the description, when a template is configured
		$description = $this->generateDescription( $md );
		
		if ( null !== $description && $description !== get_post_field( 'post_content', $id ) ) {
			wp_update_post( [ 'ID' => $id, 'post_content' => $description ] );
		}
		
		// set ALT text of image
		
		if ( pp_api::getOption('core', 'metadata', 'alt_text_enable') ) {
		
			$alt = $this->generateAltText( $md );
			
			// Nothing to say; leave any alt text that was supplied alone.
			if ( '' === $alt ) {
				return;
			}
			
			// Adds the row if there is none. It returns false when the value is
			// unchanged, which the add_post_meta() fallback here used to treat
			// as missing and add a duplicate row.
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}
	}
	
	/**
	 * photopress_attachment_file_replaced: reads the new file's metadata, as
	 * for an upload, when the client asked for it (the default).
	 */
	public function fileReplaced( $id, $replacements = [], $updated = [], $options = [] ) {
		
		if ( isset( $options['reprocess_metadata'] ) && ! $options['reprocess_metadata'] ) {
			return;
		}
		
		$this->addAttachment( $id );
	}
	
	/**
	 * Handler for updating the image meta when the file is replaced
	 */
	public function updateAttachment( $url ) {
		
		$id = attachment_url_to_postid( $url );
		$this->addAttachment( $id );
	}
	
	/**
	 * The description from the description template, or null when no
	 * template is set (the description is then not touched).
	 */
	public function generateDescription( $md ) {
		
		$template = trim( (string) pp_api::getOption( 'core', 'metadata', 'description_template' ) );
		
		if ( '' === $template ) {
			return null;
		}
		
		return self::fillAltTemplate( $template, function ( $tag ) use ( $md ) {
			return $md->getXmp( $tag );
		} );
	}
	
	/**
	 * photopress_attachment_description: the description of an image given
	 * a new file, from the template; null to leave it alone.
	 */
	public function descriptionForReplacedFile( $description, $id, $file ) {
		
		$md = new XmpReader();
		$md->loadFromFile( $file );
		
		return $this->generateDescription( $md ) ?? $description;
	}
	
	/**
	 * Generates the Alt Text of an image based on meta-data template.
	 *
	 * $md	object	XmpReader Meta-data object
	 */
	public function generateAltText( $md ) {
		
		$template = (string) pp_api::getOption('core', 'metadata', 'alt_text_template');
		
		$alt = self::fillAltTemplate( $template, function ( $tag ) use ( $md ) {
			return $md->getXmp( $tag );
		} );
		
		// The template's tags are all missing from this image.
		if ( '' === $alt ) {
			
			foreach ( [ 'dc:description', 'dc:title' ] as $tag ) {
				
				$alt = self::altTextValue( $md->getXmp( $tag ) );
				
				if ( '' !== $alt ) {
					break;
				}
			}
		}

		return $alt;
	}
	
	/**
	 * Replaces each [tag] in the template with the image's value, then removes
	 * the separators that empty tags leave behind, so that
	 * "[photoshop:Headline]. [dc:title]." gives "Bob." rather than
	 * ". Bob.", and nothing at all when every tag is empty.
	 *
	 * @param string   $template The alt text template.
	 * @param callable $lookup   Returns the value of a tag.
	 */
	public static function fillAltTemplate( $template, $lookup ) {
		
		$found = false;
		
		$alt = preg_replace_callback( '/\[([^\]]+)\]/', function ( $m ) use ( $lookup, &$found ) {
			
			$value = self::altTextValue( $lookup( $m[1] ) );
			$found = $found || '' !== $value;
			
			return $value;
		}, $template );
		
		if ( ! $found ) {
			return '';
		}
		
		$sep = '[.,;:|\x{2013}\x{2014}-]';
		$alt = preg_replace( "/($sep)(\s*$sep)+/u", '$1', $alt );   // ". ." -> "."
		$alt = preg_replace( "/^[\s.,;:|\x{2013}\x{2014}-]+/u", '', $alt ); // leading ". "
		$alt = preg_replace( '/\s+/u', ' ', $alt );
		
		return trim( $alt );
	}
	
	/**
	 * A tag value as plain text: lists are joined, markup is removed.
	 */
	private static function altTextValue( $value ) {
		
		if ( is_array( $value ) ) {
			$value = implode( ', ', array_filter( array_map( 'strval', $value ), 'strlen' ) );
		}
		
		return trim( wp_strip_all_tags( (string) $value ) );
	}
	
	public function embedLicense( $move, $file, $newfile, $type ) {

		$path = isset( $file['tmp_name'] ) ? $file['tmp_name'] : null;

		$wsr           = pp_api::getOption( 'core', 'metadata', 'web_statement_of_rights' );
		$licensor_name = pp_api::getOption( 'core', 'metadata', 'licensor_name' );
		$licensor_url  = pp_api::getOption( 'core', 'metadata', 'licensor_url' );

		$has_licensor = ( $licensor_name && $licensor_url );

		// Nothing configured, nothing to do.
		if ( ! $path || ! is_readable( $path ) || ( ! $wsr && ! $has_licensor ) ) {

			return $move;
		}

		/*
		 * Previously this shelled out to a vendored exiftool binary. That meant a
		 * ~12MB dependency fetched from exiftool.org (which stopped serving the
		 * pinned 12.30 tarball, breaking every build), a chmod at runtime to make
		 * the binary executable, and a hard requirement on exec() -- which plenty
		 * of managed hosts disable. Imagick is already WordPress's preferred image
		 * library and needs none of that.
		 */
		if ( ! class_exists( 'Imagick' ) ) {

			photopress_util::debug( 'Imagick unavailable; skipping licence embedding.' );

			return $move;
		}

		try {

			$im = new \Imagick( $path );

			$profiles = $im->getImageProfiles( '*', false );
			$existing = in_array( 'xmp', $profiles, true ) ? $im->getImageProfile( 'xmp' ) : '';

			$im->setImageProfile( 'xmp', self::mergeLicenceIntoXmp( $existing, $wsr, $licensor_name, $licensor_url ) );
			$im->writeImage( $path );
			$im->clear();

		} catch ( \Exception $e ) {

			// Never let a metadata problem block the upload itself.
			photopress_util::debug( 'Could not embed licence meta-data: ' . $e->getMessage() );
		}

		/*
		 * Pass through whatever we were given. This filter's return value decides
		 * whether WordPress performs the move; returning null unconditionally (as
		 * this did) would discard another plugin's decision and move the file a
		 * second time. Normally $move is null and the two are identical.
		 */
		return $move;
	}

	/**
	 * Splice the licence fields into an existing XMP packet.
	 *
	 * Imagick's setImageProfile() REPLACES the packet rather than merging, so
	 * writing one that contains only the licence would discard dc:title,
	 * dc:subject keywords, xmp:Rating, creator and any Lightroom settings the
	 * photographer had embedded. exiftool merged; this has to as well.
	 *
	 * Verified against a real upload: EXIF/IPTC/ICC profiles untouched, every
	 * XMP element preserved with the same multiplicity, no text content lost,
	 * idempotent on re-upload, and safe on a malformed or absent packet.
	 */
	protected static function mergeLicenceIntoXmp( $existing, $web_statement, $licensor_name, $licensor_url ) {

	    $doc = new \DOMDocument();
	    $doc->preserveWhiteSpace = false;
	    $doc->formatOutput       = false;

	    /*
	     * The packet is wrapped in <?xpacket …?> processing instructions and may
	     * carry a UTF-8 BOM. Strip both before parsing; they are re-added on write.
	     *
	     * NOTE: this is a block comment on purpose. A `//` comment containing the
	     * sequence ?> terminates PHP mode right there -- the parser leaves code mode
	     * mid-function and reports a bogus 'unclosed {' at end of file.
	     */
	    $body = trim( (string) $existing );
	    $body = preg_replace( '/^\xEF\xBB\xBF/', '', $body );
	    $body = preg_replace( '/<\?xpacket[^>]*\?>/', '', $body );
	    $body = trim( $body );

	    $loaded = false;
	    if ( $body !== '' ) {
	        $loaded = @$doc->loadXML( $body, LIBXML_NONET );
	    }

	    if ( ! $loaded ) {
	        // No usable XMP: start a minimal well-formed packet.
	        $doc = new \DOMDocument( '1.0', 'UTF-8' );
	        $meta = $doc->createElementNS( 'adobe:ns:meta/', 'x:xmpmeta' );
	        $doc->appendChild( $meta );
	        $rdf = $doc->createElementNS( 'http://www.w3.org/1999/02/22-rdf-syntax-ns#', 'rdf:RDF' );
	        $meta->appendChild( $rdf );
	    }

	    $xp = new \DOMXPath( $doc );
	    $xp->registerNamespace( 'x', 'adobe:ns:meta/' );
	    $xp->registerNamespace( 'rdf', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#' );

	    $rdf = $xp->query( '//rdf:RDF' )->item( 0 );
	    if ( ! $rdf ) {
	        $root = $doc->documentElement;
	        $rdf  = $doc->createElementNS( 'http://www.w3.org/1999/02/22-rdf-syntax-ns#', 'rdf:RDF' );
	        $root->appendChild( $rdf );
	    }

	    // Reuse an existing rdf:Description if there is one; XMP allows several,
	    // and creating another is legal but noisier.
	    $desc = $xp->query( './rdf:Description', $rdf )->item( 0 );
	    if ( ! $desc ) {
	        $desc = $doc->createElementNS( 'http://www.w3.org/1999/02/22-rdf-syntax-ns#', 'rdf:Description' );
	        $desc->setAttributeNS( 'http://www.w3.org/1999/02/22-rdf-syntax-ns#', 'rdf:about', '' );
	        $rdf->appendChild( $desc );
	    }

	    // Drop any prior copies of just the two properties we own, wherever they
	    // sit, so this is idempotent and re-uploading does not stack duplicates.
	    $xp->registerNamespace( 'xmpRights', 'http://ns.adobe.com/xap/1.0/rights/' );
	    $xp->registerNamespace( 'plus', 'http://ns.useplus.org/ldf/xmp/1.0/' );
	    foreach ( [ '//xmpRights:WebStatement', '//plus:Licensor' ] as $q ) {
	        foreach ( iterator_to_array( $xp->query( $q ) ) as $n ) {
	            $n->parentNode->removeChild( $n );
	        }
	    }

	    if ( $web_statement ) {
	        $el = $doc->createElementNS( 'http://ns.adobe.com/xap/1.0/rights/', 'xmpRights:WebStatement' );
	        $el->appendChild( $doc->createTextNode( $web_statement ) );
	        $desc->appendChild( $el );
	    }

	    if ( $licensor_name && $licensor_url ) {
	        $lic = $doc->createElementNS( 'http://ns.useplus.org/ldf/xmp/1.0/', 'plus:Licensor' );
	        $seq = $doc->createElementNS( 'http://www.w3.org/1999/02/22-rdf-syntax-ns#', 'rdf:Seq' );
	        $li  = $doc->createElementNS( 'http://www.w3.org/1999/02/22-rdf-syntax-ns#', 'rdf:li' );
	        $li->setAttributeNS( 'http://www.w3.org/1999/02/22-rdf-syntax-ns#', 'rdf:parseType', 'Resource' );
	        $n = $doc->createElementNS( 'http://ns.useplus.org/ldf/xmp/1.0/', 'plus:LicensorName' );
	        $n->appendChild( $doc->createTextNode( $licensor_name ) );
	        $u = $doc->createElementNS( 'http://ns.useplus.org/ldf/xmp/1.0/', 'plus:LicensorURL' );
	        $u->appendChild( $doc->createTextNode( $licensor_url ) );
	        $li->appendChild( $n );
	        $li->appendChild( $u );
	        $seq->appendChild( $li );
	        $lic->appendChild( $seq );
	        $desc->appendChild( $lic );
	    }

	    $xml = $doc->saveXML( $doc->documentElement );

	    return "<?xpacket begin=\"\xEF\xBB\xBF\" id=\"W5M0MpCehiHzreSzNTczkc9d\"?>\n"
	         . $xml
	         . "\n<?xpacket end=\"w\"?>";
	}

	public function setTaxonomyTerms( $id, $md ) {
		
		$taxonomies = pp_api::getOption('core', 'metadata', 'custom_taxonomies');
	
		$c = [];
		$toInsert = [];
		
		// transform the tax definitions into a control array structured by tag
		// families can have multiple children and parent taxonomies
		foreach ( $taxonomies as $tax ) {
			
			if ( ! $tax['parseTagValue'] ) {
				
				$c[ $tax['tag'] ]['parents'][] = $tax['id'];
			} else {
				
				$c[ $tax['tag'] ]['children'][] = $tax['id'];
			}	
		}
		
		foreach( $c as $tag => $family) {
			
			// get value from xmp tag
			
			$value = $md->getXmp( $tag );
			
			// The image does not carry this tag (getXmp() returns null when
			// the image has no XMP at all). Nothing to assign.
			if ( null === $value || '' === $value || [] === $value ) {
				continue;
			}
			
				// maybe parse the value
				
			if ( is_array( $value ) ) {
				
				$d = [];
				
				// loop through the value array
				foreach ( $value as $v ) {
					
					$ret = $this->matchTermToTaxonomy( $v, $family );
					$toInsert = array_merge_recursive($toInsert, $ret);
				}
				
			} else {
				
				$ret = $this->matchTermToTaxonomy( $value, $family );
				$toInsert = array_merge_recursive($toInsert, $ret);			
			}
		}
		
		// Every configured taxonomy matches the file: one the file has
		// nothing for is emptied, so a keyword removed from the file goes.
		foreach ( $c as $family ) {
			foreach ( array_merge( $family['parents'] ?? [], $family['children'] ?? [] ) as $tax_id ) {
				if ( ! isset( $toInsert[ $tax_id ] ) && taxonomy_exists( $tax_id ) ) {
					$toInsert[ $tax_id ] = [];
				}
			}
		}
		
		// loop through all the taxonomies and insert the terms
		foreach ( $toInsert as $tax_id => $terms ) {
			wp_defer_term_counting(true);
			wp_set_object_terms($id, $terms, $tax_id, $append = false);
			wp_defer_term_counting(false);
		}
	}
	
	function matchTermToTaxonomy( $value, $family ) {
		
		$toInsert = [];
		$delim = pp_api::getOption('core', 'metadata', 'custom_taxonomies_tag_delimiter');
		$term_inserted = false;
		
		// if children and delimiter
		if ( array_key_exists('children', $family ) && ! empty( $family['children'] ) && $delim && is_string( $value ) && strpos( $value, $delim ) ) {
	
			// check to see that there is a matching child tax
			$pair = explode( $delim, $value, 2 ); 
			
			// trim
			$child_label = trim( $pair[0] );
			$child_value = trim( $pair[1] );
			$child_key = 'pp_'.$child_label;
			$child_old_key = 'photos_'.$child_label;
			
			// if the child is part of the family insert it as the term can only we associated with one child.
			if ( in_array( $child_key, $family['children'] )  ) {
				
				$toInsert[ $child_key ][] = $child_value;
				//wp_set_object_terms($id, $child_value, $child_id, $append = false);
				$term_inserted = true;
			} 
			
			// if the child is part of the family insert it as the term can only we associated with one child.
			if (  in_array( $child_old_key, $family['children'] ) ) {
				
				$toInsert[ $child_old_key ][] = $child_value;
				//wp_set_object_terms($id, $child_value, $child_id, $append = false);
				$term_inserted = true;
			} 
		}
		
		if (! $term_inserted ) {
			
			// check for parents
			if ( array_key_exists('parents', $family ) && ! empty( $family['parents'] ) ) {
				
				// insert for each parent
				foreach ( $family['parents']  as $parent_id ) {
					
					$toInsert[ $parent_id ][] = $value;
					//wp_set_object_terms($id, $value, $parent_id, $append = false);
				}
			}
		}
		
		return $toInsert;			
	}

	public function makeImagesVisibleToTaxQueries( $query ) {
		
		if ( is_tax() ) {
		
			$query->set( 'post_status', 'all' );
		}
		
		return $query;

	}
	
	public function sortImageSrcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		//print_r($sources);
		ksort( $sources );
		
		return $sources;
	}
	
	public function setMaxSrcsetSize( $max_width, $size_array ) {
		
		return 3000;
	}
}

?>