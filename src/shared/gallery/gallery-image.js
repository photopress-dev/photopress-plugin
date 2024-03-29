/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import { Component, Fragment } from '@wordpress/element';
import { Button, Spinner, ButtonGroup } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { BACKSPACE, DELETE } from '@wordpress/keycodes';
import { withSelect, withDispatch } from '@wordpress/data';
import { RichText, MediaPlaceholder } from '@wordpress/block-editor';
import { isBlobURL } from '@wordpress/blob';
import { compose } from '@wordpress/compose';
import { close, chevronLeft, chevronRight, edit, image as imageIcon } from '@wordpress/icons';

import { pickRelevantMediaFiles } from '../../shared/shared.js';

const isTemporaryImage = ( id, url ) => ! id && isBlobURL( url );

class GalleryImage extends Component {
	
	constructor() {
	
		super( ...arguments );

		//this.onBlur = this.onBlur.bind( this );
		//this.onFocus = this.onFocus.bind( this );
		this.onSelectImage = this.onSelectImage.bind( this );
		this.onSelectCaption = this.onSelectCaption.bind( this );
		this.onRemoveImage = this.onRemoveImage.bind( this );
		this.bindContainer = this.bindContainer.bind( this );
		this.onEdit = this.onEdit.bind( this );
		this.onSelectImageFromLibrary = this.onSelectImageFromLibrary.bind( this );
		this.onSelectCustomURL = this.onSelectCustomURL.bind( this );

		this.state = {
			captionSelected: false,
			isEditing: false
		};
	}

	bindContainer( ref ) {
		this.container = ref;
	}

	onSelectCaption() {
		if ( ! this.state.captionSelected ) {
			this.setState( {
				captionSelected: true,
			} );
		}

		if ( ! this.props.isSelected ) {
			this.props.onSelect();
		}
	}

	onSelectImage() {
		if ( ! this.props.isSelected ) {
			this.props.onSelect();
		}

		if ( this.state.captionSelected ) {
			this.setState( {
				captionSelected: false,
			} );
		}
	}

	onRemoveImage( event ) {
		if (
			this.container === document.activeElement &&
			this.props.isSelected &&
			[ BACKSPACE, DELETE ].indexOf( event.keyCode ) !== -1
		) {
			event.stopPropagation();
			event.preventDefault();
			this.props.onRemove();
		}
	}
	
	onEdit() {
		this.setState( {
			isEditing: true,
		} );
	}

	componentDidUpdate( prevProps ) {
		
		const {
			isSelected,
			image,
			url,
			__unstableMarkNextChangeAsNotPersistent,
		} = this.props;
		if ( image && ! url ) {
			__unstableMarkNextChangeAsNotPersistent();
			this.props.setAttributes( {
				url: image.source_url,
				alt: image.alt_text,
			} );
		}

		// unselect the caption so when the user selects other image and comeback
		// the caption is not immediately selected
		if (
			this.state.captionSelected &&
			! isSelected &&
			prevProps.isSelected
		) {
			this.setState( {
				captionSelected: false,
			} );
		}
	}
	
	deselectOnBlur() {
 		this.props.onDeselect();
 	}
 	
	
	onSelectImageFromLibrary( media ) {
		
		const { setAttributes, id, url, alt, caption, sizeSlug } = this.props;
	
		if ( ! media || ! media.url ) {
		
			return;
		}

		let mediaAttributes = pickRelevantMediaFiles( media, sizeSlug );

		// If the current image is temporary but an alt text was meanwhile
		// written by the user, make sure the text is not overwritten.
		if ( isTemporaryImage( id, url ) ) {
			if ( alt ) {
				mediaAttributes = omit( mediaAttributes, [ 'alt' ] );
			}
		}

		// If a caption text was meanwhile written by the user,
		// make sure the text is not overwritten by empty captions.
		if ( caption && ! get( mediaAttributes, [ 'caption' ] ) ) {
			mediaAttributes = omit( mediaAttributes, [ 'caption' ] );
		}

		setAttributes( mediaAttributes );
		this.setState( {
			isEditing: false,
		} );
	}

	onSelectCustomURL( newURL ) {
		const { setAttributes, url } = this.props;
		if ( newURL !== url ) {
			setAttributes( {
				url: newURL,
				id: undefined,
			} );
			this.setState( {
				isEditing: false,
			} );
		}
	}

