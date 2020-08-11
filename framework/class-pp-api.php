<?php

class pp_api {

	public static function getPostMeta( $object_id, $key_group ) {
		
		$params = array( 'object_id' => $object_id, 'key_group' => $key_group);
		
		return pp_api::factory( 'pp_post_meta', PHOTOPRESS_FRAMEWORK_PATH . 'class-metadata.php' , $params );
	}
	
	public static function getTaxonomyMeta( $slug, $key_group ) {
		
		$params = array( 'object_id' => $slug, 'key_group' => $key_group);
		
		return pp_api::factory( 'pp_taxonomy_meta', PHOTOPRESS_FRAMEWORK_PATH . 'class-metadata.php', $params );
	}
	
	public static function getAllModuleNames() {
		
		$f = pp_api::getFramework();
		
		$o = $f->getOptions();
		
		return array_keys( $o->options );
	}
	
	public static function registerModuleWithFramework( $package, $module_name, $args ) {
		
		$f = pp_api::getFramework();
		
		$f->setActiveModule( $package, $module_name, $args );
	}
	
	public static function getActiveModules( $package ) {
		
		$f = pp_api::getFramework();
		
		return $f->getActiveModules( $package );
	}
	
	public static function getOption( $package, $module_name, $key ) {
		
		$f = pp_api::getFramework();
		
		$o = $f->getOptions();
		
		return $o->get( 'photopress_'.$package. '_'.$module_name, $key );
	}
	
	public static function getDefaultOption( $package, $module_name, $key ) {
		
		$f = pp_api::getFramework();
		
		$o = $f->getOptions();
		
		return $o->getDefaultValue( 'photopress_'.$package. '_'.$module_name, $key );
	}
	
	public static function getPersistedOption( $package, $module_name, $key ) {
		
		$f = pp_api::getFramework();
		
		$o = $f->getOptions();
		
		return $o->getPersistedValue( 'photopress_'.$package. '_'.$module_name, $key );
	}
	
	public static function setOption( $package, $module_name, $key, $value ) {
		
		$f = pp_api::getFramework();
		$o = $f->getOptions();
		return $o->set( 'photopress_'.$package. '_'.$module_name, $key, $value );		
		
	}
	
	public static function persistOption( $package, $module_name, $key, $value ) {
		
		$f = pp_api::getFramework();
		$o = $f->getOptions();
		return $o->persist( 'photopress_'.$package. '_'.$module_name, $key, $value );
	}
	
	public static function activateExtension( $extension_name ) {
	
		$framework = pp_api::getFramework();
		$framework->activateExtension( $extension_name );
	}
		
	public static function getFramework() {
		
		if ( ! class_exists( 'photopress_framework' ) ) {
			
			require_once( PHOTOPRESS_FRAMEWORK_PATH . 'class-pp-framework.php' );
		}
		
		return photopress_framework::singleton();
	}
	
	public static function viewFactory( $view_name, $params ) {
		
		if ( ! class_exists( 'pp_view' ) ) {
			
			require_once( PHOTOPRESS_FRAMEWORK_PATH . 'class-view.php' );
		}
		
		return pp_api::lookupFactory( 'photopress_views', $view_name, $params );
	}
	
	public static function moduleFactory( $module_name, $params ) {
		
		if ( ! class_exists( 'photopress_module' ) ) {
			
			require_once( PHOTOPRESS_FRAMEWORK_PATH . 'class-module.php' );
		}
		
		return pp_api::lookupFactory( 'photopress_modules', $module_name, $params );
	
	}
	
	
	public static function lookupFactory( $map_name, $key, $params ) {
		
		$map = array();
		
		if ( ! $map ) {
				
			$map  = apply_filters( $map_name, array() );	
		}
		//print_r($map);
		if ( is_array( $map ) && array_key_exists( $key, $map ) ) {
			
			return pp_api::factory( $map[ $key ][ 'class' ], $map[ $key ][ 'path' ], $params );
		}
	}
	
	public static function factory( $class_name, $path = '', $params = '' ) {
		
		if ( empty( $path ) ) {
			
			return new $class_name( $params );
		}
		
		
		if ( ! class_exists( $class_name ) ) {
		
			require_once( $path );	
		}
		
		return new $class_name( $params );
	}
	
	public static function controllerFactory( $controller_name,  $params = '' ) {
		
		if ( ! class_exists( 'pp_controller' ) ) {
			
			require_once( PHOTOPRESS_FRAMEWORK_PATH.'class-controller.php');
		}
		
		return pp_api::lookupFactory('photopress_controllers', $controller_name, $params );
	}
	
	public static function performAction( $action_name ) {
		
		$controller = pp_api::controllerFactory( $action_name );

		$data = $controller->doAction();
		
		if ( $controller->getView() ) {
			$view = pp_api::viewFactory( $controller->getView(), $data );
		
			return $view->output();
		}
	}
	
	public static function get_wpdb() {
		
		global $wpdb;
		return $wpdb;
	}
	
	public static function debug( $log ) {
		
        if ( true === WP_DEBUG ) {
        
            if ( is_array( $log ) || is_object( $log ) ) {

                error_log( print_r( $log, true ) );

            } else {

                error_log( $log );

            }
        }
	}
	
	public static function getModulePath( $package, $module_name, $subpath = '' ) {
		
		$ns = 'PHOTOPRESS';
		
		$package = strtoupper( $package );
		
		$module_name = strtolower( $module_name );
		
		$package_constant = sprintf( '%s_%s_PATH', $ns, $package);
		
		if ( $subpath ) {
			
			$subpath .= '/';
		}
		
		$path = sprintf( '%smodules/%s/%s', constant( $package_constant ), $module_name, $subpath );
		
		return $path;
		
	}
	
	public static function getLoader() {
					
		if ( ! class_exists( 'photopress_loader') ) {

			require_once( PHOTOPRESS_FRAMEWORK_PATH . 'class-loader.php' );
		}
	
		return $loader = new photopress_loader;
	}
	
	public static function getModule( $module_name ) {
		
		if ( ! class_exists( 'photopress_module' ) ) {
			
			require_once( PHOTOPRESS_FRAMEWORK_PATH.'class-module.php');
		}
		
		return pp_api::lookupFactory('photopress_modules', $module_name, $params );
	}
}

?>