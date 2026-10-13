/**
 * Stands in for @wordpress/block-editor in unit tests (see vitest.config.mjs).
 * The real package pulls in most of the editor; the block save() functions
 * only use RichText.Content and RichText.isEmpty, implemented here as the real one does for
 * string values, and useBlockProps.save(), which is @wordpress/blocks' own.
 */

/**
 * WordPress dependencies
 */
import { createElement, RawHTML } from '@wordpress/element';
import * as blocks from '@wordpress/blocks';

const isEmpty = ( value ) => ! value || value.length === 0;

const Content = ( { value, tagName: Tag, multiline, format, ...props } ) => {
	const inner = isEmpty( value ) ? null : createElement( RawHTML, null, value );

	return Tag ? createElement( Tag, props, inner ) : inner;
};

export const RichText = { Content, isEmpty };

// What the real useBlockProps.save() is; looked up when called, as tests that
// mock @wordpress/blocks leave it out.
export const useBlockProps = { save: ( props ) => blocks.__unstableGetBlockProps( props ) };
