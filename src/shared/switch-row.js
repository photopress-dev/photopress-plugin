/**
 * An on/off setting as a row: its label and help on the left, the switch in
 * the far right column, as every on/off on the settings pages is shown.
 */
import { FormToggle } from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';

export default function SwitchRow( { label, help, checked, onChange, disabled = false } ) {
	const id = 'photopress-switch-' + useInstanceId( SwitchRow );

	return (
		<div className="photopress-switch-row">
			<div className="photopress-switch-row__text">
				<label htmlFor={ id }>{ label }</label>
				{ help && <p id={ id + '-help' } className="photopress-switch-row__help">{ help }</p> }
			</div>
			<FormToggle
				id={ id }
				checked={ !! checked }
				disabled={ disabled }
				aria-describedby={ help ? id + '-help' : undefined }
				onChange={ ( event ) => onChange( event.target.checked ) }
			/>
		</div>
	);
}
