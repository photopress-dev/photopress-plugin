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

// Down to a hundredth of a pixel: a full row then never adds up to more than
// the gallery's width (it would wrap), and is short of it by under a pixel.
const hundredths = ( n ) => Math.floor( n * 100 ) / 100;

function sizeRow( row, height, gap ) {
	height = hundredths( height );

	row.forEach( ( item ) => {
		item.style.flex = 'none';
		item.style.height = height + 'px';
	} );

	// Each image at its own proportions. Whole pixels lost up to a pixel per
	// image and to the row height, and the last image was given all of it,
	// up to 14px wider than its shape: its image overflowed the row.
	row.forEach( ( item ) => {
		item.style.width = hundredths( ratioOf( item ) * height ) + 'px';
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
			sizeRow( row, ( width - gaps ) / ratios, gap );
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
