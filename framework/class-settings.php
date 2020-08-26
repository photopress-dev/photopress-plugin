<?php

class photopress_settingsPage {
	
	public $page_slug;
	
	public $package;
	
	public $module;
	
	public $ns;
	
	public $name;
	
	public $option_group_name; // photopress-package-module-groupname
	
	public $fields;
	
	public $properties;
	
	public function __construct( $params ) {
		

		$defaults = array(
			
			'ns'					=> '',
			'package'				=> '',
			'module'				=> '',
			'page_slug'				=> '',
			'name'					=> '',
			'title'					=> 'Placeholder Title',
			'description'			=> 'Placeholder description.',
			'sections'				=> array(),
			'required_capability'	=> 'manage_options'	
		
		);
		
		$params = photopress_util::setDefaultParams( $defaults, $params );
		
		$this->ns 				= $params['ns'];
		$this->package 			= $params['package'];
		$this->module 			= $params['module'];
		$this->name 			= $params['name'];
	
		if ( ! $params['page_slug'] ) {
						
			$params['page_slug'] = $this->generatePageSlug();		
		}
		
		$this->page_slug = $params['page_slug'];
		
		$this->default_options = array();
		
		$this->properties = $params;
				
		photopress_util::addFilter('photopress_settings_field_types', array( $this, 'registerFieldTypes'), 10, 1);
		
		// add error display callback.
		add_action( 'admin_notices', array( $this, 'displayErrorNotices' ) );
	}
	
	public function registerFieldTypes( $types = array() ) {
		
		$settings_class = PHOTOPRESS_FRAMEWORK_PATH . 'class-settings.php';
		
		$types['text'] = array(
			'class' => 'photopress_settings_field_text', 
			'path' 	=>  $settings_class
		);
		
		$types['boolean'] = array(
			'class' => 'photopress_settings_field_boolean', 
			'path' 	=> $settings_class
		);
			
		$types['integer'] = array(
			'class' => 'photopress_settings_field_integer', 
			'path' 	=> $settings_class
		);
		
		$types['boolean_array'] = array(
			'class' => 'photopress_settings_field_booleanarray', 
			'path' 	=> $settings_class
		);
		
		$types['on_off_array'] = array(
			'class' => 'photopress_settings_field_onoffarray', 
			'path' 	=> $settings_class
		);
		
		$types['comma_separated_list'] = array(
			'class' => 'photopress_settings_field_commaseparatedlist', 
			'path' 	=> $settings_class
		);
		
		$types['select'] = array(
			'class' => 'photopress_settings_field_select', 
			'path' 	=> $settings_class
		);
		
		$types['textarea'] = array(
			'class' => 'photopress_settings_field_textarea', 
			'path' 	=> $settings_class
		);
	
		$types['none'] = array(
			'class' => 'photopress_settings_field_none', 
			'path' 	=> $settings_class
		);
		
		$types['url'] = array(
			'class' => 'photopress_settings_field_url', 
			'path' 	=> $settings_class
		);

		
		return $types;
	}
	
	public function get( $key ) {
		
		if (array_key_exists( $key, $this->properties ) ) {
			
			return $this->properties[ $key ];
		} 
	}
	
	public function generatePageSlug() {
		
		return sprintf( '%s-%s-%s-%s', $this->ns, $this->package, $this->module, $this->name );
	}
	
	// generates the schema used by the wordpress rest api
	public function getSchema() {
		
		$schema = [];
		
		foreach ( $this->fields as $k => $field ) {
			//print_r($field);
			
			$type = $field->properties['type'];
			
			$r = ['type' => ''];
			
			if ( $type === 'text' ) {
			
				$r = ['type' => 'string'];
			}
			

			if ( $type === 'integer' ) {
			
				$r = ['type' => 'integer'];
			}
			
			if ( $type === 'boolean' ) {
			
				$r = ['type' => 'boolean'];
			}

			
			if ( $type === 'select' ) {
				
				$r = ['type' => ''];
			}
			
			if ( $type === 'on_off_array' ) {
				
				$r = ['type' => ''];
			}
			
			if ( $type === 'none' ) {
			
				$r = ['type' => ''];
			}
			
			if ( $type === 'url' ) {
			
				$r = ['type' => 'string'];
			}
			
/*
			if ( $k === 'custom_taxonomies') {
				
				$r = [
				
					'type' => 'object',
					'properties'	=> [
						'plural'	=> [
							'type'	=> 'string'
						],
						'singular'	=> [
							'type'	=> 'string'
						]
					]
				
				];
			}
*/
			
			$schema[ $k ] = $r;	
			//$schema[ $k ] = [ 'type' => 'string' ];	
		}
		//print_r( $schema );
		return $schema;
	}
	
