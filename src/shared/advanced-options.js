/**
 * An Advanced options link that reveals an option, and the option when it is
 * open. Closing it turns the option off, so nothing hidden stays on.
 */
import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export function useAdvanced( onClose ) {
	const [ open, setOpen ] = useState( false );

	const link = (
		<Button
			variant="link"
			className="photopress-advanced-link"
			aria-expanded={ open }
			onClick={ () => {
				if ( open ) {
					onClose();
				}
				setOpen( ! open );
			} }
		>
			{ open ? __( 'Hide advanced options' ) : __( 'Advanced options' ) }
		</Button>
	);

	return { open, link };
}
