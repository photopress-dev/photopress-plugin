<?php

namespace PhotoPress\modules\metadata;
use photopress_module;
use pp_api;

/**
 * Registers the Core Image Taxonomies
 *
 *
 */
class metadata extends photopress_module {
	
	public $label = 'Meta-data'; 
	public function definePublicHooks() {
		
		add_filter( 'max_srcset_image_width', 3000, 10,1);
		
		
		// add additional meta-data to images
		add_filter( 'wp_read_image_metadata', [$this, 'storeMoreMetaData'], 10, 5);
		
		// add additional attributes to images
		add_filter( 'wp_get_attachment_image_attributes', [$this, 'addAttributesToImages' ], 11, 2 );
		add_filter( 'the_content', array( $this, 'addAttributesToImagesInContent' ) );
		
		// stop wordpress from stripping image meta from resized images.
		add_filter ('image_strip_meta', pp_api::getOption( 'core', 'metadata', 'strip_metadata_from_resized_image' ) );
		
		// registers display widgets
		add_action( 'widgets_init', [ $this, 'registerWidgets' ] );
		
		if ( pp_api::getOption( 'core', 'metadata', 'custom_taxonomies_enable' ) ) {
			
			// registers the actual taxonomies
			 $this->registerTaxonomies();
			
			//registers the attachment page sidebar
			register_sidebar(
			
				[
				  'name' => 'Image Page',
				  'id' => 'photopress-image-primary',
				  'description' => 'Widgets in this area will be shown on single image pages.',
				  'before_widget' => '',
				  'after_widget' => ''
				]
			);
			
			/**
			 * Action handler for when new images are uploaded
			 */
			add_action('add_attachment', [ $this, 'addAttachment' ] );
			
			/**
			 * Handler for extracting meta data from image file and storing it as
			 * part of the Post's meta data.
			 */
			//add_filter('wp_generate_attachment_metadata', 'papt_storeNewMeta',1,2);
			
			add_action('enable-media-replace-upload-done', [ $this, 'updateAttachment' ], 1, 2 );
						
			// needed to show attachments on taxonomy pages
			//add_filter( 'pre_get_posts', 'papt_makeAttachmentsVisibleInTaxQueries' );
			
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
	function addAttributesToImagesInContent( $content ) {
	
		if ( ! preg_match_all( '/<img [^>]+>/', $content, $matches ) ) {
			
			return $content;
		}
		
		$selected_images = [];
		
		foreach ( $matches[0] as $image_html ) {
			
			if ( preg_match( '/(wp-image-|data-id=)\"?([0-9]+)\"?/i', $image_html, $class_id ) ) {
				
				$attachment_id = absint( $class_id[2] );
				
				/**
				 * If exactly the same image tag is used more than once, overwrite it.
				 * All identical tags will be replaced later with 'str_replace()'.
				 */
				$selected_images[ $attachment_id  ] = $image_html;
			}
		}

		$find = [];
		$replace = [];
		
		if ( empty( $selected_images ) ) {
			
			return $content;
		}

		$attachments = get_posts(
			
			[
				'include'          => array_keys( $selected_images ),
				'post_type'        => 'any',
				'post_status'      => 'any',
				'suppress_filters' => false,
			]
		);

		foreach ( $attachments as $attachment ) {
			
			$image_html = $selected_images[ $attachment->ID ];

			$attributes      = $this->addAttributesToImages( [], $attachment );
			
			$attributes_html = '';
			
			foreach ( $attributes as $k => $v ) {
				
				$attributes_html .= esc_attr( $k ) . '="' . esc_attr( $v ) . '" ';
			}

			$find[] = $image_html;
			
			$replace[] = str_replace( '<img ', "<img $attributes_html", $image_html );
		}

		$content = str_replace( $find, $replace, $content );
		
		return $content;
	}
	
	function addAttributesToImages( $attr, $attachment = null ) {
		
		$attachment_id = intval( $attachment->ID );
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return $attr;
		}

		$orig_file       = wp_get_attachment_image_src( $attachment_id, 'full' );
		$orig_file       = isset( $orig_file[0] ) ? $orig_file[0] : wp_get_attachment_url( $attachment_id );
		
		//$attachment       = get_post( $attachment_id );
		$attachment_title = wptexturize( $attachment->post_title );
		$attachment_desc  = wpautop( wptexturize( $attachment->post_content ) );
		//$size = isset( $meta['width'] ) ? intval( $meta['width'] ) . ',' . intval( $meta['height'] ) : '';
		$attr['data-orig-file']         = esc_attr( $orig_file );
		//$attr['data-orig-size']         = $size;
		$attr['data-image-title']       = esc_attr( htmlspecialchars( $attachment_title ) );
		$attr['data-image-description'] = esc_attr( htmlspecialchars( $attachment_desc ) );	
		
		return $attr;
	}
	
	public function storeMoreMetaData( $meta, $file, $image_type, $iptc, $exif ) {
		
		//pp_api::debug($meta);
		//pp_api::debug($image_type);
		//pp_api::debug($iptc);
		//pp_api::debug($exif);
		
		$meta['camera'] = $exif['Make'] . ' ' . $exif['Model'];
		
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
			
			'alt_text_tag'	=> [
				
				'default_value'							=> 'photoshop:Headline',
				'field'									=> [
					'type'									=> 'text',
					'title'									=> 'XMP Tag for Alt Text',
					'page_name'								=> 'metadata',
					'section'								=> 'general',
					'description'							=> 'The XMP tag used to populate image alt text.',
					'label_for'								=> 'The XMP tag used to populate image alt text.',
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
			]
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
		
		// set ALT text of image
		$alt = $md->getXmp( pp_api::getOption('core', 'metadata', 'alt_text_tag') );
		
		if ( ! update_post_meta( $id, '_wp_attachment_image_alt', $alt ) ) {
			
			add_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}
	}
	
	/**
	 * Handler for updating the image meta when the file is replaced
	 */
	public function updateAttachment( $url ) {
		
		$id = attachment_url_to_postid( $url );
		$md = new XmpReader();
		$file = get_attached_file( $id );
		$md->loadFromFile( $file );
		$this->setTaxonomyTerms( $id, $md );
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
		if ( array_key_exists('children', $family ) && ! empty( $family['children'] ) && strpos( $value, $delim ) ) {
	
			// check to see that there is a matching child tax
			$pair = explode( $delim, $value); 
			
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

	
}

?>