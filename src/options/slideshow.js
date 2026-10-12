/**
 * WordPress dependencies
 */
const { __ } = wp.i18n;
import { Component, Fragment, useState } from '@wordpress/element';


import {
	BaseControl,
	Button,
	ExternalLink,
	Panel,
	PanelBody,
	PanelRow,
	Placeholder,
	Spinner,
	Notice,
	Disabled,
	SelectControl,
	TextControl,
	RangeControl
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
import SwitchRow from '../shared/switch-row.js';
import FeatureSection from '../shared/feature-section.js';
/**
 * Metadata Options Component class
 */
class SlideshowSettings extends Component {
	
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
				enable: true,
				showThumbnails: true,
				showCaptions: true,
				detail_position: 'bottom',
				thumbnailHeight: 120,
				showTitleInCaption: false,
				showDescriptionInCaption: false,
				showAttachmentLink: false,
				attachmentLinkText: "Read Me...",
				captionPadding: 0
			},
			
			dirtyFields: []
		};
		
		// override settigns with data passed in from the settings API
		this.state.settings = { ...this.state.settings, ...this.props.data };
		
		this.settingsSchema = {
			
			attachmentLinkText: {
				
				type: 'string',
				validations: [
					
					{ type: 'notEmpty', errorSection: 'slideshow', errorMsg: 'Cannot be empty.'}
				]
			}
			
		}
			
	}
	
	componentDidMount() {
		
		
	}
			
	render() {
		
		return (
			<Fragment>
				{ this.getError( 'save' ) && (
					<Notice status="error" isDismissible={ false }>
						<p>{ __( 'The settings were not saved:' ) } { this.getError( 'save' ) }</p>
					</Notice>
				) }

				<FeatureSection
					title={ __( 'Slideshows' ) }
					description={ __( 'Open a full-screen slideshow when an image in a gallery is clicked.' ) }
					info={ [
						<p key="0">{ __( 'The slideshow shows the gallery’s images one at a time, filling the window, with optional thumbnails and a box for each image’s caption and details.' ) }</p>,
						<p key="1">{ __( 'Galleries made with the Gallery block and PhotoPress’s own gallery blocks open it.' ) }</p>,
					] }
					checked={ this.getSetting( 'enable' ) }
					onChange={ ( value ) => this.persistSetting( 'enable', value ) }
				>
					{ this.getError( 'slideshow' ) && (
						<Notice status="error">
							<p>{ __( 'An error occurred:' ) } <code>{ this.getError( 'slideshow' ) }</code></p>
						</Notice>
					) }

					<SwitchRow
						label={ __( 'Thumbnails' ) }
						help={ __( 'A row of thumbnails along the bottom of the slideshow.' ) }
						checked={ this.getSetting( 'showThumbnails' ) }
						onChange={ ( value ) => this.persistSetting( 'showThumbnails', value ) }
					/>
					{ this.getSetting( 'showThumbnails' ) && (
						<div className="photopress-subsettings">
							<RangeControl
								label={ __( 'Thumbnail Height' ) }
								value={ this.getSetting( 'thumbnailHeight' ) }
								onChange={ ( value ) => this.persistSetting( 'thumbnailHeight', value ) }
								min={ 75 }
								max={ 200 }
								step={ 10 }
							/>
						</div>
					) }

					<hr />
					<SwitchRow
						label={ __( 'Caption info' ) }
						help={ __( 'A box with the image’s caption and details, below or beside it.' ) }
						checked={ this.getSetting( 'showCaptions' ) }
						onChange={ ( value ) => this.persistSetting( 'showCaptions', value ) }
					/>
					{ this.getSetting( 'showCaptions' ) && (
						<div className="photopress-subsettings">
							<SelectControl
								label={ __( 'Caption Info Box Position' ) }
								value={ this.getSetting( 'detail_position' ) }
								onChange={ ( value ) => this.persistSetting( 'detail_position', value ) }
								options={ [
									{ value: 'bottom', label: __( 'Bottom of image' ) },
									{ value: 'right', label: __( 'Right of image' ) },
								] }
							/>

							<RangeControl
								label={ __( 'Caption Padding (px)' ) }
								help={ __( 'Space around the caption area.' ) }
								value={ this.getSetting( 'captionPadding' ) || 0 }
								onChange={ ( value ) => this.persistSetting( 'captionPadding', value ?? 0 ) }
								min={ 0 }
								max={ 100 }
								step={ 1 }
							/>

							<SwitchRow
								label={ __( 'Title' ) }
								help={ __( 'The image’s title in the caption info.' ) }
								checked={ this.getSetting( 'showTitleInCaption' ) }
								onChange={ ( value ) => this.persistSetting( 'showTitleInCaption', value ) }
							/>
							<SwitchRow
								label={ __( 'Description' ) }
								help={ __( 'The image’s description in the caption info.' ) }
								checked={ this.getSetting( 'showDescriptionInCaption' ) }
								onChange={ ( value ) => this.persistSetting( 'showDescriptionInCaption', value ) }
							/>
							<SwitchRow
								label={ __( 'Link to the image’s page' ) }
								help={ __( 'A link to the image’s attachment page in the caption info.' ) }
								checked={ this.getSetting( 'showAttachmentLink' ) }
								onChange={ ( value ) => this.persistSetting( 'showAttachmentLink', value ) }
							/>
							{ this.getSetting( 'showAttachmentLink' ) && (
								<div className="photopress-subsettings">
									<TextControl
										id={ 'attachment_link_text' }
										label={ __( 'Attachment Link Text' ) }
										value={ this.getSetting( 'attachmentLinkText' ) }
										className=" right-pad"
										help={ __( 'The text of the link.' ) }
										onChange={ ( value ) => this.setSetting( 'attachmentLinkText', value ) }
										onBlur={ ( event ) => this.setSetting( 'attachmentLinkText', sanitize( event.target.value, 'string' ) ) }
									/>

									<Button
										isPrimary
										disabled={ this.state.isAPISaving }
										onClick={ this.saveSettings }
										className="components-base-control__field"
									>
										{ __( 'Save' ) }
									</Button>
								</div>
							) }
						</div>
					) }
				</FeatureSection>
			</Fragment>
		);
	}
}	

export default SlideshowSettings;
