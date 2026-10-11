/**
 * The Image Sizes settings tab: the quality WordPress saves JPEG and WebP
 * sizes at, the size large uploads are scaled down to, which registered
 * sizes it makes, and regenerating images after any of them changes
 * (modules/images/images.php).
 */
const { __, _n, sprintf } = wp.i18n;
import { Component, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { BaseControl, FormToggle, Notice, PanelBody, RangeControl, TextControl } from '@wordpress/components';

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
import SaveBar from '../shared/save-bar.js';
import SwitchRow from '../shared/switch-row.js';

const DEFAULT_QUALITY = 92;
const DEFAULT_THRESHOLD = 2560;

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
							<FormToggle
								aria-label={ size.name }
								checked={ ! disabled.includes( size.name ) }
								onChange={ ( event ) => onChange( event.target.checked ? disabled.filter( ( n ) => n !== size.name ) : [ ...disabled, size.name ] ) }
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

/**
 * How many images the settings on screen would have regenerated: those not
 * made with them. Null while they are counted.
 */
function useAffected( settings, refresh ) {
	const [ affected, setAffected ] = useState( null );
	const key = JSON.stringify( settings ) + refresh;

	useEffect( () => {
		// An answer for settings since changed is dropped.
		let current = true;
		setAffected( null );
		const timer = setTimeout( () => {
			apiFetch( { path: '/photopress/v1/image-sizes/scope', method: 'POST', data: { settings } } )
				.then( ( answer ) => current && setAffected( answer.affected ) )
				.catch( () => current && setAffected( 0 ) );
		}, 400 );
		return () => {
			current = false;
			clearTimeout( timer );
		};
	}, [ key ] ); // eslint-disable-line react-hooks/exhaustive-deps

	return affected;
}

function ImagesPanel( { component } ) {
	const { status, error, reload } = useStatus();
	const [ saves, setSaves ] = useState( 0 );
	const [ jobError, setJobError ] = useState( null );
	const disabled = listOf( component.getSetting( 'disabled_sizes' ) );
	const quality = Number( component.getSetting( 'quality' ) ?? DEFAULT_QUALITY );
	const scale = component.getSetting( 'scale_large_uploads' ) ?? true;
	// The largest size turned on: large uploads are scaled to no less.
	const largest = ( status ? status.sizes : [] )
		.filter( ( size ) => ! disabled.includes( size.name ) )
		.reduce( ( most, size ) => ( Math.max( size.width, size.height ) > ( most ? Math.max( most.width, most.height ) : 0 ) ? size : most ), null );
	const minimum = largest ? Math.max( largest.width, largest.height ) : 1;
	const longest = Number( component.getSetting( 'big_image_threshold' ) ?? DEFAULT_THRESHOLD );
	// What is typed; the setting only takes a whole number of pixels.
	const [ longestText, setLongestText ] = useState( null );
	const typed = null === longestText || /^[1-9]\d*$/.test( longestText );
	const longestValid = typed && longest >= minimum;
	const affected = useAffected( {
		quality,
		disabled_sizes: disabled.join( ',' ),
		scale_large_uploads: !! scale,
		big_image_threshold: longest,
	}, saves );

	const saved = component.savedSettings || component.state.settings;
	const changed = quality !== Number( saved.quality ?? DEFAULT_QUALITY ) ||
		disabled.join( ',' ) !== listOf( saved.disabled_sizes ).join( ',' ) ||
		!! scale !== !! ( saved.scale_large_uploads ?? true ) ||
		longest !== Number( saved.big_image_threshold ?? DEFAULT_THRESHOLD );

	// Offload Media removes the originals from the server: nothing to make
	// sizes from.
	const blocked = status && status.localFilesRemoved;

	const reprocess = ! blocked && affected > 0 && {
		label: sprintf( _n( 'Save and regenerate %s affected image', 'Save and regenerate %s affected images', affected ), affected.toLocaleString() ),
		request: {},
	};

	// Saves, then, for Save and regenerate, confirmed and saved, makes the
	// sizes of the images not made with the settings saved.
	const save = ( regenerate ) => {
		// eslint-disable-next-line no-alert
		if ( regenerate && ! window.confirm( sprintf( _n( 'Save, and regenerate the sizes of %s image? Where every size is made again, the existing size files are replaced.', 'Save, and regenerate the sizes of %s images? Where every size is made again, the existing size files are replaced.', affected ), affected.toLocaleString() ) ) ) {
			return;
		}

		Promise.resolve( changed ? component.saveSettings() : null ).then( () => {
			if ( component.getError( 'save' ) ) {
				return;
			}
			setJobError( null );
			const started = regenerate
				? apiFetch( { path: '/photopress/v1/jobs', method: 'POST', data: { type: 'images.regenerate', args: {} } } )
					.catch( ( e ) => setJobError( 'photopress_job_running' === e.code
						? __( 'Saved. Regenerating was already running: the images it has still to do get the new settings; once it finishes, Save and regenerate again for the rest.' )
						: e.message || __( 'Saved, but the images could not be regenerated.' ) ) )
				: null;
			Promise.resolve( started ).then( () => {
				setSaves( ( n ) => n + 1 );
				reload();
			} );
		} );
	};

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
					help={ __( "Out of 100. WordPress's own is 82, which can show on detailed photographs; at 92 the difference from the original is not visible, at well under half the file size of 100." ) }
					value={ quality }
					min={ 50 }
					max={ 100 }
					onChange={ ( value ) => component.setSetting( 'quality', value ) }
				/>

				<h3>{ __( 'Full-size image' ) }</h3>
				<SwitchRow
					label={ __( 'Scale down large uploads' ) }
					help={ scale
						? __( 'An upload longer than this on a side gets a copy scaled down to it, used wherever the full-size image is: linked as the media file, shown by themes and blocks asking for full size, and the largest offered to large screens. The original is kept, and the sizes are made from it.' )
						: __( 'The full-size image is the upload as it is, however large.' ) }
					checked={ scale }
					onChange={ ( on ) => component.setSetting( 'scale_large_uploads', on ) }
				/>
				{ scale && (
					<TextControl
						__nextHasNoMarginBottom
						type="number"
						min={ minimum }
						className="photopress-images-threshold"
						label={ __( 'Longest side of the full-size image, in pixels' ) }
						help={ __( "WordPress's own is 2560. Larger suits big, high-density screens, at larger files." ) }
						value={ longestText ?? String( longest ) }
						onChange={ ( value ) => {
							setLongestText( value );
							if ( /^[1-9]\d*$/.test( value ) ) {
								component.setSetting( 'big_image_threshold', Number( value ) );
							}
						} }
					/>
				) }
				{ scale && ! longestValid && (
					<Notice status="error" isDismissible={ false } className="photopress-images-threshold-error">
						{ typed
							? sprintf( __( 'At least %1$s px: the largest size turned on, %2$s, is that long, and sizes are made from the original. Turn off the larger sizes below for less.' ), minimum.toLocaleString(), largest.name )
							: __( 'Enter the longest side as a whole number of pixels.' ) }
					</Notice>
				) }

				<h3>{ __( 'Sizes' ) }</h3>
				<p>{ __( 'Every image size registered by WordPress, the theme and plugins. WordPress makes each one for every image uploaded; turn off the ones nothing uses. Turning a size off applies to images uploaded from now on: those already uploaded keep theirs. A size turned on can be made for them too, with Save and regenerate.' ) }</p>
				{ status ? (
					<SizeList sizes={ status.sizes } disabled={ disabled } onChange={ ( list ) => component.setSetting( 'disabled_sizes', list.join( ',' ) ) } />
				) : (
					<p>{ __( 'Loading…' ) }</p>
				) }

				{ status && ! changed && ! blocked && (
					<p className="photopress-images-outdated" data-outdated={ status.outdated }>
						{ status.outdated > 0
							? sprintf( __( '%1$s of %2$s images lack sizes turned on here, or were made at another quality or scaled otherwise.' ), status.outdated.toLocaleString(), status.images.toLocaleString() )
							: __( 'Every image has the sizes turned on here, at this quality and scale.' ) }
					</p>
				) }

				{ blocked && (
					<Notice status="warning" isDismissible={ false } className="photopress-images-blocked">
						{ __( 'WP Offload Media is set to remove files from this server, so images already uploaded can’t be regenerated: making sizes needs each image’s original here. Images uploaded from now on get the sizes and quality set here.' ) }
					</Notice>
				) }

				<SaveBar saving={ component.state.isAPISaving || ( scale && ! longestValid ) } reprocess={ reprocess } onSave={ save } />

				{ ! blocked && (
					<p className="photopress-images-regenerate-note">
						{ __( 'Regenerating does only what each image needs: a size turned on is made alone; a new quality, or a new longest side for an image it scales, makes every size again from the original upload. It runs in the background, a few images at a time, waiting while the server is busy; you can leave this page.' ) }
					</p>
				) }

				{ jobError && <Notice status="error" isDismissible={ false }>{ jobError }</Notice> }
				<JobPanel
					type="images.regenerate"
					label={ __( 'Regenerating image sizes' ) }
					startable={ false }
					refresh={ saves }
					onChange={ ( job ) => job.status === 'done' && reload() }
				/>
			</div>
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
				scale_large_uploads: true,
				big_image_threshold: DEFAULT_THRESHOLD,
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
