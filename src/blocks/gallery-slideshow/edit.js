/**
 * WordPress dependencies
 */
import { InspectorControls, store as blockEditorStore, useBlockProps } from '@wordpress/block-editor';
import { Notice, PanelBody, Placeholder, RangeControl, SelectControl, ToggleControl } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { gallery as galleryIcon } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import metadata from '../../../modules/gallery/blocks/gallery-slideshow/block.json';

/**
 * Every core/gallery on the page, in order, with its anchor and images.
 */
export function findGalleries( blocks, found = [] ) {
	blocks.forEach( ( block ) => {
		if ( block.name === 'core/gallery' ) {
			found.push( {
				clientId: block.clientId,
				anchor: block.attributes.anchor || '',
				images: block.innerBlocks.filter( ( b ) => b.name === 'core/image' && b.attributes.id ),
			} );
		}
		findGalleries( block.innerBlocks || [], found );
	} );

	return found;
}

/**
 * An anchor not used by any gallery on the page: gallery, gallery-2, ...
 */
export function uniqueAnchor( galleries ) {
	const used = new Set( galleries.map( ( g ) => g.anchor ) );
	let anchor = 'gallery';

	for ( let n = 2; used.has( anchor ); n++ ) {
		anchor = `gallery-${ n }`;
	}

	return anchor;
}

