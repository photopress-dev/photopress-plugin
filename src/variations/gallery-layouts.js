/**
 * PhotoPress layouts for core/gallery: masonry, rows and mosaic.
 *
 * These are variations of the core block, not a block of their own. Core owns
 * the saved markup; PhotoPress only adds attributes that live in the block
 * comment, plus classes and CSS variables applied in the editor here and on the
 * front end by modules/gallery/gallery.php. None of it reaches the saved HTML,
 * so it cannot make a gallery invalid.
 */

/**
 * WordPress dependencies
 */
import { addFilter } from '@wordpress/hooks';
import { registerBlockVariation } from '@wordpress/blocks';
import { createHigherOrderComponent } from '@wordpress/compose';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { InspectorControls, store as blockEditorStore } from '@wordpress/block-editor';
import { PanelBody, RangeControl, SelectControl, ToggleControl } from '@wordpress/components';
import { useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { gallery as galleryIcon } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { createMasonry } from '../shared/gallery-layout/masonry.js';
import { justify, release } from '../shared/gallery-layout/justify.js';
import { keepSettingHidden } from './hidden-setting.js';
import './style.scss';

export const LAYOUTS = {
	masonry: __( 'Masonry' ),
	rows: __( 'Rows' ),
	mosaic: __( 'Mosaic' ),
};

// Keep in step with the attributes registered in modules/gallery/gallery.php.
const ATTRIBUTES = {
	photopressLayout: { type: 'string' },
	photopressColumnWidth: { type: 'number', default: 300 },
	photopressRowHeight: { type: 'number', default: 300 },
	photopressSlideshow: { type: 'boolean', default: false },
	photopressHideCaptions: { type: 'boolean', default: false },
};

const isGallery = ( name ) => name === 'core/gallery';

const layoutOf = ( attributes ) =>
	LAYOUTS[ attributes.photopressLayout ] ? attributes.photopressLayout : null;

/*
 * Register the attributes. Without this the editor drops them from the block
 * comment the next time the post is saved.
 */
addFilter( 'blocks.registerBlockType', 'photopress/gallery-layouts/attributes', ( settings, name ) => {
	if ( ! isGallery( name ) ) {
		return settings;
	}

	return { ...settings, attributes: { ...settings.attributes, ...ATTRIBUTES } };
} );

Object.entries( LAYOUTS ).forEach( ( [ layout, label ] ) => {
	registerBlockVariation( 'core/gallery', {
		name: `photopress-${ layout }`,
		/* translators: %s: gallery layout name, e.g. Masonry. */
		title: `${ label } ${ __( 'Gallery' ) }`,
		description: __( 'A PhotoPress gallery layout.' ),
		icon: galleryIcon,
		keywords: [ 'photopress', label.toLowerCase() ],
		attributes: { photopressLayout: layout },
		// Recognised by this attribute alone, so editing the CSS classes or
		// the block style cannot turn it back into a plain gallery.
		isActive: [ 'photopressLayout' ],
		scope: [ 'inserter', 'transform' ],
	} );
} );

/*
 * WordPress's own gallery, in columns, for the variation switcher only: the
 * way back from a PhotoPress layout. The switcher lists registered
 * variations, and core's gallery is not one. Active when no layout is set,
 * with core's own title, icon and description, so a plain gallery shows as
 * it did.
 */
registerBlockVariation( 'core/gallery', {
	name: 'photopress-columns',
	title: __( 'Gallery' ),
	description: __( 'Display multiple images in a rich gallery.' ),
	icon: galleryIcon,
	attributes: { photopressLayout: undefined },
	isActive: ( attributes ) => ! attributes.photopressLayout,
	scope: [ 'transform' ],
} );

/*
 * Runs Masonry on the gallery in the editor canvas. Rendered next to the block
 * as a hidden element, which gives access to the canvas document.
 */
function MasonryPreview( { clientId, columnWidth, gap } ) {
	const ref = useRef();

	useEffect( () => {
		const doc = ref.current.ownerDocument;
		const view = doc.defaultView;
		const figure = doc.getElementById( `block-${ clientId }` );

		if ( ! figure ) {
			return;
		}

		const masonry = createMasonry( figure, columnWidth );

		if ( ! masonry ) {
			return;
		}

		let frame = null;
		const schedule = () => {
			if ( frame === null ) {
				frame = view.requestAnimationFrame( () => {
					frame = null;
					masonry.layout();
				} );
			}
		};

		// Lay out again as images load (items resize), as the canvas width
		// changes, and as images are added, removed or reordered.
		const resizes = new view.ResizeObserver( schedule );
		const observeChildren = () => {
			resizes.observe( figure.parentNode );
			Array.from( figure.children ).forEach( ( el ) => resizes.observe( el ) );
		};
		const mutations = new view.MutationObserver( () => {
			observeChildren();
			schedule();
		} );

		observeChildren();
		mutations.observe( figure, { childList: true } );

		return () => {
			resizes.disconnect();
			mutations.disconnect();
			if ( frame !== null ) {
				view.cancelAnimationFrame( frame );
			}
			masonry.destroy();
		};
	}, [ clientId, columnWidth, gap ] );

	return <span ref={ ref } hidden />;
}

/*
 * Lays out the mosaic in the editor with the same justify() the front end
 * uses. Each image's aspect ratio comes from its attachment's stored size, as
 * it does in modules/gallery/gallery.php. Rendered next to the block like
 * MasonryPreview.
 */
function MosaicPreview( { clientId, rowHeight, gap } ) {
	const ref = useRef();

	// "innerClientId:ratio" pairs as one string, so the effect below reruns
	// only when an image or its size changes.
	const ratios = useSelect( ( select ) => {
		const { getMedia } = select( coreStore );

		return select( blockEditorStore ).getBlocks( clientId ).map( ( image ) => {
			const media = image.attributes.id && getMedia( image.attributes.id, { context: 'view' } );
			const { width, height } = media?.media_details || {};

			return `${ image.clientId }:${ width && height ? ( width / height ).toFixed( 4 ) : '' }`;
		} ).join( ',' );
	}, [ clientId ] );

	useEffect( () => {
		const doc = ref.current.ownerDocument;
		const view = doc.defaultView;
		const figure = doc.getElementById( `block-${ clientId }` );

		if ( ! figure ) {
			return;
		}

		ratios.split( ',' ).forEach( ( pair ) => {
			const [ imageId, ratio ] = pair.split( ':' );
			const item = doc.getElementById( `block-${ imageId }` );

			if ( item && ratio ) {
				item.style.setProperty( '--pp-ar', ratio );
			}
		} );

		let frame = null;
		const schedule = () => {
			if ( frame === null ) {
				frame = view.requestAnimationFrame( () => {
					frame = null;
					justify( figure, rowHeight );
				} );
			}
		};

		// Lay out again as the canvas width changes and as images are added,
		// removed, reordered or replaced.
		const resizes = new view.ResizeObserver( schedule );
		const mutations = new view.MutationObserver( schedule );

		resizes.observe( figure.parentNode );
		mutations.observe( figure, { childList: true } );
		schedule();

		return () => {
			resizes.disconnect();
			mutations.disconnect();
			if ( frame !== null ) {
				view.cancelAnimationFrame( frame );
			}
			release( figure );
		};
	}, [ clientId, rowHeight, gap, ratios ] );

	return <span ref={ ref } hidden />;
}

const withLayoutControls = createHigherOrderComponent( ( BlockEdit ) => ( props ) => {
	if ( ! isGallery( props.name ) ) {
		return <BlockEdit { ...props } />;
	}

	const { attributes, setAttributes, clientId } = props;
	const layout = layoutOf( attributes );
	// An empty gallery renders the media placeholder in place of the images;
	// Masonry must leave that alone.
	const imageCount = useSelect( ( select ) => select( blockEditorStore ).getBlockCount( clientId ), [ clientId ] );

	// Core's Columns does nothing under a PhotoPress layout: hidden while
	// this gallery is the one whose settings show.
	const hidesColumns = !! layout && !! props.isSelected;
	useEffect( () => ( hidesColumns ? keepSettingHidden( document, __( 'Columns' ) ) : undefined ), [ hidesColumns ] );

	return (
		<>
			<BlockEdit { ...props } />
			<InspectorControls>
				<PanelBody title={ __( 'PhotoPress' ) }>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Layout' ) }
						value={ layout || '' }
						options={ [
							{ label: __( 'Default (columns)' ), value: '' },
							...Object.entries( LAYOUTS ).map( ( [ value, label ] ) => ( { value, label } ) ),
						] }
						onChange={ ( value ) => setAttributes( { photopressLayout: value || undefined } ) }
					/>
					{ layout === 'masonry' && (
						<RangeControl
							__nextHasNoMarginBottom
							label={ __( 'Column width' ) }
							value={ attributes.photopressColumnWidth }
							onChange={ ( value ) => setAttributes( { photopressColumnWidth: value } ) }
							min={ 100 }
							max={ 800 }
						/>
					) }
					{ ( layout === 'rows' || layout === 'mosaic' ) && (
						<RangeControl
							__nextHasNoMarginBottom
							label={ __( 'Row height' ) }
							value={ attributes.photopressRowHeight }
							onChange={ ( value ) => setAttributes( { photopressRowHeight: value } ) }
							min={ 100 }
							max={ 800 }
						/>
					) }
					{ /* Both work with any layout, including core's own columns. */ }
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Slideshow' ) }
						help={ __( 'Open a slideshow when an image is clicked.' ) }
						checked={ !! attributes.photopressSlideshow }
						onChange={ ( value ) => setAttributes( { photopressSlideshow: value } ) }
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Hide captions' ) }
						help={ __( 'Image captions are kept, but not shown in the gallery.' ) }
						checked={ !! attributes.photopressHideCaptions }
						onChange={ ( value ) => setAttributes( { photopressHideCaptions: value } ) }
					/>
				</PanelBody>
			</InspectorControls>
			{ layout === 'masonry' && imageCount > 0 && (
				<MasonryPreview
					clientId={ clientId }
					columnWidth={ attributes.photopressColumnWidth }
					gap={ JSON.stringify( attributes.style?.spacing?.blockGap ) }
				/>
			) }
			{ layout === 'mosaic' && imageCount > 0 && (
				<MosaicPreview
					clientId={ clientId }
					rowHeight={ attributes.photopressRowHeight }
					gap={ JSON.stringify( attributes.style?.spacing?.blockGap ) }
				/>
			) }
		</>
	);
}, 'withPhotoPressLayoutControls' );

addFilter( 'editor.BlockEdit', 'photopress/gallery-layouts/controls', withLayoutControls );

/*
 * The same classes and CSS variables the PHP adds on the front end, applied to
 * the block wrapper in the editor.
 */
const withLayoutClasses = createHigherOrderComponent( ( BlockListBlock ) => ( props ) => {
	if ( ! isGallery( props.name ) ) {
		return <BlockListBlock { ...props } />;
	}

	const layout = layoutOf( props.attributes );
	const hideCaptions = !! props.attributes.photopressHideCaptions;

	if ( ! layout && ! hideCaptions ) {
		return <BlockListBlock { ...props } />;
	}

	const wrapperProps = props.wrapperProps || {};
	const className = [
		props.className,
		layout && 'photopress-layout',
		layout && `photopress-layout-${ layout }`,
		hideCaptions && 'photopress-hide-captions',
	].filter( Boolean ).join( ' ' );

	if ( ! layout ) {
		return <BlockListBlock { ...props } className={ className } />;
	}

	return (
		<BlockListBlock
			{ ...props }
			className={ className }
			wrapperProps={ {
				...wrapperProps,
				style: {
					...wrapperProps.style,
					'--pp-column-width': props.attributes.photopressColumnWidth + 'px',
					'--pp-row-height': props.attributes.photopressRowHeight + 'px',
				},
			} }
		/>
	);
}, 'withPhotoPressLayoutClasses' );

addFilter( 'editor.BlockListBlock', 'photopress/gallery-layouts/classes', withLayoutClasses );
