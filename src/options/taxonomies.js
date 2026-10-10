/**
 * The Image Taxonomies section of the Metadata settings tab: Standard
 * Metadata, Hierarchical Keyword Metadata (parent keywords) and Custom
 * Metadata, each with its add and edit screens, and re-reading the photos
 * already uploaded. All are kept in the custom_taxonomies setting; see
 * taxonomy-model.js and modules/metadata/TaxonomyModel.php.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Button, CheckboxControl, FormToggle, Notice, RadioControl, SelectControl, TextControl } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';

import JobPanel from '../shared/jobs.js';
import SwitchRow from '../shared/switch-row.js';
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
 * Save, and when a change affects photos already uploaded, Save and
 * reprocess with whether to skip images whose files have no metadata for it.
 * reprocess: { label, missing (what a file may not have), terms (what of
 * an image's would be emptied), request }, or null.
 * onSave( reprocess ): reprocess is the request with skip, or undefined.
 */
function SaveBar( { onSave, onCancel, saving, saveLabel = __( 'Save' ), reprocess } ) {
	const [ skip, setSkip ] = useState( true );

	return (
		<div className="photopress-taxonomies__savebar">
			{ reprocess && (
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ sprintf( __( 'Don’t change images whose files have no %s' ), reprocess.missing ) }
					help={ skip
						? sprintf( __( 'Each image’s file is read again. An image whose file has no %1$s keeps its %2$s as they are, in case the metadata was stripped from the file.' ), reprocess.missing, reprocess.terms )
						: sprintf( __( 'Each image’s file is read again. An image whose file has no %1$s has its %2$s emptied.' ), reprocess.missing, reprocess.terms ) }
					checked={ skip }
					onChange={ setSkip }
				/>
			) }
			<p className="photopress-taxonomies__buttons">
				<Button variant={ reprocess ? 'secondary' : 'primary' } onClick={ () => onSave() } disabled={ saving }>{ saveLabel }</Button>
				{ reprocess && (
					<Button variant="primary" onClick={ () => onSave( { ...reprocess.request, skip } ) } disabled={ saving }>
						{ reprocess.label }
					</Button>
				) }
				{ onCancel && <Button variant="tertiary" onClick={ onCancel }>{ __( 'Cancel' ) }</Button> }
			</p>
		</div>
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
		</Editor>
	);
}

/** What a photo with these keywords gets from a parent keyword. */
function ParentPreview( { levels, plural, singular, nested, example, separators } ) {
	const parent = levels.join( '|' );
	const term = example || __( 'Example' );
	const sub = __( 'Subgroup' );
	const tax = plural || __( 'This taxonomy' );
	const def = { singularLabel: singular };

	const rows = [
		[ `${ parent }|${ term }`, [ [ tax, term, '', archiveUrl( def, term ) ] ] ],
		[
			`${ parent }|${ sub }|${ term }`,
			nested
				? [ [ tax, sub, '', archiveUrl( def, sub ) ], [ tax, term, sub, archiveUrl( def, sub ) + '/' + slug( term ) ] ]
				: [ [ tax, term, '', archiveUrl( def, term ) ], [ __( 'Keywords' ), sub, '', '/keyword/' + slug( sub ) ] ],
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
	const [ nested, setNested ] = useState( !! ( def && def.nested ) );
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
		...( nested ? { nested: true } : {} ),
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
			title={ def ? sprintf( __( 'Parent keyword: %s' ), capitalize( def.pluralLabel ) ) : __( 'Add parent keyword' ) }
			saveLabel={ def ? __( 'Save' ) : __( 'Add parent keyword' ) }
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
				help={ __( 'The keyword your photos’ keywords sit under, as named in Lightroom or Capture One. For a parent further down, write its path: Clients › Acme.' ) }
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
			<RadioControl
				label={ __( 'Keywords more than one level down' ) }
				selected={ nested ? 'nested' : 'last' }
				options={ [
					{ value: 'last', label: __( 'Use the last keyword; the levels between go to Keywords' ) },
					{ value: 'nested', label: __( 'Keep the levels as nested terms, each with its own archive page' ) },
				] }
				onChange={ ( v ) => setNested( 'nested' === v ) }
			/>
			<h4>{ __( 'What a photo gets' ) }</h4>
			<ParentPreview
				levels={ levels.length ? levels : [ __( 'Parent' ) ] }
				plural={ plural }
				singular={ singular }
				nested={ nested }
				example={ found && found.examples[ 0 ] }
				separators={ separators }
			/>
			<p className="description">{ __( 'The parent keyword picks the taxonomy; the keywords under it are the terms. A prefixed keyword whose first part is the parent keyword is read the same way.' ) }</p>
		</Editor>
	);
}

function CustomEditor( { def, list, standardTags, onSave, onCancel, saving } ) {
	const used = new Set( list.filter( ( d ) => ! d.parseTagValue && ( ! def || d.id !== def.id ) ).map( ( d ) => d.tag ) );
	const available = ( tag ) => ! standardTags.has( tag ) && ( ! used.has( tag ) || ( def && def.tag === tag ) );
	const common = COMMON_FIELDS.filter( available );
	const rest = Object.keys( xmpLabels ).filter( ( tag ) => available( tag ) && ! COMMON_FIELDS.includes( tag ) );

	const first = def ? def.tag : common[ 0 ];
	const [ tag, setTag ] = useState( first );
	const [ plural, setPlural ] = useState( def ? capitalize( def.pluralLabel ) : capitalize( pluralize( fieldLabel( first ) ) ) );
	const [ singular, setSingular ] = useState( def ? capitalize( def.singularLabel ) : capitalize( fieldLabel( first ) ) );
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
			title={ def ? sprintf( __( 'Custom metadata: %s' ), capitalize( def.pluralLabel ) ) : __( 'Add custom metadata' ) }
			reprocess={ {
				label: __( 'Save and reprocess all images' ),
				missing: sprintf( __( '%s field' ), fieldLabel( tag ) ),
				terms: sprintf( __( '%s terms' ), capitalize( plural || fieldLabel( tag ) ) ),
				request: { all: true, taxonomies: [ id ] },
			} }
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
			<h4>{ __( 'What a photo gets' ) }</h4>
			<p>{ sprintf( __( 'Every value in %1$s becomes a %2$s term, as written.' ), fieldLabel( tag ), singular || __( 'taxonomy' ) ) }</p>
		</Editor>
	);
}

