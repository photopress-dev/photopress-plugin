/**
 * The Image Taxonomies section of the Metadata settings tab: Standard
 * Metadata, Hierarchical Keyword Metadata (parent keywords) and Custom
 * Metadata, each with its add and edit screens, and re-reading the photos
 * already uploaded. All are kept in the custom_taxonomies setting; see
 * taxonomy-model.js and modules/metadata/TaxonomyModel.php.
 */
import { Fragment, useEffect, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Button, CheckboxControl, FormToggle, Notice, SelectControl, TextControl } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';

import JobPanel from '../shared/jobs.js';
import SaveBar from '../shared/save-bar.js';
import { useAdvanced } from '../shared/advanced-options.js';
import xmpLabels from '../shared/xmp-labels.js';
import {
	STANDARD,
	archiveUrl,
	capitalize,
	classify,
	likelyTypoOf,
	namesFrom,
	newId,
	parentNames,
	pluralize,
	slug,
	slugTakenBy,
	standardHow,
	standardSources,
} from './taxonomy-model.js';

/** Fields most worth mapping, listed first. */
const COMMON_FIELDS = [
	'Iptc4xmpExt:Event',
	'Iptc4xmpExt:PersonInImage',
	'photoshop:Category',
	'photoshop:SupplementalCategories',
	'photoshop:Credit',
	'photoshop:Source',
	'Iptc4xmpCore:Location',
	'dc:creator',
	'dc:publisher',
	'dc:contributor',
];

const FIELD_LABELS = {
	...xmpLabels,
	'Iptc4xmpExt:Event': __( 'Event' ),
	'Iptc4xmpExt:PersonInImage': __( 'Person shown' ),
	'Iptc4xmpCore:Location': __( 'Sublocation' ),
	'photoshop:SupplementalCategories': __( 'Supplemental categories' ),
};

const fieldLabel = ( tag ) => FIELD_LABELS[ tag ] || tag;

/** How many times the settings have finished saving, to refresh what the server says. */
function useSaves( saving ) {
	const [ saves, setSaves ] = useState( 0 );
	const was = useRef( saving );

	useEffect( () => {
		if ( was.current && ! saving ) {
			setSaves( ( n ) => n + 1 );
		}
		was.current = saving;
	}, [ saving ] );

	return saves;
}

/** Each taxonomy's term count and the prefixes nobody takes, from the server. */
function useStatus( saves ) {
	const [ status, setStatus ] = useState( { counts: {}, prefixes: [] } );

	useEffect( () => {
		apiFetch( { path: '/photopress/v1/image-taxonomies' } )
			.then( setStatus )
			.catch( () => {} );
	}, [ saves ] );

	return status;
}

/**
 * What saving these settings would change for photos already uploaded
 * (metadata::changeScope): { ids, taxonomies }, or null while it is asked.
 * Asked again when refresh changes, as after a save.
 */
function useScope( settings, refresh = 0 ) {
	const [ scope, setScope ] = useState( null );
	const key = JSON.stringify( settings ) + refresh;

	useEffect( () => {
		// An answer for settings since changed is dropped.
		let current = true;
		setScope( null );
		const timer = setTimeout( () => {
			apiFetch( { path: '/photopress/v1/image-taxonomies/scope', method: 'POST', data: { settings } } )
				.then( ( answer ) => current && setScope( answer ) )
				.catch( () => current && setScope( { ids: [], taxonomies: [] } ) );
		}, 400 );
		return () => {
			current = false;
			clearTimeout( timer );
		};
	}, [ key ] ); // eslint-disable-line react-hooks/exhaustive-deps

	return scope;
}

/**
 * The reprocess choice for a change to parent keywords or separators: its
 * images. None while they are counted or when there are none. terms: what
 * an image whose file has no keywords would have emptied.
 */
function scopeChoice( scope, terms ) {
	if ( ! scope || ! scope.ids.length ) {
		return null;
	}
	return {
		label: sprintf( _n( 'Save and reprocess %s affected image', 'Save and reprocess %s affected images', scope.ids.length ), scope.ids.length.toLocaleString() ),
		missing: __( 'keywords' ),
		terms,
		request: { ids: scope.ids, taxonomies: scope.taxonomies },
	};
}

const termCount = ( status, id ) => ( id in status.counts ? status.counts[ id ].toLocaleString() : '—' );

function Table( { className, head, children } ) {
	return (
		<div className="photopress-taxonomies__scroll">
			<table className={ 'widefat striped ' + className }>
				<thead>
					<tr>
						{ head.map( ( [ text, cls ] ) => (
							'hidden' === cls
								? <th key={ text } scope="col"><span className="screen-reader-text">{ text }</span></th>
								: <th key={ text } scope="col" className={ cls }>{ text }</th>
						) ) }
					</tr>
				</thead>
				<tbody>{ children }</tbody>
			</table>
		</div>
	);
}

