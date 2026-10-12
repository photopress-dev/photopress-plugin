/**
 * The Pages & Search settings tab: whether images and image taxonomy terms
 * have pages of their own on the site, and PhotoPress's templates for them
 * in block themes (modules/search). Searching images is to come here too.
 */
const { __ } = wp.i18n;
import { Component, Fragment } from '@wordpress/element';
import { Notice } from '@wordpress/components';

import {
	setSetting,
	getSetting,
	deleteSetting,
	saveSettings,
	getError,
	setError,
	persistSetting,
} from '../shared/options.js';
import FeatureSection from '../shared/feature-section.js';
import SwitchRow from '../shared/switch-row.js';

class SearchSettings extends Component {
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
				attachment_pages: true,
				image_page_template: true,
				taxonomy_pages: true,
				archive_template: true,
				...this.props.data,
			},
			dirtyFields: [],
		};

		this.settingsSchema = {};
	}

	// The settings page keeps them for the other tabs: the Metadata tab shows
	// no archive pages while they are off.
	componentDidUpdate( prevProps, prevState ) {
		if ( this.props.onChange && prevState.settings !== this.state.settings ) {
			this.props.onChange( this.state.settings );
		}
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
					title={ __( 'Attachment pages' ) }
					description={ __( 'Give each image a page of its own, with its caption, description, terms and exposure details.' ) }
					info={ [
						<p key="0">{ __( 'Turned off, an image’s page address goes to the image file, and links to its page, such as from a gallery, go to the file. This is WordPress’s own Attachment pages setting.' ) }</p>,
						<p key="1">{ __( 'Fewer pages also means fewer for search engines and other crawlers to visit.' ) }</p>,
					] }
					checked={ this.getSetting( 'attachment_pages' ) }
					onChange={ ( value ) => this.persistSetting( 'attachment_pages', value ) }
				>
					<SwitchRow
						label={ __( 'Image Pages template' ) }
						help={ __( 'In a block theme without its own, PhotoPress’s template: the image, its caption and description, its terms and its exposure details. It can be edited in the Site Editor.' ) }
						checked={ this.getSetting( 'image_page_template' ) }
						onChange={ ( value ) => this.persistSetting( 'image_page_template', value ) }
					/>
				</FeatureSection>

				<FeatureSection
					title={ __( 'Image taxonomy pages' ) }
					description={ __( 'Give each term of an image taxonomy, such as a keyword or a camera, an archive page of its images.' ) }
					info={ [
						<p key="0">{ __( 'Turned off, no image taxonomy has archive pages, and the Image Metadata block shows terms without links. The terms are kept, in the Media Library and under Media.' ) }</p>,
						<p key="1">{ __( 'To keep a single taxonomy off the site, turn off its Public switch in the Metadata tab instead.' ) }</p>,
					] }
					checked={ this.getSetting( 'taxonomy_pages' ) }
					onChange={ ( value ) => this.persistSetting( 'taxonomy_pages', value ) }
				>
					<SwitchRow
						label={ __( 'Image Archives template' ) }
						help={ __( 'In a block theme without its own, PhotoPress’s template: the archive’s images in a Taxonomy Gallery, Masonry to start. It can be edited in the Site Editor.' ) }
						checked={ this.getSetting( 'archive_template' ) }
						onChange={ ( value ) => this.persistSetting( 'archive_template', value ) }
					/>
				</FeatureSection>
			</Fragment>
		);
	}
}

export default SearchSettings;
