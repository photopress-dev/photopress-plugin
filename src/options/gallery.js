/**
 * The Gallery settings tab: which PhotoPress layouts the Gallery block
 * offers, and taking deleted images out of galleries (modules/gallery).
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

const KEPT = __( 'Turned off, it is no longer offered for new galleries; galleries that already use it keep it.' );

const LAYOUTS = [
	{
		key: 'layout_masonry',
		title: __( 'Masonry layout' ),
		description: __( 'Offer the Masonry layout for the Gallery block: columns of a width you set, each image at its own height.' ),
		info: __( 'Each image goes at the foot of the shortest column, so photos of different shapes fit together without cropping.' ),
	},
	{
		key: 'layout_rows',
		title: __( 'Rows layout' ),
		description: __( 'Offer the Rows layout for the Gallery block: rows of a height you set, each image at its own width.' ),
		info: __( 'Images are shown at the height you set and their own widths, and wrap to a new row when one doesn’t fit, so rows can end short of the gallery’s edge.' ),
	},
	{
		key: 'layout_mosaic',
		title: __( 'Mosaic layout' ),
		description: __( 'Offer the Mosaic layout for the Gallery block: rows that fill the gallery’s width, each image at its own proportions.' ),
		info: __( 'Each row’s height is adjusted from the one you set so its images fill the width exactly, without cropping. A last row that isn’t full stays at the height you set.' ),
	},
];

class GallerySettings extends Component {
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
				layout_masonry: true,
				layout_rows: true,
				layout_mosaic: true,
				remove_deleted_images: true,
				...this.props.data,
			},
			dirtyFields: [],
		};

		this.settingsSchema = {};
	}

	render() {
		return (
			<Fragment>
				{ this.getError( 'save' ) && (
					<Notice status="error" isDismissible={ false }>
						<p>{ __( 'The settings were not saved:' ) } { this.getError( 'save' ) }</p>
					</Notice>
				) }

				{ LAYOUTS.map( ( layout ) => (
					<FeatureSection
						key={ layout.key }
						title={ layout.title }
						description={ layout.description }
						info={ [ <p key="0">{ layout.info }</p>, <p key="1">{ KEPT }</p> ] }
						checked={ this.getSetting( layout.key ) }
						onChange={ ( value ) => this.persistSetting( layout.key, value ) }
					/>
				) ) }

				<FeatureSection
					title={ __( 'Remove deleted images' ) }
					description={ __( 'Remove images from galleries that are deleted from the Media Library.' ) }
					info={ [
						<p key="0">{ __( 'The image is removed from each post’s Gallery blocks in an edit of the post, saved as a revision. A gallery left with no images stays, empty.' ) }</p>,
						<p key="1">{ __( 'Galleries that show the images attached to a post lose the image with it either way. Galleries made before WordPress 5.9, not yet updated in the editor, are left as they are.' ) }</p>,
					] }
					checked={ this.getSetting( 'remove_deleted_images' ) }
					onChange={ ( value ) => this.persistSetting( 'remove_deleted_images', value ) }
				/>
			</Fragment>
		);
	}
}

export default GallerySettings;