function Section( { title, intro, action, children } ) {
	return (
		<section className="photopress-taxonomies__section">
			<div className="photopress-taxonomies__head">
				<div>
					<h3>{ title }</h3>
					{ intro && <p className="description">{ intro }</p> }
				</div>
				{ action }
			</div>
			{ children }
		</section>
	);
}

function RowActions( { onEdit, onRemove, removeLabel } ) {
	return (
		<td className="photopress-taxonomies__actions">
			<Button variant="link" onClick={ onEdit }>{ __( 'Edit' ) }</Button>
			{ onRemove && (
				<>
					<span aria-hidden="true"> | </span>
					<Button variant="link" isDestructive onClick={ onRemove }>{ removeLabel }</Button>
				</>
			) }
		</td>
	);
}

/**
 * An add or edit screen: a title, its fields, Save (and reprocess) and Cancel.
 */
function Editor( { title, error, onSave, onCancel, saving, saveLabel, reprocess, children } ) {
	return (
		<div className="photopress-taxonomies__editor">
			<Button variant="link" onClick={ onCancel }>{ __( '← Image taxonomies' ) }</Button>
			<h3>{ title }</h3>
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ children }
			<SaveBar onSave={ onSave } onCancel={ onCancel } saving={ saving } saveLabel={ saveLabel } reprocess={ reprocess } />
		</div>
	);
}

function Names( { plural, singular, onPlural, onSingular, urlTerm, before } ) {
	return (
		<div className="photopress-taxonomies__names">
			<div className="photopress-taxonomies__pair">
				<TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={ __( 'Plural name' ) } value={ plural } onChange={ onPlural } />
				<TextControl __nextHasNoMarginBottom __next40pxDefaultSize label={ __( 'Singular name' ) } value={ singular } onChange={ onSingular } />
			</div>
			<p className="description">
				{ __( 'Archive pages:' ) } <code>{ archiveUrl( { singularLabel: singular }, urlTerm ) }</code>
				{ before && before !== slug( singular ) && (
					<> { sprintf( __( '(they were at /%s/…, which will stop working)' ), before ) }</>
				) }
			</p>
		</div>
	);
}

function StandardEditor( { kind, def, list, onSave, onCancel, saving } ) {
	const [ plural, setPlural ] = useState( capitalize( def.pluralLabel ) );
	const [ singular, setSingular ] = useState( capitalize( def.singularLabel ) );
	const [ error, setError ] = useState( null );

	const save = () => {
		const problem = namesProblem( plural, singular, list, def.index );
		if ( problem ) {
			return setError( problem );
		}
		onSave( { ...def, pluralLabel: plural.trim(), singularLabel: singular.trim() } );
	};

	return (
		<Editor title={ capitalize( def.pluralLabel ) } error={ error } onSave={ save } onCancel={ onCancel } saving={ saving }>
			<Names plural={ plural } singular={ singular } onPlural={ setPlural } onSingular={ setSingular } before={ slug( def.singularLabel ) } />
			<h4>{ __( 'Where PhotoPress looks' ) }</h4>
			<p>{ standardHow( kind ) }. { __( 'Nothing to set up: PhotoPress reads every place cameras and photo software record it.' ) }</p>
			<details>
				<summary>{ __( 'Fields read, in order' ) }</summary>
				<ol>{ standardSources( kind ).map( ( s ) => <li key={ s }>{ s }</li> ) }</ol>
			</details>
			{ 'keywords' === kind && (
				<p className="description">
					{ __( 'A keyword hierarchy keeps its levels, each under the one above with its own archive page: Places › USA › California gives California under USA under Places, at /keyword/places/usa/california. A word used both as a plain keyword and inside a hierarchy becomes two terms, one at the top and one in the hierarchy, each with its own page.' ) }
				</p>
			) }
		</Editor>
	);
}

