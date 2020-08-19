<?php

class photopress_util {

	public static function getTaxonomies( $args ) {
	
		return get_taxonomies( $args );
	}
	
	public static function getPostTypes( $args, $type = 'names', $operator = 'and') {
		
		return get_post_types( $args, $type, $operator );
	}
	
	public static function getRemoteUrl( $url ) {
		
		return wp_remote_get ( urlencode ( $url ) );
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