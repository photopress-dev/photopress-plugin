/**
 * WordPress dependencies
 */
const { __ } = wp.i18n;
import { Component, Fragment, useState } from '@wordpress/element';

import xmpLabels from '../shared/xmp-labels.js';

import {
	BaseControl,
	Button,
	ExternalLink,
	Panel,
	PanelBody,
	PanelRow,
	Placeholder,
	Spinner,
	ToggleControl,
	Notice,
	Disabled,
	CheckboxControl,
	SelectControl,
	TextControl,
	Modal
} from '@wordpress/components';

import {
	
	setSetting,
	getSetting,
	deleteSetting,
	saveSettings,
	getError,
	setError,
	persistSetting
	
} from '../shared/options.js';
/**
 * Metadata Options Component class
 */
class MetadataSettings extends Component {
	
	constructor() {
		
		super( ...arguments );
		
		this.settingsGroup = this.props.settingsGroup;
		
		
		
		this.xmpLabels = xmpLabels;
		
		this.persistSetting = persistSetting.bind( this );
		this.saveSettings = saveSettings.bind( this );
		this.getSetting = getSetting.bind( this );
		this.setSetting = setSetting.bind( this );
		this.deleteSetting = deleteSetting.bind( this );
		this.getError = getError.bind( this );
		this.setError = setError.bind( this );
		
		this.changeCustomTaxonomies = this.changeCustomTaxonomies.bind( this );
		this.deleteCustomTaxonomy = this.deleteCustomTaxonomy.bind( this );
		this.addNewTaxonomy = this.addNewTaxonomy.bind( this );
		this.newTaxValueChange = this.newTaxValueChange.bind( this );
		
		
		this.MyModal = this.MyModal.bind( this );
		
		this.state = {
			isAPILoaded: false,
			isAPISaving: false,
			errors: {},
			settings: this.props.data,
			isNewTaxPresent: false,
			modalOpen: false,
			newTaxDefinition: {
				id: '',
				pluralLabel: '',
				singularLabel: '',
				xmpTag: '',
				parseXmpTag: false
			}

			
		};
		
				
		console.log('metadata');
	}
	
	componentDidMount() {
		
/*
		console.log('metadata didmount');
		
		wp.api.loadPromise.then( () => {
			
			this.settings = new wp.api.models.Settings();
			
			console.log( this.settings);
			
			if ( false === this.state.isAPILoaded ) {
			
				this.settings.fetch().then( response => {
			
					this.setState({
						settings: response[ this.settingsGroup ],
						isAPILoaded: true
					});
					
					console.log(this.state);
				});
			}
		});
*/
		
	}
	
	addNewTaxonomy( event ) {
			
		let value = this.state.newTaxDefinition.pluralLabel;
		
		if ( ! value ) {
			
			this.setError('custom_taxonomies', __('Taxonomy name cannot be empty') );
		}
		
		if ( typeof value == 'string' ) {
			
			value = value.trim();
		}
		
		let custom_taxonomies = this.state.settings.custom_taxonomies ;
	
		console.log( custom_taxonomies );
		
		//create array of current taxonomies
		let current_tax = custom_taxonomies.map( function( tax ){
			
			return tax.plural;
		});
		
		// check for duplicates
		if ( ! current_tax.includes( value ) ) {
			custom_taxonomies.push( this.state.newTaxDefinition );
		}
		
		
		// set state
		this.setState( { 
			settings: {
				...this.state.settings,
				custom_taxonomies: custom_taxonomies
			},
			newTaxDefinition: {
				
				id: '',
				puralLabel: '',
				singularLabel: '',
				xmpTag: '',
				parseXmpTag: false
				
			},
			modalOpen: false
			
		},
		this.saveSettings
		);
		
		//console.log(this.state.settings);
		;
			
	}
		
	deleteCustomTaxonomy ( event ) {
			
		console.log(' custom_taxonomies delete');
		
		// remove from state
		let custom_taxonomies = this.state.settings.custom_taxonomies
		//delete custom_taxonomies[ event.target.id ];
		console.log(event.target.id);
		
		const index = event.target.id;
		
		console.log(index);
		if (index > -1) {
		
			custom_taxonomies.splice( index, 1 );
		}
		
		
		console.log( custom_taxonomies);
		
		this.setState(
			{ 
			settings: {
				...this.state.settings,
				custom_taxonomies
			}});
		
		// persist new state	
		this.saveSettings();
	}
	