/** What a photo with these keywords gets from a parent keyword. */
function ParentPreview( { levels, plural, singular, example, separators } ) {
	const parent = levels.join( '|' );
	const term = example || __( 'Example' );
	const sub = __( 'Group' );
	const tax = plural || __( 'This taxonomy' );
	const def = { singularLabel: singular };

	const rows = [
		[ `${ parent }|${ term }`, [ [ tax, term, '', archiveUrl( def, term ) ] ] ],
		[
			`${ parent }|${ sub }|${ term }`,
			[ [ tax, sub, '', archiveUrl( def, sub ) ], [ tax, term, sub, archiveUrl( def, sub ) + '/' + slug( term ) ] ],
		],
	];

	if ( 1 === levels.length && separators[ 0 ] ) {
		rows.push( [ `${ levels[ 0 ].toLowerCase() }${ separators[ 0 ] } ${ term }`, [ [ tax, term, '', archiveUrl( def, term ) ] ] ] );
	}

	return (
		<Table className="photopress-taxonomies__preview" head={ [ [ __( 'Keyword in the photo' ) ], [ __( 'Taxonomy' ) ], [ __( 'Term' ) ], [ __( 'Archive page' ) ] ] }>
			{ rows.flatMap( ( [ keyword, results ] ) => results.map( ( [ t, value, under, url ], i ) => (
				<tr key={ keyword + i }>
					<td>{ 0 === i && <code>{ keyword }</code> }</td>
					<td>{ t }</td>
					<td><strong>{ value }</strong>{ under && <span className="description"> { sprintf( __( 'under %s' ), under ) }</span> }</td>
					<td><code>{ url }</code></td>
				</tr>
			) ) ) }
		</Table>
	);
}

/**
 * Why these names can't be saved, or null: both are needed, and no other
 * taxonomy may have its archive pages at the same URL.
 */
function namesProblem( plural, singular, list, self ) {
	if ( ! plural.trim() || ! slug( singular ) ) {
		return __( 'Give the taxonomy a plural and a singular name.' );
	}

	const other = slugTakenBy( singular, list, self );

	return other
		? sprintf( __( '%1$s already uses /%2$s/ for its archive pages. Choose another singular name.' ), capitalize( other.pluralLabel ), slug( singular ) )
		: null;
}

/** "Clients › Acme", "Clients > Acme" and "Clients|Acme" are one path. */
const asPath = ( name ) => name.split( /\s*[›>|]\s*/ ).filter( Boolean ).join( '|' );

function ParentEditor( { def, prefill, list, parents, status, separators, onSave, onCancel, saving } ) {
	const names = def ? parentNames( def ) : [ prefill || '' ];
	const [ parent, setParent ] = useState( names[ 0 ].replace( /\|/g, ' › ' ) );
	const [ aka, setAka ] = useState( names.slice( 1 ).join( ', ' ) );
	const [ plural, setPlural ] = useState( def ? capitalize( def.pluralLabel ) : capitalize( pluralize( prefill || '' ) ) );
	const [ singular, setSingular ] = useState( def ? capitalize( def.singularLabel ) : capitalize( prefill || '' ) );
	const [ named, setNamed ] = useState( !! def || !! prefill );
	const [ error, setError ] = useState( null );

	const levels = asPath( parent ).split( '|' ).filter( Boolean );
	const found = status.prefixes.find( ( p ) => p.prefix === ( levels[ 0 ] || '' ).toLowerCase() );

	const choose = ( name ) => {
		setParent( name );
		if ( ! named ) {
			setPlural( capitalize( pluralize( name ) ) );
			setSingular( capitalize( name ) );
		}
	};

	const all = [ asPath( parent ), ...namesFrom( aka ).map( asPath ) ].filter( Boolean );
	const id = def ? def.id : newId( singular, list );
	const draft = {
		id,
		pluralLabel: plural.trim(),
		singularLabel: singular.trim(),
		tag: 'dc:subject',
		parseTagValue: true,
		names: all,
		...( def && def.disabled ? { disabled: true } : {} ),
	};
	const scope = useScope( { custom_taxonomies: def ? list.map( ( d, i ) => ( i === def.index ? draft : d ) ) : [ ...list, draft ] } );

	const save = ( reprocess ) => {
		const others = parents.filter( ( p ) => ! def || p.index !== def.index );
		const taken = all.find( ( n ) => others.some( ( p ) => parentNames( p ).some( ( m ) => m.toLowerCase() === n.toLowerCase() ) ) );

		if ( ! all.length ) {
			return setError( __( 'Enter the parent keyword.' ) );
		}
		if ( taken ) {
			return setError( sprintf( __( '“%s” is already a parent keyword.' ), taken.replace( /\|/g, ' › ' ) ) );
		}
		const problem = namesProblem( plural, singular, list, def ? def.index : -1 );
		if ( problem ) {
			return setError( problem );
		}

		onSave( draft, reprocess );
	};

	return (
		<Editor
			title={ def ? sprintf( __( 'Parent keyword: %s' ), capitalize( def.pluralLabel ) ) : __( 'Add Hierarchical Keyword Taxonomy' ) }
			saveLabel={ def ? __( 'Save' ) : __( 'Add' ) }
			reprocess={ scopeChoice( scope, sprintf( __( 'Keywords and %s terms' ), capitalize( plural || __( 'parent keyword' ) ) ) ) }
			error={ error }
			onSave={ save }
			onCancel={ onCancel }
			saving={ saving }
		>
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Parent keyword' ) }
				help={ __( 'The top-level keyword whose sub-keywords become this taxonomy’s terms, such as Genre for Genre › Portraiture.' ) }
				placeholder={ __( 'Genre, or a path such as Clients › Acme' ) }
				value={ parent }
				onChange={ choose }
			/>
			{ ! def && status.prefixes.length > 0 && (
				<p className="photopress-taxonomies__chips">
					<span className="description">{ __( 'Found in your photos:' ) }</span>
					{ status.prefixes.slice( 0, 8 ).map( ( p ) => (
						<Button key={ p.prefix } variant="secondary" size="small" onClick={ () => choose( p.prefix ) }>{ p.prefix }</Button>
					) ) }
				</p>
			) }
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Also written as (optional)' ) }
				help={ __( 'Other names for the same parent keyword, separated by commas.' ) }
				value={ aka }
				onChange={ setAka }
			/>
			<Names
				plural={ plural }
				singular={ singular }
				onPlural={ ( v ) => ( setNamed( true ), setPlural( v ) ) }
				onSingular={ ( v ) => ( setNamed( true ), setSingular( v ) ) }
				before={ def && slug( def.singularLabel ) }
			/>
			<h4>{ __( 'What a photo gets' ) }</h4>
			<ParentPreview
				levels={ levels.length ? levels : [ __( 'Parent' ) ] }
				plural={ plural }
				singular={ singular }
				example={ found && found.examples[ 0 ] }
				separators={ separators }
			/>
			<p className="description">{ __( 'The parent keyword picks the taxonomy; the keywords under it are its terms, each level under the one above with its own archive page, as in your photo software. A prefixed keyword whose first part is the parent keyword is read the same way.' ) }</p>
		</Editor>
	);
}

