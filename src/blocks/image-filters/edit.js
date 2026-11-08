/**
 * WordPress dependencies
 */
import { InspectorControls, useBlockProps, useInnerBlocksProps } from '@wordpress/block-editor';
import { PanelBody, ToggleControl } from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { filterTemplate } from './template';

const ALLOWED = [ 'photopress/image-filter' ];

/**
 * Its menus are Image Filter blocks, to move, remove and add as blocks. One
 * without any, as the block was first made, starts with a filter per
 * taxonomy shown but those it hid.
 */
export default function Edit( { attributes, setAttributes } ) {
	const { hiddenTaxonomies, showCounts } = attributes;
	const blockProps = useBlockProps();

	// Readable over REST by users who can edit posts (ImageTaxonomyRest).
	const taxonomies = useSelect(
		( select ) => select( coreStore ).getTaxonomies( { type: 'attachment', per_page: -1, context: 'view' } ),
		[]
	);

	// Undefined until the taxonomies load; the template is applied once
	// they have, while the block has no filters.
	const template = useMemo( () => taxonomies && filterTemplate( taxonomies, hiddenTaxonomies ), [ taxonomies, hiddenTaxonomies ] );

	const innerBlocksProps = useInnerBlocksProps(
		{ className: 'photopress-image-filters__menus' },
		{ allowedBlocks: ALLOWED, template, orientation: 'horizontal' }
	);

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Settings' ) }>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Show counts' ) }
						help={ __( 'How many of the images found have each term.' ) }
						checked={ showCounts }
						onChange={ ( value ) => setAttributes( { showCounts: value } ) }
					/>
				</PanelBody>
			</InspectorControls>
			<nav { ...blockProps } aria-label={ __( 'Filter the images found' ) }>
				<div { ...innerBlocksProps } />
			</nav>
		</>
	);
}
