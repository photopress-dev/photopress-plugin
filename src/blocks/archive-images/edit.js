/**
 * WordPress dependencies
 */
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, Placeholder, RangeControl, SelectControl, ToggleControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { gallery as icon } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { LAYOUTS } from '../../variations/gallery-layouts.js';
import { SIZES } from '../attachment-image/edit.js';

const LINKS = [
	{ value: 'attachment', label: __( 'Image page' ) },
	{ value: 'media', label: __( 'Image file' ) },
	{ value: 'none', label: __( 'None' ) },
];

export default function Edit( { attributes, setAttributes } ) {
	const { layout, columnWidth, rowHeight, sizeSlug, linkTo, slideshow, showCaptions } = attributes;
	const layoutName = LAYOUTS[ layout ] || __( 'Default (columns)' );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Layout' ) }>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Layout' ) }
						value={ layout }
						options={ [ { value: '', label: __( 'Default (columns)' ) }, ...Object.entries( LAYOUTS ).map( ( [ value, label ] ) => ( { value, label } ) ) ] }
						onChange={ ( value ) => setAttributes( { layout: value } ) }
					/>
					{ layout === 'masonry' && (
						<RangeControl __nextHasNoMarginBottom label={ __( 'Column width' ) } value={ columnWidth } min={ 100 } max={ 800 } onChange={ ( value ) => setAttributes( { columnWidth: value } ) } />
					) }
					{ ( layout === 'rows' || layout === 'mosaic' ) && (
						<RangeControl __nextHasNoMarginBottom label={ __( 'Row height' ) } value={ rowHeight } min={ 100 } max={ 800 } onChange={ ( value ) => setAttributes( { rowHeight: value } ) } />
					) }
					<SelectControl __nextHasNoMarginBottom label={ __( 'Image size' ) } value={ sizeSlug } options={ SIZES } onChange={ ( value ) => setAttributes( { sizeSlug: value } ) } />
				</PanelBody>
				<PanelBody title={ __( 'Images' ) }>
					<SelectControl __nextHasNoMarginBottom label={ __( 'Link to' ) } value={ linkTo } options={ LINKS } onChange={ ( value ) => setAttributes( { linkTo: value } ) } />
					<ToggleControl __nextHasNoMarginBottom label={ __( 'Full-screen slideshow' ) } help={ __( 'Clicking an image opens the slideshow, when Slideshows are on in the PhotoPress settings.' ) } checked={ slideshow } onChange={ ( value ) => setAttributes( { slideshow: value } ) } />
					<ToggleControl __nextHasNoMarginBottom label={ __( 'Captions' ) } checked={ showCaptions } onChange={ ( value ) => setAttributes( { showCaptions: value } ) } />
				</PanelBody>
			</InspectorControls>
			<div { ...useBlockProps() }>
				<Placeholder
					icon={ icon }
					label={ __( 'Archive Images' ) }
					/* translators: %s: a gallery layout, such as Masonry. */
					instructions={ sprintf( __( 'The images of the archive page being viewed, in the %s layout, with links to more pages of them. Shown on the site.' ), layoutName ) }
				/>
			</div>
		</>
	);
}