/** A likely value of each common field, for the example. */
const FIELD_EXAMPLES = {
	'Iptc4xmpExt:Event': 'Maker Faire',
	'Iptc4xmpExt:PersonInImage': 'Jane Smith',
	'photoshop:Category': 'Travel',
	'photoshop:SupplementalCategories': 'Landscape',
	'photoshop:Credit': 'Jane Smith Photography',
	'photoshop:Source': 'Acme Agency',
	'Iptc4xmpCore:Location': 'Golden Gate Park',
	'dc:creator': 'Jane Smith',
	'dc:publisher': 'Acme Press',
	'dc:contributor': 'John Doe',
};

/** What a photo with a value in the field gets: a term in the taxonomy, with its page. */
function CustomPreview( { tag, plural, singular } ) {
	const value = FIELD_EXAMPLES[ tag ] || __( 'Example' );
	const def = { singularLabel: singular };

	return (
		<Fragment>
			<h4>{ __( 'What a photo gets' ) }</h4>
			<Table className="photopress-taxonomies__preview" head={ [ [ sprintf( __( '%s in the photo' ), fieldLabel( tag ) ) ], [ __( 'Taxonomy' ) ], [ __( 'Term' ) ], [ __( 'Archive page' ) ] ] }>
				<tr>
					<td><code>{ value }</code></td>
					<td>{ plural || __( 'This taxonomy' ) }</td>
					<td><strong>{ value }</strong></td>
					<td><code>{ archiveUrl( def, value ) }</code></td>
				</tr>
			</Table>
			<p className="description">{ __( 'Each value in the field becomes a term, as written. A field with several values gives the photo a term for each.' ) }</p>
		</Fragment>
	);
}

