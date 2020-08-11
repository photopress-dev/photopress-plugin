<?php

// Include the PhotoPress Framework
if ( ! class_exists( 'photopress_framework') ) {
	
	require_once( PHOTOPRESS_FRAMEWORK_PATH . 'class-pp-framework.php' );
}


/**
 * Package 
 *
 * Abstract package class used to register a grouping of modules.
 * There is a 1:1 relationship between PhotoPress packages and WordPress plugins
 *
 * @since    1.0.0
 */
abstract class photopress_package {
	
	/**
	 * The unique identifier of this package.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $package_name    The string used to uniquely identify this package.
	 */
	protected $package_name;

	/**
	 * The label of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $package_label    The string used to label this package.
	 */
	protected $package_label;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $version    The current version of the package.
	 */
	public $version;
		
	/**
	 * The namespace of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $ns    The namespace of the package.
	 */
	protected $ns;
	
	/**
	 * The modules contained in the package.
	 *
	 * @since    1.0.0
	 * @access   public
	 * @var      array    $modules    The modules of the package.
	 */
	public $modules;
	
	public $dependencies;
	
	public static $admin_notices;
			
	/**
	 * Constructor
	 *
	 * Set the package name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the Dashboard and
	 * the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function __construct( $params ) {
		
		$defaults = array(
			
			'package_label'		=> '',
			'package_name'		=> '',
			'version'			=> '',
			'ns'				=> 'photopress',
			'dependencies'		=> array()
		);
		
		
		$params = photopress_util::setDefaultParams( $defaults, $params );
		
		if ( array_key_exists( 'package_label', $params ) ) {
			
			$this->package_label = $params['package_label'];	
		}
		
		if ( array_key_exists( 'package_name', $params ) ) {
			
			$this->package_name = $params['package_name'];	
		}
		
		if ( array_key_exists( 'version', $params ) ) {
			
			$this->version = $params['version'];
			
			$const_name = 'PHOTOPRESS_' . strtoupper( $this->package_name ) . '_VERSION';
			
			if ( ! defined( $const_name ) ) {
				
				define( $const_name, $this->version );
			}
				
		}
		
		if ( array_key_exists( 'dependencies', $params ) ) {
			
			$this->dependencies = $params['dependencies'];	
		}
		
		if ( array_key_exists( 'ns', $params ) ) {
			
			$this->ns = $params['ns'];	
		}
		
		$this->modules = array();
		
		if ( array_key_exists( 'modules', $params ) && is_array( $params['modules'] ) ) {
			
			foreach ( $params['modules'] as $module ) {
				
				$this->registerModule( $module );
			}	
		}
		
		$this->loadDependencies();
		$this->setLocale();
	}
	
	static function getVersion() {
		
		return self::$version;
	}
	
	/**
	 * Loads the required dependant classes for this package.
	 *
	 * Include the following files that make up the package.
	 *
	 * @since    1.0.0
	 * 
	 */
	abstract public function loadDependencies();
	
	/**
	 * Call back function for WordPress register_activation_hook
	 *
	 */
	public function onActivate() {
		
		return true;
	}
	
	public function onDeactivate() {
		
		return true;
	}
	
	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the Plugin_Name_i18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	public function setLocale() {
		
		//$plugin_i18n = new Plugin_Name_i18n();
		//$plugin_i18n->set_domain( $this->package_name() );
		
		//add_action( 'plugins_loaded', array( $plugin_i18n, 'load_plugin_textdomain' ) );
	}
	
	/**
	 * Activate all Modules within a package
	 *
	 * Create's Module objects and hook them to load in the WordPress 'init' hook
	 */
	public function activateModules() {
		
		foreach ( $this->modules as $module_name => $module_map ) {
		
			$module = pp_api::Factory( 
				
				$module_map['class'],  
				$module_map['path'], 
				array(
					'module_name' 	=> $module_name,
					'ns'	 		=> $this->ns,
					'package_name'	=> $this->package_name
				) 
			);	
			
			pp_api::registerModuleWithFramework( $this->package_name, $module_name, ['label' => $module->getLabel()] );
		}
	}
	
	/**
	 * Activates Package
	 *
	 */
	public function activate() {
		
		// run pre method before anything
		$this->pre();
		
		// check if minimum requirements of package are in place
		if ( $this->meetsMinRequirements() ) {
			
			// activate modules
			$this->activateModules();
			// set activation bit. needed?
			pp_api::activateExtension( $this->package_name );
			
		} else {
			// don't load anything else and display admin notice.
			photopress_util::addAction('admin_notices', array( $this, 'displayAdminNotices' ) );
		}
	}
	
/**
	 * Append a message of a certain type to the admin notices.
	 *
	 * @param string $msg 
	 * @param string $type 
	 * @return void
	 */
	static function addAdminNotice( $msg, $type = 'updated' ) {
	
		self::$admin_notices[] = array(
			'type' => $type == 'error' ? $type : 'updated', // If it's not an error, set it to updated
			'msg' => $msg
		);
	}
	
