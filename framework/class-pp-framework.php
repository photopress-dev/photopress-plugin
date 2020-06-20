<?php

if ( ! class_exists( 'pp_api') ) {
	
	require_once( 'class-pp-api.php' );
}

if ( ! class_exists( 'photopress_util') ) {
	
	require_once( 'class-util.php' );
}

class photopress_framework {
	
	public $options;
	public $active_extensions = array();
	public $version;
	public $maps = array();
	
	public function __construct() {
		
		if ( defined('PHOTOPRESS_FRAMEWORK_VERSION') ) {
			
			$this->version = PHOTOPRESS_FRAMEWORK_VERSION;
		}
		
	}
	
	public static function singleton() {
		
		static $obj;
		
		if ( ! $obj ) {
			
			$obj = new photopress_framework();
		}
		
		return $obj;
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
}

?>