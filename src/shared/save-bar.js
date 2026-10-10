/**
 * Save, and when a change affects images already uploaded, Save and
 * reprocess, as every settings section that can reprocess images shows them.
 *
 * reprocess: { label, request, missing, terms }, or null. With missing (what
 * a file may not have) and terms (what of an image's would be emptied), a
 * checkbox, unchecked, asks whether to empty them in images whose files have
 * none. onSave( reprocess ): reprocess is the request with skip (not
 * emptying them), or undefined.
 */
import { useState } from '@wordpress/element';
import { Button, CheckboxControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

export default function SaveBar( { onSave, onCancel, saving, saveLabel = __( 'Save' ), reprocess } ) {
	const [ empty, setEmpty ] = useState( false );

	return (
		<div className="photopress-savebar">
			{ reprocess && reprocess.missing && (
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ sprintf( __( 'Empty the %1$s of images whose files have no %2$s' ), reprocess.terms, reprocess.missing ) }
					help={ __( 'Unchecked, they keep what they have, in case the metadata was stripped from their files.' ) }
					checked={ empty }
					onChange={ setEmpty }
				/>
			) }
			<p className="photopress-savebar__buttons">
				<Button variant={ reprocess ? 'secondary' : 'primary' } onClick={ () => onSave() } disabled={ saving }>{ saveLabel }</Button>
				{ reprocess && (
					<Button variant="primary" onClick={ () => onSave( { ...reprocess.request, skip: ! empty } ) } disabled={ saving }>
						{ reprocess.label }
					</Button>
				) }
				{ onCancel && <Button variant="tertiary" onClick={ onCancel }>{ __( 'Cancel' ) }</Button> }
			</p>
		</div>
	);
}
