/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * Internal dependencies
 */


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
		setAttributes,
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
		postsToShow,
	} = attributes;


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
	
	const maxColumns = ( postCount >= 10 ) ? 10 : postCount;
	
	const settings = (
		
		<PanelBody title={ __( 'Child pages settings', 'photopress' ) }>
			
			<Fragment>
			
				<RangeControl
					label={ __( 'Columns' ) }
					value={ columns }
					onChange={ ( value ) => { setAttributes( { columns: value } ) } }
					min={ 1 }
					max={ maxColumns }
					step={ 1 }		
				/>
			
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
				
				
				
					<QueryControls
						order={ order }
						orderBy={ orderBy }
						onOrderChange={ ( value ) => setAttributes( { order: value } ) }
						onOrderByChange={ ( value ) => setAttributes( { orderBy: value } ) }
					/>
				
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