function CustomEditor( { def, list, standardTags, onSave, onCancel, saving } ) {
	const used = new Set( list.filter( ( d ) => ! d.parseTagValue && ( ! def || d.id !== def.id ) ).map( ( d ) => d.tag ) );
	const available = ( tag ) => ! standardTags.has( tag ) && ( ! used.has( tag ) || ( def && def.tag === tag ) );
	const common = COMMON_FIELDS.filter( available );
	const rest = Object.keys( xmpLabels ).filter( ( tag ) => available( tag ) && ! COMMON_FIELDS.includes( tag ) );

	// A new one starts with no field chosen, and its names from the field.
	const [ tag, setTag ] = useState( def ? def.tag : '' );
	const [ plural, setPlural ] = useState( def ? capitalize( def.pluralLabel ) : '' );
	const [ singular, setSingular ] = useState( def ? capitalize( def.singularLabel ) : '' );
	const [ named, setNamed ] = useState( !! def );
	const [ error, setError ] = useState( null );

	const pick = ( value ) => {
		setTag( value );
		if ( ! named ) {
			setPlural( capitalize( pluralize( fieldLabel( value ) ) ) );
			setSingular( capitalize( fieldLabel( value ) ) );
		}
	};

	const id = def ? def.id : newId( singular, list );

	const save = ( reprocess ) => {
		if ( ! tag ) {
			return setError( __( 'Choose the metadata field to make a taxonomy of.' ) );
		}
		const problem = namesProblem( plural, singular, list, def ? def.index : -1 );
		if ( problem ) {
			return setError( problem );
		}
		onSave( {
			id,
			pluralLabel: plural.trim(),
			singularLabel: singular.trim(),
			tag,
			parseTagValue: false,
			...( def && def.disabled ? { disabled: true } : {} ),
		}, reprocess );
	};

	const option = ( t ) => ( { value: t, label: `${ fieldLabel( t ) } (${ t })` } );

	return (
		<Editor
			title={ def ? sprintf( __( 'Custom metadata: %s' ), capitalize( def.pluralLabel ) ) : __( 'Add Custom Metadata Taxonomy' ) }
			reprocess={ tag ? {
				label: __( 'Save and reprocess all images' ),
				missing: sprintf( __( '%s field' ), fieldLabel( tag ) ),
				terms: sprintf( __( '%s terms' ), capitalize( plural || fieldLabel( tag ) ) ),
				request: { all: true, taxonomies: [ id ] },
			} : null }
			error={ error }
			onSave={ save }
			onCancel={ onCancel }
			saving={ saving }
		>
			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Metadata field' ) }
				help={ __( 'Camera, lens, location and keywords aren’t listed: they are Standard Metadata.' ) }
				value={ tag }
				onChange={ pick }
			>
				{ ! tag && <option value="" disabled>{ __( 'Select…' ) }</option> }
				<optgroup label={ __( 'Common fields' ) }>{ common.map( ( t ) => <option key={ t } value={ t }>{ option( t ).label }</option> ) }</optgroup>
				<optgroup label={ __( 'All fields' ) }>{ rest.map( ( t ) => <option key={ t } value={ t }>{ option( t ).label }</option> ) }</optgroup>
			</SelectControl>
			<Names
				plural={ plural }
				singular={ singular }
				onPlural={ ( v ) => ( setNamed( true ), setPlural( v ) ) }
				onSingular={ ( v ) => ( setNamed( true ), setSingular( v ) ) }
				before={ def && slug( def.singularLabel ) }
			/>
			{ tag && <CustomPreview tag={ tag } plural={ plural } singular={ singular } /> }
		</Editor>
	);
}

/** The page within the Metadata tab for an add or edit screen. */
function routeFor( screen ) {
	const base = `taxonomy/${ screen.type }/`;

	if ( 'standard' === screen.type ) {
		return base + screen.kind;
	}
	return base + ( screen.id || 'new' + ( screen.prefill ? '/' + encodeURIComponent( screen.prefill ) : '' ) );
}

/** The add or edit screen a page within the Metadata tab is, or null. */
function screenOf( route ) {
	const [ section, type, key, extra ] = String( route || '' ).split( '/' );

	if ( 'taxonomy' !== section || ! type ) {
		return null;
	}
	if ( 'standard' === type ) {
		return { type, kind: key };
	}
	return 'new' === key ? { type, prefill: extra ? decodeURIComponent( extra ) : '' } : { type, id: key };
}

