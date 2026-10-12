/**
 * Another plugin PhotoPress works with, and whether it is active, installed
 * but not active, or not installed: "Name: ● Active".
 */
import { __ } from '@wordpress/i18n';

const STATES = {
	active: __( 'Active' ),
	inactive: __( 'Disabled' ),
	missing: __( 'Not installed' ),
};

export default function PluginState( { name, state } ) {
	return (
		<p className="photopress-plugin-state">
			{ name }:{ ' ' }
			<span className={ `photopress-plugin-state__value is-${ state }` }>{ STATES[ state ] || state }</span>
		</p>
	);
}
