/**
 * Hides one of a block's own settings in the sidebar. Core offers no way to
 * remove a setting from another block, and core/gallery's Columns does
 * nothing under a PhotoPress layout, which sets its own columns or rows.
 */

const HIDDEN = 'ppHidden';

/**
 * Hides, or shows again, the item in the block sidebar's tools panels whose
 * control is labelled `label`.
 *
 * @param {Document} doc   The editor's document (the sidebar is outside the canvas).
 * @param {string}   label The control's label, translated as core translates it.
 * @param {boolean}  hide  Whether to hide it.
 */
export function hideSetting( doc, label, hide ) {
	for ( const item of doc.querySelectorAll( '.block-editor-block-inspector .components-tools-panel-item' ) ) {
		const own = item.querySelector( 'label' );
		const matches = hide && !! own && own.textContent.trim() === label;

		if ( matches && ! item.dataset[ HIDDEN ] ) {
			item.dataset[ HIDDEN ] = '1';
			item.style.display = 'none';
		} else if ( ! matches && item.dataset[ HIDDEN ] ) {
			delete item.dataset[ HIDDEN ];
			item.style.display = '';
		}
	}
}

/**
 * Keeps a setting hidden while `hide` is true: the sidebar is redrawn as the
 * block changes, and is not there at all while it is closed. Returns a
 * function that stops and shows the setting again.
 */
export function keepSettingHidden( doc, label ) {
	const view = doc.defaultView;
	let frame = null;
	const apply = () => {
		frame = null;
		hideSetting( doc, label, true );
	};
	const observer = new view.MutationObserver( () => {
		if ( frame === null ) {
			frame = view.requestAnimationFrame( apply );
		}
	} );

	hideSetting( doc, label, true );
	observer.observe( doc.body, { childList: true, subtree: true } );

	return () => {
		observer.disconnect();
		if ( frame !== null ) {
			view.cancelAnimationFrame( frame );
		}
		hideSetting( doc, label, false );
	};
}
