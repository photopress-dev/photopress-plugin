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
			const sent = this.state.settings;
			savedSettings.call( this );
			
			return apiFetch( {
				path: '/wp/v2/settings',
				method: 'POST',
				data: { [ module_name ]: sent },
			} ).then( response => {
				
				// The saved settings, except fields changed while the save was
				// on its way (typed into right after a switch saved), which
				// keep what was typed and stay to be saved.
				this.savedSettings = { ...this.savedSettings, ...response[ module_name ] };
				this.setState( ( state ) => {
					const changed = Object.keys( state.settings ).filter( ( key ) => state.settings[ key ] !== sent[ key ] );
					const settings = { ...state.settings, ...response[ module_name ] };
					changed.forEach( ( key ) => {
						settings[ key ] = state.settings[ key ];
					} );
					return {
						settings,
						isAPISaving: false,
						dirtyFields: state.dirtyFields.filter( ( key ) => changed.includes( key ) ),
					};
				} );
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
	
/**
 * The settings as last saved (or loaded), kept the first time a setting
 * changes, before it does.
 */
function savedSettings() {

	if ( ! this.savedSettings ) {
		this.savedSettings = { ...this.state.settings };
	}

	return this.savedSettings;
}

/**
 * Saves one setting, as a switch does: the others are sent as last saved, so
 * what is typed elsewhere on the tab is not saved with it, and stays on
 * screen to be saved with its own Save.
 */
export function saveSetting( key, value ) {

	const module_name = this.props.settingsGroup;
	const saved = savedSettings.call( this );

	this.setState( { isAPISaving: true } );

	return apiFetch( {
		path: '/wp/v2/settings',
		method: 'POST',
		data: { [ module_name ]: { ...saved, [ key ]: value } },
	} ).then( ( response ) => {

		const stored = response[ module_name ] || {};
		this.savedSettings = { ...saved, ...stored };

		this.setState( ( state ) => ( {
			settings: state.settings[ key ] === value && key in stored ? { ...state.settings, [ key ]: stored[ key ] } : state.settings,
			isAPISaving: false,
			dirtyFields: state.dirtyFields.filter( ( name ) => name !== key ),
		} ) );
		this.setError( 'save', null );

	} ).catch( ( error ) => {

		this.setState( { isAPISaving: false } );
		this.setError( 'save', error.message || 'The settings could not be saved.' );
	} );
}

export function	setSetting ( key, value, persist ) {

	savedSettings.call( this );

	// A new array: pushing onto the one in state would mutate it.
	let df = [ ...this.state.dirtyFields, key ];
	
	let new_settings = {
		
		...this.state.settings,
		[key]: value
	};
	
	if (persist) {
		
		// Settled once the setting is saved (or not: see getError( 'save' )).
		return new Promise( ( resolve ) => this.setState( 
			{ 
				settings: new_settings,
				dirtyFields: df
				
			},
			() => saveSetting.call( this, key, value ).then( resolve )
		) );
		
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

	savedSettings.call( this );
	
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