	changeCustomTaxonomies( val ) {
		
		let isNewTaxPresent;
		let value = val.target.value;
		// this is needed to dsiable the button
		if ( value.length > 1 ) {
			
			isNewTaxPresent = true;
			
		} else {
			
			isNewTaxPresent = false;
		}
		
		let custom_taxonomies = this.state.settings.custom_taxonomies;
		console.log(custom_taxonomies);
		custom_taxonomies[ value ] = { plural: '', singluar: '' };
		console.log(custom_taxonomies);
		
		this.setState( { 
			settings: {
				...this.state.settings,
				custom_taxonomies
			},
			isNewTaxPresent: isNewTaxPresent
		});
		
		console.log(this.state);
		this.saveSettings();
	}
	
	newTaxValueChange( key, val ) {
		
		let newTax = this.state.newTaxDefinition;
		
		let value = val;
		let id;
		let newVal = {};
		
		if ( key === 'pluralLabel') {
			
			newVal.id = 'pp_' + value;
			
		}
		
		newVal[ key ] = value;
		
		this.setState( { 
			newTaxDefinition: {
				...this.state.newTaxDefinition,
				...newVal
			}
		},
		this.setNewTaxPresent
		);
		
		
		
		console.log(this.state);
		
	}
	
	setNewTaxPresent() {
		
		let isNewTaxPresent = this.state.isNewTaxPresent;
		let newTax = this.state.newTaxDefinition;
		// this is needed to dsiable the button
		if ( newTax.pluralLabel.length > 1 && newTax.singularLabel.length > 1 && newTax.xmpTag.length > 1 ) {
			
			isNewTaxPresent = true;
			
		}
		
		this.setState({isNewTaxPresent: isNewTaxPresent});
		console.log(this.state);
	}
	
	getXmpLabels() {
		
		let labels = [{value: null, label: 'Select...'}];
		
		const keys = Object.keys( this.xmpLabels );
		
		keys.map( ( key, idx ) => {
					
			let l = this.xmpLabels[ key ];
			labels.push( { value: key, label: `${key} \u00A0 - \u00A0  (${l})`});
						
		});
		
		return labels;

	}
	
	MyModal() {
			
	    //const [ isOpen, setOpen ] = useState( false );
	    let modalOpen = this.state.modalOpen;
	    const openModal = () => this.setState( { modalOpen: true } );
	    const closeModal = () => this.setState( { modalOpen: false } );
	 
	    return (
		    
	        <div>
	            <Button isSecondary onClick={ openModal }>Add</Button>
	            { modalOpen && (
	                <Modal
	                    title="Add Image Taxonomy"
	                    onRequestClose={ closeModal }>
	                    <Button isSecondary onClick={ closeModal }>
	                        My custom close button
	                    </Button>
	                    
	                    <div key={ 'add-new-tax-definition'} className="new-taxonomy-control">
						
			                
			                <div className="taxonomy_attr">
								
				                <TextControl
				                  label={ __('Plural Label') }
				                  id={'new-taxonomy-plural'}
				                  className="right-pad"
				                  placeholder='e.g. Cars'
					              onChange={ e => this.newTaxValueChange( 'pluralLabel', e ) }
					              help={'foo bar'}
								/>
							</div>
							
							<div className="taxonomy_attr">
								
				                <TextControl
				                  label={ __('Singular Label') }
				                  id={'new-taxonomy-singular'}
				                  placeholder={ __('e.g. Car') }
				                  className="right-pad"
					              onChange={ e => this.newTaxValueChange( 'singularLabel', e ) }
					              help={'foo bar'}
								/>
							</div>
							
							<div className="taxonomy_attr">
								
								<SelectControl
									id={'new-taxonomy-embedded-tag'}	
									label="XMP Tag"
									
									options={this.getXmpLabels()}
									className="right-pad"
									onChange={ e => this.newTaxValueChange( 'xmpTag', e ) }
									help={'foo bar'}
								/>
									<ExternalLink href="#">
									{ __( 'Read about XMP Tags' ) }
								</ExternalLink>
							</div>
							
							<div className="taxonomy_attr">
							
								<CheckboxControl
									label="Parse XMP Value for taxonomy"
									id={'new-taxonomy-parse-xmp-tag'}
									defaultChecked={false}
									onChange={ e => this.newTaxValueChange( 'parseXmpTag', e ) }
									help={'e.g. "people:Elon Musk"'}
								/>
								
							
							</div>
							
							<div className="taxonomy_attr">
								<Button
									isPrimary
									//islarge
									disabled={ this.state.isAPISaving || ! this.state.isNewTaxPresent}
									onClick={ this.addNewTaxonomy }
								>
									{ __( 'Save' ) }
								</Button>
							</div>
	
						</div>

	                    
	                    
	                </Modal>
	            ) }
	        </div>
	    );
		    
	}
		
