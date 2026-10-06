/**
 * The block supports that @wordpress/block-editor registers and that change
 * saved markup: the generated wp-block-* class, the align attribute and its
 * class, and the custom className. The block-editor stub leaves them out, so
 * tests that serialize or validate blocks import this file first. Each mirrors
 * the hook of the same purpose in block-editor's src/hooks/.
 */

/**
 * WordPress dependencies
 */
import { addFilter } from '@wordpress/hooks';
import { getBlockDefaultClassName, getBlockSupport, hasBlockSupport } from '@wordpress/blocks';

const join = ( ...names ) => [ ...new Set( names.join( ' ' ).split( ' ' ).filter( Boolean ) ) ].join( ' ' );

function validAlignments( blockType ) {
	const supported = getBlockSupport( blockType, 'align' );

	if ( supported === true ) {
		return [ 'left', 'center', 'right', 'wide', 'full' ];
	}

	return Array.isArray( supported ) ? supported : [];
}

// hooks/align.js and hooks/custom-class-name.js: the attributes.
addFilter( 'blocks.registerBlockType', 'photopress-tests/supports/attributes', ( settings ) => {
	const attributes = { ...settings.attributes };

	if ( validAlignments( settings ).length && ! attributes.align ) {
		attributes.align = { type: 'string', enum: [ ...validAlignments( settings ), '' ] };
	}

	if ( hasBlockSupport( settings, 'customClassName', true ) && ! attributes.className ) {
		attributes.className = { type: 'string' };
	}

	return { ...settings, attributes };
} );

// hooks/generated-class-name.js
addFilter( 'blocks.getSaveContent.extraProps', 'photopress-tests/supports/generated-class', ( props, blockType ) =>
	hasBlockSupport( blockType, 'className', true )
		? { ...props, className: join( getBlockDefaultClassName( blockType.name ), props.className || '' ) }
		: props
);

// hooks/align.js
addFilter( 'blocks.getSaveContent.extraProps', 'photopress-tests/supports/align', ( props, blockType, attributes ) =>
	validAlignments( blockType ).includes( attributes.align )
		? { ...props, className: join( `align${ attributes.align }`, props.className || '' ) }
		: props
);

// hooks/custom-class-name.js
addFilter( 'blocks.getSaveContent.extraProps', 'photopress-tests/supports/custom-class', ( props, blockType, attributes ) =>
	hasBlockSupport( blockType, 'customClassName', true ) && attributes.className
		? { ...props, className: join( props.className || '', attributes.className ) }
		: props
);
