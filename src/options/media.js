/**
 * The Offload Media settings tab: where WP Offload Media stores and serves
 * images, their cache lifetime, and clearing replaced images from CloudFront.
 */
const { __, sprintf } = wp.i18n;
import { Component, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { BaseControl, Button, Notice, PanelBody, TextControl, ToggleControl } from '@wordpress/components';

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

	if ( 'missing' === status.plugin ) {
		return (
			<p className="photopress-offload-status" data-state="missing">
				{ __( 'WP Offload Media is not installed. With it, images are stored in a bucket (Amazon S3 and others) and can be served from a CDN; this page then shows where, sets their cache lifetime, and clears replaced images from CloudFront.' ) }
			</p>
		);
	}

	if ( 'inactive' === status.plugin ) {
		return (
			<p className="photopress-offload-status" data-state="inactive">
				{ __( 'WP Offload Media is installed but not active. Activate it to store images in a bucket; this page then shows where they are stored and served.' ) }
			</p>
		);
	}

	const storage = status.storage || {};
	const last = status.last;
	const canInvalidate = !! status.distribution && !! status.credentials;

	return (
		<div className="photopress-offload-status" data-state="active">
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
								? `${ status.distribution.id } (${ 'setting' === status.distribution.source ? __( 'set below' ) : __( 'found from the domain' ) })`
								: __( 'None found for this domain: it may not be a CloudFront domain.' ) }
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
					</Button>{ ' ' }
					<Button variant="secondary" isDestructive disabled={ busy || ! canInvalidate } onClick={ () => invalidate( 'all' ) }>
						{ __( 'Invalidate the whole CDN' ) }
					</Button>{ ' ' }
					<Button variant="tertiary" disabled={ busy } onClick={ () => call( { path: '/photopress/v1/cdn/distribution', method: 'DELETE' } ) }>
						{ __( 'Find the distribution again' ) }
					</Button>
				</>
			) }
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
				cache_control: 'max-age=86400, stale-while-revalidate=3600',
				cloudfront_distribution_id: '',
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

					<h3>{ __( 'Settings' ) }</h3>

					<TextControl
						id="cache_control"
						label={ __( 'Cache-Control for offloaded files' ) }
						help={ __( "How long browsers and a CDN may keep an image before checking back. A replaced image keeps its URL, so this is how long an old copy can still be shown where it was not cleared. Offload Media's own value is a year (max-age=31536000)." ) }
						value={ this.getSetting( 'cache_control' ) || '' }
						onChange={ ( value ) => this.setSetting( 'cache_control', value ) }
						onBlur={ ( event ) => this.setSetting( 'cache_control', sanitize( event.target.value, 'string' ) ) }
					/>

					<ToggleControl
						id="delete_replaced_objects"
						label={ __( 'Delete replaced files from the bucket' ) }
						help={ __( "When a replacement gives an image's sizes new names (new dimensions or file type), delete the old files from the bucket two days later, once no cached page can still show them. Offload Media itself leaves them there." ) }
						checked={ !! this.getSetting( 'delete_replaced_objects' ) }
						onChange={ ( value ) => this.persistSetting( 'delete_replaced_objects', value ) }
					/>

					<TextControl
						id="cloudfront_distribution_id"
						label={ __( 'CloudFront distribution ID' ) }
						help={ __( "Leave empty to find it from Offload Media's delivery domain." ) }
						value={ this.getSetting( 'cloudfront_distribution_id' ) || '' }
						onChange={ ( value ) => this.setSetting( 'cloudfront_distribution_id', value ) }
						onBlur={ ( event ) => this.setSetting( 'cloudfront_distribution_id', sanitize( event.target.value, 'string' ) ) }
					/>

					<Button variant="primary" disabled={ this.state.isAPISaving } onClick={ this.saveSettings } className="components-base-control__field">
						{ __( 'Save' ) }
					</Button>
				</BaseControl>
			</PanelBody>
		);
	}
}

export default MediaSettings;
