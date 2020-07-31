export function saveSettings( module ) {
		
	this.setState({ isAPISaving: true });
	
	console.log(this.state);
	
	const module_name = this.props.settingsGroup;
	
	const model = new wp.api.models.Settings({
		// eslint-disable-next-line camelcase
		[module_name]: this.state.settings
	});

	model.save().then( response => {
		this.setState({
			settings: response[module_name],
			isAPISaving: false
		});
	});
}
		
export function	getSetting ( key ) {

	console.log('get setting');
	
	if ( this.state.hasOwnProperty( 'settings' )  &&  this.state.settings.hasOwnProperty( key ) ) {
	
		return this.state.settings[ key ];	
	}
}
	
export function	setSetting ( key, value ) {
	
	let new_settings = {
		
		...this.state.settings,
		[key]: value
	};
	
	console.log(new_settings);
	
	this.setState( 
		{ 
			settings: new_settings
		}
	);
	
	
}

export function	persistSetting ( key, value ) {
	console.log(key);
	console.log(value);
	
	let new_settings = {
		
		...this.state.settings,
		[key]: value
	};
	
	console.log(new_settings);
	
	this.setState( 
		{ 
			settings: new_settings
		},
		() => this.saveSettings()
	);


	//this.saveSettings();
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