	public function getDefaults() {
		
		$defaults = [];
		
		foreach ( $this->fields as $k => $field ) {
			
			$defaults[ $k ] = $field->properties['default_value'];
		}
		
		return $defaults;
	}
	
	public function registerSettings() {
			
			$args = [
				
				
				'description'		=> 'Settings for PhotoPress '. $this->getOptionGroupName() . ' ' . $this->getOptionKey(),
				//'sanitize_callback'	=> [ $this, 'validateAndSanitize' ],
				'show_in_rest'		=> [
					
					'schema'			=> [
						'type'				=> 'object',
						'properties'		=> $this->getSchema()
					]
				],
				
				'default'			=> $this->getDefaults()
			];
			
			register_setting( $this->getOptionGroupName(), $this->getOptionKey(), $args );
	}
	
	public function validateAndSanitize( $options ) {
	
		$sanitized = '';
		
		if ( is_array( $options ) ) {	
			
			$sanitized = array();
			
			foreach ( $this->fields as $k => $f ) {
				
				// if the option is present
				if ( array_key_exists( $k, $options ) ) {	
					
					$value = $options[ $k ] ;
					
					// check if value is required.
					if ( ! $value && $f->isRequired() ) {
						
						$f->addError( $k, $f->get('label_for'). ' field is required' );
						continue;
					}
					
					// sanitize value
					$value = $f->sanitize( $options[ $k ] );
					
					// validate value. Could be empty at this point.
					if ( $f->isValid( $value ) ) {
						//sanitize
						$sanitized[ $k ] =  $value;
					}
					
				} else {
				
					// set a false value in case it's a boollean type
					$sanitized[ $k ] = $f->setFalseValue();
				}
			}			
		}
		
		return $sanitized;
	}
	
	public function getOptionGroupName() {
		
		return sprintf( '%s_group', $this->get('page_slug') );
	}
	
	public function getOptionKey() {
		
		return photopress_util::getModuleOptionKey( $this->package, $this->module );
	}
	
	/**
	 * Register a Settings Section with WordPress.
	 *
	 */
	public function registerSection( $params ) {
		
		// todo: add in a class type lookup here to use a custom section object
		// so that we can do custom rendering of section HTML if we 
		// ever need to.
		// $section = somemaplookup( $params['type']);
		
		$section = pp_api::factory('photopress_settings_section', '', $params);
		
		// Store the section object in case we need it later or want to inspect
		$this->sections[ $section->get( 'id' ) ] = $section;
		
		// register the section with WordPress
		if (function_exists('add_settings_section')) {
			add_settings_section( $section->get('id'), $section->get('title'), $section->get('callback'), $this->page_slug );
		}
	}
	
	public function echoHtml( $html ) {
		
		echo $html;
	}
	
	public function registerField( $key, $params ) {
		
		// Add to params array
		// We need to pack params because ultimately add_settings_field 
		// can only pass an array to the callback function that renders
		// the field. Sux. wish it would accept an object...
			
		$params['id'] = $key;
		$params['package'] = $this->package;
		$params['module'] = $this->module;
		
		// make field object based on type
		$field = pp_api::lookupFactory( 'photopress_settings_field_types', $params['type'], $params );
		
		if ( $field ) {
			// park this field object for use later by validation and sanitization 			
			$this->fields[ $key ] = $field;
				
			// register label formatter callback
			$callback = $field->get( 'value_label_callback' );
			if ( $callback ) {
				photopress_util::addFilter( $field->get( 'id' ) . '_field_value_label', $callback, 10, 1 );
			}
			
			// might not exist if the rest api is active
			if (function_exists('add_settings_field')) {
				// add setting to wordpress settings api
				add_settings_field( 
					$key, 
					$field->get( 'title' ), 
					array( $field, 'render'), 
					$this->page_slug, 
					$field->get( 'section' ), 
					$field->getProperties() 
				); 
			}
			
		} else {
			
			pp_api::debug("No field of type {$params['type']} registered.");
		}
	}
		
