<?php

if ( ! class_exists( 'pp_api') ) {
	
	require_once( 'class-pp-api.php' );
}

if ( ! class_exists( 'photopress_util') ) {
	
	require_once( 'class-util.php' );
}

class photopress_framework {
	
	public $options;
	public $active_extensions = [];
	public $version;
	public $maps = [];
	public $activeModules = [];
	
	public function __construct() {
		
		if ( defined('PHOTOPRESS_FRAMEWORK_VERSION') ) {
			
			$this->version = PHOTOPRESS_FRAMEWORK_VERSION;
		}
		
		$this->init();
		
	}
	
	
	public function init() {
		
		// Hook: Block assets.
		add_action( 'init', [$this, 'register_blocks_assets'] );
	}
	
	public static function singleton() {
		
		static $obj;
		
		if ( ! $obj ) {
			
			$obj = new photopress_framework();
		}
		
		return $obj;
	}
	
	public function setActiveModule( $package, $module_name, $args ) {
		
		$this->activeModules[ $package ][ $module_name ] = $args;
	}
	
	public function getActiveModules( $package ) {
		
		if ( array_key_exists( $package, $this->activeModules ) ) {
			
			return $this->activeModules[ $package ];
		}
	}

	
	public function activateExtension( $extension_name ) {
		
		$this->active_extensions[ $extension_name ] = true;
	}
	
	public function getActiveExtensions() {
		
		return array_keys( $this->active_extensions );
	}
	
	public function initializeOptions() {
		
		
	}
	
	public function getOptions() {
		
		if ( ! $this->options ) {
			
			$this->options = pp_api::factory( 'photopress_options', PHOTOPRESS_FRAMEWORK_PATH . 'class-options.php' );
		}
		
		return $this->options;
	}
	
	/**
	 * Enqueue Gutenberg block assets for both frontend + backend.
	 *
	 * Assets enqueued:
	 * 1. blocks.style.build.css - Frontend + Backend.
	 * 2. blocks.build.js - Backend.
	 * 3. blocks.editor.build.css - Backend.
	 *
	 * @uses {wp-blocks} for block type registration & related functions.
	 * @uses {wp-element} for WP Element abstraction ? structure of blocks.
	 * @uses {wp-i18n} to internationalize the block's text.
	 * @uses {wp-editor} for WP editor styles.
	 * @since 1.0.0
	 */
	function register_blocks_assets() { // phpcs:ignore
		
		// Register block styles for both frontend + backend.
		wp_register_style(
			'photopress-frontend', // Handle.
			plugins_url( 'dist/blocks.style.build.css', dirname( __FILE__ ) ), // Block style CSS.
			is_admin() ? array( 'wp-editor' ) : null, // Dependency to include the CSS after it.
			PHOTOPRESS_CORE_VERSION // filemtime( plugin_dir_path( __DIR__ ) . 'dist/blocks.style.build.css' ) // Version: File modification time.
		);
	
		// Register block editor script for backend.
		wp_register_script(
			'photopress-editor', // Handle.
			plugins_url( '/dist/blocks.build.js', dirname( __FILE__ ) ), // Block.build.js: We register the block here. Built with Webpack.
			array( 'wp-blocks', 'wp-i18n', 'wp-element', 'wp-editor' ), // Dependencies, defined above.
			PHOTOPRESS_CORE_VERSION, // filemtime( plugin_dir_path( __DIR__ ) . 'dist/blocks.build.js' ), // Version: filemtime ? Gets file modification time.
			true // Enqueue the script in the footer.
		);
	
		// Register block editor styles for backend.
		wp_register_style(
			'photopress-editor', // Handle.
			plugins_url( 'dist/blocks.editor.build.css', dirname( __FILE__ ) ), // Block editor CSS.
			array( 'wp-edit-blocks' ), // Dependency to include the CSS after it.
			PHOTOPRESS_CORE_VERSION // filemtime( plugin_dir_path( __DIR__ ) . 'dist/blocks.editor.build.css' ) // Version: File modification time.
		);
	
		// WP Localized globals. Use dynamic PHP stuff in JavaScript via `cgbGlobal` object.
		wp_localize_script(
			'photopress-editor',
			'photpressGlobal', // Array containing dynamic data for a JS Global.
			[
				'pluginDirPath' => plugin_dir_path( __DIR__ ),
				'pluginDirUrl'  => plugin_dir_url( __DIR__ ),
				// Add more data here that you want to access from `photopressGlobal` object.
			]
		);
		
		add_action( 'enqueue_block_assets', function() {
			// Masonry block
			if ( has_block( 'photopress/gallery') ) {
				wp_enqueue_script(
					'photopress-masonry',
					plugins_url( '/modules/gallery/assets/js/gallery-masonry.js', dirname( __FILE__ ) ),
					array( 'jquery', 'masonry', 'imagesloaded' ),
					PHOTOPRESS_CORE_VERSION,
					true
				);
			}
		});
	}

}

?>