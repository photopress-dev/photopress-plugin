/**
 * WordPress dependencies
 */
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, Placeholder, RangeControl, TextControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { search as icon } from '@wordpress/icons';

export default function Edit( { attributes, setAttributes } ) {
	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Settings' ) }>
					<TextControl __nextHasNoMarginBottom label={ __( 'Heading' ) } value={ attributes.heading } onChange={ ( value ) => setAttributes( { heading: value } ) } />
					<RangeControl __nextHasNoMarginBottom label={ __( 'Number shown' ) } value={ attributes.count } min={ 1 } max={ 20 } onChange={ ( value ) => setAttributes( { count: value } ) } />
				</PanelBody>
			</InspectorControls>
			<div { ...useBlockProps() }>
				<Placeholder
					icon={ icon }
					label={ __( 'Search Posts' ) }
					/* translators: %d: a number of posts and pages. */
					instructions={ sprintf( __( 'On a search with images, the first %d posts and pages found, with a link to all of them. Shown on the site.' ), attributes.count ) }
				/>
			</div>
		</>
	);
}