	public function renderPage() {
		
		wp_enqueue_script('jquery','','','',true);
		wp_enqueue_script('jquery-ui-core','','','',true);
		wp_enqueue_script('jquery-ui-tabs','','','',true);
		//add_settings_field( $id, $title, $callback, $page, $section = 'default', $args = array() )
		
		if ( ! current_user_can( $this->get('required_capability') ) ) {
    
        	wp_die(__( 'You do not have sufficient permissions to access this page!' ) );
		}
    
		echo '<div class="wrap">';
		echo	'<div class="icon32" id="icon-options-general"><br></div>';
		echo	sprintf('<h2>%s</h2>', $this->get( 'title') );
		echo	$this->get('description');
		
		if ( $this->fields ) {
			settings_errors();
			echo	sprintf('<form id=%s" action="options.php" method="post">', $this->page_slug);
			settings_fields( $this->getOptionGroupName() );
			//do_settings_sections( $this->get('page_slug') );
			$this->doTabbedSettingsSections( $this->get('page_slug') );
			echo	'<p class="submit">';
			echo	sprintf('<input name="Submit" type="submit" class="button-primary" value="%s" />', 'Save Changes' );
			echo	'</p>';
			echo	'</form>';
		}

		echo    '</div>';
	}
	
	/**
	 * Outputs Settings Sections and Fields
	 *
	 * Sadly this is a replacement for WP's do_settings_sections template function
	 * because it doesn't allows for filtered output which we need for adding tabs.
	 *
	 * var $page	string	name of the settings page.
	 */
	public function doTabbedSettingsSections( $page ) {
		
		global $wp_settings_sections, $wp_settings_fields;
 
	    if ( ! isset( $wp_settings_sections[$page] ) ) {
	    
	        return;
		}
		
		echo '<div class="photopress_admin_tabs">';
		echo '<h2 class="nav-tab-wrapper">';
		echo '<ul style="padding:0px;margin:0px;">';
		foreach ( (array) $wp_settings_sections[$page] as $section ) {
			
			echo  sprintf('<li class="nav-tab" style=""><a href="#%s" class="%s">%s</a></li>', $section['id'], '', $section['title']);
			
		}
		echo '</ul>';
		echo '</h2>';
		
	    foreach ( (array) $wp_settings_sections[$page] as $section ) {
	    	
	    	echo sprintf( '<div id="%s">', $section['id'] );
	        if ( $section['title'] )
	            echo "<h3>{$section['title']}</h3>\n";
	 
	        if ( $section['callback'] )
	            call_user_func( $section['callback'], $section );
	 
	        if ( ! isset( $wp_settings_fields ) || !isset( $wp_settings_fields[$page] ) || !isset( $wp_settings_fields[$page][$section['id']] ) )
	            continue;
	        echo '<table class="form-table">';
	        do_settings_fields( $page, $section['id'] );
	        echo '</table>';
	        echo '</div>';
	    }
	    echo '</div>';
	    
	    echo'   <script>
					jQuery(function() { 
					
						jQuery( ".photopress_admin_tabs" ).tabs({
							 
							create: function(event, ui) {
								
								// CSS hackery to match up with WP built in tab styles.
								jQuery(this).find("li a").css({"text-decoration": "none", color: "grey"});
								ui.tab.find("a").css({color: "black"});
								ui.tab.addClass("nav-tab-active");
								// properly set the form action to correspond to active tab
								// in case it is resubmitted
								target = jQuery(".photopress_admin_tabs").parent().attr("action");
								new_target = target + "" + window.location.hash;
								jQuery(".photopress_admin_tabs").parent().attr("action", new_target);
							},
							
							activate: function(event, ui) {
								
								// CSS hackery to match up with WP built in tab styles.
								ui.oldTab.removeClass("nav-tab-active");
								ui.oldTab.find("a").css({color: "grey"});
								ui.newTab.addClass("nav-tab-active");
								ui.newTab.find("a").css({color: "black"});
								
								// get target tab nav link.
								new_tab_anchor = ui.newTab.find("a").attr("href");
								// set the url anchor
								window.location.hash = new_tab_anchor;
								// get current action attr of the form
								target = jQuery(".photopress_admin_tabs").parent().attr("action");
								// clear any existing hash from form target
								if ( target.indexOf("#") > -1 ) {
								
									pieces = target.split("#");
									new_target = pieces[0] + "" + new_tab_anchor;
									
								} else {
								
									new_target = target + "" + new_tab_anchor;
								}
								// add the anchor hash to the form action so that
								// the user returns to the correct tab after submit
								jQuery(".photopress_admin_tabs").parent().attr("action", new_target);
								
							}
						});
					});
					
			
				</script>';
	}
	
