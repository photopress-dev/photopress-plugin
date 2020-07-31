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

class SettingsPage extends Component {
	
	constructor() {
		
		super( ...arguments );
		
		this.settingsModules = {
			
			photopress_core_metadata: {
				label: 'Image Meta-data'
			},
			photopress_core_slideshow: {
				label: 'Slideshow'
			},
			photopress_core_base: {
				label: 'General'
			}
			
		};
		this.state = {
			isAPILoaded: false,
			isAPISaving: false,
		};
	}

	componentDidMount() {
			
			// load site settings
			wp.api.loadPromise.then( () => {
			
			this.settings = new wp.api.models.Settings();
		
			console.log( this.settings );
			
			if ( false === this.state.isAPILoaded ) {
			
				this.settings.fetch().then( response => {
			
					this.setState({
						photopress_core_metadata: response.photopress_core_metadata ,
						photopress_core_slideshow: response.photopress_core_slideshow ,
						photopress_core_base: response.photopress_core_base,
						isAPILoaded: true
					});
					
					console.log(this.state);
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
		
		const renderTab = (tab) => (
			
			<MetadataSettings
				key = {"metadataoptionspage'"}
				data= {this.state.photopress_core_metadata}
				settingsGroup={"photopress_core_metadata"}
			/>	
		);
		
		
		
		return (
			<Fragment>
					
				<div className="photopress-options-container">
					
					<div className="masthead row">
						<div className="masthead-container">
							<div className="logo">
								<h1>{ __( 'PhotoPress Settings' ) }</h1>
							</div>
						</div>
					</div>
					
					
					<div className="settings row">
					
						<TabPanel className="tab-navigation row"
					        activeClass="active-tab"
					        
					        onSelect={ (tabName) => { console.log( 'Selecting tab', tabName ) } }
					        tabs={ this.generateNavTabs() }>
					        {
					            ( tab ) => renderTab(tab)
					        }
					    </TabPanel>
						
						
							
						<PanelBody>
							<div className="codeinwp-info">
								<h2>{ __( 'Got a question for us?' ) }</h2>
	
								<p>{ __( 'We would love to help you out if you need any help.' ) }</p>
	
								<div className="codeinwp-info-button-group">
									<Button
										isDefault
										//islarge
										target="_blank"
										href="#"
									>
										{ __( 'Ask a question' ) }
									</Button>
	
									<Button
										isDefault
										//islarge
										target="_blank"
										href="#"
									>
										{ __( 'Leave a review' ) }
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
			<SettingsPage/>,
			document.getElementById( 'photopress-core-options' )
	);
}