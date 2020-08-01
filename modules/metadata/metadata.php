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

	public function definePublicHooks() {
		
		if ( pp_api::getOption( 'core', 'metadata', 'custom_taxonomies_enable' ) ) {
			
			// registers the actual taxonomies
			 $this->registerTaxonomies();
			
			// registers widgets
			//add_action( 'widgets_init', 'papt_load_widgets' );
			
			//registers the attachment page sidebar
			register_sidebar(array(
			  'name' => 'PhotoPress Image Page Sidebar',
			  'id' => 'papt-image-sidebar',
			  'description' => 'Widgets in this area will be shown on image (attachment) page templates.',
			  'after_widget' => '<BR>'
			));
			
			/**
			 * Action handler for when new images are uploaded
			 */
			add_action('add_attachment', [ $this, 'addAttachment' ] );
			
			/**
			 * Handler for extracting meta data from image file and storing it as
			 * part of the Post's meta data.
			 */
			//add_filter('wp_generate_attachment_metadata', 'papt_storeNewMeta',1,2);
			// is this really needed if all we are doing in pulling the metadata from the file again.
			//add_filter('wp_update_attachment_metadata', [ $this, 'updateAttachment' ], 1, 2 );
			add_action('attachment_updated', [ $this, 'updateAttachment' ], 1, 2 );
						
			// needed to show attachments on taxonomy pages
			//add_filter( 'pre_get_posts', 'papt_makeAttachmentsVisibleInTaxQueries' );
			
			// shortcode for showing exif. needed?
			//add_shortcode('photopress-exif', 'photopress_showExif');

		}
	}	
	
	public function defineAdminHooks() {
		
		//wp_enqueue_style(' wp-block-library');
		
		
		//if ( pp_api::getOption( 'core', 'metadata', 'enable' ) ) {
			
			/**
			 * Action handler for when new images are uploaded
			 */
			//add_action('add_attachment', 'papt_addAttachment');
			
			/**
			 * Handler for extracting meta data from image file and storing it as
			 * part of the Post's meta data.
			 */
			// no longer used
			//add_filter('wp_generate_attachment_metadata', 'papt_storeNewMeta',1,2);
			
			// is this really needed if all we are doing in pulling the metadata from the file again.
			//add_filter('wp_update_attachment_metadata', 'papt_storeNewMeta',1,2);
		//}
	
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
				
			]
						
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
		pp_api::debug('add attachment handler');
		//extract metadata from file	
		$file = get_attached_file($id);
		$md = new XmpReader();
		$md->loadFromFile($file);
		
		$this->setTaxonomyTerms($id, $md);
		
		// set ALT text and caption of image
		$post = get_post( $id );
		
		// make this configurable at some point
		$alt = $md->getXmp( pp_api::getOption('core', 'metadata', 'alt_text_tag') );
		
		if ( ! update_post_meta($id, '_wp_attachment_image_alt', $alt) ) {
			add_post_meta($id, '_wp_attachment_image_alt', $alt);
		}
	}
	
	public function updateAttachment( $id ) {
		pp_api::debug('update attachment handler');
		$md = new XmpReader();
		$file = get_attached_file($id);
		$md->loadFromFile($file);
		$data['papt_meta'] = $md->getAllMetaData();
		//print_r($data['papt_meta']);
		$this->setTaxonomyTerms($id, $md);
	
		return $data;
	}
	
	public function setTaxonomyTerms($id, $md) {
		
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
		
		return pp_api::debug($toInsert);
		// loop through all the taxonomies and insert the terms
		foreach ( $toInsert as $tax_id => $terms ) {
			
			wp_set_object_terms($id, $terms, $tax_id, $append = false);
		}
/*
		
		//print_r($md);
		// add keyword tags
		$keywords = $md->getKeywords();
		if (!empty($keywords)) {
			wp_set_object_terms($id, $keywords, 'photos_keywords', $append = false);
		}
		
		//add geo location tags
		$city = $md->getCity();
		if (!empty($city)) {
			wp_set_object_terms($id, $city, 'photos_city', $append = false);
		}
		
		$state = $md->getState();
		if (!empty($state)) {
			wp_set_object_terms($id, $state, 'photos_state', $append = false);
		}
		
		$country = $md->getCountry();
		if (!empty($country)) {
			wp_set_object_terms($id, $country, 'photos_country', $append = false);
		}
	
		//add camera tag
		$camera = $md->getCamera();
		if (!empty($camera)) {
			wp_set_object_terms($id, $camera, 'photos_camera', $append = false);
		}
		
		//add lens tag
		$lens = $md->getLens();
		if (!empty($lens)) {
			wp_set_object_terms($id, $lens, 'photos_lens', $append = false);
		}
	
*/
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