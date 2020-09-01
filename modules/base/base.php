<?php 

namespace PhotoPress\modules\base;
use photopress_module; 
use photopress_util;
use pp_api;

class base extends photopress_module {

	public function definePublicHooks() {
	
		add_action( 'wp_enqueue_scripts', [ $this, 'photopress_base_js' ] );
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
			array( 'wp-api', 'wp-i18n', 'wp-components', 'wp-element' ), PHOTOPRESS_CORE_VERSION, true 
		);
		
		wp_localize_script( 'photopress-options-script', 'photopress_options_conf', [
			
			'plugin_url' => plugins_url( '', dirname(dirname(__FILE__)) )
		] );
		
		wp_enqueue_style( 
			'photopress-options-style', 
			plugins_url( '/', __FILE__ ) . '../../dist/blocks.style.build.css', 
			array( 'wp-components' ),
			PHOTOPRESS_CORE_VERSION 
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
		];
	}
	
	public function registerSettingsPages() {
		
		$pages = array();
		
		$pages['general'] = array(
			
			'parent_slug'				=> 'photopress-core-base',
			'is_top_level'				=> true,
			'top_level_menu_title'		=> 'PhotoPress',
			'title'						=> 'PhotoPress Settings',
			'menu_title'				=> 'Settings',
			'required_capability'		=> 'manage_options',
			'menu_slug'					=> 'photopress-core-base',
			'menu-icon'					=> 'dashicons-camera-alt',
			'description'				=> 'These are settings for the PhotoPress plugin.',
			'sections'					=> [
				'general'						=> [
					'id'							=> 'general',
					'title'							=> 'General',
					'description'					=> 'The following settings control PhotoPress.'
				],
			
			],
			'render_callback'			=> array( $this, 'renderExtensionsPage')
		);
		
		return $pages;
	}
	
	public function renderExtensionsPage() {
		
		// This is not ideal
		$modules = pp_api::getActiveModules( 'core' );
		// todo add active module list to this div as a data attribute
		
		$modules = esc_attr(  json_encode( $modules ) );
		
		echo sprintf('<div id="photopress-core-options" data-modules=%s></div>', $modules);
	}
	
}
?>