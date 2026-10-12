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
import { InspectorControls, useBlockProps, store as blockEditorStore } from '@wordpress/block-editor';
import { ComboboxControl, PanelBody, Placeholder, RangeControl, SelectControl, ToggleControl } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
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
	// Taxonomy Gallery (modules/gallery/ImageTemplates.php): "archive", the
	// archive page's images; "query", those of a term, or the latest.
	photopressSource: { type: 'string' },
	photopressTaxonomy: { type: 'string' },
	photopressTerm: { type: 'number' },
	photopressCount: { type: 'number', default: 12 },
	photopressOrder: { type: 'string', default: 'date-desc' },
};

// The layouts turned on in the Gallery settings tab (modules/gallery/gallery.php).
const ENABLED = Array.isArray( window.photopressGalleryLayouts ) ? window.photopressGalleryLayouts : Object.keys( LAYOUTS );

const isGallery = ( name ) => name === 'core/gallery';

const layoutOf = ( attributes ) =>
	LAYOUTS[ attributes.photopressLayout ] ? attributes.photopressLayout : null;

/** A Taxonomy Gallery: its images are found when the page is viewed. */
export const isTaxonomyGallery = ( attributes ) => [ 'archive', 'query' ].includes( attributes.photopressSource );

/** The slug of the template being edited, or '' when it is not a template. */
export const templateSlugOf = ( postType, postId ) =>
	'wp_template' === postType && 'string' === typeof postId ? postId.split( '//' ).pop() : '';

/**
 * Whether a template is for the archives of image taxonomies: PhotoPress's
 * taxonomy-attachment, or the theme's for one of them.
 *
 * @param {string}   slug       The template's.
 * @param {string[]} taxonomies The image taxonomies.
 */
export const isImageArchiveTemplate = ( slug, taxonomies ) =>
	'taxonomy-attachment' === slug || taxonomies.some( ( tax ) => slug === `taxonomy-${ tax }` || slug.startsWith( `taxonomy-${ tax }-` ) );

/**
 * Where a Taxonomy Gallery finds its images: on an image taxonomy's
 * archive template, the archive's; anywhere else, those chosen.
 */
export const sourceFor = ( slug, taxonomies ) => ( isImageArchiveTemplate( slug, taxonomies ) ? 'archive' : 'query' );

const ORDERS = {
	'date-desc': __( 'newest first' ),
	'date-asc': __( 'oldest first' ),
	title: __( 'by title' ),
	rand: __( 'in random order' ),
};

/**
 * What a Taxonomy Gallery shows, in a sentence.
 *
 * @param {Object} attributes The gallery's.
 * @param {Object} names      taxonomy (its name) and term (its name), when chosen.
 */
export function summaryOf( attributes, names = {} ) {
	const layout = LAYOUTS[ attributes.photopressLayout ] || __( 'Default (columns)' );

	if ( 'archive' === attributes.photopressSource ) {
		/* translators: %s: a gallery layout, such as Masonry. */
		return sprintf( __( 'The images of the archive page being viewed, in the %s layout, with links to more pages of them.' ), layout );
	}

	const count = attributes.photopressCount || 12;
	const order = ORDERS[ attributes.photopressOrder ] || ORDERS[ 'date-desc' ];

	if ( attributes.photopressTerm && names.term ) {
		/* translators: 1: a number of images, 2: a taxonomy, such as Keywords, 3: a term, 4: an order, such as newest first, 5: a gallery layout. */
		return sprintf( __( '%1$d images in %2$s: “%3$s”, %4$s, in the %5$s layout.' ), count, names.taxonomy || attributes.photopressTaxonomy, names.term, order, layout );
	}

	/* translators: 1: a number of images, 2: an order, such as newest first, 3: a gallery layout. */
	return sprintf( __( 'The %1$d latest images, %2$s, in the %3$s layout.' ), count, order, layout );
}

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

