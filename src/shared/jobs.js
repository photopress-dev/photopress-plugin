/**
 * A background job's panel for a settings page: start it, watch its
 * progress, cancel it. The job runs on the server (jobs/Jobs.php); while this
 * panel watches a running job, each progress request also moves it on.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Button, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

const POLL_MS = 2000;

const isRunning = ( job ) => !! job && [ 'queued', 'running' ].includes( job.status );

function statusText( job ) {
	const when = job.finished ? new Date( job.finished * 1000 ).toLocaleString() : '';
	// A paced job (see jobs/Jobs.php) waiting for the server, before or
	// between batches.
	if ( isRunning( job ) && job.waiting === 'busy' ) {
		return __( 'Waiting: the server is busy…' );
	}
	switch ( job.status ) {
		case 'queued':
			return __( 'Waiting to start…' );
		case 'running':
			// Between batches.
			if ( job.next && job.next * 1000 > Date.now() ) {
				return __( 'Running (resting between batches)…' );
			}
			return __( 'Running…' );
		case 'done':
			return sprintf( __( 'Finished %s' ), when );
		case 'cancelled':
			return sprintf( __( 'Canceled %s' ), when );
		default:
			return job.status;
	}
}

/**
 * refresh: changing it asks for the most recent job again, as when a save
 * may have started one. note( job ): words shown with a job's progress.
 * startable: false for a job started elsewhere (Save and reprocess), shown
 * here only once there is one, with its progress and Cancel. compact: the
 * button (startLabel) with the progress beside it, no heading. after: shown
 * right after the button, as an Advanced options link.
 */
export default function JobPanel( { type, label, description, args = {}, confirm = '', onChange, refresh, note, startable = true, compact = false, startLabel, after } ) {
	const [ job, setJob ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const timer = useRef( null );

	const fail = ( e ) => setError( e.message || __( 'The request failed.' ) );

	// The most recent job of this type, if any.
	useEffect( () => {
		apiFetch( { path: `/photopress/v1/jobs?type=${ encodeURIComponent( type ) }` } )
			.then( ( jobs ) => setJob( jobs[ 0 ] || null ) )
			.catch( fail );
	}, [ type, refresh ] );

	useEffect( () => {
		if ( onChange && job ) {
			onChange( job );
		}
	}, [ job ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// While it runs, ask for its progress every couple of seconds.
	useEffect( () => {
		if ( ! isRunning( job ) ) {
			return undefined;
		}
		timer.current = setTimeout( () => {
			apiFetch( { path: `/photopress/v1/jobs/${ job.id }` } ).then( setJob ).catch( fail );
		}, POLL_MS );
		return () => clearTimeout( timer.current );
	}, [ job ] );

	const start = () => {
		// eslint-disable-next-line no-alert
		if ( confirm && ! window.confirm( confirm ) ) {
			return;
		}
		setBusy( true );
		setError( null );
		apiFetch( { path: '/photopress/v1/jobs', method: 'POST', data: { type, args } } )
			.then( setJob )
			.catch( fail )
			.finally( () => setBusy( false ) );
	};

	const cancel = () => {
		setBusy( true );
		apiFetch( { path: `/photopress/v1/jobs/${ job.id }`, method: 'DELETE' } )
			.then( setJob )
			.catch( fail )
			.finally( () => setBusy( false ) );
	};

	const processed = job ? job.done + job.failed : 0;

	if ( ! startable && ! job && ! error ) {
		return null;
	}

	const progress = job && (
		<div className="photopress-job__progress" data-status={ job.status }>
			<progress max={ Math.max( job.total, 1 ) } value={ processed } />
			<p>
				{ sprintf( __( '%1$d of %2$d' ), processed, job.total ) }
				{ note && note( job ) && ' · ' + note( job ) }
				{ job.failed > 0 && ' · ' + sprintf( __( '%d failed' ), job.failed ) }
				{ ' · ' + statusText( job ) }
			</p>
			{ job.errors.length > 0 && (
				<details>
					<summary>{ __( 'Errors' ) }</summary>
					<ul>
						{ job.errors.map( ( e, i ) => (
							<li key={ i }>{ `${ e.item }: ${ e.message }` }</li>
						) ) }
					</ul>
				</details>
			) }
		</div>
	);

	const button = isRunning( job ) ? (
		<Button variant="secondary" onClick={ cancel } disabled={ busy }>
			{ __( 'Cancel' ) }
		</Button>
	) : startable && (
		<Button variant="primary" onClick={ start } disabled={ busy }>
			{ startLabel || ( job ? __( 'Run again' ) : __( 'Start' ) ) }
		</Button>
	);

	// The button, with the progress beside it.
	if ( compact ) {
		return (
			<div className="photopress-job photopress-job--compact" data-job-type={ type }>
				{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
				<div className="photopress-job__row">
					{ button }
					{ after }
					{ progress }
				</div>
			</div>
		);
	}

	return (
		<div className="photopress-job" data-job-type={ type }>
			<h3>{ label }</h3>
			{ description && <p className="photopress-job__description">{ description }</p> }

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ progress }

			{ button }
		</div>
	);
}
