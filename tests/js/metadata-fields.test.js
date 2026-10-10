/**
 * The fields offered for Custom Metadata: each listed once, with names a
 * taxonomy can take as they are, and none of Standard Metadata's.
 */
import { describe, expect, it } from 'vitest';
import { FIELD_GROUPS, COMMON_FIELDS, fieldOf, guideUrl } from '../../src/shared/metadata-fields.js';
import { STANDARD } from '../../src/options/taxonomy-model.js';

const fields = FIELD_GROUPS.flatMap( ( group ) => group.fields );

describe( 'metadata fields', () => {
	it( 'lists each field once, none of them Standard Metadata', () => {
		const tags = fields.map( ( f ) => f.tag );
		expect( new Set( tags ).size ).toBe( tags.length );
		for ( const s of STANDARD ) {
			expect( tags ).not.toContain( s.tag );
		}
	} );

	it( 'gives every field a label, names and an example, the names usable as they are', () => {
		for ( const f of fields ) {
			expect( f.label && f.singular && f.plural && f.example && f.about, f.tag ).toBeTruthy();
			expect( `${ f.singular } ${ f.plural }`, f.tag ).not.toMatch( /[()/]/ );
		}
		expect( fieldOf( 'dc:publisher' ) ).toMatchObject( { singular: 'Publisher', plural: 'Publishers' } );
		expect( fieldOf( 'Iptc4xmpExt:PersonInImage' ) ).toMatchObject( { singular: 'Person', plural: 'People' } );
	} );

	it( 'lists the common fields among the rest, and names a field it does not list by its tag', () => {
		for ( const tag of COMMON_FIELDS ) {
			expect( fields.map( ( f ) => f.tag ) ).toContain( tag );
		}
		expect( fieldOf( 'xap:Nickname' ) ).toMatchObject( { label: 'xap:Nickname', example: '' } );
	} );
} );

describe( 'the IPTC guide', () => {
	it( 'links an IPTC field to its section, and a camera field nowhere', () => {
		expect( guideUrl( fieldOf( 'Iptc4xmpExt:Event' ) ) ).toBe( 'https://www.iptc.org/std/photometadata/documentation/userguide/#_event' );
		expect( guideUrl( fieldOf( 'tiff:Make' ) ) ).toBe( '' );
		for ( const f of fields.filter( ( x ) => /^(Iptc4xmp|photoshop:(?!Category|Supplemental))/.test( x.tag ) ) ) {
			expect( f.anchor, f.tag ).toMatch( /^_/ );
		}
	} );
} );
