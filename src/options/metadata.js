/**
 * WordPress dependencies
 */
const { __, sprintf } = wp.i18n;
import { Component, Fragment } from '@wordpress/element';

import {
	BaseControl,
	Button,
	CheckboxControl,
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

import TaxonomySettings, { ArchivePages } from './taxonomies.js';
import FeatureSection from '../shared/feature-section.js';
import JobPanel from '../shared/jobs.js';
import SaveBar from '../shared/save-bar.js';
import TemplateEditor from '../shared/template-editor.js';
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
		this.jobStarted = this.jobStarted.bind( this );
		
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
				alt_text_from_file: true,
				alt_text_template: '[photoshop:Headline]. [photopress:stringOfKeywords].',
				description_template: '',
				strip_metadata_from_resized_image: false
			},
			dirtyFields: [],
			// Jobs started here, so the job panels ask for the latest again.
			jobs: 0,
			// The job type a Save and reprocess is starting, and the jobs
			// started, by type, as the server returned them.
			starting: null,
			started: {},
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

		// The job's panel shows at once, starting, while it saves.
		if ( reprocess ) {
			this.setState( { starting: type } );
		}

		const saved = save();

		if ( false === saved ) {
			this.setState( { starting: null } );
			return;
		}
		if ( ! reprocess ) {
			return;
		}

		Promise.resolve( saved ).then( () => {
			if ( this.getError( 'save' ) ) {
				this.setState( { starting: null } );
				return;
			}
			this.setError( type, null );
			apiFetch( { path: '/photopress/v1/jobs', method: 'POST', data: { type, args: { force: ! reprocess.skip } } } )
				.then( ( job ) => this.jobStarted( type, job ) )
				.catch( ( e ) => {
					this.setState( { starting: null } );
					this.setError( type, e.message || __( 'Saved, but the images could not be reprocessed.' ) );
				} );
		} );
	}

	/** A job of this type started: its panel shows it, no longer starting. */
	jobStarted( type, job ) {
		this.setState( ( state ) => ( {
			jobs: state.jobs + 1,
			starting: null,
			started: job ? { ...state.started, [ type ]: job } : state.started,
		} ) );
	}

	/** A section's reprocess job: an error starting it, and its progress. */
	jobPanel( type, label ) {
		return (
			<Fragment>
				{ this.getError( type ) && <Notice status="error" isDismissible={ false }>{ this.getError( type ) }</Notice> }
				<JobPanel type={ type } label={ label } startable={ false } starting={ type === this.state.starting } started={ this.state.started[ type ] } refresh={ this.state.jobs } />
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
						<ArchivePages.Provider value={ false !== this.props.archivePages }><TaxonomySettings component={ this } route={ this.props.route } /></ArchivePages.Provider>
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
			<FeatureSection
				title={ __( 'Image Taxonomies' ) }
				description={ __( 'Let visitors browse your photos by camera, place, person and more.' ) }
				info={ [
					<p key="0">{ __( 'Each image taxonomy has its own archive pages, and PhotoPress fills them from the metadata embedded in each photo when it is uploaded.' ) }</p>,
					<p key="1">{ __( 'Standard Metadata is built in. Map the keyword hierarchies and other metadata fields you use to taxonomies of their own below.' ) }</p>,
				] }
				checked={ this.getSetting( 'custom_taxonomies_enable' ) }
				onChange={ ( value ) => this.persistSetting( 'custom_taxonomies_enable', value ) }
				className="photopress-feature--taxonomies"
			>
				<ArchivePages.Provider value={ false !== this.props.archivePages }><TaxonomySettings component={ this } /></ArchivePages.Provider>
			</FeatureSection>
			
			<FeatureSection
				title={ __( 'Alt Text' ) }
				description={ __( 'Set each image’s alt text from its metadata when it is uploaded.' ) }
				info={ [
					<p key="0">{ __( 'The alt text written in the image’s file is used when it has one: the Alt Text (Accessibility) field that Lightroom Classic and Photoshop write. Otherwise the template’s fields, in square brackets, are filled from the file’s metadata, e.g. [photoshop:Headline]; with none of them, its description or title.' ) }</p>,
					<p key="1">{ __( 'Save and reprocess all images applies a new template to the images already uploaded.' ) }</p>,
				] }
				checked={ this.getSetting( 'alt_text_enable' ) }
				onChange={ ( value ) => this.persistSetting( 'alt_text_enable', value ) }
			>
				<BaseControl>
					<CheckboxControl
						__nextHasNoMarginBottom
						label={ __( 'Use the alt text written in the file, when it has one' ) }
						help={ __( 'Lightroom Classic and Photoshop write it as Alt Text (Accessibility). It takes the place of the template.' ) }
						checked={ !! this.getSetting( 'alt_text_from_file' ) }
						onChange={ ( value ) => this.setSetting( 'alt_text_from_file', value ) }
					/>

					<TemplateEditor
						id="alt_text_template"
						label={ __( 'Alt Text Template' ) }
						value={ this.getSetting( 'alt_text_template' ) || '' }
						help={ this.getSetting( 'alt_text_from_file' )
							? __( 'For images whose files have no alt text of their own. Type the words around the fields; a field an image doesn’t have is left out.' )
							: __( 'Type the words around the fields; a field an image doesn’t have is left out.' ) }
						onChange={ ( value ) => this.setSetting( 'alt_text_template', value ) }
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
			</FeatureSection>

			<FeatureSection
				title={ __( 'Description' ) }
				description={ __( 'Set each image’s description from its metadata when it is uploaded or its file is replaced.' ) }
				info={ [
					<p key="0">{ __( 'The template’s fields, in square brackets, are filled from the metadata embedded in the image’s file, e.g. [dc:description].' ) }</p>,
					<p key="1">{ __( 'Save and reprocess all images applies a new template to the images already uploaded.' ) }</p>,
				] }
				checked={ this.getSetting( 'description_enable' ) }
				onChange={ ( value ) => this.persistSetting( 'description_enable', value ) }
			>
				<BaseControl>
					<TemplateEditor
						id="description_template"
						label={ __( 'Description Template' ) }
						value={ this.getSetting( 'description_template' ) || '' }
						help={ __( 'Type the words around the fields; a field an image doesn’t have is left out.' ) }
						onChange={ ( value ) => this.setSetting( 'description_template', value ) }
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
			</FeatureSection>

			<FeatureSection
				title={ __( 'Licensing' ) }
				description={ __( 'Add your licensing information to your images and the pages that show them.' ) }
				info={ [
					<p key="0">{ __( 'The Web Statement of Rights and the licensor are written into each image file’s metadata on upload, where search engines such as Google Images read them, and added as structured data (JSON-LD) with each image on the pages that show it, with any theme.' ) }</p>,
					<p key="1">{ __( 'All three settings are needed. Save and reprocess all images writes them into the files of the images already uploaded.' ) }</p>,
				] }
				checked={ this.getSetting( 'embed_licensor_enable' ) }
				onChange={ ( value ) => this.persistSetting( 'embed_licensor_enable', value ) }
			>
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
			</FeatureSection>

			</Fragment>
		
		);
	}
}	

export default MetadataSettings;
