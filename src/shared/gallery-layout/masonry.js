/**
 * Masonry for the PhotoPress layouts on core/gallery.
 *
 * Imported by both the editor (src/variations/gallery-layouts.js) and the front
 * end (src/frontend/gallery-layouts.js) so that both place images identically.
 *
 * Masonry is WordPress's bundled masonry-layout, taken from the window that owns
 * the gallery. In the editor that is the canvas iframe: masonry-layout keeps only
 * elements that pass `instanceof HTMLElement` against its own window, so a copy
 * from the parent window would discard every image.
 */

export const ITEM_SELECTOR = '.wp-block-image';

const PLACED = 'ppPlaced';

function gutterOf( figure ) {
	const view = figure.ownerDocument.defaultView;

	return parseFloat( view.getComputedStyle( figure ).columnGap ) || 0;
}

/*
 * Centres the columns in the gallery. Masonry's fitWidth option would centre
 * them too, but it counts columns from the width of the gallery's parent, and
 * a gallery in a constrained layout is narrower than that, so the last column
 * overflowed. Masonry now counts from the gallery's own width; the images are
 * shifted by the leftover space through --pp-masonry-offset.
 */
function centre( figure, masonry ) {
	const used = masonry.cols * masonry.columnWidth - masonry.gutter;
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
 * Starts Masonry on a gallery figure and lays it out.
 *
 * @param {HTMLElement} figure      The core/gallery figure.
 * @param {number}      columnWidth Column width in pixels.
 * @return {?{layout: Function, destroy: Function}} Null when Masonry is not loaded.
 */
export function createMasonry( figure, columnWidth ) {
	const view = figure.ownerDocument.defaultView;

	if ( ! view.Masonry ) {
		return null;
	}

	const masonry = new view.Masonry( figure, {
		itemSelector: ITEM_SELECTOR,
		columnWidth,
		gutter: gutterOf( figure ),
		fitWidth: false,
		percentPosition: false,
		transitionDuration: 0,
		initLayout: false,
	} );

	// Masonry also lays out again by itself when the window resizes.
	masonry.on( 'layoutComplete', () => {
		centre( figure, masonry );
		placeOthers( figure );
	} );

	const layout = () => {
		// Images may have been added, removed or reordered, and the block
		// spacing (the gutter) may have changed.
		masonry.reloadItems();
		masonry.options.gutter = gutterOf( figure );
		masonry.layout();
		centre( figure, masonry );
		placeOthers( figure );
	};

	layout();

	return {
		layout,
		destroy() {
			masonry.destroy();
			figure.style.removeProperty( '--pp-masonry-offset' );
			releaseOthers( figure );
		},
	};
}
