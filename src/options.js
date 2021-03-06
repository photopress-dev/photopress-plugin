/* eslint-disable camelcase */
/**
 * WordPress dependencies
 */
const { __ } = wp.i18n;

const {
	BaseControl,
	Button,
	ExternalLink,
	PanelBody,
	PanelRow,
	Placeholder,
	Spinner,
	ToggleControl,
} = wp.components;

import { TabPanel } from '@wordpress/components';

const {
	render,
	Component,
	Fragment
} = wp.element;

/**
 * Internal dependencies
 */
import MetadataSettings from './options/metadata.js';
import SlideshowSettings from './options/slideshow.js';

class SettingsPage extends Component {
	
	constructor() {
		
		super( ...arguments );
		
		this.settingsModules = {};
		
		// these are the modules that are active. set as a prop on the container
		let modules = JSON.parse( this.props.modules );
		
		// loop through leys to build the settings module control obj
		let modules_keys = Object.keys( modules );
		for (var i = 0; i < modules_keys.length; i++) {
			
			// this is the name format that settings are stored in WP options table
			let name = 'photopress_core_' + modules_keys[ i ];
			
			let label = modules[ modules_keys[ i ] ].label;
			
			// if a module has set a label then it is new style and can have its settings page rendered
			if ( label ) {
				this.settingsModules[ name ] = {label: label };
			}
		}; 

		this.state = {
			isAPILoaded: false,
			isAPISaving: false,
		};
		
		//console.log(this.props);
	}

	componentDidMount() {
			
			// load site settings
			wp.api.loadPromise.then( () => {
			
			this.settings = new wp.api.models.Settings();
		
			//console.log( this.settings );
			
			if ( false === this.state.isAPILoaded ) {
			
				this.settings.fetch().then( response => {
			
					this.setState({
						photopress_core_metadata: response.photopress_core_metadata ,
						photopress_core_slideshow: response.photopress_core_slideshow ,
						photopress_core_base: response.photopress_core_base,
						isAPILoaded: true
					});
					
					//console.log(this.state);
				});
			}
		});
	}
	
	getSettingsModuleNames() {
		
		return Object.keys(this.settingsModules);
	}
	
	generateNavTabs() {
		
		let modules = this.getSettingsModuleNames();
		let tabs = [];
		modules.map( (val, idx) => {
			
			tabs.push(
				{
	                name: val,
	                title: this.settingsModules[val].label,
	                className: 'tab-one',
	            }	
			);
			
		});	
		
		return tabs;
	}
	
	packageModuleKey( name ) {
		
		return 'photopress_core_' + name;
	}
	
	render() {
		
		if ( ! this.state.isAPILoaded ) {
			return (
				<Placeholder>
					<Spinner/>
				</Placeholder>
			);
		}
		
		const { 
			thumbnailHeight, 
			enable 
			
		} = this.state.photopress_core_slideshow;
		
		const {
			custom_taxonomies,
			default_taxonomies
			
		} = this.state.photopress_core_metadata;
		
		
		const renderMetaDataSettings = () => (
			
			<MetadataSettings
				key = {"metadataoptionspage'"}
				data= {this.state.photopress_core_metadata}
				settingsGroup={"photopress_core_metadata"}
			/>	
		);
		
		const renderSlideshowSettings = () => (
			
			<SlideshowSettings
				key = {"slideshowoptionspage'"}
				data= {this.state.photopress_core_slideshow}
				settingsGroup={"photopress_core_slideshow"}
			/>	
		);
		
		const renderTab = (tab) => { 
			//console.log(tab);
			let rf = function() {};
			let anchor = window.location.hash ? window.location.hash.substring(1) : tab.name;
			
			switch( anchor ) {
				
				case "photopress_core_metadata":
				
					rf = renderMetaDataSettings;
					
					break;
					
				case "photopress_core_slideshow":
					
					rf = renderSlideshowSettings;
				
					break;	
			}
			
			
			
			return ( 
				
				rf()
		
		)};
		
		const logo_url = photopress_options_conf.plugin_url + '/static/images/logo-200w.png';
		
		return (
			<Fragment>
					
				<div className="photopress-options-container">
					
					<div className="masthead row">
						<div className="masthead-container">
							<div className="logo">
								<h1>
									<img src={logo_url} alt={__( 'PhotoPress' ) } />
								</h1>
							</div>
						</div>
					</div>
					
					
					<div className="settings row">
					
						<TabPanel className="tab-navigation row"
					        activeClass="active-tab"
					        initialTabName={window.location.hash.substring(1) || null} 
					        onSelect={ (tabName) => { window.location.hash = tabName } }
					        tabs={ this.generateNavTabs() }
					    >
					        {
					            ( tab ) => renderTab(tab)
					        }
					        
					    </TabPanel>
						
						
							
						<PanelBody>
							<div className="photopress-options-info">
								<h2>{ __( 'Got a question? Found a bug?' ) }</h2>
	
								<p>{ __( 'Visit us on Github if you need any help.' ) }</p>
	
								<div className="photopress-info-button-group">
									<Button
										isSecondary
										className="right-pad"
										target="_blank"
										href="https://github.com/photopress-dev/photopress-plugin/issues"
									>
										{ __( 'Submit a question / bug' ) }
									</Button>
	
									<Button
										isSecondary
										target="_blank"
										href="https://github.com/photopress-dev/photopress-plugin/wiki"
									>
										{ __( 'Documentation' ) }
									</Button>
								</div>
							</div>
						</PanelBody>
					</div>
				</div>
			</Fragment>
		);
	}
}

if ( document.getElementById( 'photopress-core-options' ) ) {
	
	render(
			<SettingsPage
				modules={ document.querySelector('#photopress-core-options').dataset.modules }
			/>,
			document.getElementById( 'photopress-core-options' )
	);
}