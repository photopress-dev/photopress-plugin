/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * Internal dependencies
 */
import OptionSelectorControl from './option-select-control';
import gutterOptions from './gutter-options';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Fragment } from '@wordpress/element';
import { InspectorControls } from '@wordpress/block-editor';
import {
	ENTER,
	SPACE,
} from '@wordpress/keycodes';
import {
	PanelBody,
	QueryControls,
	RadioControl,
	RangeControl,
	ToggleControl,
} from '@wordpress/components';

const Inspector = ( props ) => {
	const {
		attributes,
		activeStyle,
		styleOptions,
		onUpdateStyle,
		setAttributes,
		onUserModifiedColumn,
		categoriesList,
		postCount,
		hasPosts,
		hasFeaturedImage,
	} = props;

	const {
		columns,
		padding,
		imageSize,
		order,
		orderBy,
		postFeedType,
		postsToShow,
	} = attributes;

	const sizeOptions = [
		
		{
			value: 'post-thumbnail',
			label: /* translators: abbreviation for small size */ __( 'S', 'photopress' ),
			tooltip: /* translators: label for small size option */ __( 'Small', 'photopress' ),
		},
		{
			value: 'medium',
			label: /* translators: abbreviation for medium size */ __( 'M', 'photopress' ),
			tooltip: /* translators: label for medium size option */ __( 'Medium', 'photopress' ),
		},
		{
			value: 'large',
			label: /* translators: abbreviation for large size */ __( 'L', 'photopress' ),
			tooltip: /* translators: label for large size option */ __( 'Large', 'photopress' ),
		},
	];

	const postsCountOnChange = ( selectedPosts ) => {
		
		const changedAttributes = { postsToShow: selectedPosts };
		
		if ( columns > selectedPosts || ( selectedPosts === 1 && columns !== 1 ) ) {
			
			Object.assign( changedAttributes, { columns: selectedPosts } );
		}
		
		setAttributes( changedAttributes );
	};
	
	const onUpdatePadding = ( value ) => {
		
		//updatePadding( value ) {
		
		setAttributes( { padding: value } );
	}
	
	const settings = (
		
		<PanelBody title={ __( 'Child pages settings', 'photopress' ) }>
			
			<Fragment>
			
				<RangeControl
					label={ __( 'Padding', 'photopress' ) }
					value={ padding }
					onChange={ ( value ) =>  {
						//setAttributes( { padding: value } ) } 
						onUpdatePadding( value ) }
					}
					min={ 1 }
					max={ 100 }
					required
				/>
				
				{ hasFeaturedImage &&
					<OptionSelectorControl
						label={ __( 'Thumbnail size', 'photopress' ) }
						options={ sizeOptions }
						currentOption={ imageSize }
						onChange={ ( newImageSize ) => setAttributes( { imageSize: newImageSize } ) }
					/>
				}
				
				{ postFeedType === 'internal' &&
					<QueryControls
						order={ order }
						orderBy={ orderBy }
						onOrderChange={ ( value ) => setAttributes( { order: value } ) }
						onOrderByChange={ ( value ) => setAttributes( { orderBy: value } ) }
					/>
				}
				<RangeControl
					label={ __( 'Max Number of Child Pages', 'photopress' ) }
					value={ postsToShow }
					onChange={ ( value ) => postsCountOnChange( value ) }
					min={ 1 }
					max={ 150 }
				/>
				

			</Fragment>
			
		</PanelBody>
	);

	return (
		
		<InspectorControls>
			
			{ hasPosts ? settings : null }
			
		</InspectorControls>
	);
};

export default Inspector;