/**
 * The Image Sizes settings tab: the quality WordPress saves JPEG and WebP
 * sizes at, which registered sizes it makes, and making every image's sizes
 * again after either changes (modules/images/images.php).
 */
const { __, sprintf } = wp.i18n;
import { Component, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { BaseControl, Button, Notice, PanelBody, RangeControl, ToggleControl } from '@wordpress/components';

import {
	setSetting,
	getSetting,
	deleteSetting,
	saveSettings,
	getError,
	setError,
	persistSetting,
} from '../shared/options.js';
import JobPanel from '../shared/jobs.js';

const DEFAULT_QUALITY = 92;

const listOf = ( value ) => String( value || '' ).split( ',' ).map( ( s ) => s.trim() ).filter( Boolean );

const dimensions = ( size ) => {
	const side = ( n ) => ( n ? n + 'px' : __( 'any' ) );
	return sprintf( '%1$s × %2$s%3$s', side( size.width ), side( size.height ), size.crop ? ' · ' + __( 'cropped' ) : '' );
};

/**
 * Every registered size, with a switch to turn it on or off.
 */
function SizeList( { sizes, disabled, onChange } ) {
	return (
		<table className="widefat striped photopress-image-sizes">
			<thead>
				<tr>
					<th>{ __( 'Size' ) }</th>
					<th>{ __( 'Largest dimensions' ) }</th>
					<th>{ __( 'On' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ sizes.map( ( size ) => (
					<tr key={ size.name } data-size={ size.name }>
						<td>
							<code>{ size.name }</code>
							{ ! size.core && <span className="photopress-image-sizes__source"> { __( '(theme or plugin)' ) }</span> }
						</td>
						<td>{ dimensions( size ) }</td>
						<td>
							<ToggleControl
								__nextHasNoMarginBottom
								label={ size.name }
								hideLabelFromVision
								checked={ ! disabled.includes( size.name ) }
								onChange={ ( on ) => onChange( on ? disabled.filter( ( n ) => n !== size.name ) : [ ...disabled, size.name ] ) }
							/>
						</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

/**
 * The sizes, and how many images were made with other settings.
 */
function useStatus() {
	const [ status, setStatus ] = useState( null );
	const [ error, setErr ] = useState( null );
	const load = () => apiFetch( { path: '/photopress/v1/image-sizes' } ).then( setStatus ).catch( ( e ) => setErr( e.message ) );

	useEffect( () => {
		load();
	}, [] );

	return { status, error, reload: load };
}

function ImagesPanel( { component } ) {
	const { status, error, reload } = useStatus();
	const disabled = listOf( component.getSetting( 'disabled_sizes' ) );
	const quality = Number( component.getSetting( 'quality' ) ?? DEFAULT_QUALITY );

	// The count of images to regenerate depends on what was just saved.
	const save = () => Promise.resolve( component.saveSettings() ).then( reload );

	return (
		<BaseControl id="photopress_images" label="" help="">
			{ component.getError( 'save' ) && (
				<Notice status="error" isDismissible={ false }>
					{ component.getError( 'save' ) }
				</Notice>
			) }
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			<div className="photopress-images-settings">
				<h3>{ __( 'Quality' ) }</h3>
				<RangeControl
					__nextHasNoMarginBottom
					label={ __( 'JPEG and WebP quality' ) }
					help={ __( "Out of 100. WordPress's own is 82, which can show on detailed photographs; at 92 the difference from the original is not visible, at well under half the file size of 100. Applies to sizes made from now on." ) }
					value={ quality }
					min={ 50 }
					max={ 100 }
					onChange={ ( value ) => component.setSetting( 'quality', value ) }
				/>

				<h3>{ __( 'Sizes' ) }</h3>
				<p>{ __( 'Every image size registered by WordPress, the theme and plugins. WordPress makes each one for every image uploaded; turn off the ones nothing uses. A size turned off is not made from now on; the files already made stay until the images are regenerated.' ) }</p>
				{ status ? (
					<SizeList sizes={ status.sizes } disabled={ disabled } onChange={ ( list ) => component.setSetting( 'disabled_sizes', list.join( ',' ) ) } />
				) : (
					<p>{ __( 'Loading…' ) }</p>
				) }

				<p>
					<Button variant="primary" disabled={ component.state.isAPISaving } onClick={ save }>
						{ __( 'Save' ) }
					</Button>
				</p>
			</div>

			<hr />

			{ status && (
				<p className="photopress-images-outdated" data-outdated={ status.outdated }>
					{ status.outdated > 0
						? sprintf( __( '%1$d of %2$d images were made with other sizes or quality.' ), status.outdated, status.images )
						: __( 'Every image was made with these sizes and quality.' ) }
				</p>
			) }

			<JobPanel
				type="images.regenerate"
				label={ __( 'Regenerate image sizes' ) }
				description={ __( "Makes each image's sizes again from its original upload, with the sizes and quality above; images already made with them are skipped. It runs in the background, a few images at a time, resting between batches and waiting while the server is busy, so the site stays responsive; you can leave this page. Sizes turned off are dropped from each image's list; their files stay." ) }
				confirm={ __( "Make every image's sizes again? This takes a while for a large media library, and replaces the existing size files." ) }
				onChange={ ( job ) => job.status === 'done' && reload() }
			/>
		</BaseControl>
	);
}

class ImagesSettings extends Component {
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
				quality: DEFAULT_QUALITY,
				disabled_sizes: '',
				...this.props.data,
			},
			dirtyFields: [],
		};

		this.settingsSchema = {};
	}

	render() {
		return (
			<PanelBody>
				<ImagesPanel component={ this } />
			</PanelBody>
		);
	}
}

export default ImagesSettings;
