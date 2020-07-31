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
		
		if ( pp_api::getOption( 'core', 'taxonomies', 'enable' ) ) {
			
			// registers the actual taxonomies
			add_action('init', [ $this, 'regTaxonomies' ] );
			
			// registers widgets
			//add_action( 'widgets_init', 'papt_load_widgets' );
			
			//registers the attachment page sidebar
			register_sidebar(array(
			  'name' => 'PhotoPress Image Page Sidebar',
			  'id' => 'papt-image-sidebar',
			  'description' => 'Widgets in this area will be shown on image (attachment) page templates.',
			  'after_widget' => '<BR>'
			));
			
			// needed to show attachments on taxonomy pages
			//add_filter( 'pre_get_posts', 'papt_makeAttachmentsVisibleInTaxQueries' );
			
			// shortcode for showing exif. needed?
			//add_shortcode('photopress-exif', 'photopress_showExif');

		}
	}	
	
	public function defineAdminHooks() {
		
		//wp_enqueue_style(' wp-block-library');
		
		
		if ( pp_api::getOption( 'core', 'taxonomies', 'enable' ) ) {
			
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
		}
	
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
			
			'default_taxonomies'			=> [
				
				'default_value'							=> $this->getTaxonomyDefinitions(),
				'field'									=> [
					'type'									=> 'none',
					'title'									=> 'Default Meta Data Taxonomies',
					'page_name'								=> 'metadata',
					'section'								=> 'general',
					'description'							=> 'Default taxonomies used to store image meta data.',
					'label_for'								=> 'Default taxonomies used to store image meta data',
					'error_message'							=> ''		
				]	
			],
			
			'custom_taxonomies' => [
				
				'default_value'							=> [],
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
	
	public function getTaxonomyDefinitions() {
		
		$taxonomies = [
			
			'photos_camera' => [
				
				'plural' => 'cameras',
				'singular'	=> 'camera',
			],
			
			'photos_lens' => [
				
				'plural' => 'lenses',
				'singular'	=> 'lens',
			],
	
			'photos_city' => [
				
				'plural' => 'cities',
				'singular'	=> 'city',
			],
	
			'photos_state' => [
				
				'plural' => 'states',
				'singular'	=> 'state',
			],
			
			'photos_country' => [
				
				'plural' => 'countries',
				'singular'	=> 'country',
			],
			
			'photos_people' => [
				
				'plural' => 'people',
				'singular'	=> 'person',
			],
			
			'photos_keywords' => [
				
				'plural' => 'keywords',
				'singular'	=> 'keyword',
			],
			
			'photos_collection' => [
				
				'plural' => 'collections',
				'singular'	=> 'collection',
			],
			
			'photos_prints' => [
				
				'plural' => 'prints',
				'singular'	=> 'print',
			]
			
		];	
		
		return apply_filters( 'photopress/taxonomies/definitions', $taxonomies );

	}
	
	public function registerTaxonomies() {
			
		$taxonomies = pp_api::getOption('core', 'taxonomies', 'taxonomies');		
		
		foreach ($taxonomies as $tax_name => $args ) {
			
			$upper_plural = ucwords( $args[ 'plural' ] );
			$upper_singular = ucwords( $args[ 'singular' ] );
			
			register_taxonomy( $tax_name, 'attachment', array(
				
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
						'separate_items_with_commas' 	=> __( 'Separate ' . $args[ 'plural' ] . ' people with commas' ),
						'add_or_remove_items'        	=> __( 'Add or remove ' . $upper_plural ),
						'choose_from_most_used'      	=> __( 'Choose from the most used '. $args[ 'plural' ]),
						'not_found'                  	=> __( 'No ' . $args[ 'plural' ] . ' found.' ),
						'menu_name'         			=> __( $upper_plural )
					),
					
					'query_var' => $tax_name, 
					'rewrite' => array('slug' => $args[ 'singular' ], 'ep_mask' => EP_PERMALINK  ),
					'update_count_callback'	=> '_update_generic_term_count',
					'show_admin_column' => true,
					'public'	=> true 
				)
			);
		}

	}
	
}

?>