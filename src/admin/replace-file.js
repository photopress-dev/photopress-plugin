/**
 * The Replace file button in an image's details (ReplaceFileButton.php).
 * The chosen file goes to Replace File, which reads its metadata as for an
 * upload. The media modal draws its fields by Ajax, so events are taken on
 * the document.
 */
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

document.addEventListener( 'click', ( event ) => {
	const button = event.target.closest( '.photopress-replace-file button' );

	if ( button ) {
		button.parentNode.querySelector( 'input[type="file"]' ).click();
	}
} );

// Taken before the media modal's form sees it, which saves the image's
// fields on every change.
document.addEventListener( 'change', ( event ) => {
	const input = event.target.closest( '.photopress-replace-file input[type="file"]' );

	if ( ! input ) {
		return;
	}

	event.stopPropagation();

	if ( input.files.length ) {
		replace( input.closest( '.photopress-replace-file' ), input.files[ 0 ] );
	}

	input.value = '';
}, true );

async function replace( field, file ) {
	const id = Number( field.dataset.id );
	const button = field.querySelector( 'button' );
	const status = field.querySelector( '.photopress-replace-file__status' );
	const body = new FormData();

	body.append( 'file', file, file.name );
	button.disabled = true;
	status.textContent = __( 'Replacing…' );

	try {
		const media = await apiFetch( { path: `/photopress/v1/media/${ id }/file?reprocess_metadata=true`, method: 'POST', body } );

		await refreshCached( media );
		status.textContent = __( 'Replaced.' );

		// The edit screen's fields hold the old text: saving them would put
		// it back.
		if ( ! field.closest( '.media-modal' ) ) {
			window.location.reload();
			return;
		}

		window.wp?.media?.attachment( id ).fetch();
	} catch ( error ) {
		status.textContent = error?.message || __( 'The file could not be replaced.' );
	} finally {
		button.disabled = false;
	}
}

/**
 * The new files keep the old URLs: fetched again, so this browser shows
 * them rather than the copies it has.
 */
function refreshCached( media ) {
	const sizes = Object.values( media?.media_details?.sizes || {} ).map( ( size ) => size.source_url );
	const urls = [ ...new Set( [ media?.source_url, ...sizes ].filter( Boolean ) ) ];

	return Promise.all( urls.map( ( url ) => fetch( url, { cache: 'reload', mode: 'no-cors' } ).catch( () => {} ) ) );
}
