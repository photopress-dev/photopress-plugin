/**
 * WordPress dependencies
 */
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { Button, CheckboxControl, Flex, FlexItem, PanelBody, Placeholder, Spinner, ToggleControl } from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { chevronDown, chevronUp, tag } from '@wordpress/icons';
import ServerSideRender from '@wordpress/server-side-render';

/**
 * Internal dependencies
 */
import metadata from '../../../modules/metadata/blocks/image-taxonomies/block.json';

function TaxonomyList( { available, selected, onChange } ) {
	// The chosen taxonomies first, in their display order, then the rest.
	const ordered = [
		...selected.map( ( slug ) => available.find( ( t ) => t.slug === slug ) ).filter( Boolean ),
		...available.filter( ( t ) => ! selected.includes( t.slug ) ),
	];

	const move = ( index, by ) => {
		const next = [ ...selected ];
		[ next[ index ], next[ index + by ] ] = [ next[ index + by ], next[ index ] ];
		onChange( next );
	};

	return ordered.map( ( taxonomy ) => {
		const index = selected.indexOf( taxonomy.slug );

		return (
			<Flex key={ taxonomy.slug } align="center">
				<FlexItem isBlock>
					<CheckboxControl
						__nextHasNoMarginBottom
						label={ taxonomy.name }
						checked={ index !== -1 }
						onChange={ ( checked ) =>
							onChange( checked ? [ ...selected, taxonomy.slug ] : selected.filter( ( slug ) => slug !== taxonomy.slug ) )
						}
					/>
				</FlexItem>
				{ index !== -1 && (
					<FlexItem>
						<Button size="small" icon={ chevronUp } label={ __( 'Move up' ) } disabled={ index === 0 } onClick={ () => move( index, -1 ) } />
						<Button size="small" icon={ chevronDown } label={ __( 'Move down' ) } disabled={ index === selected.length - 1 } onClick={ () => move( index, 1 ) } />
					</FlexItem>
				) }
			</Flex>
		);
	} );
}

export default function Edit( { attributes, setAttributes, context } ) {
	const { taxonomies, linkTerms, showLabels } = attributes;
	const blockProps = useBlockProps();

	// Readable over REST by users who can edit posts (ImageTaxonomyRest).
	const available = useSelect(
		( select ) => select( coreStore ).getTaxonomies( { type: 'attachment', per_page: -1, context: 'view' } ),
		[]
	);

	// Taxonomies saved in the block but no longer registered stay listed so
	// they can be removed; the server skips them.
	const missing = available ? taxonomies.filter( ( slug ) => ! available.some( ( t ) => t.slug === slug ) ) : [];

	const preview = context.postId ? (
		<ServerSideRender
			block={ metadata.name }
			attributes={ attributes }
			urlQueryArgs={ { post_id: context.postId } }
			EmptyResponsePlaceholder={ () => <p>{ __( 'This image has no terms in the chosen taxonomies.' ) }</p> }
		/>
	) : (
		<Placeholder
			icon={ tag }
			label={ metadata.title }
			instructions={ __( 'Shows the taxonomy terms of the current image, on image (attachment) pages and in their templates.' ) }
		/>
	);

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Taxonomies' ) }>
					<p className="components-base-control__help">{ __( 'Choose which to show, in order. None chosen shows all.' ) }</p>
					{ available ? (
						<TaxonomyList
							available={ [ ...available, ...missing.map( ( slug ) => ( { slug, name: slug } ) ) ] }
							selected={ taxonomies }
							onChange={ ( next ) => setAttributes( { taxonomies: next } ) }
						/>
					) : (
						<Spinner />
					) }
				</PanelBody>
				<PanelBody title={ __( 'Display' ) }>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Link terms' ) }
						help={ __( 'Each term links to the images with that term.' ) }
						checked={ linkTerms }
						onChange={ ( value ) => setAttributes( { linkTerms: value } ) }
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Show labels' ) }
						help={ __( 'Show the taxonomy name before its terms.' ) }
						checked={ showLabels }
						onChange={ ( value ) => setAttributes( { showLabels: value } ) }
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>{ preview }</div>
		</>
	);
}
