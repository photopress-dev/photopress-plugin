/**
 * The Image Taxonomies screen's reading of the custom_taxonomies setting,
 * which must agree with modules/metadata/TaxonomyModel.php.
 */
import { describe, expect, test } from 'vitest';
import { archiveUrl, classify, likelyTypoOf, namesFrom, newId, parentNames, pluralize, slug } from '../../src/options/taxonomy-model.js';

const LIST = [
	{ id: 'photos_camera', pluralLabel: 'cameras', singularLabel: 'camera', tag: 'photopress:camera', parseTagValue: false },
	{ id: 'photos_keywords', pluralLabel: 'keywords', singularLabel: 'keyword', tag: 'dc:subject', parseTagValue: false },
	{ id: 'photos_people', pluralLabel: 'people', singularLabel: 'person', tag: 'dc:subject', parseTagValue: true },
	{ id: 'pp_genre', pluralLabel: 'genres', singularLabel: 'genre', tag: 'dc:subject', parseTagValue: true, names: [ 'genre' ], nested: true },
	{ id: 'pp_event', pluralLabel: 'events', singularLabel: 'event', tag: 'Iptc4xmpExt:Event', parseTagValue: false },
	{ id: 'pp_more', pluralLabel: 'more', singularLabel: 'more', tag: 'dc:subject', parseTagValue: false },
];

describe( 'classify', () => {
	test( 'standard by field, parent keywords by parseTagValue, the rest custom', () => {
		const { standard, parents, custom } = classify( LIST );

		expect( Object.keys( standard ) ).toEqual( [ 'camera', 'keywords' ] );
		expect( parents.map( ( p ) => p.id ) ).toEqual( [ 'photos_people', 'pp_genre' ] );
		// A second definition on a standard field is custom, as in PHP.
		expect( custom.map( ( c ) => [ c.id, c.index ] ) ).toEqual( [ [ 'pp_event', 4 ], [ 'pp_more', 5 ] ] );
	} );
} );

test( 'a parent keyword is named by its names, or its id without pp_ or photos_', () => {
	expect( parentNames( LIST[ 2 ] ) ).toEqual( [ 'people' ] );
	expect( parentNames( { id: 'pp_person' } ) ).toEqual( [ 'person' ] );
	expect( parentNames( { id: 'x', names: [ 'Clients|Acme', '' ] } ) ).toEqual( [ 'Clients|Acme' ] );
} );

test( 'new ids are unique and within WordPress’s 32 characters', () => {
	expect( newId( 'Organization', LIST ) ).toBe( 'pp_organization' );
	expect( newId( 'Genre', LIST ) ).toBe( 'pp_genre_2' );
	expect( newId( 'Café owner', [] ) ).toBe( 'pp_cafe_owner' );
	expect( newId( 'x'.repeat( 60 ), [] ).length ).toBeLessThanOrEqual( 32 );
} );

test( 'archive URLs and slugs', () => {
	expect( slug( 'Point of View' ) ).toBe( 'point-of-view' );
	expect( archiveUrl( { singularLabel: 'Person' }, 'Jane Doe' ) ).toBe( '/person/jane-doe' );
	expect( archiveUrl( { singularLabel: 'Person' } ) ).toBe( '/person/…' );
} );

test( 'plurals for suggested names', () => {
	expect( [ 'organization', 'person', 'Person', 'city', 'lens', 'day', 'match' ].map( pluralize ) )
		.toEqual( [ 'organizations', 'people', 'People', 'cities', 'lenses', 'days', 'matches' ] );
} );

test( 'a prefix one letter off a parent keyword is likely a typo of it', () => {
	const { parents } = classify( LIST );

	expect( likelyTypoOf( 'penre', parents ).id ).toBe( 'pp_genre' );
	expect( likelyTypoOf( 'gender', parents ) ).toBeUndefined();
	expect( likelyTypoOf( 'pep', parents ) ).toBeUndefined();
} );

test( 'names from a comma-separated list', () => {
	expect( namesFrom( ' person, People ,, person ' ) ).toEqual( [ 'person', 'People' ] );
} );