export default function Edit( { attributes, setAttributes } ) {
	const { galleryAnchor, hideGallery, sizeSlug, autoplay, delay, effect, maxHeightOffset, showCaptions, captionPosition, captionPadding, galleryNavigation, scrollBehavior } = attributes;
	const blockProps = useBlockProps( {
		className: `is-effect-${ effect } has-captions-${ captionPosition }`,
		style: { '--pp-slideshow-caption-padding': `${ captionPadding }px` },
	} );
	const { updateBlockAttributes } = useDispatch( blockEditorStore );

	const { blocks, imageSizes } = useSelect( ( select ) => ( {
		blocks: select( blockEditorStore ).getBlocks(),
		imageSizes: select( blockEditorStore ).getSettings().imageSizes || [],
	} ), [] );

	const galleries = useMemo( () => findGalleries( blocks ), [ blocks ] );
	const source = galleryAnchor ? galleries.find( ( g ) => g.anchor === galleryAnchor ) : undefined;

	const chooseGallery = ( clientId ) => {
		const chosen = galleries.find( ( g ) => g.clientId === clientId );

		if ( ! chosen ) {
			setAttributes( { galleryAnchor: '' } );
			return;
		}

		// The slideshow finds its gallery by the gallery's HTML anchor.
		let anchor = chosen.anchor;
		if ( ! anchor ) {
			anchor = uniqueAnchor( galleries );
			updateBlockAttributes( chosen.clientId, { anchor } );
		}

		setAttributes( { galleryAnchor: anchor } );
	};

	const galleryOptions = [
		{ value: '', label: __( 'Choose a gallery' ) },
		...galleries.map( ( g, i ) => ( {
			value: g.clientId,
			/* translators: 1: gallery number on the page, 2: number of images, 3: HTML anchor. */
			label: sprintf( __( 'Gallery %1$d: %2$d images%3$s' ), i + 1, g.images.length, g.anchor ? ` (#${ g.anchor })` : '' ),
		} ) ),
	];

	const first = source && source.images[ 0 ];

	let preview;
	if ( first ) {
		preview = (
			<div className="photopress-gallery-slideshow__track">
				<figure className="photopress-gallery-slideshow__slide is-current">
					<img className="photopress-gallery-slideshow__image" src={ first.attributes.url } alt={ first.attributes.alt || '' } />
					{ showCaptions && first.attributes.caption && (
						<figcaption className="photopress-gallery-slideshow__caption wp-element-caption">
							{ String( first.attributes.caption ).replace( /<[^>]+>/g, '' ) }
						</figcaption>
					) }
				</figure>
				<span className="photopress-gallery-slideshow__count">{ sprintf( __( '1 of %d' ), source.images.length ) }</span>
			</div>
		);
	} else {
		preview = (
			<Placeholder
				icon={ galleryIcon }
				label={ metadata.title }
				instructions={ galleries.length
					? __( 'Choose the gallery this slideshow shows.' )
					: __( 'Add a Gallery to this page, then choose it here.' ) }
			>
				{ galleries.length > 0 && (
					<SelectControl __nextHasNoMarginBottom value="" options={ galleryOptions } onChange={ chooseGallery } />
				) }
			</Placeholder>
		);
	}

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Source' ) }>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Gallery' ) }
						value={ source ? source.clientId : '' }
						options={ galleryOptions }
						onChange={ chooseGallery }
					/>
					{ galleryAnchor && ! source && (
						<Notice status="warning" isDismissible={ false }>
							{ sprintf( __( 'There is no gallery with the anchor "%s" on this page.' ), galleryAnchor ) }
						</Notice>
					) }
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Hide the gallery' ) }
						help={ hideGallery
							? __( 'Visitors see only the slideshow. The gallery stays in the editor, where you choose its images.' )
							: __( 'Show only the slideshow to visitors.' ) }
						checked={ hideGallery }
						onChange={ ( value ) => setAttributes( { hideGallery: value } ) }
					/>
				</PanelBody>
				<PanelBody title={ __( 'Slideshow' ) }>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Transition' ) }
						value={ effect }
						options={ [ { value: 'slide', label: __( 'Slide' ) }, { value: 'fade', label: __( 'Fade' ) } ] }
						onChange={ ( value ) => setAttributes( { effect: value } ) }
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Autoplay' ) }
						help={ __( 'Pauses while the pointer is over the slideshow, and stays off for visitors who prefer reduced motion.' ) }
						checked={ autoplay }
						onChange={ ( value ) => setAttributes( { autoplay: value } ) }
					/>
					{ autoplay && (
						<RangeControl
							__nextHasNoMarginBottom
							label={ __( 'Seconds per image' ) }
							value={ delay }
							min={ 1 }
							max={ 15 }
							onChange={ ( value ) => setAttributes( { delay: value } ) }
						/>
					) }
					<RangeControl
						__nextHasNoMarginBottom
						label={ __( 'Height offset (px)' ) }
						help={ __( 'Images are never taller than the window minus this much, leaving room for the header.' ) }
						value={ maxHeightOffset }
						min={ 0 }
						max={ 400 }
						onChange={ ( value ) => setAttributes( { maxHeightOffset: value } ) }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Image size' ) }
						value={ sizeSlug }
						options={ imageSizes.map( ( size ) => ( { value: size.slug, label: size.name } ) ) }
						onChange={ ( value ) => setAttributes( { sizeSlug: value } ) }
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Show captions' ) }
						checked={ showCaptions }
						onChange={ ( value ) => setAttributes( { showCaptions: value } ) }
					/>
					{ showCaptions && (
						<>
							<SelectControl
								__nextHasNoMarginBottom
								label={ __( 'Caption position' ) }
								help={ __( 'On phones, captions are always below the image.' ) }
								value={ captionPosition }
								options={ [
									{ value: 'below', label: __( 'Below' ) },
									{ value: 'left', label: __( 'Left' ) },
									{ value: 'right', label: __( 'Right' ) },
								] }
								onChange={ ( value ) => setAttributes( { captionPosition: value } ) }
							/>
							<RangeControl
								__nextHasNoMarginBottom
								label={ __( 'Caption padding (px)' ) }
								value={ captionPadding }
								min={ 0 }
								max={ 100 }
								onChange={ ( value ) => setAttributes( { captionPadding: value ?? 0 } ) }
							/>
						</>
					) }
				</PanelBody>
				{ ! hideGallery && <PanelBody title={ __( 'Gallery link' ) }>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Show clicked gallery images here' ) }
						help={ __( 'Clicking an image in the gallery shows it in this slideshow and scrolls to it, with an arrow to go back.' ) }
						checked={ galleryNavigation }
						onChange={ ( value ) => setAttributes( { galleryNavigation: value } ) }
					/>
					{ galleryNavigation && (
						<SelectControl
							__nextHasNoMarginBottom
							label={ __( 'Scroll to the slideshow' ) }
							value={ scrollBehavior }
							options={ [ { value: 'instant', label: __( 'Instantly' ) }, { value: 'smooth', label: __( 'Smoothly' ) } ] }
							onChange={ ( value ) => setAttributes( { scrollBehavior: value } ) }
						/>
					) }
				</PanelBody> }
			</InspectorControls>
			<div { ...blockProps }>{ preview }</div>
		</>
	);
}