	render() {
		
		const {
			url,
			alt,
			id,
			linkTo,
			link,
			aspectRatio,
			isFirstItem,
			isLastItem,
			isSelected,
			caption,
			onRemove,
			onMoveForward,
			onMoveBackward,
			attributes,
			setAttributes,
			'aria-label': ariaLabel,
			style
		} = this.props;
		
		const {
			
			showCaptions
			
		} = attributes;
		
		const { isEditing } = this.state;
		
		let href;

		switch ( linkTo ) {
			case 'media':
				href = url;
				break;
			case 'attachment':
				href = link;
				break;
		}

		const img = (
			// Disable reason: Image itself is not meant to be interactive, but should
			// direct image selection and unfocus caption fields.
			/* eslint-disable jsx-a11y/no-noninteractive-element-interactions */
			<Fragment>
				<img
					src={ url }
					alt={ alt }
					data-id={ id }
					onClick={ this.onSelectImage }
					onFocus={ this.onSelectImage }
					onKeyDown={ this.onRemoveImage }
					tabIndex="0"
					aria-label={ ariaLabel }
					ref={ this.bindContainer }
					style={ style }
				/>
				{ isBlobURL( url ) && <Spinner /> }
			</Fragment>
			/* eslint-enable jsx-a11y/no-noninteractive-element-interactions */
		);

		const className = classnames( {
			'is-selected': isSelected,
			'is-transient': isBlobURL( url ),
		}, 'photopress-gallery-item__figure' );
		
		let inlineStyle;
		
		switch ( attributes.galleryStyle ) {
			case 'rows':
				inlineStyle = {margin: 0};
				break;
			case 'columns':
				inlineStyle = {margin: 0};
				break;
			default:
				inlineStyle = {margin: 0};
				break;

		}

		return (
			<figure
				className={ className }
				style={ inlineStyle }

			>
				{  ! isEditing && ( href ? <a href={ href }>{ img }</a> : img ) }
				
				{ isEditing && (
					<MediaPlaceholder
						labels={ { title: __( 'Edit gallery image' ) } }
						icon={ imageIcon }
						onSelect={ this.onSelectImageFromLibrary }
						onSelectURL={ this.onSelectCustomURL }
						accept="image/*"
						allowedTypes={ [ 'image' ] }
						value={ { id, src: url } }
					/>
				) }
				
				<ButtonGroup className="photopress-gallery-item__inline-menu is-left">
					<Button
						icon={ chevronLeft }
						onClick={ isFirstItem ? undefined : onMoveBackward }
						className="photopress-gallery-item__move-backward"
						label={ __( 'Move image backward' ) }
						aria-disabled={ isFirstItem }
						disabled={ ! isSelected }
						
					/>
					<Button
						icon={ chevronRight }
						onClick={ isLastItem ? undefined : onMoveForward }
						className="photopress-gallery-item__move-forward"
						label={ __( 'Move image forward' ) }
						aria-disabled={ isLastItem }
						disabled={ ! isSelected }
					/>
				</ButtonGroup>
				
				<ButtonGroup className="photopress-gallery-item__inline-menu is-right">
					
					<Button
						icon={ edit }
						onClick={ this.onEdit }
						className="photopress-gallery-item__remove"
						label={ __( 'Replace image' ) }
						disabled={ ! isSelected }
					/>
					
					<Button
						icon={ close }
						onClick={ onRemove }
						className="photopress-gallery-item__remove"
						label={ __( 'Remove image' ) }
						disabled={ ! isSelected }
					/>
				</ButtonGroup>
				{ ( isSelected || showCaptions && caption ) && (
					<RichText
						tagName="figcaption"
						placeholder={
							isSelected ? __( 'Write caption…' ) : null
						}
						value={ caption }
						className= {'photopress-gallery-item__caption'}
						isSelected={ this.state.captionSelected }
						onChange={ ( newCaption ) =>
							setAttributes( { caption: newCaption } )
						}
						unstableOnFocus={ this.onSelectCaption }
						inlineToolbar
					/>
				) }
			</figure>
		);
	}
}

export default compose( [
	withSelect( ( select, ownProps ) => {
		const { getMedia } = select( 'core' );
		const { id } = ownProps;

		return {
			image: id ? getMedia( parseInt( id, 10 ) ) : null,
		};
	} ),
	withDispatch( ( dispatch ) => {
		const { __unstableMarkNextChangeAsNotPersistent } = dispatch(
			'core/block-editor'
		);
		return {
			__unstableMarkNextChangeAsNotPersistent,
		};
	} ),
] )( GalleryImage );
