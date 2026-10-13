/**
 * The block wrapper that block API version 2 and later expect from a block's
 * edit, for the legacy blocks, whose edit is a class component and cannot
 * call useBlockProps itself.
 */
import { useBlockProps } from '@wordpress/block-editor';

export default function withBlockProps( Edit ) {
	return function EditWithBlockProps( props ) {
		return (
			<div { ...useBlockProps() }>
				<Edit { ...props } />
			</div>
		);
	};
}
