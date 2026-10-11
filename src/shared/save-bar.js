/**
 * Save, and when a change affects images already uploaded, Save and
 * reprocess, as every settings section that can reprocess images shows them.
 *
 * reprocess: { label, request, missing, terms }, or null. With missing (what
 * a file may not have) and terms (what of an image's would be emptied), a
 * checkbox under Advanced options asks whether to empty them in images whose
 * files have none. onSave( reprocess ): reprocess is the request with skip (not
 * emptying them), or undefined.
 */
import { useState } from '@wordpress/element';
import { Button, CheckboxControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { useAdvanced } from './advanced-options.js';

export default function SaveBar( { onSave, onCancel, saving, saveLabel = __( 'Save' ), reprocess } ) {
	const [ empty, setEmpty ] = useState( false );
	const advanced = useAdvanced( () => setEmpty( false ) );
	const canEmpty = reprocess && reprocess.missing;

	return (
		<div className="photopress-savebar">
			<p className="photopress-savebar__buttons">
				<Button variant={ reprocess ? 'secondary' : 'primary' } onClick={ () => onSave() } disabled={ saving }>{ saveLabel }</Button>
				{ reprocess && (
					<Button variant="primary" onClick={ () => onSave( { ...reprocess.request, skip: ! empty } ) } disabled={ saving }>
						{ reprocess.label }
					</Button>
				) }
				{ canEmpty && advanced.link }
				{ onCancel && <Button variant="tertiary" className="photopress-savebar__cancel" onClick={ onCancel }>{ __( 'Cancel' ) }</Button> }
			</p>
			{ canEmpty && advanced.open && (
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ sprintf( __( 'Empty the %1$s of images whose files have no %2$s' ), reprocess.terms, reprocess.missing ) }
					help={ __( 'Unchecked, they keep what they have, in case the metadata was stripped from their files.' ) }
					checked={ empty }
					onChange={ setEmpty }
				/>
			) }
		</div>
	);
}