	/**
	 * Displays admin notices 
	 *
	 * @return void
	 */
	static function displayAdminNotices() {
		
		if ( is_array( self::$admin_notices ) ) {
		
			foreach ( self::$admin_notices as $notice ) {
				
				echo(
					
					sprintf(
						'<div class="%s"><p>%s</p></div><!-- / %s -->',
						esc_attr( $notice['type'] ),
						$notice['msg'],
						esc_html( $notice['type'] )
					)
				);				
			}
		}
	}

	/**
	 * Check package's minimum requirements
	 *
	 * @return boolean
	 */
	public function meetsMinRequirements() {
		
		$defaults = array(
		
			'version'		=> '',
			'label'			=> '',
			'install_url'	=> '',
			'slug'			=> '',
			'upgrade_url'	=> ''
		);
		
		$meets = true;
		
		if ( $this->dependencies ) {
		
			// loop through required packages
			foreach ( $this->dependencies as $k => $package ) {
				// packages must be named using convention 'somename_package'
				$class_name = $this->ns . '_' . $k . '_package';
				// standardize the array with default keys
				$package = photopress_util::setDefaultParams( $defaults, $package );
				// class must exist and its version must >= to the required version
				
				$msg = '';
				$url = '';
				
				// check if class exists
				if ( ! class_exists( $class_name ) ) {
					
					$msg = '%s relies on the <a href="%s">%s</a> plugin, please install this plugin.';
					$url = $package['install_url'];
					$meets = false;	
				}

				// check is version is high enought
				$const_name = 'PHOTOPRESS_'. strtoupper( $k ) . '_VERSION';
				if ( class_exists( $class_name ) && version_compare( constant( $const_name ), $package[ 'version' ], '<' ) ) {
						
					$msg = '%s relies on a more recent version of the <a href="%s">%s</a> plugin, please update this plugin.';
					$url = $package['upgrade_url'];
					$meets = false;	
				}
				
				// add error msg
				if ( $msg ) {		
				
					$this->addAdminNotice( 
						sprintf(
							$msg,
							$this->package_label,
							$url,
							$package[ 'label' ]
						), 
						'error' 
					);			
				}
			}
		}
		// return bool
		return $meets;
	}
	
	/**
	 * Registers a module with the package.
	 *
	 * Module names correspond to file names and directory layout
	 * For example a module name of "sitemap" will utilitely try to load
	 * 'modules/sitemap/sitemap.php'.
	 *
	 * @param $module_name	string	the name of the module.
	 *
	 */	
	public function registerModule( $module_name ) {
		
		$path = pp_api::getModulePath( $this->package_name, $module_name ) . $module_name .'.php';
		
		if ( ! file_exists( $path ) ) {
			
			$path = pp_api::getModulePath( $this->package_name, $module_name ) .'index.php';
		}
		
		if ( ! file_exists( $path ) ) {
			// no file found, let's return.	
		
			return;
		}
		
		if ( $this->package_name === 'core' ) {

			$this->modules[ $module_name ] = [
			
				'class'			=> "PhotoPress\modules\\$module_name\\$module_name",
				'path'			=> ''
			];
			
		} else {
			
			$this->modules[ $module_name ] = [
			
				'class'			=> sprintf('%s_%s_%s_module', 'photopress', $this->package_name, $module_name),
				'path'			=> $path
			];
		}
		
		//pp_api::registerModuleWithFramework( $this->package_name, $module_name );
	}
	
	/**
	 * Hook function runs before any modules are activated
	 *
	 */
	public function pre() {
		
		return true;
	}
	
	public function getNsPackageName() {
		
		return sprintf( '%s_%s', $this->ns, $this->package_name);
	}
	
	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     1.0.0
	 * @return    string    The name of the plugin.
	 */
	public function getPackageName() {
		
		return $this->package_name;
	}
	
	public function get_plugin_name() {
		
		return $this->getPackageName();	
	}

	/**
	 * Retrieve the version number of the package.
	 *
	 * @since     1.0.0
	 * @return    string    The version number of the package.
	 */
/*
	public function getVersion() {
	
		return $this->version;
	}
*/
	
	public static function isLoaded() {
		
		return true;
	}
}

?>