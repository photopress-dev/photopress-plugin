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
				tag: '',
				parseTagValue: false
			}	
		};
	}
	
	componentDidMount() {
		
		
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

		this.setState(
			{ 
				settings: {
					...this.state.settings,
					custom_taxonomies
				}
			},
			this.saveSettings	
		);
	}
		
	newTaxValueChange( key, value ) {
		
		let newTax = this.state.newTaxDefinition;
			
		let newVal = {};
		
		if ( key === 'singularLabel') {
			
			newVal.id = 'pp_' + value;
			
		}
		
		newVal[ key ] = value;
		
		this.setState( 
			
			{ 
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
		if ( newTax.id.length > 1 && newTax.pluralLabel.length > 1 && newTax.singularLabel.length > 1 && newTax.tag.length > 1 ) {
			
			isNewTaxPresent = true;
			
		}
		
		this.setState(
			
			{ isNewTaxPresent: isNewTaxPresent }
		);
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
	                   
	                
	                    <div key={ 'add-new-tax-definition'} className="new-taxonomy-control">
						
			                <div className="taxonomy_attr">
								
				                <TextControl
				                  label={ __('Singular Label') }
				                  id={'new-taxonomy-singular'}
				                  placeholder={ __('e.g. Car') }
				                  className="right-pad"
					              onChange={ e => this.newTaxValueChange( 'singularLabel', e ) }
					              help={''}
								/>
							</div>
							
			                <div className="taxonomy_attr">
								
				                <TextControl
				                  label={ __('Plural Label') }
				                  id={'new-taxonomy-plural'}
				                  className="right-pad"
				                  placeholder='e.g. Cars'
					              onChange={ e => this.newTaxValueChange( 'pluralLabel', e ) }
					              help={''}
								/>
							</div>
							
							<div className="taxonomy_attr">
								
								<SelectControl
									id={'new-taxonomy-tag'}	
									label="XMP Tag"
									
									options={this.getXmpLabels()}
									className="right-pad"
									onChange={ e => this.newTaxValueChange( 'tag', e ) }
									help={'The embedded meta-data tag to extract.'}
								/>
									<ExternalLink href="#">
									{ __( 'Read about XMP Tags' ) }
								</ExternalLink>
							</div>
							
							<div className="taxonomy_attr">
							
								<CheckboxControl
									label="Child Taxonomy"
									id={'new-taxonomy-parse-xmp-tag'}
									defaultChecked={false}
									onChange={ e => this.newTaxValueChange( 'parseTagValue', e ) }
									help={'e.g. "Parse people:Elon Musk from dc:subjects"'}
								/>
								
							
							</div>
							
							<div className="taxonomy_attr">
								<Button
									className="right-pad"
									isPrimary
									
									disabled={ this.state.isAPISaving || ! this.state.isNewTaxPresent}
									onClick={ this.addNewTaxonomy }
								>
									{ __( 'Save' ) }
								</Button> 
								 <Button 
								 	isSecondary 
								 	onClick={ closeModal }
								 	
								 >
			                        Cancel
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
			                	
			                	<div className="taxonomy_attr placeholder-small">
									
									<TextControl
										label={ __('Singular Label') }
										value={`${val.singularLabel}`} 
										className="right-pad"
										readOnly
									/>
								
								</div>
			                	
			                	<div className="taxonomy_attr placeholder-small">
									
									<TextControl
										label={ __('Plural Label') }
										value={`${val.pluralLabel}`} 
										className=" right-pad"
										readOnly
									/>
									
								</div>
								
								<div className="taxonomy_attr">
									
									<TextControl
										label={ __('XMP Tag') }
										value={`${val.tag}`} 
										className="right-pad"
										readOnly
									/>
								
								</div>
								
								<div className="taxonomy_attr placeholder-tiny">
									
									<TextControl
										label={ __('Parse XMP') }
										value={`${val.parseTagValue}`} 
										className=" right-pad"
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