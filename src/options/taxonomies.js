/**
 * The Image Taxonomies section of the Metadata settings tab: Standard
 * Metadata, Hierarchical Keyword Metadata (parent keywords) and Custom
 * Metadata, each with its add and edit screens, and re-reading the photos
 * already uploaded. All are kept in the custom_taxonomies setting; see
 * taxonomy-model.js and modules/metadata/TaxonomyModel.php.
 */
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Button, FormToggle, Notice, RadioControl, SelectControl, TextControl } from '@wordpress/components';
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

/** Each taxonomy's term count and the prefixes nobody takes, from the server. */
function useStatus( list ) {
	const [ status, setStatus ] = useState( { counts: {}, prefixes: [] } );
	const key = JSON.stringify( list );

	useEffect( () => {
		apiFetch( { path: '/photopress/v1/image-taxonomies' } )
			.then( setStatus )
			.catch( () => {} );
	}, [ key ] );

	return status;
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
 * An add or edit screen: a title, its fields, Save and Cancel.
 */
function Editor( { title, error, onSave, onCancel, saving, saveLabel = __( 'Save' ), children } ) {
	return (
		<div className="photopress-taxonomies__editor">
			<Button variant="link" onClick={ onCancel }>{ __( '← Image taxonomies' ) }</Button>
			<h3>{ title }</h3>
			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ children }
			<p className="photopress-taxonomies__buttons">
				<Button variant="primary" onClick={ onSave } disabled={ saving }>{ saveLabel }</Button>
				<Button variant="secondary" onClick={ onCancel }>{ __( 'Cancel' ) }</Button>
			</p>
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

	const save = () => {
		const all = [ asPath( parent ), ...namesFrom( aka ).map( asPath ) ].filter( Boolean );
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

		onSave( {
			id: def ? def.id : newId( singular, list ),
			pluralLabel: plural.trim(),
			singularLabel: singular.trim(),
			tag: 'dc:subject',
			parseTagValue: true,
			names: all,
			...( nested ? { nested: true } : {} ),
			...( def && def.disabled ? { disabled: true } : {} ),
		} );
	};

	return (
		<Editor
			title={ def ? sprintf( __( 'Parent keyword: %s' ), capitalize( def.pluralLabel ) ) : __( 'Add parent keyword' ) }
			saveLabel={ def ? __( 'Save' ) : __( 'Add parent keyword' ) }
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

	const save = () => {
		const problem = namesProblem( plural, singular, list, def ? def.index : -1 );
		if ( problem ) {
			return setError( problem );
		}
		onSave( {
			id: def ? def.id : newId( singular, list ),
			pluralLabel: plural.trim(),
			singularLabel: singular.trim(),
			tag,
			parseTagValue: false,
			...( def && def.disabled ? { disabled: true } : {} ),
		} );
	};

	const option = ( t ) => ( { value: t, label: `${ fieldLabel( t ) } (${ t })` } );

	return (
		<Editor
			title={ def ? sprintf( __( 'Custom metadata: %s' ), capitalize( def.pluralLabel ) ) : __( 'Add custom metadata' ) }
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
	const status = useStatus( list );
	const [ screen, setScreen ] = useState( null );
	const [ force, setForce ] = useState( false );
	const saving = component.state.isAPISaving;

	const save = ( next ) => component.setSetting( 'custom_taxonomies', next, true );
	const replace = ( index, def ) => save( list.map( ( d, i ) => ( i === index ? def : d ) ) );
	const remove = ( def, what ) => {
		// eslint-disable-next-line no-alert
		if ( window.confirm( sprintf( __( 'Remove %1$s? Its archive pages go. Its terms stay in the database and come back if you add %2$s again.' ), capitalize( def.pluralLabel ), what ) ) ) {
			save( list.filter( ( d, i ) => i !== def.index ) );
		}
	};
	const done = ( apply ) => ( def ) => {
		apply( def );
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
						onSave={ done( ( d ) => ( def ? replace( def.index, d ) : save( [ ...list, d ] ) ) ) }
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
						onSave={ done( ( d ) => ( def ? replace( def.index, d ) : save( [ ...list, d ] ) ) ) }
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
						value={ component.getSetting( 'custom_taxonomies_tag_delimiter' ) ?? '' }
						onChange={ ( value ) => component.setSetting( 'custom_taxonomies_tag_delimiter', value ) }
					/>
					<Button variant="secondary" disabled={ saving } onClick={ () => component.saveSettings() }>{ __( 'Save' ) }</Button>
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
					description={ __( 'Changes here apply to photos uploaded from now on. This reads every photo’s embedded metadata again, as on upload: its taxonomies, alt text and description. A photo whose file has no keywords at all, or no location, camera or lens, keeps its terms there, since its file may have had its metadata stripped. It runs in the background; you can leave this page.' ) }
					args={ { force } }
					confirm={ force
						? __( 'Re-read the metadata of every photo, and empty the terms of photos whose files have none? Terms, alt text and descriptions set by hand are replaced by what the files say.' )
						: __( 'Re-read the metadata of every photo? Terms, alt text and descriptions set by hand are replaced by what the files say.' ) }
				/>
				<SwitchRow
					label={ __( 'Also empty terms a file has nothing for' ) }
					help={ __( 'Removes the keywords, location, camera or lens terms of photos whose files have none, as when every keyword was removed from a file.' ) }
					checked={ force }
					onChange={ setForce }
				/>
			</Section>
		</div>
	);
}
