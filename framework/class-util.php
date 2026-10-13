<?php

defined( 'ABSPATH' ) || exit;

class photopress_util {

	public static function getTaxonomies( $args ) {
	
		return get_taxonomies( $args );
	}
	
	public static function getPostTypes( $args, $type = 'names', $operator = 'and') {
		
		return get_post_types( $args, $type, $operator );
	}
	
	/**
	 * The dependencies and version of a built script, from the .asset.php the
	 * build writes next to it in dist/. A copy installed from git without
	 * running the build has no dist/; requiring the file directly was a fatal
	 * error on every request.
	 *
	 * @param string $name Script name without extension, e.g. 'blocks.build'.
	 * @return array { dependencies: string[], version: string }
	 */
	public static function getBuildAsset( $name ) {
		
		$file = dirname( __DIR__ ) . '/dist/' . $name . '.asset.php';
		
		if ( is_readable( $file ) ) {
			return require $file;
		}
		
		return [
			'dependencies' => [],
			'version'      => defined( 'PHOTOPRESS_CORE_VERSION' ) ? PHOTOPRESS_CORE_VERSION : false,
		];
	}
	
	/**
	 * A module's blocks registered from the manifest the build makes of
	 * their block.json files (dist/blocks-manifest/), so WordPress 6.7 and
	 * later reads one cached PHP file rather than reading and decoding each
	 * block.json on every request. Without the build or on an earlier
	 * WordPress, register_block_type() reads the block.json files.
	 *
	 * @param string $blocks The folder of the module's block folders.
	 * @param string $name   Its manifest's name, e.g. 'gallery'.
	 */
	public static function registerBlockManifest( $blocks, $name ) {

		$manifest = dirname( __DIR__ ) . '/dist/blocks-manifest/' . $name . '.php';

		if ( function_exists( 'wp_register_block_metadata_collection' ) && is_readable( $manifest ) ) {
			wp_register_block_metadata_collection( $blocks, $manifest );
		}
	}
	
	public static function getModuleOptionKey( $package_name, $module_name ) {
		
		return sprintf( '%s_%s_%s', 'photopress', $package_name, $module_name );
	}
	
	public static function setDefaultParams( $defaults, $params, $class_name = '' ) {
		
		$newparams = $defaults;
		
		foreach ( $params as $k => $v ) {
			
			$newparams[$k] = $v;
		}
		
		return $newparams;
	}
	
	public static function addFilter( $hook, $callback, $priority = '', $accepted_args = '' ) {
		
		return add_filter( $hook, $callback, $priority, $accepted_args );
	}
	
	public static function addAction( $hook, $callback, $priority = '', $accepted_args = '' ) {
		
		return add_action( $hook, $callback, $priority, $accepted_args );
	}
	
	public static function escapeOutput( $string ) {
		
		return esc_html( $string );
	}
	
	
	/**
	 * A plugin "active", "inactive" (installed) or "missing", by whether it
	 * is running and the folders it is installed in.
	 */
	public static function pluginState( $active, array $folders ) {

		if ( $active ) {
			return 'active';
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( array_keys( get_plugins() ) as $file ) {
			foreach ( $folders as $folder ) {
				if ( 0 === strpos( $file, $folder ) ) {
					return 'inactive';
				}
			}
		}

		return 'missing';
	}

	/**
	 * Outputs Localized String
	 *
	 */
	public static function out( $string ) {
		
		echo ( 
			photopress_util::escapeOutput ( $string ) 
		);
	}
	
	/**
	 * Localize String
	 *
	 */
	public static function localize( $string ) {
		
		return $string;
	}
	
	/**
	 * Flushes WordPress rewrite rules.
	 *
	 */
	public static function flushRewriteRules() {
		
		global $wp_rewrite;
		$wp_rewrite->flush_rules();
	}
	
	/**
	 * Get a direct link to install or update a plugin
	 *
	 */
	public static function getWpPluginInstallUrl( $slug, $action = 'install-plugin' ) {
		
		return wp_nonce_url(
		    add_query_arg(
		        array(
		            'action' => $action,
		            'plugin' => $slug
		        ),
		        admin_url( 'update.php' )
		    ),
		    $action . '_' . $slug
		);
	}
	
	/**
	 * Get information about available image sizes
	 */
	public static function get_image_sizes( $size = '' ) {
	    $wp_additional_image_sizes = wp_get_additional_image_sizes();
	 
	    $sizes = array();
	    $get_intermediate_image_sizes = get_intermediate_image_sizes();
	 
	    // Create the full array with sizes and crop info
	    foreach( $get_intermediate_image_sizes as $_size ) {
	        if ( in_array( $_size, array( 'thumbnail', 'medium', 'large' ) ) ) {
	            $sizes[ $_size ]['width'] = get_option( $_size . '_size_w' );
	            $sizes[ $_size ]['height'] = get_option( $_size . '_size_h' );
	            $sizes[ $_size ]['crop'] = (bool) get_option( $_size . '_crop' );
	        } elseif ( isset( $wp_additional_image_sizes[ $_size ] ) ) {
	            $sizes[ $_size ] = array( 
	                'width' => $wp_additional_image_sizes[ $_size ]['width'],
	                'height' => $wp_additional_image_sizes[ $_size ]['height'],
	                'crop' =>  $wp_additional_image_sizes[ $_size ]['crop']
	            );
	        }
	    }
	 
	    // Get only 1 size if found
	    if ( $size ) {
	        if( isset( $sizes[ $size ] ) ) {
	            return $sizes[ $size ];
	        } else {
	            return false;
	        }
	    }
	    return $sizes;
	}
	
	public static function shell( $command ) {
		
		//system
		if( function_exists( 'system' ) ) {
			
			ob_start();
			system( $command , $return_var );
			$output = ob_get_contents();
			ob_end_clean();
		}
		
		//passthru
		else if( function_exists( 'passthru' ) ) {
			ob_start();
			passthru( $command , $return_var );
			$output = ob_get_contents();
			ob_end_clean();
		}
		
		//exec
		else if( function_exists( 'exec' ) ) {
			
			exec( $command , $output , $return_var );
			$output = implode( "n" , $output );
		}
		
		//shell_exec
		else if( function_exists( 'shell_exec' ) ) {

			$output = shell_exec($command) ;
		}
		
		else {
			
			$output = 'Command execution not possible on this system';
			$return_var = 1;
		}
		
		return ['output' => $output , 'status' => $return_var ];
	}
	
	public static function debug ( $msg ) {
		
		if ( true === WP_DEBUG ) {
			
			if ( is_array( $msg ) || is_object( $msg ) ) {
				error_log( print_r( $msg, true ) );
			} else {
				error_log( $msg );
			}
		}
	}

}

?>