	public function displayErrorNotices() {
	
    	settings_errors( 'your-settings-error-slug' );
	}
}

class photopress_settings_field {
	
	public $id;
	
	public $package;
	
	public $module;
	
	public $properties;
	
	/**
	 * name of the validator callback to be used
	 */
	public $validator_callback;
	
	/**
	 * name of the santizer callback to be used
	 */
	public $santizer_callback;
	
	public function __construct( $params = '' ) {
		
		$defaults = array(
			
			'title'			=> 'Sample Title',
			'type'			=> 'text',
			'section'		=> '',
			'default_value'	=> '',
			'dom_id'		=> '',
			'name'			=> '',
			'id'			=> '',
			'package'		=> '',
			'module'		=> '',
			'required'		=> false,
			'label_for'		=> ''
			
		);
		
		$params = photopress_util::setDefaultParams( $defaults, $params );
		
		
		$this->package 		= $params['package'];
		$this->module		= $params['module'];
		$this->id 			= $params['id'];
		$this->properties 	= $params;
		
		$this->properties['name'] = $this->setName();
		$this->properties['dom_id'] = $this->setDomId();
	}
	
	public function get( $key ) {
		
		if (array_key_exists( $key, $this->properties) ) {
			
			return $this->properties[ $key ];
		}
	}
	
	public function getProperties() {
		
		return $this->properties;
	}
	
	public function setName( ) {
		
		return sprintf( 
			'%s[%s]', 
			photopress_util::getModuleOptionKey(  
				$this->package, 
				$this->module 
			), 
			$this->id
		);
	}
	
	public function render( $field ) {
		
		return false;
	}	
	
	public function setDomId( ) {
		
		return sprintf( 
			'%s_%s', 
			photopress_util::getModuleOptionKey( $this->package, $this->module ), 
			$this->id
		);
	}	
	
	public function sanitize( $value ) {
		
		return $value;
	}
	
	public function isValid( $value ) {
		
		return true;
	}
		
	public function addError( $key, $message ) {
		
		add_settings_error(
			$this->get( 'id' ),
			$key,
			$message,
			'error'
		);
		
	}
	
	public function setFalseValue() {
		
		return 0;
	}
	
	public function isRequired() {
		
		return $this->get('required');
	}
	
	public function getErrorMessage() {
		
		return $this->get('error_message');
	}
}

class photopress_settings_field_text extends photopress_settings_field {

	public function render( $attrs ) {
	//print_r();
		$value = pp_api::getOption( $this->package, $this->module, $attrs['id'] );
		
		if ( ! $value ) {
			
			$value = pp_api::getDefaultOption( $this->package, $this->module, $attrs['id'] );
		}
		
		echo sprintf(
			'<input name="%s" id="%s" value="%s" type="text" /> ', 
			esc_attr( $attrs['name'] ), 
			esc_attr( $attrs['dom_id'] ),
			esc_attr( $value ) 
		);
		
		echo sprintf('<p class="description">%s</p>', $attrs['description']);
	}	
	
	public function sanitize( $value ) {
		
		return trim($value);
	}
}

class photopress_settings_field_textarea extends photopress_settings_field {

	public function render( $attrs ) {
	//print_r();
		$value = pp_api::getOption( $this->package, $this->module, $attrs['id'] );
		
		if ( ! $value ) {
			
			$value = pp_api::getDefaultOption( $this->package, $this->module, $attrs['id'] );
		}
		
		echo sprintf(
			'<textarea name="%s" rows="%s" cols="%s" />%s</textarea> ', 
			esc_attr( $attrs['name'] ), 
			esc_attr( $attrs['rows'] ),
			esc_attr( $attrs['cols'] ),
			esc_attr( $value ) 
		);
		
		echo sprintf('<p class="description">%s</p>', $attrs['description']);
	}	
	
	public function sanitize( $value ) {
		
		return trim($value);
	}
}



class photopress_settings_field_commaseparatedlist extends photopress_settings_field_text {
	
	public function sanitize( $value ) {
		
		$value = trim( $value );
		$value = str_replace(' ', '', $value ); 
		$value = trim( $value, ',');
		
		return $value;
	}
	