	render() {
		
				
		
		const MyNotice = () => (
		    <Notice status="error">
		        <p>An error occurred: <code>{ '' }</code>.</p>
		    </Notice>
		);
		
		const rows = [];
		
		const enable = () => (
			
			<BaseControl
				label={ __( '' ) }
				help={ '' }		
				id="'custom_taxonomies_enable'"
				className="codeinwp-text-field"
			>
			
				{ this.getError('custom_taxonomies') &&
					
					<Notice status="error">
				        <p>An error occurred: <code>{ this.getError('custom_taxonomies') }</code>.</p>
				    </Notice>	
									
				}
				
			
				<ToggleControl
					id={'custom_taxonomies_eneable'}
					label={ __( 'Extract embedded image meta data.' ) }
					help={ 'Stores embedded image meta-data into custom taxonomies that you define.' }
					checked={ this.getSetting('custom_taxonomies_enable') || false }
					onChange={ ( value ) => this.persistSetting( 'custom_taxonomies_enable', value ) }
				/>
				
			</BaseControl>	

		);
		
		const tax_roster = () => (
			
			<BaseControl
				label={ __( 'Taxonomy Definitions' ) }
				className={"codeinwp-text-field"}
				help={"The list of taxononomies currently defined. To edit a taxonomy definition you must delete it and then re-add using the form below. "}
			>
				{ this.MyModal() }	
				{ this.getSetting('custom_taxonomies') &&
		        
		        	this.getSetting( 'custom_taxonomies' ).map( ( val, idx ) => {
			              
			            let idx_label = idx + 1;
			           
			            return (
				          
			            	<div className="taxonomy_control" key={idx}>
			            		
			                	<div className="taxonomy_attr">
			                		
			                		<div className="components-base-control__field">
			                			<label htmlFor={idx}>{`${idx_label}.`}</label>
			                		</div>
			                		
			                	</div>
			                	
			                	<div className="taxonomy_attr placeholder-small">
									
									<TextControl
										label={ __('ID') }
										value={`${val.id}`} 
										className="right-pad"
										readOnly
									/>
		
			                	</div>
			                	
			                	<div className="taxonomy_attr">
									
									<TextControl
										label={ __('Plural Label') }
										value={`${val.pluralLabel}`} 
										className="right-pad"
										readOnly
									/>
									
								</div>
								
								<div className="taxonomy_attr">
									
									<TextControl
										label={ __('Singular Label') }
										value={`${val.singularLabel}`} 
										className="right-pad"
										readOnly
									/>
								
								</div>
								
								<div className="taxonomy_attr">
									
									<TextControl
										label={ __('XMP Tag') }
										value={`${val.xmpTag}`} 
										className="right-pad"
										readOnly
									/>
								
								</div>
								
								<div className="taxonomy_attr">
									
									<TextControl
										label={ __('Parse XMP') }
										value={`${val.parseXmpTag}`} 
										className="placeholder-small right-pad"
										readOnly
									/>
		
								</div>
								
								<div className="taxonomy_attr">
								
					                <Button
										isPrimary
										id = {idx}
										disabled={ this.state.isAPISaving }
										onClick={ this.deleteCustomTaxonomy }
										className="components-base-control__field"
									>
										{ __( 'Delete' ) }
									</Button>
								</div>
								
							</div>
			              
			            )
			            
			          })
		        }
		        
		        </BaseControl>
			
		);
		
		rows.push( enable, tax_roster );
		

		return (
			
			<PanelBody title={ __( 'Custom Taxonomies' ) }>
						
					{ rows.map( ( val, idx ) => {
					
						let row = val();
						return (
						
						 <PanelRow key={`component-${idx}`}>{row}</PanelRow> 
						 
						 );
						
					})}
					
					
								
			</PanelBody>	
		
		);
	}
}	

export default MetadataSettings;
