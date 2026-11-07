/**
 * WordPress dependencies
 */
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { CheckboxControl, PanelBody, Spinner, ToggleControl } from '@wordpress/components';
import { store as coreStore } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

/**
 * As the site shows it, a menu per taxonomy shown, closed: the terms come
 * from the images a search finds, so there are none to show here.
 */
export default function Edit( { attributes, setAttributes } ) {
	const { hiddenTaxonomies, showCounts } = attributes;
	const blockProps = useBlockProps();

	// Readable over REST by users who can edit posts (ImageTaxonomyRest).
	// Those for the admin only are never shown to visitors, so not offered.
	const taxonomies = useSelect(
		( select ) => select( coreStore ).getTaxonomies( { type: 'attachment', per_page: -1, context: 'view' } ),
		[]
	);
	const available = taxonomies && taxonomies.filter( ( t ) => false !== t.photopress_public );
	const shown = available && available.filter( ( t ) => ! hiddenTaxonomies.includes( t.slug ) );

	const toggle = ( slug, on ) =>
		setAttributes( {
			hiddenTaxonomies: on ? hiddenTaxonomies.filter( ( s ) => s !== slug ) : [ ...hiddenTaxonomies, slug ],
		} );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Taxonomies' ) }>
					{ ! available && <Spinner /> }
					{ available &&
						available.map( ( taxonomy ) => (
							<CheckboxControl
								__nextHasNoMarginBottom
								key={ taxonomy.slug }
								label={ taxonomy.name }
								checked={ ! hiddenTaxonomies.includes( taxonomy.slug ) }
								onChange={ ( on ) => toggle( taxonomy.slug, on ) }
							/>
						) ) }
				</PanelBody>
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
				{ ! shown && <Spinner /> }
				{ shown && (
					<div className="photopress-image-filters__menus">
						{ shown.map( ( taxonomy ) => (
							<details key={ taxonomy.slug } className="photopress-image-filters__menu">
								<summary onClick={ ( event ) => event.preventDefault() }>{ taxonomy.name }</summary>
							</details>
						) ) }
					</div>
				) }
			</nav>
		</>
	);
}
