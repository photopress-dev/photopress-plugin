/**
 * WordPress dependencies
 */
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, Placeholder, SelectControl, ToggleControl } from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { image as icon } from '@wordpress/icons';

export const SIZES = [
	{ value: 'medium', label: __( 'Medium' ) },
	{ value: 'large', label: __( 'Large' ) },
	{ value: 'full', label: __( 'Full size' ) },
];

export default function Edit( { attributes, setAttributes, context } ) {
	const { sizeSlug, linkToFile, showCaption } = attributes;
	const blockProps = useBlockProps();

	// The image, when the editor has one: an image page, not a template.
	const media = useSelect(
		( select ) => ( 'attachment' === context.postType && context.postId ? select( coreStore ).getMedia( context.postId, { context: 'view' } ) : null ),
		[ context.postType, context.postId ]
	);
	const src = media && ( media.media_details?.sizes?.[ sizeSlug ]?.source_url || media.source_url );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Settings' ) }>
					<SelectControl __nextHasNoMarginBottom label={ __( 'Image size' ) } value={ sizeSlug } options={ SIZES } onChange={ ( value ) => setAttributes( { sizeSlug: value } ) } />
					<ToggleControl __nextHasNoMarginBottom label={ __( 'Link to the image file' ) } checked={ linkToFile } onChange={ ( value ) => setAttributes( { linkToFile: value } ) } />
					<ToggleControl __nextHasNoMarginBottom label={ __( 'Caption' ) } checked={ showCaption } onChange={ ( value ) => setAttributes( { showCaption: value } ) } />
				</PanelBody>
			</InspectorControls>
			<figure { ...blockProps }>
				{ src ? (
					<img src={ src } alt={ media.alt_text } />
				) : (
					<Placeholder icon={ icon } label={ __( 'Attachment Image' ) } instructions={ __( 'The image of an image (attachment) page, with its caption. Shown on the site.' ) } />
				) }
			</figure>
		</>
	);
}
