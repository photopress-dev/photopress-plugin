/**
 * WordPress dependencies
 */
import { InspectorControls, store as blockEditorStore, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, SelectControl, TextControl } from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { offeredTaxonomies } from '../image-filters/template';

/**
 * As the site shows it, closed: its terms come from the images a search
 * finds, so there are none to show here. Without a taxonomy, as the plain
 * block is inserted, it asks for one.
 */
export default function Edit( { attributes, setAttributes, clientId } ) {
	const { taxonomy, label } = attributes;
	const blockProps = useBlockProps( { className: 'photopress-image-filters__menu' } );

	const taxonomies = useSelect(
		( select ) => select( coreStore ).getTaxonomies( { type: 'attachment', per_page: -1, context: 'view' } ),
		[]
	);

	// The taxonomies its sibling filters have, not offered again.
	const taken = useSelect(
		( select ) => {
			const { getBlockRootClientId, getBlocks } = select( blockEditorStore );
			return getBlocks( getBlockRootClientId( clientId ) )
				.filter( ( block ) => block.clientId !== clientId )
				.map( ( block ) => block.attributes.taxonomy );
		},
		[ clientId ]
	);

	const offered = offeredTaxonomies( taxonomies );
	const current = offered.find( ( t ) => t.slug === taxonomy );
	const options = [
		{ value: '', label: __( 'Choose a taxonomy' ), disabled: true },
		...offered
			.filter( ( t ) => t.slug === taxonomy || ! taken.includes( t.slug ) )
			.map( ( t ) => ( { value: t.slug, label: t.name } ) ),
	];

	const choose = (
		<SelectControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ __( 'Taxonomy' ) }
			value={ taxonomy }
			options={ options }
			onChange={ ( value ) => setAttributes( { taxonomy: value } ) }
		/>
	);

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Settings' ) }>
					{ choose }
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Label' ) }
						help={ __( 'The menu’s name. Left empty, it is the taxonomy’s.' ) }
						placeholder={ current?.name }
						value={ label }
						onChange={ ( value ) => setAttributes( { label: value } ) }
					/>
				</PanelBody>
			</InspectorControls>
			{ taxonomy ? (
				<details { ...blockProps }>
					<summary onClick={ ( event ) => event.preventDefault() }>{ label || current?.name || taxonomy }</summary>
				</details>
			) : (
				<div { ...blockProps }>{ choose }</div>
			) }
		</>
	);
}
