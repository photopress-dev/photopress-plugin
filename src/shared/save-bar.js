/**
 * Save, and when a change affects images already uploaded, Save and
 * reprocess, as every settings section that can reprocess images shows them.
 *
 * reprocess: { label, request, missing, terms }, or null. With missing (what
 * a file may not have) and terms (what of an image's would be emptied), a
 * checkbox asks whether to leave images whose files have none unchanged.
 * onSave( reprocess ): reprocess is the request with skip, or undefined.
 */
import { useState } from '@wordpress/element';
import { Button, CheckboxControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

export default function SaveBar( { onSave, onCancel, saving, saveLabel = __( 'Save' ), reprocess } ) {
	const [ skip, setSkip ] = useState( true );

	return (
		<div className="photopress-savebar">
			{ reprocess && reprocess.missing && (
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ sprintf( __( 'Don’t change images whose files have no %s' ), reprocess.missing ) }
					help={ skip
						? sprintf( __( 'Each image’s file is read again. An image whose file has no %1$s keeps its %2$s as they are, in case the metadata was stripped from the file.' ), reprocess.missing, reprocess.terms )
						: sprintf( __( 'Each image’s file is read again. An image whose file has no %1$s has its %2$s emptied.' ), reprocess.missing, reprocess.terms ) }
					checked={ skip }
					onChange={ setSkip }
				/>
			) }
			<p className="photopress-savebar__buttons">
				<Button variant={ reprocess ? 'secondary' : 'primary' } onClick={ () => onSave() } disabled={ saving }>{ saveLabel }</Button>
				{ reprocess && (
					<Button variant="primary" onClick={ () => onSave( { ...reprocess.request, skip } ) } disabled={ saving }>
						{ reprocess.label }
					</Button>
				) }
				{ onCancel && <Button variant="tertiary" onClick={ onCancel }>{ __( 'Cancel' ) }</Button> }
			</p>
		</div>
	);
}
