/**
 * Gutenberg Blocks
 *
 * All blocks related JavaScript files should be imported here.
 * You can create a new block folder in this dir and include code
 * for that block here as well.
 *
 * All blocks should be included here since this is the file that
 * Webpack is compiling as the input file.
 */
 
import { registerBlockType, } from '@wordpress/blocks';

//import './block/block.js';

import * as childpages from './blocks/child_pages/index.js';

import * as gallery from './blocks/gallery/index.js';

const registerBlock = ( block ) => {
	
	if ( ! block ) {
		
		return;
	}

	let { category } = block;

	const { name, settings } = block;

	if ( ! name.includes( 'gallery' ) ) {
		
		category = 'layout';
	}
	
	registerBlockType( name, {
		category,
		...settings,
	} );
};


export const registerPhotoPressBlocks = () => {
	[
		childpages,
		gallery
		
	].forEach( registerBlock );
};

registerPhotoPressBlocks();
