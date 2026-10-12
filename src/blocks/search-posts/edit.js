/**
 * WordPress dependencies
 */
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, Placeholder, RangeControl, TextControl, ToggleControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { search as icon } from '@wordpress/icons';

export default function Edit( { attributes, setAttributes } ) {
	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Settings' ) }>
					<ToggleControl __nextHasNoMarginBottom label={ __( 'Show heading' ) } help={ __( 'Above the posts and pages, when there are some.' ) } checked={ attributes.showHeading } onChange={ ( value ) => setAttributes( { showHeading: value } ) } />
					{ attributes.showHeading && (
						<TextControl __nextHasNoMarginBottom label={ __( 'Heading' ) } value={ attributes.heading } onChange={ ( value ) => setAttributes( { heading: value } ) } />
					) }
					<RangeControl __nextHasNoMarginBottom label={ __( 'Number shown' ) } value={ attributes.count } min={ 1 } max={ 20 } onChange={ ( value ) => setAttributes( { count: value } ) } />
				</PanelBody>
			</InspectorControls>
			<div { ...useBlockProps() }>
				{ attributes.showHeading && attributes.heading && <h2 className="wp-block-heading">{ attributes.heading }</h2> }
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
