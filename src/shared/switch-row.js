/**
 * An on/off option within a feature: the switch at the left, its label and
 * help beside it, as the feature's own switch is. (A switch turning a row of
 * a table on or off sits in the table's last column instead.)
 */
import { FormToggle } from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';

export default function SwitchRow( { label, help, checked, onChange, disabled = false } ) {
	const id = 'photopress-switch-' + useInstanceId( SwitchRow );

	return (
		<div className="photopress-switch-row">
			<FormToggle
				id={ id }
				checked={ !! checked }
				disabled={ disabled }
				aria-describedby={ help ? id + '-help' : undefined }
				onChange={ ( event ) => onChange( event.target.checked ) }
			/>
			<div className="photopress-switch-row__text">
				<label htmlFor={ id }>{ label }</label>
				{ help && <p id={ id + '-help' } className="photopress-switch-row__help">{ help }</p> }
			</div>
		</div>
	);
}
