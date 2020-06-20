<?php 

if ( ! class_exists( 'photopress_module' ) ) {

	require_once( PHOTOPRESS_FRAMEWORK_PATH . 'class-module.php' );
}

class photopress_core_base_module extends photopress_module {

	public function definePublicHooks() {
	
		add_action( 'wp_enqueue_scripts', array( $this, 'photopress_base_js' ) );
	
	}
	
	public function photopress_base_js() {
		
		wp_enqueue_script(
			'photopress',
			plugins_url( 'js/photopress.js' , __FILE__ ),
			array( 'jquery' )
		);
	}

	public function registerOptions() {		

		return array(
		
		'general_enable'						=> array(
			
				'default_value'							=> true,
				'field'									=> array(
					'type'									=> 'boolean',
					'title'									=> 'Enable PhotoPress',
					'page_name'								=> 'general',
					'section'								=> 'general',
					'description'							=> 'Enable or disable all functionality.',
					'label_for'								=> 'Enable general',
					'error_message'							=> 'You must select On or Off.'		
				)				
			),
		
		
		);
	}
	
	public function registerSettingsPages() {
		
		$pages = array();
		
		$pages['general'] = array(
			
			'parent_slug'				=> 'photopress-core-base',
			'is_top_level'				=> true,
			'top_level_menu_title'		=> 'PhotoPress',
			'title'						=> 'PhotoPress General Settings',
			'menu_title'				=> 'General',
			'required_capability'		=> 'manage_options',
			'menu_slug'					=> 'photopress-core-base',
			'description'				=> 'These are the general settings for PhotoPress plugin.',
			'sections'					=> array(
				'general'						=> array(
					'id'							=> 'general',
					'title'							=> 'General',
					'description'					=> 'The following settings control PhotoPress.'
				),
			
			)
		);
		
		$pages['extensions'] = array(
			'parent_slug'				=> 'photopress-core-base',
			'title'						=> 'PhotoPress Extensions',
			'menu_title'				=> 'Extensions',
			'required_capability'		=> 'manage_options',
			'menu_slug'					=> 'photopress-core-base-extensions',
			'description'				=> 'There are many extension plugins that add functionality to PhotoPress. Install extensions from the list below.',
			'sections'					=> array(),
			'render_callback'			=> array( $this, 'renderExtensionsPage')
			
		);
		
		return $pages;
	}
	
	public function renderExtensionsPage() {
		?>
		<style>
		
			.photopress_admin .extension {
			    border: 1px solid #ccc;
			    box-sizing: border-box;
			    float: left;
			    height: 230px;
			    margin: 10px 20px 10px 0;
			    width: 300px;
			}
			
			.photopress_admin .extensions {
				display: block;
				margin-top: 15px;
			}
			.photopress_admin .extension a {
				
				text-decoration: none;
			}
			
			.photopress_admin .extension h3 {
			    background: #fff none no-repeat scroll left 10px / 130px;
			    background-position: 10px;
			    border-bottom: 1px solid #ccc;
			    box-sizing: border-box;
			    height: 110px;
			    margin: 0;
			    padding: 20px 10px 0 150px;
			}
			
			.photopress_admin .extension p {
			    margin: 0;
			    padding: 10px;
			}
			 
		</style>
		<?php
		
		$extensions = array(
		
			'gallery'		=> array(
				'label'			=> 'Gallery',
				'description'	=> 'Adds gallery related features to PhotoPress.',
				'repo_url'		=> photopress_util::getWpPluginInstallUrl( 'photopress-gallery' ),
				'icons'			=> array (
					'200w'			=> 'photopress-gallery.200w.jpg'
				)
			),
			'taxonomies'	=> array(
				'label'			=> 'Image Taxonomies',
				'description'	=> 'Adds image taxonomies for keywords, camera, lens, people, city, state and country.',
				'repo_url'		=> photopress_util::getWpPluginInstallUrl( 'photo-tools-image-taxonomies' ),
				'icons'			=> array (
					'200w'			=> 'photopress-taxonomies.200w.jpg'
				)
			),
			/*

			'seo'			=> array(
				'label'			=> 'SEO',
				'description'	=> 'Test description his there this is a test description of my extension.',
				'repo_url'		=> '',
				'icons'			=> array (
					'200w'			=> 'photopress-cart.200w.jpg'
				)
			),
			
			*/
			'cart'			=> array(
				'label'			=> 'Paypal Shopping Cart',
				'description'	=> 'Adds shopping cart widgets that allow you to sell prints of your images.',
				'repo_url'		=> photopress_util::getWpPluginInstallUrl( 'photopress-paypal-shopping-cart' ),
				'icons'			=> array (
					'200w'			=> 'photopress-cart.200w.jpg'
				)
			),
			'sideways'		=> array(
				'label'			=> 'Sideways Gallery',
				'description'	=> 'Adds a sideways gallery presentation style.',
				'repo_url'		=> photopress_util::getWpPluginInstallUrl( 'photopress-sideways-gallery' ),
				'icons'			=> array (
					'200w'			=> 'photopress-sideways.200w.jpg'
				)
			),
			'masonry'		=> array(
				'label'			=> 'Masonry Gallery',
				'description'	=> 'Adds a Masonry gallery presentation style.',
				'repo_url'		=> photopress_util::getWpPluginInstallUrl( 'photopress-masonry-gallery' ),
				'icons'			=> array (
					'200w'			=> 'photopress-masonry.200w.jpg'
				)
			),
			'latest'		=> array(
				'label'			=> 'Latest Images',
				'description'	=> 'Allows you to show a gallery of the latest uploded images.',
				'repo_url'		=> photopress_util::getWpPluginInstallUrl( 'photopress-latest-images' ),
				'icons'			=> array (
					'200w'			=> 'photopress-latestimages.200w.jpg'
				)
			)
		);
		
		echo '<div class="wrap photopress_admin">';
			
		echo '	<div class="icon32" id="icon-options-general"><br></div> ';
		echo '	<h2>PhotoPress Extensions</h2>';
			
		echo ' 	<div class="extensions"> ';
		
		foreach ( $extensions as $k => $v ) {
			$url = "'" . plugins_url( 'photopress' ) . '/static/images/'. $v['icons']['200w'] . "'";
			echo ' 		<div class="extension">';
			echo '			<a href="'. $v['repo_url'] .'"> ';
			echo '				<h3 style="background-image: url(' . $url . ');">' . $v['label'] . '</h3>';
			echo '			</a>';
			echo '			<p>' . $v['description'] . '</p>';
			echo '			<p><a class="button-primary" href="'. $v['repo_url'] .'" target="_blank"> Get this extension </a></p> ';
			echo '		</div> ';
		}
		echo ' 	</div>';
		echo '</div>';
		
	}
}
?>