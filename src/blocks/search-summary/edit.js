/**
 * WordPress dependencies
 */
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/** As the site shows it, with example terms and count. */
export default function Edit( { attributes, setAttributes } ) {
	const { showLabel, label, showCount } = attributes;

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Settings' ) }>
					<ToggleControl __nextHasNoMarginBottom label={ __( 'Show label' ) } checked={ showLabel } onChange={ ( value ) => setAttributes( { showLabel: value } ) } />
					{ showLabel && <TextControl __nextHasNoMarginBottom label={ __( 'Label' ) } value={ label } onChange={ ( value ) => setAttributes( { label: value } ) } /> }
					<ToggleControl __nextHasNoMarginBottom label={ __( 'Show number of images' ) } checked={ showCount } onChange={ ( value ) => setAttributes( { showCount: value } ) } />
				</PanelBody>
			</InspectorControls>
			<div { ...useBlockProps() }>
				{ showLabel && label && <p className="photopress-search-summary__label">{ label }</p> }
				<h1 className="photopress-search-summary__terms">{ __( 'Search terms' ) }</h1>
				{ showCount && <p className="photopress-search-summary__count">{ __( '(143 images.)' ) }</p> }
			</div>
		</>
	);
}
