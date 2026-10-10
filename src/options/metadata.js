/**
 * WordPress dependencies
 */
const { __, sprintf } = wp.i18n;
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
	sanitize,
	validateInput
	
} from '../shared/options.js';

import TaxonomySettings from './taxonomies.js';
import SwitchRow from '../shared/switch-row.js';
import JobPanel from '../shared/jobs.js';
import SaveBar from '../shared/save-bar.js';
import apiFetch from '@wordpress/api-fetch';
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
		this.saveLicensing = this.saveLicensing.bind( this );
		this.saveThen = this.saveThen.bind( this );
		this.navigate = this.navigate.bind( this );
		
		this.state = {
			isAPILoaded: false,
			isAPISaving: false,
			errors: {},
			settings: {
				
				custom_taxonomies_enable: true,
				embed_licensor_enable: false,
				description_enable: false,
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
			dirtyFields: [],
			// Jobs started here, so the job panels ask for the latest again.
			jobs: 0,
			// What a page within the tab, closing, says: { status, text }.
			flash: null,
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
	
	/**
	 * Licensing's Save: all three settings are required, so no file or page
	 * gets half of the licensing information.
	 */
	saveLicensing() {
		const missing = [
			[ 'licensor_name', __( 'Licensor Name' ) ],
			[ 'web_statement_of_rights', __( 'Web Statement of Rights URL' ) ],
			[ 'licensor_url', __( 'Licensing URL' ) ],
		].filter( ( [ key ] ) => ! String( this.getSetting( key ) || '' ).trim() ).map( ( [ , label ] ) => label );

		if ( missing.length ) {
			this.setError( 'licensor', sprintf( __( 'Licensing needs all three settings. Fill in: %s.' ), missing.join( ', ' ) ) );
			return false;
		}

		for ( const key of [ 'web_statement_of_rights', 'licensor_url' ] ) {
			if ( ! validateInput( this.getSetting( key ), 'url' ) ) {
				this.setError( 'licensor', this.settingsSchema[ key ].validations[ 0 ].errorMsg );
				return false;
			}
		}

		this.setError( 'licensor', null );
		return this.saveSettings();
	}

	/**
	 * Save, or Save and reprocess: saves (with save, the section's own save
	 * when it checks its settings), then, when reprocess is given, confirmed
	 * and the save went through, starts the job of this type over every
	 * image.
	 */
	saveThen( type, reprocess, confirm, save = this.saveSettings ) {
		// eslint-disable-next-line no-alert
		if ( reprocess && ! window.confirm( confirm ) ) {
			return;
		}

		const saved = save();

		if ( ! reprocess || false === saved ) {
			return;
		}

		Promise.resolve( saved ).then( () => {
			if ( this.getError( 'save' ) ) {
				return;
			}
			this.setError( type, null );
			apiFetch( { path: '/photopress/v1/jobs', method: 'POST', data: { type, args: { force: ! reprocess.skip } } } )
				.then( () => this.setState( ( state ) => ( { jobs: state.jobs + 1 } ) ) )
				.catch( ( e ) => this.setError( type, e.message || __( 'Saved, but the images could not be reprocessed.' ) ) );
		} );
	}

	/** A section's reprocess job: an error starting it, and its progress. */
	jobPanel( type, label ) {
		return (
			<Fragment>
				{ this.getError( type ) && <Notice status="error" isDismissible={ false }>{ this.getError( type ) }</Notice> }
				<JobPanel type={ type } label={ label } startable={ false } refresh={ this.state.jobs } />
			</Fragment>
		);
	}

	/**
	 * Goes to a page within the tab (taxonomy/parent/new), or with none back
	 * to the tab, at the top of the window, where flash is shown.
	 */
	navigate( route = '', flash = null ) {
		this.setState( { flash } );
		window.location.hash = this.settingsGroup + ( route ? '/' + route : '' );
		window.scrollTo( 0, 0 );
	}

	/** Whether all three licensing settings are filled in and saved. */
	licensingSaved() {
		const keys = [ 'licensor_name', 'web_statement_of_rights', 'licensor_url' ];

		return keys.every( ( key ) => String( this.getSetting( key ) || '' ).trim() ) && ! keys.some( ( key ) => this.state.dirtyFields.includes( key ) );
	}

	render() {
		
		const saveError = this.getError( 'save' ) && (
			<Notice status="error" isDismissible={ false }>
				<p>{ __( 'The settings were not saved:' ) } { this.getError( 'save' ) }</p>
			</Notice>
		);
		
		// A page within the tab: adding or editing an image taxonomy.
		if ( ( this.props.route || '' ).startsWith( 'taxonomy/' ) ) {
			return (
				<Fragment>
					{ saveError }
					<PanelBody>
						<TaxonomySettings component={ this } route={ this.props.route } />
					</PanelBody>
				</Fragment>
			);
		}
		
		return (
			<Fragment>
			{ saveError }
			{ this.state.flash && (
				<Notice status={ this.state.flash.status } onRemove={ () => this.setState( { flash: null } ) } className="photopress-flash">
					{ this.state.flash.text }
				</Notice>
			) }
			<PanelBody title={ __( 'Image Taxonomies' ) }>
				<TaxonomySettings component={ this } />
			</PanelBody>
			
			<PanelBody title={ __( 'Alt Text' ) }>
				<SwitchRow
					label={ __( 'Alt text from metadata' ) }
					help={ __( 'Sets each image’s alt text from its metadata on upload, with the template below.' ) }
					checked={ this.getSetting( 'alt_text_enable' ) }
					onChange={ ( value ) => this.persistSetting( 'alt_text_enable', value ) }
				/>

				{ this.getSetting( 'alt_text_enable' ) && (
					<BaseControl>
						<TextControl
							id={ 'alt_text_template' }
							label={ __( 'Alt Text Template' ) }
							value={ this.getSetting( 'alt_text_template' ) }
							className="small-input right-pad"
							help={ __( 'Metadata fields go in square brackets, e.g. [photoshop:Headline].' ) }
							onChange={ ( value ) => this.setSetting( 'alt_text_template', value ) }
							onBlur={ ( event ) => this.setSetting( 'alt_text_template', sanitize( event.target.value, 'string' ) ) }
						/>

						<SaveBar
							saving={ this.state.isAPISaving }
							reprocess={ {
								label: __( 'Save and reprocess all images' ),
								missing: __( 'metadata for the template' ),
								terms: __( 'alt text' ),
								request: {},
							} }
							onSave={ ( reprocess ) => this.saveThen( 'metadata.alt_text', reprocess, __( 'Save, and set the alt text of every image from its metadata? Alt text written by hand is replaced.' ) ) }
						/>

						{ this.jobPanel( 'metadata.alt_text', __( 'Reprocessing alt text' ) ) }
					</BaseControl>
				) }
			</PanelBody>

			<PanelBody title={ __( 'Description' ) }>
				<SwitchRow
					label={ __( 'Description from metadata' ) }
					help={ __( 'Sets each image’s description from its metadata on upload and when its file is replaced, with the template below.' ) }
					checked={ this.getSetting( 'description_enable' ) }
					onChange={ ( value ) => this.persistSetting( 'description_enable', value ) }
				/>

				{ this.getSetting( 'description_enable' ) && (
					<BaseControl>
						<TextControl
							id={ 'description_template' }
							label={ __( 'Description Template' ) }
							value={ this.getSetting( 'description_template' ) || '' }
							className="small-input right-pad"
							help={ __( 'Metadata fields go in square brackets, e.g. [dc:description].' ) }
							onChange={ ( value ) => this.setSetting( 'description_template', value ) }
							onBlur={ ( event ) => this.setSetting( 'description_template', sanitize( event.target.value, 'string' ) ) }
						/>

						<SaveBar
							saving={ this.state.isAPISaving }
							reprocess={ {
								label: __( 'Save and reprocess all images' ),
								missing: __( 'metadata for the template' ),
								terms: __( 'description' ),
								request: {},
							} }
							onSave={ ( reprocess ) => this.saveThen( 'metadata.description', reprocess, __( 'Save, and set the description of every image from its metadata? Descriptions written by hand are replaced.' ) ) }
						/>

						{ this.jobPanel( 'metadata.description', __( 'Reprocessing descriptions' ) ) }
					</BaseControl>
				) }
			</PanelBody>

			<PanelBody title={ __( 'Licensing' ) }>
				<SwitchRow
					label={ __( 'Licensing metadata' ) }
					help={ __( 'Adds your licensing information to your images: written into each image’s file on upload, and as structured data (JSON-LD) on the pages that show them, which search engines read.' ) }
					checked={ this.getSetting( 'embed_licensor_enable' ) }
					onChange={ ( value ) => this.persistSetting( 'embed_licensor_enable', value ) }
				/>

				{ this.getSetting( 'embed_licensor_enable' ) && (
					<BaseControl>
						{ this.getError( 'licensor' ) && (
							<Notice status="error" isDismissible={ false }>
								<p><b>{ __( 'An error occurred:' ) }</b> <code>{ this.getError( 'licensor' ) }</code></p>
							</Notice>
						) }

						<TextControl
							id={ 'licensor_name' }
							label={ __( 'Licensor Name' ) }
							value={ this.getSetting( 'licensor_name' ) }
							className=" right-pad"
							help={ __( 'The name of the person or organization that licenses your images.' ) }
							onChange={ ( value ) => this.setSetting( 'licensor_name', value ) }
							onBlur={ ( event ) => this.setSetting( 'licensor_name', sanitize( event.target.value, 'string' ) ) }
						/>

						<TextControl
							id={ 'web_statement_of_rights' }
							label={ __( 'Web Statement of Rights URL' ) }
							value={ this.getSetting( 'web_statement_of_rights' ) }
							className=" right-pad"
							help={ __( 'Used by search engines to display a link to the license statement of your images.' ) }
							onChange={ ( value ) => this.setSetting( 'web_statement_of_rights', value.trim() ) }
						/>

						<TextControl
							id={ 'licensor_url' }
							label={ __( 'Licensing URL' ) }
							value={ this.getSetting( 'licensor_url' ) }
							className=" right-pad"
							help={ __( 'The URL where people can obtain a license for your images.' ) }
							onChange={ ( value ) => this.setSetting( 'licensor_url', value.trim() ) }
						/>

						<SaveBar
							saving={ this.state.isAPISaving }
							reprocess={ {
								label: __( 'Save and reprocess all images' ),
								request: {},
							} }
							onSave={ ( reprocess ) => this.saveThen( 'metadata.license', reprocess, __( 'Save, and write the licensing information into the files of every image? Each image’s files are rewritten, and copied again wherever they are stored.' ), this.saveLicensing ) }
						/>
						<p className="description">{ __( 'Reprocessing writes the licensing information into the files of every image already uploaded: the original and each size, without re-encoding them. Images whose files are stored elsewhere, as with WP Offload Media, are uploaded there again. It runs in the background, pausing while the server is busy.' ) }</p>

						{ ! this.licensingSaved() && (
							<Notice status="warning" isDismissible={ false } className="photopress-licensing-incomplete">
								{ __( 'Licensing is not applied until all three settings are filled in and saved: nothing is written into files and no structured data is added to pages.' ) }
							</Notice>
						) }

						{ this.jobPanel( 'metadata.license', __( 'Reprocessing licensing metadata' ) ) }
					</BaseControl>
				) }
			</PanelBody>

			</Fragment>
		
		);
	}
}	

export default MetadataSettings;
