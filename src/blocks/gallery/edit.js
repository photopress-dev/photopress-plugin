/**
 * External dependencies
 */
import {
	every,
	filter,
	find,
	forEach,
	get,
	isEmpty,
	map,
	reduce,
	some,
	toString,
} from 'lodash';

/**
 * WordPress dependencies
 */
import { compose } from '@wordpress/compose';
import { MediaPlaceholder } from '@wordpress/block-editor';
import { Component, Platform, Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { getBlobByURL, isBlobURL, revokeBlobURL } from '@wordpress/blob';
import { withSelect } from '@wordpress/data';
import { withViewportMatch } from '@wordpress/viewport';
import { withNotices} from '@wordpress/components';

/**
 * Internal dependencies
 */
import { sharedIcon } from '../../shared/gallery/shared-icon';
import { defaultColumnsNumber, pickRelevantMediaFiles } from '../../shared/shared.js';
import MasonryGallery from './gallery-masonry.js';
import ColumnsGallery from './gallery-columns.js';
import RowsGallery from './gallery-rows.js';
import MosaicGallery from './gallery-mosaic.js';
import Inspector from './inspector';

const MAX_COLUMNS = 8;

const ALLOWED_MEDIA_TYPES = [ 'image' ];

const PLACEHOLDER_TEXT = Platform.select( {
	web: __(
		'Drag images, upload new ones or select files from your library.'
	),
	native: __( 'ADD MEDIA' ),
} );

const MOBILE_CONTROL_PROPS_RANGE_CONTROL = Platform.select( {
	
	web: {},
	native: { type: 'stepper' },
} );

class GalleryEdit extends Component {
	
	constructor() {
		super( ...arguments );

		this.onSelectImage = this.onSelectImage.bind( this );
		this.onSelectImages = this.onSelectImages.bind( this );
		this.onDeselectImage = this.onDeselectImage.bind( this );
		this.onMove = this.onMove.bind( this );
		this.onMoveForward = this.onMoveForward.bind( this );
		this.onMoveBackward = this.onMoveBackward.bind( this );
		this.onRemoveImage = this.onRemoveImage.bind( this );
		this.onUploadError = this.onUploadError.bind( this );
		this.setImageAttributes = this.setImageAttributes.bind( this );
		this.setAttributes = this.setAttributes.bind( this );
		this.onFocusGalleryCaption = this.onFocusGalleryCaption.bind( this );
		

		this.state = {
			selectedImage: null,
			attachmentCaptions: null,
		};
	}

	setAttributes( attributes ) {
		
		if ( attributes.ids ) {
			throw new Error(
				'The "ids" attribute should not be changed directly. It is managed automatically when "images" attribute changes'
			);
		}
	
		if ( attributes.images ) {
			attributes = {
				...attributes,
				// Unlike images[ n ].id which is a string, always ensure the
				// ids array contains numbers as per its attribute type.
				ids: map( attributes.images, ( { id } ) => parseInt( id, 10 ) ),
			};
		}

		this.props.setAttributes( attributes );
	}

	onSelectImage( index ) {
		return () => {
			if ( this.state.selectedImage !== index ) {
				this.setState( {
					selectedImage: index,
				} );
			}
		};
	}

	onDeselectImage( index ) {
		return () => {
			if ( this.state.selectedImage === index ) {
				this.setState( {
					selectedImage: null,
				} );
			}
		};
	}

	onMove( oldIndex, newIndex ) {
		const images = [ ...this.props.attributes.images ];
		images.splice( newIndex, 1, this.props.attributes.images[ oldIndex ] );
		images.splice( oldIndex, 1, this.props.attributes.images[ newIndex ] );
		this.setState( { selectedImage: newIndex } );
		this.setAttributes( { images } );
	}

	onMoveForward( oldIndex ) {
		return () => {
			if ( oldIndex === this.props.attributes.images.length - 1 ) {
				return;
			}
			this.onMove( oldIndex, oldIndex + 1 );
		};
	}

	onMoveBackward( oldIndex ) {
		return () => {
			if ( oldIndex === 0 ) {
				return;
			}
			this.onMove( oldIndex, oldIndex - 1 );
		};
	}

	onRemoveImage( index ) {
		return () => {
			const images = filter(
				this.props.attributes.images,
				( img, i ) => index !== i
			);
			const { columns } = this.props.attributes;
			this.setState( { selectedImage: null } );
			this.setAttributes( {
				images,
				columns: columns ? Math.min( images.length, columns ) : columns,
			} );
		};
	}

	selectCaption( newImage, images, attachmentCaptions ) {
		// The image id in both the images and attachmentCaptions arrays is a
		// string, so ensure comparison works correctly by converting the
		// newImage.id to a string.
		const newImageId = toString( newImage.id );
		const currentImage = find( images, { id: newImageId } );
		const currentImageCaption = currentImage
			? currentImage.caption
			: newImage.caption;

		if ( ! attachmentCaptions ) {
			return currentImageCaption;
		}

		const attachment = find( attachmentCaptions, {
			id: newImageId,
		} );

		// if the attachment caption is updated
		if ( attachment && attachment.caption !== newImage.caption ) {
			return newImage.caption;
		}

		return currentImageCaption;
	}
	
	selectAttachmentMeta( newImage, images, attachmentMeta, key ) {
		//console.log(attachmentMeta);
		// The image id in both the images and attachmentCaptions arrays is a
		// string, so ensure comparison works correctly by converting the
		// newImage.id to a string.
		const newImageId = toString( newImage.id );
		const currentImage = find( images, { id: newImageId } );
		
		const currentImageMeta = currentImage
			? currentImage[ key ]
			: '';
			
		return currentImageMeta;
	}

	onSelectImages( newImages ) {
		const { columns, images, sizeSlug } = this.props.attributes;
		const { attachmentCaptions } = this.state;
		
		this.setState( {
			attachmentCaptions: newImages.map( ( newImage ) => ( {
				// Store the attachmentCaption id as a string for consistency
				// with the type of the id in the images attribute.
				id: toString( newImage.id ),
				caption: newImage.caption,
			} ) ),
						
		} );
		
		this.setAttributes( {
			images: newImages.map( ( newImage ) => ( {
				
				...pickRelevantMediaFiles( newImage, sizeSlug ),
				caption: this.selectCaption(
					newImage,
					images,
					attachmentCaptions
				),
				// The id value is stored in a data attribute, so when the
				// block is parsed it's converted to a string. Converting
				// to a string here ensures it's type is consistent.
				id: toString( newImage.id ),
				// adding aspec ratio
				apsectRatio: newImage.aspectRatio
				
			} ) ),
			columns: columns ? Math.min( newImages.length, columns ) : columns,
		} );
		
		//console.log(this.props.attributes.images);
		
	}
	
	calcAspectRatio( image ) {
		
		if ( image.hasOwnProperty('sizes') ) {
			
			return Math.floor( (image.sizes.full.width / image.sizes.full.height) * 100) /100;	
			
		} else {
			
			
		}
	}

	onUploadError( message ) {
		const { noticeOperations } = this.props;
		noticeOperations.removeAllNotices();
		noticeOperations.createErrorNotice( message );
	}

	onFocusGalleryCaption() {
		this.setState( {
			selectedImage: null,
		} );
	}

	setImageAttributes( index, attributes ) {
		const {
			attributes: { images },
		} = this.props;
		const { setAttributes } = this;
		if ( ! images[ index ] ) {
			return;
		}
		setAttributes( {
			images: [
				...images.slice( 0, index ),
				{
					...images[ index ],
					...attributes,
				},
				...images.slice( index + 1 ),
			],
		} );
	}

	

	
	componentDidMount() {
		const { attributes, mediaUpload } = this.props;
		
		const { images } = attributes;
		
		if (
			Platform.OS === 'web' &&
			images &&
			images.length > 0 &&
			every( images, ( { url } ) => isBlobURL( url ) )
		) {
			const filesList = map( images, ( { url } ) => getBlobByURL( url ) );
			forEach( images, ( { url } ) => revokeBlobURL( url ) );
			mediaUpload( {
				filesList,
				onFileChange: this.onSelectImages,
				allowedTypes: [ 'image' ],
			} );
		}
	}

	componentDidUpdate( prevProps ) {
		// Deselect images when deselecting the block
		if ( ! this.props.isSelected && prevProps.isSelected ) {
			this.setState( {
				selectedImage: null,
				captionSelected: false,
			} );
		}
	}

	render() {
		
		const {
			attributes,
			className,
			isSelected,
			noticeUI,
			insertBlocksAfter,
		} = this.props;
		
		const {
			//columns = defaultColumnsNumber( attributes ),
			columns,
			imageCrop,
			images,
			linkTo,
			sizeSlug,
			galleryStyle,
			themeHorizontalMargin
		} = attributes;
	
		const hasImages = !! images.length;
		
		const mediaPlaceholder = (
			
			<MediaPlaceholder
				addToGallery={ hasImages }
				isAppender={ hasImages }
				className={ className }
				disableMediaButtons={ hasImages && ! isSelected }
				icon={ ! hasImages && sharedIcon }
				labels={ {
					title: ! hasImages && __( 'Gallery' ),
					instructions: ! hasImages && PLACEHOLDER_TEXT,
				} }
				onSelect={ this.onSelectImages }
				accept="image/*"
				allowedTypes={ ALLOWED_MEDIA_TYPES }
				multiple
				value={ hasImages ? images : undefined }
				onError={ this.onUploadError }
				notices={ hasImages ? undefined : noticeUI }
				onFocus={ this.props.onFocus }
			/>
		);
		
		// if not images yet selcted then show the media placeholder.
		if ( ! hasImages ) {
			return mediaPlaceholder;
		}
			
		// else return a gallery
		return (
			<Fragment>
				
				<Inspector
					{ ...this.props }
					setAttributes={ this.setAttributes }
				/>
				
				{ noticeUI }
				
				{ galleryStyle === 'masonry' &&
				<MasonryGallery
					{ ...this.props }
					selectedImage={ this.state.selectedImage }
					mediaPlaceholder={ mediaPlaceholder }
					onMoveBackward={ this.onMoveBackward }
					onMoveForward={ this.onMoveForward }
					onRemoveImage={ this.onRemoveImage }
					onSelectImage={ this.onSelectImage }
					onDeselectImage={ this.onDeselectImage }
					onSetImageAttributes={ this.setImageAttributes }
					onFocusGalleryCaption={ this.onFocusGalleryCaption }
					insertBlocksAfter={ insertBlocksAfter }
				/>
				}
				
				{ galleryStyle === 'columns' &&
				<ColumnsGallery
					{ ...this.props }
					selectedImage={ this.state.selectedImage }
					mediaPlaceholder={ mediaPlaceholder }
					onMoveBackward={ this.onMoveBackward }
					onMoveForward={ this.onMoveForward }
					onRemoveImage={ this.onRemoveImage }
					onSelectImage={ this.onSelectImage }
					onDeselectImage={ this.onDeselectImage }
					onSetImageAttributes={ this.setImageAttributes }
					onFocusGalleryCaption={ this.onFocusGalleryCaption }
					insertBlocksAfter={ insertBlocksAfter }
				/>
				}
				
				{ galleryStyle === 'rows' &&
				<RowsGallery
					{ ...this.props }
					selectedImage={ this.state.selectedImage }
					mediaPlaceholder={ mediaPlaceholder }
					onMoveBackward={ this.onMoveBackward }
					onMoveForward={ this.onMoveForward }
					onRemoveImage={ this.onRemoveImage }
					onSelectImage={ this.onSelectImage }
					onDeselectImage={ this.onDeselectImage }
					onSetImageAttributes={ this.setImageAttributes }
					onFocusGalleryCaption={ this.onFocusGalleryCaption }
					insertBlocksAfter={ insertBlocksAfter }
				/>
				}
				
				{ galleryStyle === 'mosaic' &&
				<MosaicGallery
					{ ...this.props }
					selectedImage={ this.state.selectedImage }
					mediaPlaceholder={ mediaPlaceholder }
					onMoveBackward={ this.onMoveBackward }
					onMoveForward={ this.onMoveForward }
					onRemoveImage={ this.onRemoveImage }
					onSelectImage={ this.onSelectImage }
					onDeselectImage={ this.onDeselectImage }
					onSetImageAttributes={ this.setImageAttributes }
					onFocusGalleryCaption={ this.onFocusGalleryCaption }
					insertBlocksAfter={ insertBlocksAfter }
				/>
				}
				
				
			</Fragment>
		);
	}
}

export default compose( [
	
	withSelect( ( select, { attributes: { ids }, isSelected } ) => {
		
		const { getMedia } = select( 'core' );
		const { getSettings } = select( 'core/block-editor' );
		const { imageSizes, mediaUpload } = getSettings();

		let resizedImages = {};
		let attachmentMeta = {};

		if ( isSelected ) {
			
			resizedImages = reduce(
				ids,
				( currentResizedImages, id ) => {
					if ( ! id ) {
						return currentResizedImages;
					}
					const image = getMedia( id );
					
					const sizes = reduce(
						imageSizes,
						( currentSizes, size ) => {
							const defaultUrl = get( image, [
								'sizes',
								size.slug,
								'url',
							] );
							const mediaDetailsUrl = get( image, [
								'media_details',
								'sizes',
								size.slug,
								'source_url',
							] );
							
							
							
							const mediaWidth = get( image, [
								'media_details', 'width'
							] );
							
							return {
								...currentSizes,
								[ size.slug ]: { 'src': defaultUrl || mediaDetailsUrl, 'width': mediaWidth }
							};
						},
						{}
					);
							
					return {
						...currentResizedImages,
						[ parseInt( id, 10 ) ]: sizes,
					};
				},
				{}
			);
		}
		

		return {
			imageSizes,
			mediaUpload,
			resizedImages,
		};
	} ),
	withNotices,
	withViewportMatch( { isNarrow: '< small' } ),
] )( GalleryEdit );
