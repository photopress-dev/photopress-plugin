/**
 * How a gallery shows its images' captions, by name; see CAPTION_STYLES in
 * modules/gallery/gallery.php. Shared by the Gallery block's controls and
 * the Gallery settings tab.
 */
import { __ } from '@wordpress/i18n';

export const CAPTION_STYLES = {
	theme: __( 'As the theme styles them' ),
	below: __( 'Below each image' ),
	hover: __( 'On hover' ),
	hidden: __( 'Hidden' ),
};
