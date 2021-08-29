/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import { RichText } from '@wordpress/block-editor';
import { Fragment } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { defaultColumnsNumber } from '../../shared/shared.js';

export default function save( { attributes } ) {
	
	const {
		images,
		columns = defaultColumnsNumber( attributes ),
		imageCrop,
		caption,
		linkTo,
		galleryStyle,
		gutter,
		columnWidth,
		rowHeight,
		bottomGutter,
		showCaptions,
		themeHorizontalMargin
		
	} = attributes;
	
	// gallery style classes
	let galleryClasses = [];
	
	const styleClasses = {
		
		columns: 'photopress-gallery-columns',
		rows:	 'photopress-gallery-rows',
		masonry: 'photopress-gallery-masonry',
		mosaic:  'photopress-gallery-mosaic'
	};
	
	// choose a base class based on what style is chosen.
	galleryClasses.push( styleClasses[ galleryStyle ] );
	
	// Gallery level inline CSS
	let galleryInlineCss = {};
	
	// Item level inline CSS
	let itemInlineCss;
	
	// placeholder for adding elements to be the beginning of the item list.
	let preItemList;
	
	// placeholder for adding elements to be the end of the item list.
	let postItemList
	
	// uses to give a little wider image size for cropped images in the responsive images sizes calc
	const cropSizeFactor = imageCrop || galleryStyle === 'mosaic' ? 1.3 : 1;
	
	// set classes and variables for Column style.
	if ( galleryStyle === 'columns' ) {
		
		galleryClasses.push( `columns-${ columns }` );
		
		galleryClasses.push( { "is-cropped": imageCrop } );
		
		galleryInlineCss = {
			padding: '0', 
			margin: '0', 
			"--pp-gallery-gutter": gutter + 'px'
		};
	}
	
	// set classes and variables for Row and Mosaic styles.
	if ( galleryStyle === 'rows' || galleryStyle === 'mosaic' || galleryStyle === 'masonry' ) {
		
		galleryInlineCss = {
			//padding: '0', 
			//margin: '0', 
			"--pp-gallery-gutter": gutter + 'px', 
			"--pp-gallery-rowheight": rowHeight + 'px'
		};
	}

	// set classes and variables for Masonry style.
	if ( galleryStyle === 'masonry' ) {
		
		itemInlineCss = {width: columnWidth + "px", marginBottom: bottomGutter + 'px' }
		
		preItemList = (
			
			<Fragment>
			<li className="gutter-sizer" style={ {width: gutter + "px"} } ></li>
			<li className="grid-sizer" style={ {width: columnWidth + "px"} } ></li>
			</Fragment>
		);
	}
	
	if ( galleryStyle === 'mosaic' ) {
		
		postItemList = (
			
			<li className={"mosaic-spacer"} ></li>
		);

	}
	
	/**
	 * Calculates Image Crop Factor for use in <img> sizes attr.
	 *
	 * Calculates a crop factor is the image is a horizontal as it may be croped in 
	 * CSS using object-fit: cover.
	 */
	const calcCropSizeFactor = (aspectRatio) => {
		
		return  ( imageCrop || galleryStyle === ' mosaic') && aspectRatio > 1 ? 1.5 : 1;
	}
	
	/**
	 * Prepares <img> tag sizes attr css calculation 
	 *
	 * Dynamically calc the responsive image sizes attr based on image dimentions
	 */
	const getImageSizeCalc = (aspectRatio) => {
		
		let size;
		
		let cropSizeFactor = calcCropSizeFactor(aspectRatio);
		
		switch(galleryStyle) {
			
			case "mosaic":
				// calculate image width and apply crop factor.
				size = `${rowHeight}px * ${aspectRatio} * ${cropSizeFactor}`;
				break;
				
			case "rows":
			// calculate image width
				size = `${rowHeight}px * ${aspectRatio}`;
				break;
				
			case "masonry":
				// do nothing as we get an explicit width
				size = `${columnWidth}px`;
				break;
				
			case "columns":
				// calculate width based on column count and apply possible crop factor 
				size = `( (100vw - ${themeHorizontalMargin} ) / ${columns} ) * ${cropSizeFactor}`;
				break;
		}
		
		return size;
	};
	
	const getImageDimensions = (aspectRatio) => {
		
		let size = {
			
			width: null,
			height: null
		};
		
		let cropSizeFactor = calcCropSizeFactor(aspectRatio);
		
		switch(galleryStyle) {
			
			case "mosaic":
				// calculate image width and apply crop factor.
				size.width = Math.floor( rowHeight * aspectRatio * cropSizeFactor );
				size.height= rowHeight;
				break;
				
			case "rows":
			// calculate image width
				size.width = Math.floor( rowHeight * aspectRatio );
				size.height = rowHeight
				break;
				
			case "masonry":
				// do nothing as we get an explicit width
				size.width = columnWidth;
				size.height = Math.floor( columnWidth / aspectRatio );
				break;
				
			case "columns":
				// we can't determin these here as we do not know how wide each column will be.
				// Core will fill in some defaults for us before it renders the block.
				
				break;
		}
		
		return size;
	};
	
	
	
	return (
		
		<figure className={ 'photopress-gallery' } >
			
			<ul 
				className={ classnames( galleryClasses ) } 
				style={ galleryInlineCss }
			>
			
				{ preItemList }
			
				{ images.map( ( image, index ) => {
					let href;

					switch ( linkTo ) {
						case 'media':
							href = image.fullUrl || image.url;
							break;
						case 'attachment':
							href = image.link;
							break;
					}
					
					const img = (
						<img
							src={ image.url }
							alt={ image.alt }
							data-caption={image.caption}
							data-id={ image.id }
							data-aspectRatio={image.aspectRatio}
							data-position={index}
							data-full-url={ image.fullUrl }
							data-link={ image.link }
							className={ image.id ? `wp-image wp-image-${ image.id }` : null }
							sizes={'(max-width: 700px) 100vw, calc(' + getImageSizeCalc( image.aspectRatio ) +')'}
							width={ getImageDimensions( image.aspectRatio ).width }
							height={ getImageDimensions( image.aspectRatio ).height }
						/>
					);
					
					return (
						<li
							key={ image.id || image.url }
							className="photopress-gallery-item"
							style={ itemInlineCss }
							data-id={ image.id }
						>
							<figure className={ 'photopress-gallery-item__figure' }>
								{ href ? <a href={ href }>{ img }</a> : img }
								{ ! RichText.isEmpty( image.caption ) && showCaptions && (
									<RichText.Content
										tagName="figcaption"
										className="photopress-gallery-item__caption"
										value={ image.caption }
									/>
								) }
							</figure>
						</li>
					);
				} ) }
				
				{ postItemList }
				
			</ul>

		</figure>
	);
}
