<?php

/*
Plugin Name: PhotoPress
Plugin URI: http://www.photopressdev.com
Description: Making WordPress work for photographers with beautiful image galleries, slideshows, meta-data tools, and more.
Author: Peter Adams
Author URI: http://www.photopressdev.com
License: GPL v3
Version: 1.5.0
*/

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// require the composer autoloader
require_once('vendor/autoload.php');

// Define the path to this plugin
if ( ! defined( 'PHOTOPRESS_CORE_PATH' ) ) {

	define('PHOTOPRESS_CORE_PATH', plugin_dir_path( __FILE__ ) );
}

// Define the path to the PhotoPress Framework. This is used by other plugins
if ( ! defined( 'PHOTOPRESS_FRAMEWORK_PATH' ) ) {
	
	define('PHOTOPRESS_FRAMEWORK_PATH', PHOTOPRESS_CORE_PATH . 'framework/' );
}

// Hook for plugin package creation
add_action('plugins_loaded', [ 'photopress_plugin', 'getInstance' ], 1 );

register_activation_hook(__FILE__, [ 'photopress_plugin', 'activate' ] );


/**
 * PhotoPress Core Plugin
 *
 * Adds core framework classes to PhotoPress.
 *
 * @link              http://photopressdev.com
 * @since             1.0.0
 * @package           photopress_core
 *
 */
class photopress_plugin {
	
	/**
	 * Static instance
	 */
	static $instance;
	
	/**
	 * Get instance of the plugin's package.
	 *
	 * Since everything within the package is registered via hooks,
	 * kicking off the plugin from this point in the file does
	 * not affect the page life cycle.
	 *
	 * @since    1.0.0
	 */
	public static function getInstance() {
		
		if ( defined('PHOTOPRESS_FRAMEWORK_PATH') ) {
			
			if ( ! self::$instance ) {
			
				self::$instance = pp_api::factory(
				
					'photopress_core_package', 
					PHOTOPRESS_CORE_PATH . 'includes/class-photopress-core-package.php',
					array(
						'package_name'			=> 'core',
						'package_label'			=> 'PhotoPress',
						'version'				=> '1.5.0',
						'modules'				=> ['base', 'childpages', 'gallery', 'slideshow', 'metadata'],
						'dependencies'			=> []
					)
				);
				
				self::$instance->activate();
			}
		}
	}
	
	/**
	 * move this to the gallery plugin
	 */
	public function random_gallery_taxonomy_query( $attachments, $attr ) {
		
		// discard any attachments we might get.
		$attachments = array();
		
		if ( ! $attr ) {
			
			$attr = array();
		}
		
		extract(  $attr  );
		
		// if there are taxonomy oriented attrs then do an image taxonomy query
		if ( ! empty( $taxonomy ) && ! empty( $term ) && $post_type === 'attachment' && $query === 'random') {
			
			$term = strtolower( $term );
			$taxonomy = strtolower( $taxonomy ); 
			$paged = (get_query_var('page')) ? (int) get_query_var('page') : 1;
			
			// setup taxonomy query
			$args = array(
				'tax_query'			=> array(),
				//'showposts' => $num_posts,
				'posts_per_page' 	=> $numberposts,
				'post_type' 		=> 'attachment',
				'paged' 			=> $paged,
				'post_status'		=> 'inherit',
				'offset'			=> '',
				'orderby'			=> 'rand'
			);
			
			$args['tax_query'][] = array(
			
				'taxonomy'	=> $taxonomy,
				'field' 	=> 'slug',
				'terms' 	=> $term
			);
			
			// perform taxonomy query
			$attachment_query = new WP_Query( $args );
			$r_attachments = $attachment_query->get_posts();
		
			if ( $r_attachments ) {
				
				foreach ($r_attachments as $a) {
					
					$attachments[$a->ID] = $a;
				}
			}
		}
		
		// always return something so downstream plugins can operate.
		return $attachments;
	}	
	
	public static function activate() {
		
		$exiftool_path = PHOTOPRESS_CORE_PATH . 'vendor/philharvey/exiftool/exiftool ';	
		if ( function_exists( 'chmod' ) ) {
			photopress_util::debug( $exiftool_path );
			$ret = chmod( trim($exiftool_path), 0755 );
			photopress_util::debug( $ret );
		} else {
			
			photopress_util::debug('cannot set executable permission on exiftool. please manually change to 755.');
		}
	}
}

?>