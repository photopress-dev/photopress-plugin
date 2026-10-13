<?php 

namespace PhotoPress\modules\base;
use photopress_module; 
use photopress_util;
use pp_api;

class base extends photopress_module {

	public function definePublicHooks() {

		$this->photopress_base_js();
	}

	public function defineAdminHooks() {

		add_action( 'admin_enqueue_scripts', [ $this, 'option_assets' ] );
	}

	/**
	 * The photopress object the slideshow's scripts build on, registered for
	 * them to load as a dependency: a page without a slideshow gets neither
	 * it nor jQuery.
	 */
	public function photopress_base_js() {

		wp_register_script(
			'photopress',
			plugins_url( 'js/photopress.js' , __FILE__ ),
			array( 'jquery' ),
			PHOTOPRESS_CORE_VERSION,
			true
		);
	}
	
	/**
	 * admin_enqueue_scripts: the settings app and its styles, on PhotoPress's
	 * own screens only. Elsewhere, in the block editor above all, they have
	 * no place: the block editor copied the styles into its canvas, with a
	 * warning.
	 */
	public function option_assets() {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which screen, not an action.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( 0 !== strpos( $page, 'photopress' ) ) {
			return;
		}

		// wp-i18n is used through the wp global rather than imported, so the
		// build cannot list it in the .asset.php.
		$asset = \photopress_util::getBuildAsset( 'options.build' );

		wp_enqueue_script(
			'photopress-options-script',
			plugins_url( '/', __FILE__ ) . '../../dist/options.build.js',
			array_unique( array_merge( array( 'wp-i18n' ), $asset['dependencies'] ) ), $asset['version'], true
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
		
		// The Offload Media tab only where WP Offload Media is installed,
		// active or not. The media module itself stays: it has the image
		// replacement route.
		if ( ! empty( $modules['media'] ) && 'missing' === \PhotoPress\modules\media\OffloadMedia::pluginState() ) {
			unset( $modules['media'] );
		}
		
		// Quoted: unquoted, the attribute ended at the first space in the JSON
		// (a module label such as "Offload Media").
		echo sprintf( '<div id="photopress-core-options" data-modules="%s"></div>', esc_attr( wp_json_encode( $modules ) ) );
	}
	
}
?>