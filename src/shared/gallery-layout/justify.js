/**
 * Justified rows for the PhotoPress mosaic layout on core/gallery.
 *
 * Imported by both the editor and the front end, like masonry.js. CSS alone can
 * only stretch widths at a fixed row height, which crops heavily when a row has
 * room for one image. This fills each row with whole images and then sets the
 * row's height so they span the gallery exactly at their own proportions; only
 * an unfilled last row stays at the target height.
 *
 * Reads each image's aspect ratio from --pp-ar on its item (set by
 * modules/gallery/gallery.php, or MosaicPreview in the editor) and sets the
 * item's width and height inline. Items without a ratio yet are laid out as
 * squares and corrected on the next pass.
 */

import { ITEM_SELECTOR } from './masonry.js';

const items = ( figure ) =>
	Array.from( figure.children ).filter( ( el ) => el.matches( ITEM_SELECTOR ) );

const ratioOf = ( item ) => parseFloat( item.style.getPropertyValue( '--pp-ar' ) ) || 1;

function sizeRow( row, height, gap ) {
	row.forEach( ( item ) => {
		item.style.flex = 'none';
		item.style.height = height + 'px';
	} );

	// Whole pixels, with the rounding remainder given to the last image, so
	// a full row neither overflows nor leaves a gap.
	let used = 0;
	row.forEach( ( item, i ) => {
		const width = i === row.length - 1 && row.full
			? row.width - used - gap * ( row.length - 1 )
			: Math.floor( ratioOf( item ) * height );
		item.style.width = width + 'px';
		used += width;
	} );
}

/**
 * Lays out a mosaic gallery.
 *
 * @param {HTMLElement} figure    The core/gallery figure.
 * @param {number}      rowHeight Target row height in pixels.
 */
export function justify( figure, rowHeight ) {
	const view = figure.ownerDocument.defaultView;
	const style = view.getComputedStyle( figure );
	const width = figure.clientWidth - parseFloat( style.paddingLeft ) - parseFloat( style.paddingRight );
	const gap = parseFloat( style.columnGap ) || 0;

	if ( width <= 0 ) {
		return;
	}

	let row = [];
	let ratios = 0;

	items( figure ).forEach( ( item ) => {
		row.push( item );
		ratios += ratioOf( item );

		const gaps = gap * ( row.length - 1 );

		if ( ratios * rowHeight + gaps >= width ) {
			row.full = true;
			row.width = width;
			sizeRow( row, Math.floor( ( width - gaps ) / ratios ), gap );
			row = [];
			ratios = 0;
		}
	} );

	if ( row.length ) {
		sizeRow( row, rowHeight, gap );
	}
}

export function release( figure ) {
	items( figure ).forEach( ( item ) => {
		item.style.flex = item.style.width = item.style.height = '';
	} );
}
