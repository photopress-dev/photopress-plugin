<?php 

/**
 * PhotoPress Options
 *
 * This class must be initialized after all WordPress plugins are loaded.
 * This object is currently lazy loaded when an option is requested via
 * the API.
 *
 */
class photopress_options {
	
	public $options;
	public $persistent_options = array();
	public $dirty_modules = array();
	public $init = false;
	
	public function __construct() {
		
		//$this->options = apply_filters('photopress_default_options', array() );
		$this->options = array();
		$this->init = true;
		
		$this->options['photopress_core_defaults'] = $this->setDefaultCoreOptions();
	}
	
	public function setDefaultCoreOptions() {
		
		return array(
		
			'excluded_public_taxonomies' => array( 'nav_menu', 'link_category', 'post_format' )
		
		);
	}
	
	
	public function __destruct() {
		
		$this->save();
	}
	
	public function set( $module_name, $key, $value) {
		
		$this->options[ $module_name ][ $key ] = $value;
	}
	
	public function persist( $module_name, $key, $value ) {
		
		$this->persistent_options[ $module_name ][ $key ] = $value;
		$this->dirty_modules[ $module_name ] = true;
	}
	
	public function get( $module_name, $name ) {
	
		// if default options are not yet loaded, load them.
		$this->loadDefaults( $module_name );
		
		// if persistent options are not loaded, then load them
		if ( ! array_key_exists( $module_name, $this->persistent_options ) ) {
			// this is a lazy load right now.
			// move this to __constructor once there is a static singleton holding PhotoPress.
			$this->loadModuleOptions( $module_name );
		}
		
		// return the persisted option first if it exists
		if ( array_key_exists( $module_name, $this->persistent_options ) &&
			 array_key_exists( $name, $this->persistent_options[ $module_name ] ) 
		) {
			
			return $this->persistent_options[ $module_name ][ $name ];
			
		} else {
	        	
			return $this->getDefaultValue( $module_name, $name );
		}
	}
	
	public function loadDefaults( $module_name ) {
		
		if ( ! array_key_exists( $module_name, $this->options ) ) {
			
			$filter_name = $module_name.'_default_options';
			
			$this->options[ $module_name ] = apply_filters( $filter_name, array() );
			
		}
	}
	
	public function getPersistedValue( $module_name, $name ) {
		
		// return the persisted option first if it exists
		if ( array_key_exists( $module_name, $this->persistent_options ) &&
			 array_key_exists( $name, $this->persistent_options[ $module_name ] ) 
		) {
			
			return $this->persistent_options[ $module_name ][ $name ];
		}
		
	}
	
	public function getDefaultValue( $module_name, $name ) {
		
		// if default options are not yet loaded, load them.
		$this->loadDefaults( $module_name );
		
		if ( array_key_exists( $module_name, $this->options ) &&
			 array_key_exists( $name, $this->options[ $module_name ] )
		) {
			return $this->options[ $module_name ][ $name ];
		}
	}
	
	public function loadModuleOptions( $module_name ) {
					
		$options = get_option( $module_name );
		
		if ( $options ) {
		
			$this->persistent_options[ $module_name ] = $options;
			
		} else {
			
			//$this->persistent_options[ $module_name ] = array();
		}
	}
	
	private function getDirtyModules() {
		
		return array_keys( $this->dirty_modules );
	}
	
	public function save() {
		
		$dirty_modules = $this->getDirtyModules();
		
		foreach ( $dirty_modules as $module_name ) {
			
			update_option( $module_name, $this->persistent_options[ $module_name ] );
		}
	}
}

?>