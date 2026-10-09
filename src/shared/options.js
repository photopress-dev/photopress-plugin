/**
 * Option helper methods
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

export function saveSettings( module ) {
		
	//console.log(this.state);
	
	let ever_validated = true;
	
	if ( this.state.dirtyFields.length > 0 ) {
		
		this.state.dirtyFields.map( ( name, index ) => {
						
			let validated;
			
			if ( this.settingsSchema.hasOwnProperty( name ) && this.settingsSchema[ name ].validations.length > 0 ) {
				
				this.settingsSchema[ name ].validations.map( ( validation ) => {
					
					validated = validateInput( this.getSetting( name ), validation.type );
					
					if ( ! validated ) {
					
						ever_validated = false;
						this.setError( validation.errorSection, validation.errorMsg );
						
					} else {
						
						this.setError(validation.errorSection, null);
					}

				});
			}
		});
		
		if ( ever_validated ) {
			
			this.setState({ isAPISaving: true });
			
			const module_name = this.props.settingsGroup;
			
			return apiFetch( {
				path: '/wp/v2/settings',
				method: 'POST',
				data: { [ module_name ]: this.state.settings },
			} ).then( response => {
				
				// merge response with any other defaults
				let new_settings = { ...this.state.settings, ...response[module_name] };
				this.setState({
					settings: new_settings,
					isAPISaving: false,
					dirtyFields: []
				});
				this.setError( 'save', null );
				
			} ).catch( error => {
				
				// Without this the Save button stayed disabled and nothing
				// said why. The REST API rejects values its schema does not
				// allow, such as a select value that is not one of its options.
				this.setState({ isAPISaving: false });
				this.setError( 'save', error.message || 'The settings could not be saved.' );
			} );
		}
	}
}

export function sanitize( value, type ) {
	
	if (type ===  'string') {
			
			let nv = value.trim() + '';
			return nv;
	}
	
	return value;
}

export function validateInput( input, type ) {
	
	if ( type === 'url' ) {
		console.log('validating url', input);
		
		var pattern = new RegExp('^(https?:\\/\\/)?'+ // protocol
	    '((([a-z\\d]([a-z\\d-]*[a-z\\d])*)\\.)+[a-z]{2,}|'+ // domain name
	    '((\\d{1,3}\\.){3}\\d{1,3}))'+ // OR ip (v4) address
	    '(\\:\\d+)?(\\/[-a-z\\d%_.~+]*)*'+ // port and path
	    '(\\?[;&a-z\\d%_.~+=-]*)?'+ // query string
	    '(\\#[-a-z\\d_]*)?$','i'); // fragment locator
		
		let ret = pattern.test( input );
		//console.log('validation result', ret);
		return ret;
	}
	
	
	if ( type === 'notEmpty' ) {
		
		input = input.trim();
		
		let len = input.length;
		
		if ( len > 0 ) {
			
			return true;
		} else {
			
			return false;
			
		}
	}
}
		
export function	getSetting ( key ) {
	
	if ( this.state.hasOwnProperty( 'settings' )  &&  this.state.settings.hasOwnProperty( key ) ) {
	
		return this.state.settings[ key ];	
	}
}
	
export function	setSetting ( key, value, persist ) {

	// A new array: pushing onto the one in state would mutate it.
	let df = [ ...this.state.dirtyFields, key ];
	
	let new_settings = {
		
		...this.state.settings,
		[key]: value
	};
	
	if (persist) {
		
		this.setState( 
			{ 
				settings: new_settings,
				dirtyFields: df
				
			},
			() => this.saveSettings()
		);
		
	} else {
		
		this.setState( 
			{ 
				settings: new_settings,
				dirtyFields: df
			}
		);	
		
	}	
}

export function	persistSetting ( key, value ) {
	
	this.setSetting( key, value, true );
	
}
	
/**
 * Removes a setting, or one entry of an object-valued setting.
 */
export function	deleteSetting ( key, subKey ) {
	
	if ( ! this.state.settings || ! this.state.settings.hasOwnProperty( key ) ) {
		return;
	}
	
	let settings = { ...this.state.settings };
	
	if ( typeof subKey !== 'undefined' ) {
		
		let value = Array.isArray( settings[ key ] ) ? [ ...settings[ key ] ] : { ...settings[ key ] };
		
		if ( Array.isArray( value ) ) {
			value.splice( subKey, 1 );
		} else {
			delete value[ subKey ];
		}
		
		settings[ key ] = value;
		
	} else {
		
		delete settings[ key ];
	}
	
	this.setState( {
		settings,
		dirtyFields: [ ...this.state.dirtyFields, key ]
	} );
}

export function getError( key ) {
	
	if ( this.state.errors.hasOwnProperty( key ) ) {
		
		return this.state.errors[ key ];
	}
}

export function setError( key, msg ) {
	
	this.setState( { 
		errors: {
			...this.state.errors,
			[`${key}`]: msg
		}
	});
}