Object.entries( LAYOUTS ).filter( ( [ layout ] ) => ENABLED.includes( layout ) ).forEach( ( [ layout, label ] ) => {
	registerBlockVariation( 'core/gallery', {
		name: `photopress-${ layout }`,
		/* translators: %s: gallery layout name, e.g. Masonry. */
		title: `${ label } ${ __( 'Gallery' ) }`,
		description: __( 'A PhotoPress gallery layout.' ),
		icon: galleryIcon,
		keywords: [ 'photopress', label.toLowerCase() ],
		attributes: { photopressLayout: layout },
		// Recognized by this attribute alone, so editing the CSS classes or
		// the block style cannot turn it back into a plain gallery.
		isActive: ( attributes ) => layout === attributes.photopressLayout && ! isTaxonomyGallery( attributes ),
		scope: [ 'inserter', 'transform' ],
	} );
} );

/*
 * Taxonomy Gallery: a gallery whose images are found when the page is viewed,
 * like core's Query Loop. On an image taxonomy's archive template, the
 * archive's, with links to more pages of them; anywhere else, those of a
 * chosen taxonomy term, or the latest. It saves no images; the server fills
 * it (modules/gallery/ImageTemplates.php), in whichever layout is chosen.
 */
registerBlockVariation( 'core/gallery', {
	name: 'photopress-taxonomy-gallery',
	title: __( 'Taxonomy Gallery' ),
	description: __( 'A gallery of images found when the page is viewed: those of a keyword, a camera or another term, or the latest; on an image archive template, the archive’s. In a PhotoPress layout.' ),
	icon: galleryIcon,
	keywords: [ 'photopress', 'query', 'archive', 'taxonomy', 'keyword', 'latest' ],
	attributes: {
		photopressSource: 'query',
		photopressLayout: ENABLED[ 0 ],
		photopressCount: 12,
		linkTo: 'attachment',
		sizeSlug: 'large',
		photopressHideCaptions: true,
		align: 'wide',
	},
	isActive: isTaxonomyGallery,
	scope: [ 'inserter' ],
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
	isActive: ( attributes ) => ! attributes.photopressLayout && ! isTaxonomyGallery( attributes ),
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

const IMAGE_LINKS = [
	{ value: 'attachment', label: __( 'Image page' ) },
	{ value: 'media', label: __( 'Image file' ) },
	{ value: 'none', label: __( 'None' ) },
];

const IMAGE_SIZES = [
	{ value: 'medium', label: __( 'Medium' ) },
	{ value: 'large', label: __( 'Large' ) },
	{ value: 'full', label: __( 'Full size' ) },
];

/**
 * The term of an image taxonomy, found as its name is typed.
 */
function TermPicker( { taxonomy, value, onChange } ) {
	const [ search, setSearch ] = useState( '' );
	const { found, current } = useSelect( ( select ) => {
		const store = select( coreStore );
		return {
			found: store.getEntityRecords( 'taxonomy', taxonomy, { search, per_page: 20, context: 'view' } ) || [],
			current: value ? store.getEntityRecord( 'taxonomy', taxonomy, value, { context: 'view' } ) : null,
		};
	}, [ taxonomy, search, value ] );

	const options = [ ...( current ? [ current ] : [] ), ...found.filter( ( t ) => ! current || t.id !== current.id ) ]
		.map( ( t ) => ( { value: String( t.id ), label: t.name } ) );

	return (
		<ComboboxControl
			__nextHasNoMarginBottom
			label={ __( 'Term' ) }
			help={ __( 'Type to find one. None chosen: the latest images of any term.' ) }
			value={ value ? String( value ) : null }
			options={ options }
			onFilterValueChange={ setSearch }
			onChange={ ( next ) => onChange( next ? Number( next ) : undefined ) }
		/>
	);
}

/**
 * Taxonomy Gallery in the editor: there are no images to show until the page is
 * viewed, so a sentence saying what it will show, in place of core's
 * gallery, which would offer to add images; its source, set from where it
 * is; and the settings of the images it finds.
 */
function TaxonomyGalleryEdit( { attributes, setAttributes } ) {
	const { slug, taxonomies } = useSelect( ( select ) => {
		const editor = select( 'core/editor' );
		return {
			slug: templateSlugOf( editor?.getCurrentPostType?.(), editor?.getCurrentPostId?.() ),
			// Not those for the admin only.
			taxonomies: ( select( coreStore ).getTaxonomies( { type: 'attachment', per_page: -1, context: 'view' } ) || [] ).filter( ( t ) => false !== t.photopress_public ),
		};
	}, [] );

	const source = sourceFor( slug, taxonomies.map( ( t ) => t.slug ) );
	const taxonomy = taxonomies.find( ( t ) => t.slug === attributes.photopressTaxonomy );
	const term = useSelect( ( select ) => ( taxonomy && attributes.photopressTerm ? select( coreStore ).getEntityRecord( 'taxonomy', taxonomy.slug, attributes.photopressTerm, { context: 'view' } ) : null ), [ taxonomy, attributes.photopressTerm ] );

	// Where it is decides: a gallery moved between an archive template and
	// anywhere else follows.
	useEffect( () => {
		if ( source !== attributes.photopressSource ) {
			setAttributes( { photopressSource: source } );
		}
	}, [ source, attributes.photopressSource ] );

	return (
		<>
			<div { ...useBlockProps() }>
				<Placeholder icon={ galleryIcon } label={ __( 'Taxonomy Gallery' ) } instructions={ summaryOf( { ...attributes, photopressSource: source }, { taxonomy: taxonomy?.name, term: term?.name } ) } />
			</div>
			<InspectorControls>
				<PanelBody title={ __( 'Images' ) }>
					{ 'query' === source && (
						<>
							<SelectControl
								__nextHasNoMarginBottom
								label={ __( 'Taxonomy' ) }
								value={ attributes.photopressTaxonomy || '' }
								options={ [ { value: '', label: __( 'Any (the latest images)' ) }, ...taxonomies.map( ( t ) => ( { value: t.slug, label: t.name } ) ) ] }
								onChange={ ( value ) => setAttributes( { photopressTaxonomy: value || undefined, photopressTerm: undefined } ) }
							/>
							{ taxonomy && (
								<TermPicker taxonomy={ taxonomy.slug } value={ attributes.photopressTerm } onChange={ ( value ) => setAttributes( { photopressTerm: value } ) } />
							) }
							<RangeControl __nextHasNoMarginBottom label={ __( 'Number of images' ) } value={ attributes.photopressCount || 12 } min={ 1 } max={ 100 } onChange={ ( value ) => setAttributes( { photopressCount: value } ) } />
							<SelectControl
								__nextHasNoMarginBottom
								label={ __( 'Order' ) }
								value={ attributes.photopressOrder || 'date-desc' }
								options={ Object.entries( ORDERS ).map( ( [ value, label ] ) => ( { value, label: label.charAt( 0 ).toUpperCase() + label.slice( 1 ) } ) ) }
								onChange={ ( value ) => setAttributes( { photopressOrder: value } ) }
							/>
						</>
					) }
					{ 'archive' === source && <p className="components-base-control__help">{ __( 'On an image archive template, the images of the archive page being viewed.' ) }</p> }
					<SelectControl __nextHasNoMarginBottom label={ __( 'Link to' ) } value={ attributes.linkTo || 'attachment' } options={ IMAGE_LINKS } onChange={ ( value ) => setAttributes( { linkTo: value } ) } />
					<SelectControl __nextHasNoMarginBottom label={ __( 'Image size' ) } value={ attributes.sizeSlug || 'large' } options={ IMAGE_SIZES } onChange={ ( value ) => setAttributes( { sizeSlug: value } ) } />
				</PanelBody>
			</InspectorControls>
		</>
	);
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
			{ isTaxonomyGallery( attributes ) ? <TaxonomyGalleryEdit attributes={ attributes } setAttributes={ setAttributes } /> : <BlockEdit { ...props } /> }
			<InspectorControls>
				<PanelBody title={ __( 'PhotoPress' ) }>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Layout' ) }
						value={ layout || '' }
						options={ [
							{ label: __( 'Default (columns)' ), value: '' },
							// A gallery keeps a layout turned off since it was chosen.
							...Object.entries( LAYOUTS )
								.filter( ( [ value ] ) => ENABLED.includes( value ) || value === layout )
								.map( ( [ value, label ] ) => ( { value, label } ) ),
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