export default function TaxonomySettings( { component } ) {
	const list = component.getSetting( 'custom_taxonomies' ) || [];
	const separators = String( component.getSetting( 'custom_taxonomies_tag_delimiter' ) ?? '' ).trim().split( /\s+/ ).filter( Boolean );
	const { standard, parents, custom } = classify( list );
	const saving = component.state.isAPISaving;
	const saves = useSaves( saving );
	const status = useStatus( saves );
	const [ screen, setScreen ] = useState( null );
	const [ skip, setSkip ] = useState( true );
	const [ notice, setNotice ] = useState( null );
	const [ jobs, setJobs ] = useState( 0 );
	const reprocessAfterSave = useRef( null );
	const delimiter = component.getSetting( 'custom_taxonomies_tag_delimiter' ) ?? '';
	const separatorScope = useScope( { custom_taxonomies_tag_delimiter: delimiter }, saves );

	// Save and reprocess: once the settings are saved, the re-read starts.
	useEffect( () => {
		const request = reprocessAfterSave.current;
		reprocessAfterSave.current = null;

		if ( ! request || component.getError( 'save' ) ) {
			return;
		}
		apiFetch( { path: '/photopress/v1/image-taxonomies/reprocess', method: 'POST', data: request } )
			.then( ( result ) => {
				setNotice( result.queued
					? { status: 'info', text: __( 'Saved. The images will be reprocessed when the re-read now running finishes.' ) }
					: { status: 'success', text: __( 'Saved. The images are being reprocessed in the background; progress is under Photos already uploaded.' ) } );
				setJobs( ( n ) => n + 1 );
			} )
			.catch( ( e ) => setNotice( { status: 'error', text: e.message || __( 'Saved, but the images could not be reprocessed.' ) } ) );
	}, [ saves ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const save = ( next, reprocess ) => {
		reprocessAfterSave.current = reprocess || null;
		setNotice( null );
		component.setSetting( 'custom_taxonomies', next, true );
	};
	const replace = ( index, def, reprocess ) => save( list.map( ( d, i ) => ( i === index ? def : d ) ), reprocess );
	const remove = ( def, what ) => {
		// eslint-disable-next-line no-alert
		if ( window.confirm( sprintf( __( 'Remove %1$s? Its archive pages go. Its terms stay in the database and come back if you add %2$s again.' ), capitalize( def.pluralLabel ), what ) ) ) {
			save( list.filter( ( d, i ) => i !== def.index ) );
		}
	};
	const done = ( apply ) => ( def, reprocess ) => {
		apply( def, reprocess );
		setScreen( null );
	};

	if ( screen ) {
		const close = () => setScreen( null );

		switch ( screen.type ) {
			case 'standard': {
				const def = standard[ screen.kind ] || { ...STANDARD.find( ( s ) => s.kind === screen.kind ), index: -1 };
				const apply = ( d ) => {
					const { kind, index, ...clean } = d; // eslint-disable-line no-unused-vars
					return index < 0 ? save( [ ...list, { ...clean, parseTagValue: false } ] ) : replace( index, clean );
				};
				return <StandardEditor kind={ screen.kind } def={ def } list={ list } onSave={ done( apply ) } onCancel={ close } saving={ saving } />;
			}
			case 'parent': {
				const def = parents.find( ( p ) => p.index === screen.index );
				return (
					<ParentEditor
						def={ def }
						prefill={ screen.prefill }
						list={ list }
						parents={ parents }
						status={ status }
						separators={ separators }
						onSave={ done( ( d, reprocess ) => ( def ? replace( def.index, d, reprocess ) : save( [ ...list, d ], reprocess ) ) ) }
						onCancel={ close }
						saving={ saving }
					/>
				);
			}
			case 'custom': {
				const def = custom.find( ( c ) => c.index === screen.index );
				return (
					<CustomEditor
						def={ def }
						list={ list }
						standardTags={ new Set( STANDARD.map( ( s ) => s.tag ) ) }
						onSave={ done( ( d, reprocess ) => ( def ? replace( def.index, d, reprocess ) : save( [ ...list, d ], reprocess ) ) ) }
						onCancel={ close }
						saving={ saving }
					/>
				);
			}
		}
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
			{ notice && <Notice status={ notice.status } onRemove={ () => setNotice( null ) }>{ notice.text }</Notice> }
			<p className="photopress-taxonomies__intro">
				{ __( 'Image taxonomies let visitors browse your photos by camera, place, person and so on, each with its own archive pages. PhotoPress fills them from the metadata embedded in each photo when it is uploaded.' ) }
			</p>

			<SwitchRow
				label={ __( 'Image taxonomies' ) }
				help={ __( 'Registers the taxonomies below and fills them from each photo’s metadata on upload.' ) }
				checked={ component.getSetting( 'custom_taxonomies_enable' ) }
				onChange={ ( value ) => component.persistSetting( 'custom_taxonomies_enable', value ) }
			/>

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
				intro={ __( 'Keywords filed under a parent keyword, like People › Jane, or people: Jane, go to the parent keyword’s own taxonomy instead of Keywords.' ) }
				action={ <Button variant="primary" onClick={ () => setScreen( { type: 'parent' } ) }>{ __( 'Add parent keyword' ) }</Button> }
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
								{ p.nested && <span className="description"> { __( '(nested)' ) }</span> }
							</td>
							<td>{ parentNames( p ).map( ( n ) => <code key={ n } className="photopress-taxonomies__chip">{ n.replace( /\|/g, ' › ' ) }</code> ) }</td>
							<td><code>{ archiveUrl( p ) }</code></td>
							<td className="num">{ termCount( status, p.id ) }</td>
							<RowActions
								onEdit={ () => setScreen( { type: 'parent', index: p.index } ) }
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
												{ __( 'Add parent keyword' ) }
											</Button>
										) }
									</li>
								);
							} ) }
						</ul>
					</div>
				) }

				<div className="photopress-taxonomies__separators">
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Prefix separators' ) }
						help={ __( 'What comes between a parent keyword and the rest, as in people: Jane. Several: separate them with spaces (: >). Empty: prefixes are not read. Keyword lists from Lightroom and Capture One are always read.' ) }
						value={ delimiter }
						onChange={ ( value ) => component.setSetting( 'custom_taxonomies_tag_delimiter', value ) }
					/>
					<SaveBar
						saving={ saving }
						reprocess={ scopeChoice( separatorScope, __( 'Keywords and parent keyword terms' ) ) }
						onSave={ ( reprocess ) => {
							reprocessAfterSave.current = reprocess || null;
							setNotice( null );
							component.saveSettings();
						} }
					/>
				</div>
			</Section>

			<Section
				title={ __( 'Custom Metadata' ) }
				intro={ __( 'Any other metadata field as a taxonomy. Every value in the field becomes a term, as written.' ) }
				action={ <Button variant="primary" onClick={ () => setScreen( { type: 'custom' } ) }>{ __( 'Add custom metadata' ) }</Button> }
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
								onEdit={ () => setScreen( { type: 'custom', index: c.index } ) }
								onRemove={ () => remove( c, __( 'the field' ) ) }
								removeLabel={ __( 'Delete' ) }
							/>
						</tr>
					) ) }
				</Table>
			</Section>

			<Section title={ __( 'Photos already uploaded' ) }>
				<JobPanel
					type="metadata.reprocess"
					label={ __( 'Re-read image metadata' ) }
					description={ __( 'Reads every image’s embedded metadata again, as on upload: its taxonomies, alt text and description. For after changing the alt text or description templates, or turning a standard taxonomy back on; a change to a parent keyword, the separators or custom metadata can reprocess the images it affects when you save it. It runs in the background; you can leave this page.' ) }
					args={ { force: ! skip } }
					refresh={ saves + jobs }
					note={ ( job ) => {
						if ( ! job.args || ! job.args.taxonomies || ! job.args.taxonomies.length ) {
							return '';
						}
						return job.args.ids && job.args.ids.length ? __( 'the images a change affects, image taxonomies only' ) : __( 'image taxonomies only' );
					} }
					confirm={ skip
						? __( 'Re-read the metadata of every photo? Terms, alt text and descriptions set by hand are replaced by what the files say.' )
						: __( 'Re-read the metadata of every photo, and empty the terms of photos whose files have none? Terms, alt text and descriptions set by hand are replaced by what the files say.' ) }
				/>
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ __( 'Don’t change images whose files have no metadata for a taxonomy' ) }
					help={ skip
						? __( 'Every image’s file is read again. Where a file has no keywords, location, camera, lens or custom field, the image keeps its terms in that taxonomy as they are, in case the metadata was stripped from the file.' )
						: __( 'Every image’s file is read again. Where a file has no keywords, location, camera, lens or custom field, the image’s terms in that taxonomy are emptied.' ) }
					checked={ skip }
					onChange={ setSkip }
				/>
			</Section>
		</div>
	);
}
