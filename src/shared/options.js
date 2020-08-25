/**
 * Option helper methods
 */

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
			
			const model = new wp.api.models.Settings({
				// eslint-disable-next-line camelcase
				[module_name]: this.state.settings
			});
		
			model.save().then( response => {
				
				// merge response with any other defaults
				let new_settings = { ...this.state.settings, ...response[module_name] };
				this.setState({
					settings: new_settings,
					isAPISaving: false,
					dirtyFields: []
				});
			});
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

	let df = this.state.dirtyFields;
	df.push(key);
	
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
	
export function	deleteSetting ( pack, module, key, subKey ) {
	
	if ( this.state.hasOwnProperty( 'settings' )  &&  this.state.settings.hasOwnProperty( key ) ) {
		
		let setting = this.state[ group ][ key ];
		
		if ( subkey  && this.state[ group ][ key ].hasOwnProperty( subKey ) ) {
			
			delete setting[ key ][ subkey ];
			
		} else {
			
			delete setting[ key ];	
		}
		
		this.setState( { 
			settings: {
				...this.state.settings,
				[`${key}`]: setting
			}
		});
	}
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

