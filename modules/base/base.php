<?php 

namespace PhotoPress\modules\base;
use photopress_module; 
use photopress_util;

class base extends photopress_module {

	public function definePublicHooks() {
	
		add_action( 'wp_enqueue_scripts', array( $this, 'photopress_base_js' ) );
	
	}
	
	public function defineAdminHooks() {
		$page_hook_suffix = 'photopress-core-base-extensions';
		//add_action( "admin_print_scripts-{$page_hook_suffix}", [$this, 'options_assets'] );
		//add_action( "admin_init", [$this, 'options_assets'] );
		
		$this->option_assets();
	}
	
	public function photopress_base_js() {
		
		wp_enqueue_script(
			'photopress',
			plugins_url( 'js/photopress.js' , __FILE__ ),
			array( 'jquery' )
		);
	}
	
	public function option_assets() {
		
		wp_enqueue_script( 
			'photopress-options-script', 
			plugins_url( '/', __FILE__ ) . '../../dist/options.build.js', 
			array( 'wp-api', 'wp-i18n', 'wp-components', 'wp-element' ), '1.0.0', true 
		);
		
		wp_enqueue_style( 
			'photopress-options-style', 
			plugins_url( '/', __FILE__ ) . '../../dist/blocks.style.build.css', 
			array( 'wp-components' ) 
		);
	}

	public function registerOptions() {		

		return [
		
		'general_enable'						=> [
			
				'default_value'							=> true,
				'field'									=> [
					'type'									=> 'boolean',
					'title'									=> 'Enable PhotoPress',
					'page_name'								=> 'general',
					'section'								=> 'general',
					'description'							=> 'Enable or disable all functionality.',
					'label_for'								=> 'Enable general',
					'error_message'							=> 'You must select On or Off.'		
				]	
			],
		
/*
			'test'									=> [
			
				'default_value'							=> true,
				'field'									=> [
					'type'									=> 'boolean',
					'title'									=> 'Enable PhotoPress',
					'page_name'								=> 'general',
					'section'								=> 'general',
					'description'							=> 'Enable or disable all functionality.',
					'label_for'								=> 'Enable general',
					'error_message'							=> 'You must select On or Off.'		
				]	
			]
*/
		
		];
	}
	
	public function registerSettingsPages() {
		
		$pages = array();
		
		$pages['general'] = array(
			
			'parent_slug'				=> 'photopress-core-base',
			'is_top_level'				=> true,
			'top_level_menu_title'		=> 'PhotoPress',
			'title'						=> 'PhotoPress General Settings',
			'menu_title'				=> 'General',
			'required_capability'		=> 'manage_options',
			'menu_slug'					=> 'photopress-core-base',
			'description'				=> 'These are the general settings for PhotoPress plugin.',
			'sections'					=> array(
				'general'						=> array(
					'id'							=> 'general',
					'title'							=> 'General',
					'description'					=> 'The following settings control PhotoPress.'
				),
			
			)
		);
		

		$pages['extensions'] = array(
			'parent_slug'				=> 'photopress-core-base',
			'title'						=> 'PhotoPress Extensions',
			'menu_title'				=> 'Extensions',
			'required_capability'		=> 'manage_options',
			'menu_slug'					=> 'photopress-core-base-extensions',
			'description'				=> 'There are many extension plugins that add functionality to PhotoPress. Install extensions from the list below.',
			'sections'					=> array(),
			'render_callback'			=> array( $this, 'renderExtensionsPage')
			
		);

		
		return $pages;
	}
	
	public function renderExtensionsPage() {
		
		echo '<div id="photopress-core-options"></div>';
	}
	
}
?>