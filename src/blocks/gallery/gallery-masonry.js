/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import { RichText } from '@wordpress/block-editor';
import { VisuallyHidden } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { createBlock } from '@wordpress/blocks';
import { Component, Fragment, createRef } from '@wordpress/element';

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

		this.listRef = createRef();
		this.scheduleLayout = this.scheduleLayout.bind( this );
	}

	/*
	 * Runs the same Masonry the front end uses (WordPress's bundled
	 * masonry-layout), taken from the editor iframe's own window. A copy
	 * bundled into this script runs in the parent window instead, where its
	 * `instanceof HTMLElement` check rejects every element in the iframe and
	 * the gallery collapses to 0 height. The PHP loads `masonry` into the
	 * iframe (framework/class-pp-framework.php).
	 */
	componentDidMount() {

		const list = this.listRef.current;
		const view = list.ownerDocument.defaultView;

		this.view = view;

		if ( ! view.Masonry ) {
			return;
		}

		// Keep in step with modules/gallery/assets/js/gallery-masonry.js.
		this.masonry = new view.Masonry( list, {
			itemSelector: '.photopress-gallery-item',
			transitionDuration: 0,
			percentPosition: false,
			columnWidth: '.grid-sizer',
			gutter: '.gutter-sizer',
			isFitWidth: true,
		} );

		// The front end lays out once on window load. In the editor images
		// arrive later and the canvas resizes, so lay out again whenever an
		// item or the available width changes.
		this.resizeObserver = new view.ResizeObserver( this.scheduleLayout );
		this.resizeObserver.observe( list.parentNode );
		this.observeItems();
	}

	componentDidUpdate() {

		if ( ! this.masonry ) {
			return;
		}

		// Images may have been added, removed or reordered.
		this.masonry.reloadItems();
		this.observeItems();
		this.scheduleLayout();
	}

	componentWillUnmount() {

		if ( ! this.masonry ) {
			return;
		}

		this.resizeObserver.disconnect();
		this.view.cancelAnimationFrame( this.layoutFrame );
		this.masonry.destroy();
	}

	observeItems() {

		// Observing an element twice is a no-op.
		this.listRef.current
			.querySelectorAll( '.photopress-gallery-item' )
			.forEach( ( item ) => this.resizeObserver.observe( item ) );
	}

	scheduleLayout() {

		if ( this.layoutFrame ) {
			return;
		}

		this.layoutFrame = this.view.requestAnimationFrame( () => {

			this.layoutFrame = null;
			this.masonry.layout();
		} );
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
			
		const masonryClasses = classnames( 
		
			'photopress-gallery-masonry' 
		);
	
		return (
			
			<div>
			
				<figure className={'photopress-gallery'}>
				
					<ul
						ref={ this.listRef }
						className={ masonryClasses }
						style={ { opacity: 1 } }
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
											style={ {width: attributes.columnWidth + "px" } }
										/>
				                </li>
							);
					} ) }
		
					</ul>
			
				</figure>
				
				<div>
			
					{ mediaPlaceholder }
			
				</div>	
				
			</div>	
		);
	}
}

export default MasonryGallery;
