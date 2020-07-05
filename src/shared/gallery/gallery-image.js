/**
 * External dependencies
 */
import classnames from 'classnames';
import { debounce } from 'lodash';

/**
 * WordPress dependencies
 */
import { Component, Fragment } from '@wordpress/element';
import { Button, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { BACKSPACE, DELETE } from '@wordpress/keycodes';
import { withSelect, withDispatch } from '@wordpress/data';
import { RichText } from '@wordpress/block-editor';
import { isBlobURL } from '@wordpress/blob';
import { compose } from '@wordpress/compose';
import { close, chevronLeft, chevronRight } from '@wordpress/icons';

class GalleryImage extends Component {
	constructor() {
		super( ...arguments );

		this.onBlur = this.onBlur.bind( this );
		this.onFocus = this.onFocus.bind( this );
		this.onSelectImage = this.onSelectImage.bind( this );
		this.onSelectCaption = this.onSelectCaption.bind( this );
		this.onRemoveImage = this.onRemoveImage.bind( this );
		this.bindContainer = this.bindContainer.bind( this );

		// The onDeselect prop is used to signal that the GalleryImage component
		// has lost focus. We want to call it when focus has been lost
		// by the figure element or any of its children but only if
		// the element that gained focus isn't any of them.
		//
		// debouncedOnSelect is scheduled every time a figure's children
		// is blurred and cancelled when any is focused. If none gain focus,
		// the call to onDeselect will be executed.
		//
		// onBlur / onFocus events are quick operations (<5ms apart in my testing),
		// so 50ms accounts for 10x lagging while feels responsive to the user.
		this.debouncedOnDeselect = debounce( this.props.onDeselect, 50 );

		this.state = {
			captionSelected: false,
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

	/**
	 * Note that, unlike the DOM, all React events bubble,
	 * so this will be called after the onBlur event of any figure's children.
	 */
	onBlur() {
		this.debouncedOnDeselect();
	}

	/**
	 * Note that, unlike the DOM, all React events bubble,
	 * so this will be called after the onBlur event of any figure's children.
	 */
	onFocus() {
		this.debouncedOnDeselect.cancel();
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
		} = this.props;
		
		const {
			
			showCaptions
			
		} = attributes;
		
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
				onBlur={ this.onBlur }
				onFocus={ this.onFocus }
				style={ inlineStyle }

			>
				{ href ? <a href={ href }>{ img }</a> : img }
				<div className="photopress-gallery-item__move-menu" >
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
				</div>
				<div className="photopress-gallery-item__inline-menu">
					<Button
						icon={ close }
						onClick={ onRemove }
						className="photopress-gallery-item__remove"
						label={ __( 'Remove image' ) }
						disabled={ ! isSelected }
					/>
				</div>
				{ ( isSelected || showCaptions && caption ) && (
					<RichText
						tagName="figcaption"
						placeholder={
							isSelected ? __( 'Write caption…' ) : null
						}
						value={ caption }
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