	public function isValid( $value ) {
		
		$re = '/^\d+(?:,\d+)*$/';
	
		if ( preg_match( $re, $value ) ) {
		    
		    return true;
		
		} else {
		
		    $this->addError( 
		    	$this->get('dom_id'), 
				sprintf(
					'%s %s',
					$this->get( 'label_for' ),
					photopress_util::localize( 'can only contain a list of numbers separated by commas.' ) 
				)
			);
		}
	}
}

class photopress_settings_field_none extends photopress_settings_field {

	public function render ( $attrs ) {
		
	}
}
class photopress_settings_field_onoffarray extends photopress_settings_field {

	public function render ( $attrs ) {
		
		// get persisted options
		$values = pp_api::getPersistedOption( $this->package, $this->module, $attrs['id'] );
		
		// get the default options
		$defaults = pp_api::getDefaultOption( $this->package, $this->module, $attrs['id'] );
		
		$options = $attrs['options'];
		
		if ( ! $values ) {
		
			$values = $defaults;
		}
	
		echo sprintf('<p class="description">%s</p>', $attrs['description']);
		
		foreach ( $options as $k => $label ) {
			
			$checked = '';
			$check = false;
			
			if ( in_array( trim( $k ), array_keys( $values ), true ) && $values[ trim( $k ) ] == true ) {
				
				$check = true;
			} 
				
			$on_checked = '';
			$off_checked = '';
			
			if ( $check ) {
				
				$on_checked = 'checked=checked';
				
			} else {
				
				$off_checked = 'checked';
			}
			
			//$callback = $this->get('value_label_callback');
				
			//$dvalue_label = apply_filters( $this->get('id').'_field_value_label', $ovalue );
			
			echo sprintf(
				'<p>%s: <label for="%s_on"><input class="" name="%s[%s]" id="%s_on" value="1" type="radio" %s> On</label>&nbsp; &nbsp; ', 
				$label,
				esc_attr( $attrs['dom_id'] ),
				esc_attr( $attrs['name'] ), 
				esc_attr( $k ),
				esc_attr( $attrs['dom_id'] ),
				$on_checked
			);
			
			echo sprintf(
				'<label for="%s_off"><input class="" name="%s[%s]" id="%s" value="0" type="radio" %s> Off</label></p>', 
				
				esc_attr( $attrs['dom_id'] ),
				esc_attr( $attrs['name'] ), 
				esc_attr( $k ),
				esc_attr( $attrs['dom_id'] ),
				$off_checked
			);

			
			
			/*
echo sprintf(
				'<p><input name="%s[]" id="%s" value="%s" type="checkbox" %s> %s</p>', 
				esc_attr( $attrs['name'] ), 
				esc_attr( $attrs['dom_id'] ),
				esc_attr( $k ),
				$checked,
				esc_html( $label )
			);
*/
		}
	}
	
	public function setFalseValue() {
		
		return array();
	}
}

class photopress_settings_field_booleanarray extends photopress_settings_field {

	public function render ( $attrs ) {
		
		// get persisted options
		$values = pp_api::getPersistedOption( $this->package, $this->module, $attrs['id'] );
		
		// get the default options
		$defaults = pp_api::getDefaultOption( $this->package, $this->module, $attrs['id'] );
		
		if ( ! $values ) {
		
			$values = array();
		}
	
		echo sprintf('<p class="description">%s</p>', $attrs['description']);
		
		foreach ( $defaults as $dvalue ) {
			
			$checked = '';
			$check = in_array( trim($dvalue), $values, true ); 
				
			if ( $check ) {
				
				$checked = 'checked="checked"';
			}
			
			$callback = $this->get('value_label_callback');
				
			$dvalue_label = apply_filters( $this->get('id').'_field_value_label', $dvalue );
			
			echo sprintf(
				'<p><input name="%s[]" id="%s" value="%s" type="checkbox" %s> %s</p>', 
				esc_attr( $attrs['name'] ), 
				esc_attr( $attrs['dom_id'] ),
				esc_attr( $dvalue ),
				$checked,
				esc_html( $dvalue_label )
			);
		}
	}
	
	public function setFalseValue() {
		
		return array();
	}
}

class photopress_settings_field_integer extends photopress_settings_field_text {
	
	
	public function sanitize( $value ) {
		
		return intval( trim( $value ) );
	}
	
