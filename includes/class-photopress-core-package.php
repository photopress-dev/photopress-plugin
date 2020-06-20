<?php

/**
 * PhotoPress Core Package 
 *
 * @link       http://photopressdev.com
 * @since      1.0.0
 *
 * @package    photopress_seo
 */

require_once( PHOTOPRESS_FRAMEWORK_PATH . 'class-package.php' );

/**
 * The concrete photopress_core Package class.
 *
 * This is used to define internationalization, dashboard-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @package    photopress_seo
 * @subpackage photopress_seo/includes
 * @author     Peter Adams <peter@photopressdev.com>
 */
class photopress_core_package extends photopress_package {
	
	public static $instance;
	
	public function loadDependencies() {

	}

	public function onDeactivate() {
	
		error_log('Deactivating photopress_core package.');
	}
}

?>