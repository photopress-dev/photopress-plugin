/**
 * The image taxonomy settings (custom_taxonomies) in the three kinds the
 * Image Taxonomies screen shows them by, as modules/metadata/TaxonomyModel.php
 * reads them: Standard Metadata, parent keywords (Hierarchical Keyword
 * Metadata) and Custom Metadata.
 */
import { __ } from '@wordpress/i18n';

/** The standard taxonomies, in the order they are listed, with their defaults. */
export const STANDARD = [
	{ kind: 'camera', tag: 'photopress:camera', id: 'photos_camera', pluralLabel: 'cameras', singularLabel: 'camera' },
	{ kind: 'lens', tag: 'aux:Lens', id: 'photos_lens', pluralLabel: 'lenses', singularLabel: 'lens' },
	{ kind: 'city', tag: 'photoshop:City', id: 'photos_city', pluralLabel: 'cities', singularLabel: 'city' },
	{ kind: 'state', tag: 'photoshop:State', id: 'photos_state', pluralLabel: 'states', singularLabel: 'state' },
	{ kind: 'country', tag: 'photoshop:Country', id: 'photos_country', pluralLabel: 'countries', singularLabel: 'country' },
	{ kind: 'keywords', tag: 'dc:subject', id: 'photos_keywords', pluralLabel: 'keywords', singularLabel: 'keyword' },
];

/** What fills each standard taxonomy, in words. */
export function standardHow( kind ) {
	return {
		camera: __( 'The camera’s make and model, cleaned up' ),
		lens: __( 'The lens model, wherever the camera or your software put it' ),
		city: __( 'The city in the photo’s location' ),
		state: __( 'The state or province in the photo’s location' ),
		country: __( 'The country in the photo’s location' ),
		keywords: __( 'Every keyword not under a parent keyword' ),
	}[ kind ];
}

/** Where PhotoPress looks for each standard taxonomy, in order. */
export function standardSources( kind ) {
	return {
		camera: [ __( 'EXIF make and model' ), __( 'XMP make and model (tiff:Make, tiff:Model)' ), __( 'XMP model alone (tiff:Model)' ) ],
		lens: [ __( 'XMP lens model (exifEX:LensModel)' ), __( 'EXIF lens model' ), __( 'Adobe’s lens field (aux:Lens)' ) ],
		city: [ __( 'XMP city (photoshop:City)' ) ],
		state: [ __( 'XMP state or province (photoshop:State)' ) ],
		country: [ __( 'XMP country (photoshop:Country)' ) ],
		keywords: [ __( 'The keyword list Lightroom and Capture One keep (lr:hierarchicalSubject)' ), __( 'Plain keywords (dc:subject)' ) ],
	}[ kind ];
}

const STANDARD_TAGS = Object.fromEntries( STANDARD.map( ( s ) => [ s.tag, s.kind ] ) );

/**
 * The definitions by kind. Each entry carries its index in the list, which
 * is how it is saved back.
 *
 * @param {Array} list The custom_taxonomies setting.
 * @return {{standard: Object, parents: Array, custom: Array}} standard is
 *         keyed by kind; a standard taxonomy not in the list is missing.
 */
export function classify( list ) {
	const out = { standard: {}, parents: [], custom: [] };

	( list || [] ).forEach( ( def, index ) => {
		const entry = { ...def, index };
		const kind = STANDARD_TAGS[ def.tag ];

		if ( def.parseTagValue ) {
			out.parents.push( entry );
		} else if ( kind && ! out.standard[ kind ] ) {
			out.standard[ kind ] = entry;
		} else {
			out.custom.push( entry );
		}
	} );

	return out;
}

/**
 * A parent keyword's names: its names setting, or as prefixes were matched
 * before it existed, its id without pp_ or photos_.
 */
export function parentNames( def ) {
	const names = ( def.names || [] ).filter( Boolean );
	return names.length ? names : [ String( def.id || '' ).replace( /^(pp|photos)_/, '' ) ];
}

/** A name as WordPress makes a slug of it, for showing archive URLs. */
export function slug( text ) {
	return String( text || '' )
		.toLowerCase()
		.normalize( 'NFD' )
		.replace( /[̀-ͯ]/g, '' )
		.replace( /[^a-z0-9]+/g, '-' )
		.replace( /^-+|-+$/g, '' );
}

/** The archive URL of a term of a taxonomy: /person/jane. */
export function archiveUrl( def, term = '…' ) {
	return '/' + slug( def.singularLabel ) + '/' + ( '…' === term ? term : slug( term ) );
}

/**
 * The id of a new taxonomy: pp_ and its singular name, unique among the
 * existing ones, at most 32 characters (WordPress's limit).
 */
export function newId( singular, list ) {
	const taken = new Set( ( list || [] ).map( ( def ) => def.id ) );
	const base = ( 'pp_' + slug( singular ).replace( /-/g, '_' ) ).slice( 0, 29 ) || 'pp_taxonomy';
	let id = base;

	for ( let n = 2; taken.has( id ); n++ ) {
		id = base + '_' + n;
	}

	return id;
}

/** An English plural, for suggesting names: organization -> organizations. */
export function pluralize( word ) {
	const w = String( word || '' ).trim();
	const irregular = { person: 'people', man: 'men', woman: 'women', child: 'children' };

	if ( irregular[ w.toLowerCase() ] ) {
		return matchCase( irregular[ w.toLowerCase() ], w );
	}
	if ( /[^aeiou]y$/i.test( w ) ) {
		return w.slice( 0, -1 ) + 'ies';
	}
	if ( /(s|x|z|ch|sh)$/i.test( w ) ) {
		return w + 'es';
	}
	return w ? w + 's' : w;
}

function matchCase( word, model ) {
	return model[ 0 ] === model[ 0 ].toUpperCase() ? word[ 0 ].toUpperCase() + word.slice( 1 ) : word;
}

/** Capitalized, for labels: genre -> Genre. */
export function capitalize( text ) {
	const t = String( text || '' );
	return t ? t[ 0 ].toUpperCase() + t.slice( 1 ) : t;
}

/** How many single-character edits make a into b. */
export function distance( a, b ) {
	const row = Array.from( { length: b.length + 1 }, ( _, i ) => i );

	for ( let i = 1; i <= a.length; i++ ) {
		let prev = row[ 0 ];
		row[ 0 ] = i;
		for ( let j = 1; j <= b.length; j++ ) {
			const was = row[ j ];
			row[ j ] = Math.min( row[ j ] + 1, row[ j - 1 ] + 1, prev + ( a[ i - 1 ] === b[ j - 1 ] ? 0 : 1 ) );
			prev = was;
		}
	}

	return row[ b.length ];
}

/**
 * The parent keyword a prefix nobody takes is likely a misspelling of
 * (penre -> genre), or undefined.
 */
export function likelyTypoOf( prefix, parents ) {
	if ( prefix.length < 4 ) {
		return undefined;
	}

	return parents.find( ( parent ) => parentNames( parent ).some( ( name ) => 1 === distance( prefix, name.toLowerCase() ) ) );
}

/** A comma-separated list of names, trimmed, without empty ones or repeats. */
export function namesFrom( text ) {
	const seen = new Set();

	return String( text || '' )
		.split( ',' )
		.map( ( s ) => s.trim() )
		.filter( ( s ) => s && ! seen.has( s.toLowerCase() ) && seen.add( s.toLowerCase() ) );
}