	public function isValid( $value ) {
		
		if ( is_numeric( $value ) && $value > $this->get('min_value') ) {
			
			return true;
			
		} else {
		
			$this->addError( 
				$this->get('dom_id'), 
				sprintf(
					'%s %s %s %s %s.',
					$this->get('label_for'),
					photopress_util::localize('must be a number between'),
					$this->get('min_value'),
					photopress_util::localize('and'),
					$this->get('max_value')
				)
			);
		}
	}
}

class photopress_settings_field_url extends photopress_settings_field {
	
	public function sanitize( $value ) {
		
		return filter_var( trim( $value ), FILTER_SANITIZE_URL );
	}
	
	public function isValid( $value ) {
		
		if ( filter_var( $value, FILTER_VALIDATE_URL ) ) {
			
			return true;
			
		} else {
		
			$this->addError( 
				$this->get('dom_id'), 
				sprintf(
					'%s %s',
					$this->get('label_for'),
					photopress_util::localize('Must be a valid url.')
				)
			);
		}
	}
}


class photopress_settings_field_select extends photopress_settings_field {
	
	public function sanitize ( $value ) {
		
		return $value;
	}
	
	public function render( $attrs ) {
		
		$selected = pp_api::getOption( $this->package, $this->module, $attrs['id'] );
		
		$default = pp_api::getDefaultOption( $this->package, $this->module, $attrs['id'] );
		
		$options = $attrs['options'];
		$opts = '';
		
		foreach ($options as $option) {
			
			$selected_attr = '';
			
			if ($option === $selected) {
				
				$selected_attr = 'selected';
			}
			
			$opts .= sprintf(
				'<option value="%s" %s>%s</option> \n',
				$option,
				$selected_attr,
				ucwords( $option )
			);
		}
		
		
		echo sprintf(
			'<select id="%s" name="%s">%s</select>', 
			
			esc_attr( $attrs['dom_id'] ),
			esc_attr( $attrs['name'] ), 
			$opts
		);
		
		echo sprintf('<p class="description">%s</p>', $attrs['description']);
	
	}
}

class photopress_settings_field_boolean extends photopress_settings_field {
	
	public function isValid( $value ) {
	
		$value = intval($value);
		
		if ( $value === 1 || $value === 0 ) {
			
			return true;
		} else {
		
			$this->addError( $this->get('dom_id'), $this->get('label_for') . ' ' . photopress_util::localize( 'field must be On or Off.' ) );
		}

	}
	
	public function sanitize ( $value ) {
		
		return intval( $value );
	}
	
	public function render( $attrs ) {
		
		$value = pp_api::getOption( $this->package, $this->module, $attrs['id'] );
		
		if ( ! $value && ! is_numeric( $value )  ) {
			
			$value = pp_api::getDefaultOption( $this->package, $this->module, $attrs['id'] );
		}
		
		$on_checked = '';
		$off_checked = '';
		
		if ( $value ) {
			
			$on_checked = 'checked=checked';
			
		} else {
			
			$off_checked = 'checked';
		}
		
		echo sprintf(
			'<label for="%s_on"><input class="" name="%s" id="%s_on" value="1" type="radio" %s> On</label>&nbsp; &nbsp; ', 
			
			esc_attr( $attrs['dom_id'] ),
			esc_attr( $attrs['name'] ), 
			esc_attr( $attrs['dom_id'] ),
			$on_checked
		);
		
		echo sprintf(
			'<label for="%s_off"><input class="" name="%s" id="%s" value="0" type="radio" %s> Off</label>', 
			esc_attr( $attrs['dom_id'] ),
			esc_attr( $attrs['name'] ), 
			esc_attr( $attrs['dom_id'] ),
			$off_checked
		);
		
		echo sprintf('<p class="description">%s</p>', $attrs['description']);
	}
}

class photopress_settings_section {
	
	public $properties;
	
	public function __construct( $params ) {
	
		$this->properties = array();
		
		$defaults = array(
			
			'id'			=> '',
			'title'			=> '',
			'callback'		=> array( $this, 'renderSection'),
			'description'	=> ''
		);
		
		$this->properties = photopress_util::setDefaultParams( $defaults, $params );
	}
	
	public function get( $key ) {
		
		if ( array_key_exists( $key, $this->properties ) ) {
			
			return $this->properties[ $key ];
		}
	}
	
	/**
	 * Renders the html of the section header
	 *
	 * Callback function for 
	 *
	 * wordpress passes a single array here that contains ID, etc..
	 */
	public function renderSection( $arg ) {
	
		echo $this->get('description');
	}
}

?>