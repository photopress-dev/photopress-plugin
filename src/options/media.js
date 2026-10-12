/**
 * The Offload Media settings tab: where WP Offload Media stores and serves
 * images, their cache lifetime, and clearing replaced images from CloudFront.
 */
const { __, _n, sprintf } = wp.i18n;
import SwitchRow from '../shared/switch-row.js';
import PluginState from '../shared/plugin-state.js';
import { Component, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { BaseControl, Button, Notice, PanelBody, SelectControl, TextControl } from '@wordpress/components';

import {
	setSetting,
	getSetting,
	deleteSetting,
	saveSettings,
	getError,
	setError,
	persistSetting,
	sanitize,
} from '../shared/options.js';

const CREDENTIAL_SOURCES = {
	photopress: __( 'PHOTOPRESS_AWS_* constants in wp-config.php' ),
	'instance-role': __( 'The server’s IAM role' ),
	'offload-media': __( "WP Offload Media's credentials" ),
};

const when = ( time ) => new Date( time * 1000 ).toLocaleString();

const Row = ( { label, children } ) => (
	<tr>
		<th scope="row">{ label }</th>
		<td>{ children }</td>
	</tr>
);

/**
 * Storage, delivery and invalidation, as WP Offload Media and PhotoPress see
 * them, with the invalidate buttons.
 */
function OffloadStatus() {
	const [ status, setStatus ] = useState( null );
	const [ error, setErr ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const call = ( request ) => {
		setBusy( true );
		setErr( null );
		return apiFetch( request )
			.then( setStatus )
			.catch( ( e ) => setErr( e.message ) )
			.finally( () => setBusy( false ) );
	};

	useEffect( () => {
		call( { path: '/photopress/v1/cdn' } );
	}, [] );

	const invalidate = ( scope ) => {
		if (
			'all' === scope &&
			// eslint-disable-next-line no-alert
			! window.confirm( __( 'Clear every image from the CDN? Each is fetched from cloud storage again the next time it’s viewed.' ) )
		) {
			return;
		}
		call( { path: '/photopress/v1/cdn/invalidate', method: 'POST', data: { scope } } );
	};

	if ( ! status ) {
		return error ? <Notice status="error" isDismissible={ false }>{ error }</Notice> : <p>{ __( 'Checking…' ) }</p>;
	}

	const state = <PluginState name={ __( 'WP Offload Media' ) } state={ status.plugin } />;

	if ( 'missing' === status.plugin ) {
		return (
			<div className="photopress-offload-status" data-state="missing">
				{ state }
				<p>
					{ __( 'WP Offload Media stores images in cloud storage, such as Amazon S3, and serves them from a CDN. Once it’s active, this page shows where, sets how long images are cached, and clears replaced ones from CloudFront.' ) }
				</p>
			</div>
		);
	}

	if ( 'inactive' === status.plugin ) {
		return (
			<div className="photopress-offload-status" data-state="inactive">
				{ state }
				<p>{ __( 'Activate it to store images in cloud storage.' ) }</p>
			</div>
		);
	}

	const storage = status.storage || {};
	const last = status.last;
	const canInvalidate = !! status.distribution && !! status.credentials;

	return (
		<div className="photopress-offload-status" data-state="active">
			{ state }
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }

			<h3>{ __( 'Storage' ) }</h3>
			<table className="form-table">
				<tbody>
					<Row label={ __( 'Cloud storage' ) }>{ storage.bucket ? `${ storage.bucket } (${ storage.provider || 'aws' }${ storage.region ? ', ' + storage.region : '' })` : __( 'Not set' ) }</Row>
					<Row label={ __( 'Folder' ) }>{ storage.object_prefix || '/' }{ storage.object_versioning ? __( ', then a dated folder per upload' ) : '' }</Row>
					<Row label={ __( 'New uploads' ) }>{ storage.copy_to_s3 ? __( 'Copied to cloud storage' ) : __( 'Not copied' ) }{ storage.remove_local_file ? __( ', removed from this server' ) : __( ', also kept on this server' ) }</Row>
				</tbody>
			</table>

			<h3>{ __( 'Delivery' ) }</h3>
			<table className="form-table">
				<tbody>
					<Row label={ __( 'Served from' ) }>
						{ ! storage.serve_from_s3 ? __( 'This server (Offload Media doesn’t rewrite URLs)' ) : status.domain || __( 'Cloud storage, directly (no CDN)' ) }
					</Row>
					{ status.domain && (
						<Row label={ __( 'CloudFront distribution' ) }>
							{ status.distribution
								? status.distribution.id
								: __( 'None found: the domain may not be on CloudFront.' ) }
							<br />
							<span className="description">{ __( 'Looked up from Offload Media’s delivery domain.' ) }</span>
						</Row>
					) }
					<Row label={ __( 'AWS credentials for CloudFront' ) }>
						{ status.credentials ? CREDENTIAL_SOURCES[ status.credentials ] || status.credentials : __( 'None found' ) }
					</Row>
				</tbody>
			</table>
			{ status.error && <Notice status="warning" isDismissible={ false }>{ status.error }</Notice> }

			{ status.domain && (
				<>
					<h3>{ __( 'Clearing replaced images' ) }</h3>
					<p>
						{ __( 'A replaced image keeps its URL, so PhotoPress has CloudFront drop the old copy a few seconds later.' ) }
					</p>
					<p>
						{ last
							? last.error
								? sprintf( __( 'Last cleared %1$s, but it failed: %2$s' ), when( last.time ), last.error )
								: last.all
								? sprintf( __( 'Last cleared: %s, everything' ), when( last.time ) )
								: sprintf( _n( 'Last cleared: %1$s, %2$d image', 'Last cleared: %1$s, %2$d images', last.paths ), when( last.time ), last.paths )
							: __( 'Nothing cleared yet.' ) }
						{ status.pending > 0 && ' ' + sprintf( _n( '%d image waiting.', '%d images waiting.', status.pending ), status.pending ) }
					</p>
					<Button variant="secondary" disabled={ busy || ! canInvalidate || ! status.pending } onClick={ () => invalidate( 'pending' ) }>
						{ __( 'Clear waiting images now' ) }
					</Button>
					<Button variant="secondary" isDestructive disabled={ busy || ! canInvalidate } onClick={ () => invalidate( 'all' ) }>
						{ __( 'Clear the whole CDN' ) }
					</Button>
				</>
			) }
		</div>
	);
}

const HOUR = 3600;
const DAY = 86400;

/**
 * A duration in seconds, entered as a number of hours or days.
 */
function DurationControl( { id, label, help, seconds, onChange } ) {
	const [ unit, setUnit ] = useState( seconds % DAY === 0 && seconds >= DAY ? DAY : HOUR );
	const amount = Math.round( ( seconds / unit ) * 100 ) / 100;

	return (
		<div className="photopress-duration" id={ id }>
			<div className="photopress-duration__inputs">
				<TextControl
					__nextHasNoMarginBottom
					label={ label }
					type="number"
					min={ 0 }
					step={ 1 }
					value={ String( amount ) }
					onChange={ ( value ) => onChange( Math.max( 0, Math.round( ( parseFloat( value ) || 0 ) * unit ) ) ) }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Unit' ) }
					hideLabelFromVision
					value={ String( unit ) }
					options={ [
						{ value: String( HOUR ), label: __( 'hours' ) },
						{ value: String( DAY ), label: __( 'days' ) },
					] }
					onChange={ ( value ) => {
						// The same amount in the new unit: "1 hour" becomes "1 day".
						const next = Number( value );
						setUnit( next );
						onChange( Math.round( amount * next ) );
					} }
				/>
			</div>
			{ help && <p className="components-base-control__help">{ help }</p> }
		</div>
	);
}

class MediaSettings extends Component {
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
				cache_seconds: DAY,
				delete_replaced_objects: false,
				...this.props.data,
			},
			dirtyFields: [],
		};

		this.settingsSchema = {};
	}

	render() {
		return (
			<PanelBody>
				<BaseControl id="photopress_offload_media" label="" help="">
					{ this.getError( 'save' ) && (
						<Notice status="error" isDismissible={ false }>
							{ this.getError( 'save' ) }
						</Notice>
					) }

					<OffloadStatus />

					<div className="photopress-offload-settings">
						<h3>{ __( 'Settings' ) }</h3>

						<DurationControl
							id="cache_seconds"
							label={ __( 'Cache images for' ) }
							help={ __( 'How long browsers and the CDN keep an image before checking for a new one. Where a replaced image wasn’t cleared, its old copy can show this long. Offload Media’s default is a year.' ) }
							seconds={ Number( this.getSetting( 'cache_seconds' ) ?? DAY ) }
							onChange={ ( value ) => this.setSetting( 'cache_seconds', value ) }
						/>

						<SwitchRow
							label={ __( 'Delete replaced files from cloud storage' ) }
							help={ __( 'When a replacement renames an image’s sizes (new dimensions or file type), delete the old files two days later, once no cached page can show them. Offload Media leaves them.' ) }
							checked={ !! this.getSetting( 'delete_replaced_objects' ) }
							onChange={ ( value ) => this.persistSetting( 'delete_replaced_objects', value ) }
						/>

						<Button variant="primary" disabled={ this.state.isAPISaving } onClick={ this.saveSettings }>
							{ __( 'Save' ) }
						</Button>
					</div>
				</BaseControl>
			</PanelBody>
		);
	}
}

export default MediaSettings;
