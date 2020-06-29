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
 * Main Gallery Class
 */
class ColumnsGallery extends Component {
	
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
		
		return (
			
			<figure className={'photopress-gallery'}>
			
				<ul 
					className={ classnames( 'photopress-gallery-columns', {
						[ `align${ align }` ]: align,
						[ `columns-${ columns }` ]: columns,
						'is-cropped': imageCrop,
					} ) }
					style={ {padding: '0', margin: '0', "--pp-gallery-gutter": gutter + 'px'} } 
				>
					
					
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
		
								
				</ul>
				
				<div>
			
					{ mediaPlaceholder }
			
				</div>	
				
			</figure>	
		);
	}
}

export default ColumnsGallery;
