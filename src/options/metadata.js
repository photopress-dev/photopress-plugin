/**
 * WordPress dependencies
 */
const { __ } = wp.i18n;
import { Component, Fragment } from '@wordpress/element';

import {
	BaseControl,
	Button,
	PanelBody,
	Notice,
	TextControl
} from '@wordpress/components';

import {
	
	setSetting,
	getSetting,
	deleteSetting,
	saveSettings,
	getError,
	setError,
	persistSetting,
	sanitize
	
} from '../shared/options.js';

import TaxonomySettings from './taxonomies.js';
import SwitchRow from '../shared/switch-row.js';
/**
 * Metadata Options Component class
 */
class MetadataSettings extends Component {
	
	constructor() {
		
		super( ...arguments );
		
		this.settingsGroup = this.props.settingsGroup;
		
		this.persistSetting = persistSetting.bind( this );
		this.saveSettings = saveSettings.bind( this );
		this.getSetting = getSetting.bind( this );
		this.setSetting = setSetting.bind( this );
		this.deleteSetting = deleteSetting.bind( this );
		this.getError = getError.bind( this );
		this.setError = setError.bind( this );
		
		this.state = {
			isAPILoaded: false,
			isAPISaving: false,
			errors: {},
			settings: {
				
				custom_taxonomies_enable: true,
				embed_licensor_enable: false,
				web_statement_of_rights: '',
				licensor_name: '',
				licensor_url: '',
				custom_taxonomies: [],
				custom_taxonomies_tag_delimiter: ':',
				alt_text_enable: true,
				alt_text_template: '[photoshop:Headline]. [photopress:stringOfKeywords].',
				description_template: '',
				strip_metadata_from_resized_image: false
			},
			dirtyFields: []	
		};
		
		
		// override default settings with data passed in from the site settings API
		this.state.settings = { ...this.state.settings, ...this.props.data };
		
		// settings schema used for sanitize and validations.
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
	
	render() {
		
		return (
			<Fragment>
			{ this.getError( 'save' ) &&
				<Notice status="error" isDismissible={ false }>
					<p>{ __( 'The settings were not saved:' ) } { this.getError( 'save' ) }</p>
				</Notice>
			}
			<PanelBody title={ __( 'Image Taxonomies' ) }>
				<TaxonomySettings component={ this } />
			</PanelBody>
			
			<PanelBody title={ __( 'Alt Text' ) }>
			
				<BaseControl
					label={ __( '' ) }
					
				>
					<SwitchRow
						label={ __( 'Alt text from metadata' ) }
						help={ __( 'Fills each image’s alt text from its metadata, with the template below.' ) }
						checked={ this.getSetting( 'alt_text_enable' ) }
						onChange={ ( value ) => this.persistSetting( 'alt_text_enable', value ) }
					/>
				
					<TextControl
						id={'alt_text_template'}
						label={ __('Alt Text Template') }
						value={ this.getSetting('alt_text_template') } 
						className="small-input right-pad"
						
						help={"The template to use for populating alt text. Meta-data placeholders should be surounded by square brackets (i.e. [photoshop:Headline]"}
						onChange={ ( value ) => this.setSetting( 'alt_text_template', value ) }
						onBlur={ ( event ) => this.setSetting( 'alt_text_template', sanitize( event.target.value, 'string' ) ) }
					/>
					
					<TextControl
						id={'description_template'}
						label={ __('Description Template') }
						value={ this.getSetting('description_template') || '' }
						className="small-input right-pad"
						help={"The template for an image's description, set on upload and when its file is replaced, e.g. [photoshop:Headline]. Leave empty to leave descriptions alone."}
						onChange={ ( value ) => this.setSetting( 'description_template', value ) }
						onBlur={ ( event ) => this.setSetting( 'description_template', sanitize( event.target.value, 'string' ) ) }
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
						onChange={ ( value ) => this.setSetting( 'licensor_name', value ) }
						onBlur={ ( event ) => this.setSetting( 'licensor_name', sanitize( event.target.value, 'string' ) ) }
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
					
					<SwitchRow
						label={ __( 'Embed licensing metadata in images' ) }
						help={ __( 'Writes the licensor name, licensing URL and Web Statement of Rights into each image’s file on upload.' ) }
						checked={ this.getSetting( 'embed_licensor_enable' ) }
						onChange={ ( value ) => this.persistSetting( 'embed_licensor_enable', value ) }
					/>
					
					
				
				</BaseControl>
				
			</PanelBody>
			
			</Fragment>
		
		);
	}
}	

export default MetadataSettings;
