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
			},
			dirtyFields: []	
		};
		
		this.settingsSchema = {
			
			web_statement_of_rights: {
				
				type: 'url',
				validations: [
					
					{ type: 'url', errorSection: 'licensor', errorMsg: 'Web Statement of Rights URL is not a valid url. Be sure the URL begins with http:// or https:// .'}
				]
			},
			
			licensor_url: {
				
				type: 'url',
				validations: [
					
					{ type: 'url', errorSection: 'licensor', errorMsg: 'Licensor URL is not a valid url. Be sure the URL begins with http:// or https:// .'}
				]
			}
			
		}
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
			
		// remove from state
		let custom_taxonomies = this.state.settings.custom_taxonomies
		//delete custom_taxonomies[ event.target.id ];
		
		
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

		//console.log(this.state);
	}
	
	setUrlSetting( key, value) {
		
		var pattern = new RegExp('^(https?:\\/\\/)?'+ // protocol
	    '((([a-z\\d]([a-z\\d-]*[a-z\\d])*)\\.)+[a-z]{2,}|'+ // domain name
	    '((\\d{1,3}\\.){3}\\d{1,3}))'+ // OR ip (v4) address
	    '(\\:\\d+)?(\\/[-a-z\\d%_.~+]*)*'+ // port and path
	    '(\\?[;&a-z\\d%_.~+=-]*)?'+ // query string
	    '(\\#[-a-z\\d_]*)?$','i'); // fragment locator
		
		let ret = pattern.test( value );
		
		if (ret) {
			
			this.setSetting( key, value );
		} else {
			
			this.setError( 'licensor', `${key} is not a valid url.` );
		}
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
	        
	            <Button 
	            	isPrimary 
	            	onClick={ openModal }
	            >
	            Add New
	            </Button>
	            
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
					id={'custom_taxonomies_enable'}
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
				help={'The list of taxononomies currently defined. To edit a taxonomy definition you must delete it and then re-add using the "Add New" button'}
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
										label={ __('Child Taxonomy?') }
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
		
		const child_delimiter = () => (
			<div>
			
			<BaseControl
				label={ __( 'Child Taxonomy Delimiter' ) }
				help={'The default delimiter is the ":" (semicolon) character. Only change this if you know what you are doing. This delimiter is used to parse child taxonomies from within other meta-data values. e.g. "person:elon musk" would store the term "Elon Musk" into the "person" taxonomy. Child taxonomies are typically parsed from keyword values contained in dc:subject XMP tag.'}		
				id="'custom_taxonomies_enable'"
				className="codeinwp-text-field"
			>
			<TextControl
					id={'custom_taxonomies_tag_delimiter'}
					label={ __('') }
					value={ this.getSetting('custom_taxonomies_tag_delimiter') } 
					className="tiny-input right-pad"
					length={5}
					onChange={ ( value ) => this.setSetting( 'custom_taxonomies_tag_delimiter', value.trim() ) }
					
				/>
			</BaseControl>	
			<Button
				isPrimary
				disabled={ this.state.isAPISaving }
				onClick={ this.saveSettings }
				className="components-base-control__field"
			>
				{ __( 'Save' ) }
			</Button>
			</div>
		);
		
		// push render constants into rows array for final rendering. Order matters.
		rows.push( 
			enable, 
			tax_roster,
			child_delimiter
		);
		

		return (
			<Fragment>
			<PanelBody title={ __( 'Custom Taxonomies' ) }>
						
					{ rows.map( ( val, idx ) => {
					
						let row = val();
						return (
						
						 <PanelRow key={`component-${idx}`}>{row}</PanelRow> 
						 
						 );
						
					})}
								
			</PanelBody>	
			
			<PanelBody title={ __( 'Alt Text' ) }>
			
				<BaseControl
					label={ __( '' ) }
					
				>
					<ToggleControl
						id={'alt_text_enable'}
						label={ __( 'Use Meta-data for alt text of images.' ) }
						help={ 'Populate the alternate text attribute with the value of a meta-data field.' }
						checked={ this.getSetting('alt_text_enable')  }
						onChange={ ( value ) => this.persistSetting( 'alt_text_enable', value ) }
					/>
				
					<TextControl
						id={'alt_text_template'}
						label={ __('Alt Text Template') }
						value={ this.getSetting('alt_text_template') } 
						className="small-input right-pad"
						
						help={"The template to use for populating alt text. Meta-data placeholders should be surounded by square brackets (i.e. [photoshop:Headline]"}
						onChange={ ( value ) => this.setSetting( 'alt_text_template', value.trim() ) }
					/>
					
					<Button
						isPrimary
						disabled={ this.state.isAPISaving }
						onClick={ this.saveSettings }
						className="components-base-control__field"
					>
						{ __( 'Save' ) }
					</Button>
					
				</BaseControl>
				
			</PanelBody>
			
			<PanelBody title={ __( 'Licensing' ) }>
			
				<BaseControl
					label={ __( '' ) }
					
				>
				
					{ this.getError('licensor') &&
						
						<Notice 
							status="error"
							isDismissible={false}
						>
					        <p><b>An error occured:</b> <code>{ this.getError('licensor') }</code></p>
					    </Notice>	
										
					}
					
					<TextControl
						id={'licensor_name'}
						label={ __('Licensor Name') }
						value={ this.getSetting('licensor_name') } 
						className=" right-pad"
						help={"The name of the person or organization that licenses your images."}
						onChange={ ( value ) => this.setSetting( 'licensor_name', value.trim() ) }
					/>
				
					
					<TextControl
						id={'web_statement_of_rights'}
						label={ __('Web Statement of Rights URL') }
						value={ this.getSetting('web_statement_of_rights') } 
						className=" right-pad"
						help={"Used by search engines to display a link to the license statement of your images."}
						onChange={ ( value ) => this.setSetting( 'web_statement_of_rights', value.trim() ) }
					/>
					
					<TextControl
						id={'licensor_url'}
						label={ __('Licensing URL') }
						value={ this.getSetting('licensor_url') } 
						className=" right-pad"
						help={"The URL where people can obtain a license your images."}
						onChange={ ( value ) => this.setSetting( 'licensor_url', value.trim() ) }
					/>

					<Button
						isPrimary
						disabled={ this.state.isAPISaving }
						onClick={ this.saveSettings }
						className="components-base-control__field"
					>
						{ __( 'Save' ) }
					</Button>
					
					<hr/>
					
					<ToggleControl
						id={'embed_licensor_enable'}
						label={ __( 'Embed licensing meta-data in images ' ) }
						help={ 'Embeds licensing related meta-data (Licensor Name, Licensor URL, Web Statement of Rights, etc.) in image file during upload if they do not already exist. Requires exiftool to be installed on your server.' }
						checked={ this.getSetting('embed_licensor_enable')  }
						onChange={ ( value ) => this.persistSetting( 'embed_licensor_enable', value ) }
					/>
					
					
				
				</BaseControl>
				
			</PanelBody>
			
			</Fragment>
		
		);
	}
}	

export default MetadataSettings;
