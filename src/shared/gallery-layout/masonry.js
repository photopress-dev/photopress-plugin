/**
 * Masonry for the PhotoPress layouts on core/gallery: each image in turn goes
 * at the foot of the shortest column, as the masonry-layout library places
 * them, but without the library, so that it can also run inline, as soon as
 * a gallery's markup is parsed (src/frontend/gallery-layouts-inline.js): the
 * gallery is laid out before the browser first paints it, and nothing moves
 * when the rest of the page's scripts run.
 *
 * Imported by the editor (src/variations/gallery-layouts.js), the front end
 * (src/frontend/gallery-layouts.js) and the inline script, so that all three
 * place images identically. Image heights are read from the page: each image
 * has its width and height attributes, so its height is known before it
 * loads.
 */

export const ITEM_SELECTOR = '.wp-block-image';

const PLACED = 'ppPlaced';

function gutterOf( figure ) {
	const view = figure.ownerDocument.defaultView;

	return parseFloat( view.getComputedStyle( figure ).columnGap ) || 0;
}

const itemsOf = ( figure ) => Array.from( figure.children ).filter( ( el ) => el.matches( ITEM_SELECTOR ) && ! el.hidden );

/*
 * Places the images in columns: as many columns of the column width (or the
 * gallery's width, if less) as fit, each image at the foot of the shortest
 * column, the leftmost of equals. The figure is as tall as the tallest.
 * Heights include the images' margins, which are the vertical gap.
 *
 * Returns the columns and their width, or null for a gallery with no width
 * (not laid out, as when hidden).
 */
function place( figure, columnWidth ) {
	const view = figure.ownerDocument.defaultView;
	const width = figure.clientWidth;

	if ( width <= 0 ) {
		return null;
	}

	const gutter = gutterOf( figure );
	const column = Math.min( columnWidth, width );
	const cols = Math.max( 1, Math.floor( ( width + gutter ) / ( column + gutter ) ) );
	const items = itemsOf( figure );

	// All taken out of the flow first, then all measured, then all placed:
	// one layout, rather than one per image.
	items.forEach( ( item ) => ( item.style.position = 'absolute' ) );

	const heights = items.map( ( item ) => {
		const style = view.getComputedStyle( item );

		return item.getBoundingClientRect().height + ( parseFloat( style.marginTop ) || 0 ) + ( parseFloat( style.marginBottom ) || 0 );
	} );

	const columns = new Array( cols ).fill( 0 );

	items.forEach( ( item, i ) => {
		const col = columns.indexOf( Math.min( ...columns ) );

		item.style.left = col * ( column + gutter ) + 'px';
		item.style.top = columns[ col ] + 'px';
		columns[ col ] += heights[ i ];
	} );

	figure.style.height = Math.max( 0, ...columns ) + 'px';

	return { cols, column, gutter };
}

/*
 * Centers the columns in the gallery: the images are shifted by half the
 * space left over, through --pp-masonry-offset. Columns are counted from the
 * gallery's own width, which in a constrained layout is narrower than its
 * parent's.
 */
function center( figure, placed ) {
	const used = placed.cols * ( placed.column + placed.gutter ) - placed.gutter;
	const offset = Math.max( 0, Math.floor( ( figure.clientWidth - used ) / 2 ) );

	figure.style.setProperty( '--pp-masonry-offset', offset + 'px' );
}

/*
 * Children that are not images -- the gallery caption, and in the editor the
 * caption field -- would sit underneath the absolutely positioned images.
 * Stack them below the columns and grow the figure to fit.
 */
function placeOthers( figure ) {
	let y = parseFloat( figure.style.height ) || 0;

	for ( const el of figure.children ) {
		if ( el.matches( ITEM_SELECTOR ) || el.hidden ) {
			continue;
		}

		el.dataset[ PLACED ] = '1';
		el.style.position = 'absolute';
		el.style.left = '0';
		el.style.right = '0';
		el.style.top = y + 'px';
		y += el.offsetHeight;
	}

	figure.style.height = y + 'px';
}

function releaseOthers( figure ) {
	for ( const el of figure.children ) {
		if ( el.dataset[ PLACED ] ) {
			delete el.dataset[ PLACED ];
			el.style.position = el.style.left = el.style.right = el.style.top = '';
		}
	}
}

/**
 * Lays out a masonry gallery, and gives a way to do it again (images added,
 * removed or resized, the gallery's width or gap changed) and to undo it.
 *
 * @param {HTMLElement} figure      The core/gallery figure.
 * @param {number}      columnWidth Column width in pixels.
 * @return {{layout: Function, destroy: Function}} Its controls.
 */
export function createMasonry( figure, columnWidth ) {
	const layout = () => {
		releaseOthers( figure );

		const placed = place( figure, columnWidth );

		if ( placed ) {
			center( figure, placed );
			placeOthers( figure );
		}
	};

	layout();

	return {
		layout,
		destroy() {
			itemsOf( figure ).forEach( ( item ) => {
				item.style.position = item.style.left = item.style.top = '';
			} );
			figure.style.height = '';
			figure.style.removeProperty( '--pp-masonry-offset' );
			releaseOthers( figure );
		},
	};
}