export default function TaxonomySettings( { component, route } ) {
	const list = component.getSetting( 'custom_taxonomies' ) || [];
	const separators = String( component.getSetting( 'custom_taxonomies_tag_delimiter' ) ?? '' ).trim().split( /\s+/ ).filter( Boolean );
	const { standard, parents, custom } = classify( list );
	const saving = component.state.isAPISaving;
	const saves = useSaves( saving );
	const status = useStatus( saves );
	const screen = screenOf( route );
	const [ empty, setEmpty ] = useState( false );
	const emptyOption = useAdvanced( () => setEmpty( false ) );
	const [ separatorNotice, setSeparatorNotice ] = useState( null );
	// The prefix separators, shown under Advanced settings, open when changed.
	const [ advanced, setAdvanced ] = useState( false );
	const delimiter = component.getSetting( 'custom_taxonomies_tag_delimiter' ) ?? '';
	const separatorScope = useScope( { custom_taxonomies_tag_delimiter: delimiter }, saves );
	const setScreen = ( next ) => component.navigate( next ? routeFor( next ) : '' );
	const jobStarted = () => component.setState( ( state ) => ( { jobs: state.jobs + 1 } ) );

	/** Starts a reprocess; what to say about it after "saved". */
	const startReprocess = ( request ) => apiFetch( { path: '/photopress/v1/image-taxonomies/reprocess', method: 'POST', data: request } )
		.then( ( result ) => {
			jobStarted();
			return result.queued
				? { status: 'info', text: __( 'Its images will be reprocessed when the re-read now running finishes.' ) }
				: { status: 'success', text: __( 'Its images are being reprocessed; the progress is below.' ) };
		} )
		.catch( ( e ) => ( { status: 'warning', text: e.message || __( 'But its images could not be reprocessed.' ) } ) );

	// On the list: a switch, a removal, a name added. What changed is in view.
	const save = ( next ) => component.setSetting( 'custom_taxonomies', next, true );
	const replace = ( index, def ) => save( list.map( ( d, i ) => ( i === index ? def : d ) ) );
	const remove = ( def, what ) => {
		// eslint-disable-next-line no-alert
		if ( window.confirm( sprintf( __( 'Remove %1$s? Its archive pages go. Its terms stay in the database and come back if you add %2$s again.' ), capitalize( def.pluralLabel ), what ) ) ) {
			save( list.filter( ( d, i ) => i !== def.index ) );
		}
	};

	/**
	 * An add or edit screen's Save (and reprocess): saves, then back on the
	 * tab says so, at its top, where the progress of a reprocess is. A save
	 * that fails stays on the screen, the error above it.
	 */
	const commit = ( next, def, added, reprocess ) => save( next ).then( () => {
		if ( component.getError( 'save' ) ) {
			return;
		}
		const name = capitalize( def.pluralLabel );
		const said = added ? sprintf( __( '%s added.' ), name ) : sprintf( __( '%s saved.' ), name );

		if ( ! reprocess ) {
			return component.navigate( '', { status: 'success', text: said } );
		}
		return startReprocess( reprocess ).then( ( then ) => component.navigate( '', { status: then.status, text: said + ' ' + then.text } ) );
	} );

	if ( screen ) {
		const close = () => setScreen( null );

		switch ( screen.type ) {
			case 'standard': {
				const fresh = STANDARD.find( ( s ) => s.kind === screen.kind );
				if ( ! fresh ) {
					break;
				}
				const def = standard[ screen.kind ] || { ...fresh, index: -1 };
				const apply = ( d ) => {
					const { kind, index, ...clean } = d; // eslint-disable-line no-unused-vars
					return commit( index < 0 ? [ ...list, { ...clean, parseTagValue: false } ] : list.map( ( x, i ) => ( i === index ? clean : x ) ), clean, false );
				};
				return <StandardEditor kind={ screen.kind } def={ def } list={ list } onSave={ apply } onCancel={ close } saving={ saving } />;
			}
			case 'parent':
			case 'custom': {
				const def = ( 'parent' === screen.type ? parents : custom ).find( ( d ) => d.id === screen.id );
				if ( screen.id && ! def ) {
					break;
				}
				const apply = ( d, reprocess ) => commit( def ? list.map( ( x, i ) => ( i === def.index ? d : x ) ) : [ ...list, d ], d, ! def, reprocess );
				return 'parent' === screen.type ? (
					<ParentEditor
						def={ def }
						prefill={ screen.prefill }
						list={ list }
						parents={ parents }
						status={ status }
						separators={ separators }
						onSave={ apply }
						onCancel={ close }
						saving={ saving }
					/>
				) : (
					<CustomEditor
						def={ def }
						list={ list }
						standardTags={ new Set( STANDARD.map( ( s ) => s.tag ) ) }
						onSave={ apply }
						onCancel={ close }
						saving={ saving }
					/>
				);
			}
		}

		return (
			<Notice status="warning" isDismissible={ false }>
				{ __( 'There is no such image taxonomy.' ) } <Button variant="link" onClick={ close }>{ __( 'Back to Image taxonomies' ) }</Button>
			</Notice>
		);
	}

	const toggleStandard = ( kind, on ) => {
		const def = standard[ kind ];

		if ( ! def ) {
			const { kind: k, ...fresh } = STANDARD.find( ( s ) => s.kind === kind ); // eslint-disable-line no-unused-vars
			return save( [ ...list, { ...fresh, parseTagValue: false } ] );
		}

		const { index, ...clean } = def;
		delete clean.disabled;
		replace( index, on ? clean : { ...clean, disabled: true } );
	};

	const addName = ( parent, name ) => {
		const { index, ...clean } = parent;
		replace( index, { ...clean, names: [ ...parentNames( parent ), name ] } );
	};

	const unclaimed = status.prefixes;

	return (
		<div className="photopress-taxonomies">
			<Fragment>
				<div className="photopress-taxonomies__reprocess" id="photopress-reprocess">
					<JobPanel
						type="metadata.reprocess"
						compact
						startLabel={ __( 'Reprocess all images' ) }
						args={ { force: empty } }
						after={ emptyOption.link }
						refresh={ saves + component.state.jobs }
						note={ ( job ) => {
							if ( ! job.args || ! job.args.taxonomies || ! job.args.taxonomies.length ) {
								return '';
							}
							return job.args.ids && job.args.ids.length ? __( 'the images a change affects, image taxonomies only' ) : __( 'image taxonomies only' );
						} }
						confirm={ empty
							? __( 'Reprocess every image, reading its metadata again as on upload, and empty the terms of images whose files have none? Terms, alt text and descriptions set by hand are replaced by what the files say.' )
							: __( 'Reprocess every image, reading its metadata again as on upload? Terms, alt text and descriptions set by hand are replaced by what the files say.' ) }
					/>
					{ emptyOption.open && <CheckboxControl
						__nextHasNoMarginBottom
						label={ __( 'Empty the terms of images whose files have no metadata for a taxonomy' ) }
						help={ __( 'As when every keyword, or the location, camera or lens, was removed from a file. Unchecked, those images keep their terms, in case the metadata was stripped from their files.' ) }
						checked={ empty }
						onChange={ setEmpty }
					/> }
				</div>

				<Section
					title={ __( 'Standard Metadata' ) }
					intro={ __( 'Built in. Each knows every place its information can be stored, so you don’t need to know which fields your photo software writes. Turn off the ones you don’t want.' ) }
				>
					<Table
						className="photopress-taxonomies__standard"
						head={ [ [ __( 'Taxonomy' ) ], [ __( 'How it’s filled' ) ], [ __( 'Archive pages' ) ], [ __( 'Terms' ), 'num' ], [ __( 'Actions' ), 'hidden' ], [ __( 'On' ), 'switch' ] ] }
					>
						{ STANDARD.map( ( s ) => {
							const def = standard[ s.kind ];
							const on = !! def && ! def.disabled;
							return (
								<tr key={ s.kind } data-kind={ s.kind }>
									<td><strong>{ capitalize( ( def || s ).pluralLabel ) }</strong></td>
									<td>{ standardHow( s.kind ) }</td>
									<td><code>{ archiveUrl( def || s ) }</code></td>
									<td className="num">{ def ? termCount( status, def.id ) : '—' }</td>
									<RowActions onEdit={ () => setScreen( { type: 'standard', kind: s.kind } ) } />
									<td className="switch">
										<FormToggle
											checked={ on }
											disabled={ saving }
											aria-label={ sprintf( __( '%s on' ), capitalize( ( def || s ).pluralLabel ) ) }
											onChange={ ( e ) => toggleStandard( s.kind, e.target.checked ) }
										/>
									</td>
								</tr>
							);
						} ) }
					</Table>
				</Section>

				<Section
					title={ __( 'Hierarchical Keyword Metadata' ) }
					intro={ __( 'Map specific keyword hierarchies set in Lightroom or Capture One into their own image taxonomies. For example, an image file containing Genre › Portraiture will be tagged Portraiture in a Genres taxonomy, with its own archive page at /genre/portraiture.' ) }
					action={ <Button variant="primary" aria-label={ __( 'Add a hierarchical keyword taxonomy' ) } onClick={ () => setScreen( { type: 'parent' } ) }>{ __( 'Add' ) }</Button> }
				>
					<Table
						className="photopress-taxonomies__parents"
						head={ [ [ __( 'Taxonomy' ) ], [ __( 'Parent keywords' ) ], [ __( 'Archive pages' ) ], [ __( 'Terms' ), 'num' ], [ __( 'Actions' ), 'hidden' ] ] }
					>
						{ parents.length === 0 && <tr><td colSpan="5">{ __( 'No parent keywords yet.' ) }</td></tr> }
						{ parents.map( ( p ) => (
							<tr key={ p.id } data-taxonomy={ p.id }>
								<td>
									<strong>{ capitalize( p.pluralLabel ) }</strong>
										</td>
								<td>{ parentNames( p ).map( ( n ) => <code key={ n } className="photopress-taxonomies__chip">{ n.replace( /\|/g, ' › ' ) }</code> ) }</td>
								<td><code>{ archiveUrl( p ) }</code></td>
								<td className="num">{ termCount( status, p.id ) }</td>
								<RowActions
									onEdit={ () => setScreen( { type: 'parent', id: p.id } ) }
									onRemove={ () => remove( p, __( 'the parent keyword' ) ) }
									removeLabel={ __( 'Remove' ) }
								/>
							</tr>
						) ) }
					</Table>

					{ unclaimed.length > 0 && (
						<div className="photopress-taxonomies__found">
							<h4>{ __( 'Parent keywords in your photos with no taxonomy' ) }</h4>
							<p className="description">{ __( 'Until they have one, these keywords are filed under Keywords as written.' ) }</p>
							<ul>
								{ unclaimed.map( ( p ) => {
									const typo = likelyTypoOf( p.prefix, parents );
									return (
										<li key={ p.prefix }>
											<span>
												<code>{ p.prefix }</code> <span className="description">{ sprintf( _n( 'used %1$d time, e.g. %2$s', 'used %1$d times, e.g. %2$s', p.photos ), p.photos, p.examples.join( ', ' ) ) }</span>
											</span>
											{ typo ? (
												<Button variant="secondary" size="small" disabled={ saving } onClick={ () => addName( typo, p.prefix ) }>
													{ sprintf( __( 'Add to %s' ), capitalize( typo.pluralLabel ) ) }
												</Button>
											) : (
												<Button variant="secondary" size="small" onClick={ () => setScreen( { type: 'parent', prefill: p.prefix } ) }>
													{ __( 'Add taxonomy' ) }
												</Button>
											) }
										</li>
									);
								} ) }
							</ul>
						</div>
					) }

					<div className="photopress-advanced">
						<Button variant="link" aria-expanded={ advanced } onClick={ () => setAdvanced( ! advanced ) }>
							{ advanced ? __( 'Hide advanced options' ) : __( 'Advanced options' ) }
						</Button>
					</div>

					{ advanced && <div className="photopress-taxonomies__separators">
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Prefix separators' ) }
							help={ __( 'For keywords written as one word with a prefix, such as genre: Portraiture, instead of as a hierarchy. The character between the prefix and the rest; separate several with spaces, such as : >. Leave empty to ignore prefixes. Hierarchies from Lightroom and Capture One are read either way.' ) }
							value={ delimiter }
							onChange={ ( value ) => component.setSetting( 'custom_taxonomies_tag_delimiter', value ) }
						/>
						<SaveBar
							saving={ saving }
							reprocess={ scopeChoice( separatorScope, __( 'Keywords and parent keyword terms' ) ) }
							onSave={ ( reprocess ) => {
								setSeparatorNotice( null );
								Promise.resolve( component.saveSettings() ).then( () => {
									if ( component.getError( 'save' ) ) {
										return;
									}
									if ( ! reprocess ) {
										return setSeparatorNotice( { status: 'success', text: __( 'Separators saved.' ) } );
									}
									startReprocess( reprocess ).then( ( then ) => setSeparatorNotice( { status: then.status, text: __( 'Separators saved.' ) + ' ' + then.text.replace( __( 'the progress is below.' ), __( 'the progress is at the top of Image Taxonomies.' ) ), progress: true } ) );
								} );
							} }
						/>
					</div> }
					{ separatorNotice && (
						<Notice status={ separatorNotice.status } onRemove={ () => setSeparatorNotice( null ) }>
							{ separatorNotice.text }
							{ separatorNotice.progress && (
								<Fragment>
									{ ' ' }
									<Button variant="link" onClick={ () => document.getElementById( 'photopress-reprocess' )?.scrollIntoView( { behavior: 'smooth' } ) }>{ __( 'View progress' ) }</Button>
								</Fragment>
							) }
						</Notice>
					) }
				</Section>

				<Section
					title={ __( 'Custom Metadata' ) }
					intro={ __( 'Any other metadata field as a taxonomy. Every value in the field becomes a term, as written.' ) }
					action={ <Button variant="primary" aria-label={ __( 'Add a custom metadata taxonomy' ) } onClick={ () => setScreen( { type: 'custom' } ) }>{ __( 'Add' ) }</Button> }
				>
					<Table
						className="photopress-taxonomies__custom"
						head={ [ [ __( 'Taxonomy' ) ], [ __( 'Field' ) ], [ __( 'Archive pages' ) ], [ __( 'Terms' ), 'num' ], [ __( 'Actions' ), 'hidden' ] ] }
					>
						{ custom.length === 0 && <tr><td colSpan="5">{ __( 'No custom metadata yet.' ) }</td></tr> }
						{ custom.map( ( c ) => (
							<tr key={ c.id } data-taxonomy={ c.id }>
								<td><strong>{ capitalize( c.pluralLabel ) }</strong></td>
								<td>{ fieldLabel( c.tag ) } <span className="description">({ c.tag })</span></td>
								<td><code>{ archiveUrl( c ) }</code></td>
								<td className="num">{ termCount( status, c.id ) }</td>
								<RowActions
									onEdit={ () => setScreen( { type: 'custom', id: c.id } ) }
									onRemove={ () => remove( c, __( 'the field' ) ) }
									removeLabel={ __( 'Delete' ) }
								/>
							</tr>
						) ) }
					</Table>
				</Section>
			</Fragment>
		</div>
	);
}
