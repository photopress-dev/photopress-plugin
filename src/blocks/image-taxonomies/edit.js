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

// ImageTaxonomies::exifFields(): rows from the exposure details WordPress
// read from the image's file.
export const EXIF_FIELDS = [
	{ slug: 'exif:camera', name: __( 'Camera model' ) },
	{ slug: 'exif:aperture', name: __( 'Aperture' ) },
	{ slug: 'exif:shutter_speed', name: __( 'Shutter speed' ) },
	{ slug: 'exif:iso', name: __( 'ISO' ) },
	{ slug: 'exif:focal_length', name: __( 'Focal length' ) },
	{ slug: 'exif:created_timestamp', name: __( 'Date taken' ) },
];

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
	// Those for the admin only are never shown to visitors, so not offered.
	const taxonomiesOf = useSelect(
		( select ) => select( coreStore ).getTaxonomies( { type: 'attachment', per_page: -1, context: 'view' } ),
		[]
	);
	const available = taxonomiesOf && taxonomiesOf.filter( ( t ) => false !== t.photopress_public );

	// Taxonomies saved in the block but no longer registered or public stay
	// listed so they can be removed; the server skips them.
	const known = available ? [ ...available, ...EXIF_FIELDS ] : [];
	const missing = available ? taxonomies.filter( ( slug ) => ! known.some( ( t ) => t.slug === slug ) ) : [];

	const preview = context.postId ? (
		<ServerSideRender
			block={ metadata.name }
			attributes={ attributes }
			urlQueryArgs={ { post_id: context.postId } }
			EmptyResponsePlaceholder={ () => <p>{ __( 'This image has nothing to show in the chosen rows.' ) }</p> }
		/>
	) : (
		<Placeholder
			icon={ tag }
			label={ metadata.title }
			instructions={ __( 'Shows the taxonomy terms and exposure details of the current image, on image (attachment) pages and in their templates.' ) }
		/>
	);

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Rows' ) }>
					<p className="components-base-control__help">{ __( 'Choose which to show, in order: taxonomies, then exposure details from the file. None chosen shows every taxonomy.' ) }</p>
					{ available ? (
						<TaxonomyList
							available={ [ ...known, ...missing.map( ( slug ) => ( { slug, name: slug } ) ) ] }
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
						help={ __( 'Show each row’s name before its terms or value.' ) }
						checked={ showLabels }
						onChange={ ( value ) => setAttributes( { showLabels: value } ) }
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>{ preview }</div>
		</>
	);
}
