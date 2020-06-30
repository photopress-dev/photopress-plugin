/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import { RichText } from '@wordpress/block-editor';

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
		bottomGutter,
		showCaptions
		
	} = attributes;
	

	// gallery style classes
	var galleryClasses = [];
	
	const styleClasses = {
		
		columns: 'photopress-gallery-columns',
		rows:	 'photopress-gallery-rows',
		masonry: 'photopress-gallery-masonry'
	};
	
	galleryClasses.push( styleClasses[ galleryStyle ] );
	
	if ( galleryStyle === 'columns' ) {
		
		galleryClasses.push( `columns-${ columns }` );
		
		galleryClasses.push( { "is-cropped": imageCrop } );
	}
	
	// item style for setting gutter attribute.
	let itemStyle = {};
	
	if ( galleryStyle === 'columns' || 'rows' ) {
		
		itemStyle = {"--pp-gallery-gutter": gutter + 'px'};
	}
	
	// setup masonry sizers elements.
	let masonryGridSizer;
	let masonryGutterSizer;
	let galleryItemStyle;
	if ( galleryStyle === 'masonry' ) {
		
		galleryItemStyle = {width: columnWidth + "px", marginBottom: bottomGutter + 'px' }
		
		masonryGridSizer = (
			
			<li className="grid-sizer" style={ {width: columnWidth + "px"} } ></li>
		);
		
		masonryGutterSizer = (
			
			<li className="gutter-sizer" style={ {width: gutter + "px"} } ></li>
		);
		
	}
	
	return (
		<figure
			className={ 'photopress-gallery' }
		>
			<ul 
				className={ classnames( galleryClasses ) } 
				style={ itemStyle }
			>
			
			{ masonryGridSizer }
			{ masonryGutterSizer }
			
				{ images.map( ( image ) => {
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
							data-id={ image.id }
							data-full-url={ image.fullUrl }
							data-link={ image.link }
							className={
								image.id ? `photopress-image-${ image.id }` : null
							}
						/>
					);

					return (
						<li
							key={ image.id || image.url }
							className="photopress-gallery-item"
							style={ galleryItemStyle }
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
			</ul>

		</figure>
	);
}
