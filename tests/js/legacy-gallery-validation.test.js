import { describe, test, expect, beforeAll, afterAll } from 'vitest';

/**
 * Saved legacy photopress/gallery blocks must stay valid: if save() ever
 * produces different markup for them, the editor reports "This block contains
 * unexpected or invalid content". The fixtures are galleries saved on the
 * live sites, one per distinct combination of settings.
 */

/**
 * External dependencies
 */
import fs from 'fs';
import path from 'path';

/**
 * Internal dependencies
 */
import './stubs/block-supports';

/**
 * WordPress dependencies
 */
import { registerBlockType, parse, getBlockTypes, unregisterBlockType } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import metadata from '../../src/blocks/gallery/block.json';
import save from '../../src/blocks/gallery/save';

// @wordpress/block-editor resolves to tests/js/stubs/block-editor.js.

const fixtures = path.join( __dirname, 'fixtures/legacy-galleries' );

beforeAll( () => {
	// title and supports are set in src/blocks/gallery/index.js, which also
	// imports the editor UI; registration fails without a title.
	registerBlockType( metadata.name, {
		...metadata,
		title: 'PhotoPress Gallery',
		supports: { align: [ 'wide', 'full' ], html: false },
		edit: () => null,
		save,
	} );
} );

afterAll( () => {
	getBlockTypes().forEach( ( { name } ) => unregisterBlockType( name ) );
} );

describe.each( fs.readdirSync( fixtures ) )( '%s', ( file ) => {
	test( 'parses as a valid block', () => {
		const [ block ] = parse( fs.readFileSync( path.join( fixtures, file ), 'utf8' ) );

		expect( block ).toBeDefined();
		expect( block.name ).toBe( 'photopress/gallery' );
		expect( block.attributes.images.length ).toBeGreaterThan( 0 );
		expect( block.validationIssues ).toEqual( [] );
		expect( block.isValid ).toBe( true );
	} );
} );
