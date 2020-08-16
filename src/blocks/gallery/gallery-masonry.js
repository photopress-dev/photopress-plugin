/**
 * External dependencies
 */
import classnames from 'classnames';
import Masonry from 'react-masonry-component';

/**
 * WordPress dependencies
 */
import { RichText } from '@wordpress/block-editor';
import { VisuallyHidden } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { createBlock } from '@wordpress/blocks';
import { Component, Fragment } from '@wordpress/element';

/**
 * Internal dependencies
 */
import GalleryImage from '../../shared/gallery/gallery-image.js';
import { defaultColumnsNumber } from '../../shared/shared.js';

/**
 * Masonry Gallery Class
 */
class MasonryGallery extends Component {
	
	constructor() {
		
		super( ...arguments );
	}
	
	render() {
	
		const {
			attributes,
			className,
			isSelected,
			setAttributes,
			selectedImage,
			mediaPlaceholder,
			onMoveBackward,
			onMoveForward,
			onRemoveImage,
			onSelectImage,
			onDeselectImage,
			onSetImageAttributes,
			onFocusGalleryCaption,
			insertBlocksAfter,
		} = this.props;
	
		const {
			align,
			columns = defaultColumnsNumber( attributes ),
			caption,
			imageCrop,
			images,
			gridSize,
			gutter,
			gutterMobile
		} = attributes;
			
		const masonryOptions = {
			
			columnWidth: '.grid-sizer',
			gutter: '.gutter-sizer',
			isFitWidth: true
			
		};
		
		const masonryClasses = classnames( 
		
			'photopress-gallery-masonry' 
		);
	
		return (
			
			<div>
			
				<figure className={'photopress-gallery'}>
				
					<Masonry
						ref={function(c) {this.masonry = this.masonry || c.masonry;}.bind(this)}
						elementType={ 'ul' }
						className={ masonryClasses }
						options={ masonryOptions }
						disableImagesLoaded={ false }
						updateOnEachImageLoad={ true }
						enableResizableChildren={ true }
						style={{opacity: 1}}
					>
					
					<li 
						className="grid-sizer" 
						style={ {width: attributes.columnWidth + "px"} } 
					></li>
					<li 
						className="gutter-sizer" 
						style={ {width: attributes.gutter + "px"} } 
					></li>
					
					{ images.map( ( img, index ) => {
								
								const ariaLabel = sprintf(
									/* translators: 1: the order number of the image. 2: the total number of images. */
									__( 'image %1$d of %2$d in gallery' ),
									index + 1,
									images.length
								);
								
								return (
									
									<li
										className="photopress-gallery-item"
										key={ img.id || img.url }
										style={ {width: attributes.columnWidth + "px", marginBottom: attributes.bottomGutter + 'px' } }
									>
				
										<GalleryImage
											{ ...this.props }
											url={ img.url }
											alt={ img.alt }
											id={ img.id }
											isFirstItem={ index === 0 }
											isLastItem={ index + 1 === images.length }
											isSelected={
												isSelected && selectedImage === index
											}
											onMoveBackward={ onMoveBackward( index ) }
											onMoveForward={ onMoveForward( index ) }
											onRemove={ onRemoveImage( index ) }
											onSelect={ onSelectImage( index ) }
											onDeselect={ onDeselectImage( index ) }
											setAttributes={ ( attrs ) =>
												onSetImageAttributes( index, attrs )
											}
											caption={ img.caption }
											aria-label={ ariaLabel }
										/>
				                </li>
							);
					} ) }
		
					</Masonry>
			
				</figure>
				
				<div>
			
					{ mediaPlaceholder }
			
				</div>	
				
			</div>	
		);
	}
}

export default MasonryGallery;
