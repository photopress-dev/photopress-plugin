/**
 * The Offload Media settings tab: where WP Offload Media stores and serves
 * images, their cache lifetime, and clearing replaced images from CloudFront.
 */
const { __, sprintf } = wp.i18n;
import { Component, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { BaseControl, Button, Notice, PanelBody, SelectControl, TextControl, ToggleControl } from '@wordpress/components';

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
	'instance-role': __( "the server's IAM role" ),
	'offload-media': __( "WP Offload Media's credentials" ),
};

const PLUGIN_STATES = {
	active: __( 'Active' ),
	inactive: __( 'Disabled' ),
	missing: __( 'Not installed' ),
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
			! window.confirm( __( 'Clear every file from the CDN? Each is fetched from the bucket again on its next request.' ) )
		) {
			return;
		}
		call( { path: '/photopress/v1/cdn/invalidate', method: 'POST', data: { scope } } );
	};

	if ( ! status ) {
		return error ? <Notice status="error" isDismissible={ false }>{ error }</Notice> : <p>{ __( 'Checking…' ) }</p>;
	}

	const state = (
		<p className="photopress-offload-state">
			{ __( 'Offload Media status:' ) }{ ' ' }
			<span className={ `photopress-offload-state__value is-${ status.plugin }` }>{ PLUGIN_STATES[ status.plugin ] || status.plugin }</span>
		</p>
	);

	if ( 'missing' === status.plugin ) {
		return (
			<div className="photopress-offload-status" data-state="missing">
				{ state }
				<p>
					{ __( 'With WP Offload Media, images are stored in a bucket (Amazon S3 and others) and can be served from a CDN; this page then shows where, sets their cache lifetime, and clears replaced images from CloudFront.' ) }
				</p>
			</div>
		);
	}

	if ( 'inactive' === status.plugin ) {
		return (
			<div className="photopress-offload-status" data-state="inactive">
				{ state }
				<p>{ __( 'Activate it to store images in a bucket; this page then shows where they are stored and served.' ) }</p>
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
					<Row label={ __( 'Bucket' ) }>{ storage.bucket ? `${ storage.bucket } (${ storage.provider || 'aws' }${ storage.region ? ', ' + storage.region : '' })` : __( 'Not set' ) }</Row>
					<Row label={ __( 'Path in the bucket' ) }>{ storage.object_prefix || '/' }{ storage.object_versioning ? __( ' + a dated folder per image' ) : '' }</Row>
					<Row label={ __( 'New uploads' ) }>{ storage.copy_to_s3 ? __( 'Copied to the bucket' ) : __( 'Not copied' ) }{ storage.remove_local_file ? __( ', removed from this server' ) : __( ', kept on this server too' ) }</Row>
				</tbody>
			</table>

			<h3>{ __( 'Delivery' ) }</h3>
			<table className="form-table">
				<tbody>
					<Row label={ __( 'Served from' ) }>
						{ ! storage.serve_from_s3 ? __( 'This server (Offload Media does not rewrite URLs)' ) : status.domain || __( 'The bucket directly (no CDN domain)' ) }
					</Row>
					{ status.domain && (
						<Row label={ __( 'CloudFront distribution' ) }>
							{ status.distribution
								? status.distribution.id
								: __( 'None found for this domain: it may not be a CloudFront domain.' ) }
							<br />
							<span className="description">{ __( "Found from Offload Media's delivery domain." ) }</span>
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
						{ __( 'A replaced image keeps its URL. PhotoPress asks CloudFront to drop its copy, a few seconds after the replacement, so the new file shows at once.' ) }
					</p>
					<p>
						{ last
							? last.error
								? sprintf( __( 'Last invalidation failed (%1$s): %2$s' ), when( last.time ), last.error )
								: last.all
								? sprintf( __( 'Last invalidation: %s, everything' ), when( last.time ) )
								: sprintf( __( 'Last invalidation: %1$s, %2$d image(s)' ), when( last.time ), last.paths )
							: __( 'No invalidations yet.' ) }
						{ status.pending > 0 && ' ' + sprintf( __( '%d image(s) waiting.' ), status.pending ) }
					</p>
					<Button variant="secondary" disabled={ busy || ! canInvalidate || ! status.pending } onClick={ () => invalidate( 'pending' ) }>
						{ __( 'Clear waiting images now' ) }
					</Button>
					<Button variant="secondary" isDestructive disabled={ busy || ! canInvalidate } onClick={ () => invalidate( 'all' ) }>
						{ __( 'Invalidate the whole CDN' ) }
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
				stale_seconds: HOUR,
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
							label={ __( 'Browsers and the CDN keep an image for' ) }
							help={ __( "Before checking back for a new copy. A replaced image keeps its URL, so this is how long an old copy can still be shown where it was not cleared. Offload Media's own is a year." ) }
							seconds={ Number( this.getSetting( 'cache_seconds' ) ?? DAY ) }
							onChange={ ( value ) => this.setSetting( 'cache_seconds', value ) }
						/>

						<DurationControl
							id="stale_seconds"
							label={ __( 'Then keep showing it while checking for' ) }
							help={ __( 'After that, how long the old copy may still be shown while the new one is fetched, so nobody waits on the check. 0 to always wait.' ) }
							seconds={ Number( this.getSetting( 'stale_seconds' ) ?? HOUR ) }
							onChange={ ( value ) => this.setSetting( 'stale_seconds', value ) }
						/>

						<ToggleControl
							__nextHasNoMarginBottom
							id="delete_replaced_objects"
							label={ __( 'Delete replaced files from the bucket' ) }
							help={ __( "When a replacement gives an image's sizes new names (new dimensions or file type), delete the old files from the bucket two days later, once no cached page can still show them. Offload Media itself leaves them there." ) }
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
