/**
 * A metadata template, edited as text with each field shown as a pill:
 * "Photo of [Person Shown ×] in [City ×]." It is saved as text with each
 * field's tag in square brackets, "Photo of [Iptc4xmpExt:PersonInImage] in
 * [photoshop:City].", which the server fills from each image
 * (metadata::fillAltTemplate). A tag typed in brackets becomes a pill when
 * the field is left.
 */
import { useEffect, useRef } from '@wordpress/element';
import { BaseControl, Button, Dropdown, MenuGroup, MenuItem } from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import { __, sprintf } from '@wordpress/i18n';

const field = ( tag, label, example ) => ( { tag, label, example } );

/**
 * The fields a template can use: those a photo's text is made of. Each is
 * one value, or a list joined with commas.
 */
export const TEMPLATE_FIELDS = [
	{
		label: __( 'The photo' ),
		fields: [
			field( 'photoshop:Headline', __( 'Headline' ), 'Sunset over the bay' ),
			field( 'dc:title', __( 'Title' ), 'Golden Hour' ),
			field( 'dc:description', __( 'Description' ), 'The sun setting behind the Golden Gate Bridge.' ),
			field( 'photopress:stringOfKeywords', __( 'Keywords' ), 'sunset, bridge, fog' ),
			field( 'Iptc4xmpExt:PersonInImage', __( 'Person Shown' ), 'Jane Smith' ),
			field( 'Iptc4xmpExt:OrganisationInImageName', __( 'Organization Shown' ), 'Acme Corporation' ),
			field( 'Iptc4xmpExt:Event', __( 'Event' ), 'Maker Faire' ),
		],
	},
	{
		label: __( 'Where it was taken' ),
		fields: [
			field( 'Iptc4xmpCore:Location', __( 'Sublocation' ), 'Golden Gate Park' ),
			field( 'photoshop:City', __( 'City' ), 'San Francisco' ),
			field( 'photoshop:State', __( 'State/Province' ), 'California' ),
			field( 'photoshop:Country', __( 'Country' ), 'United States' ),
		],
	},
	{
		label: __( 'Who made it' ),
		fields: [
			field( 'dc:creator', __( 'Creator' ), 'Jane Smith' ),
			field( 'photoshop:Credit', __( 'Credit Line' ), 'Jane Smith Photography' ),
			field( 'dc:rights', __( 'Copyright Notice' ), '© 2024 Jane Smith' ),
		],
	},
	{
		label: __( 'Camera' ),
		fields: [
			field( 'photopress:camera', __( 'Camera' ), 'Canon EOS R5' ),
			field( 'aux:Lens', __( 'Lens' ), 'RF 24-70mm F2.8 L IS USM' ),
		],
	},
];

const BY_TAG = Object.fromEntries( TEMPLATE_FIELDS.flatMap( ( group ) => group.fields.map( ( f ) => [ f.tag, f ] ) ) );

/** A tag's field, or one named by the tag itself, as a template saved before may have. */
const fieldOf = ( tag ) => BY_TAG[ tag ] || field( tag, tag, '' );

const TAG = /\[([^\]]+)\]/g;

/** The template's text and fields, in order: { text } or { tag }. */
export function parseTemplate( template ) {
	const parts = [];
	let at = 0;
	for ( const match of String( template || '' ).matchAll( TAG ) ) {
		if ( match.index > at ) {
			parts.push( { text: template.slice( at, match.index ) } );
		}
		parts.push( { tag: match[ 1 ].trim() } );
		at = match.index + match[ 0 ].length;
	}
	if ( at < String( template || '' ).length ) {
		parts.push( { text: template.slice( at ) } );
	}
	return parts;
}

/**
 * What the template gives a photo with each field's example value, as the
 * server fills it: the separators left by fields with no value removed.
 */
export function exampleOf( template ) {
	const filled = parseTemplate( template ).map( ( part ) => ( 'tag' in part ? fieldOf( part.tag ).example : part.text ) ).join( '' );
	return filled
		.replace( /([.,;:|–—-])(\s*[.,;:|–—-])+/gu, '$1' )
		.replace( /^[\s.,;:|–—-]+/u, '' )
		.replace( /\s+/gu, ' ' )
		.trim();
}

function pillNode( tag ) {
	const { label } = fieldOf( tag );
	const pill = document.createElement( 'span' );
	pill.className = 'photopress-template__pill';
	pill.contentEditable = 'false';
	pill.dataset.tag = tag;
	pill.title = `[${ tag }]`;
	pill.append( document.createTextNode( label ) );

	const remove = document.createElement( 'button' );
	remove.type = 'button';
	remove.tabIndex = -1;
	remove.className = 'photopress-template__remove';
	remove.setAttribute( 'aria-label', sprintf( __( 'Remove %s' ), label ) );
	remove.textContent = '×';
	pill.append( remove );

	return pill;
}

/** The editor's content, from a template. */
function render( editor, template ) {
	editor.replaceChildren( ...parseTemplate( template ).map( ( part ) => ( 'tag' in part ? pillNode( part.tag ) : document.createTextNode( part.text ) ) ) );
}

/** The template, from the editor's content; whatever the browser added around the text is read as text. */
function serialize( node ) {
	return Array.from( node.childNodes ).map( ( child ) => {
		if ( child.nodeType === Node.TEXT_NODE ) {
			return child.textContent;
		}
		if ( child.dataset && child.dataset.tag ) {
			return `[${ child.dataset.tag }]`;
		}
		return serialize( child );
	} ).join( '' ).replace( /[\r\n ]+/g, ' ' );
}

/** Whether text typed in the editor has a tag in brackets, to show as a pill. */
const typedTag = ( editor ) => Array.from( editor.childNodes ).some( ( child ) => child.nodeType === Node.TEXT_NODE && /\[[^\]]+\]/.test( child.textContent ) );

export default function TemplateEditor( { id, label, help, value, onChange, onBlur } ) {
	const instance = useInstanceId( TemplateEditor );
	const editor = useRef( null );
	// The template last shown or typed, so a change from here is not shown again.
	const shown = useRef( null );
	// Where an inserted field goes: the caret when the editor was last used.
	const caret = useRef( null );

	useEffect( () => {
		if ( value !== shown.current ) {
			render( editor.current, value || '' );
			shown.current = value;
		}
	}, [ value ] );

	useEffect( () => {
		const remember = () => {
			const selection = window.getSelection();
			if ( selection.rangeCount && editor.current && editor.current.contains( selection.anchorNode ) ) {
				caret.current = selection.getRangeAt( 0 ).cloneRange();
			}
		};
		document.addEventListener( 'selectionchange', remember );
		return () => document.removeEventListener( 'selectionchange', remember );
	}, [] );

	const changed = () => {
		const next = serialize( editor.current );
		shown.current = next;
		onChange( next );
	};

	/** Puts a node at the caret, or at the end, with the caret after it. */
	const insertNode = ( node ) => {
		let range = caret.current;
		if ( ! range || ! editor.current.contains( range.startContainer ) ) {
			range = document.createRange();
			range.selectNodeContents( editor.current );
			range.collapse( false );
		}
		range.deleteContents();
		range.insertNode( node );

		const after = document.createRange();
		after.setStartAfter( node );
		after.collapse( true );
		editor.current.focus();
		const selection = window.getSelection();
		selection.removeAllRanges();
		selection.addRange( after );
		caret.current = after.cloneRange();
		changed();
	};

	const onClick = ( event ) => {
		const remove = event.target.closest( '.photopress-template__remove' );
		if ( remove ) {
			remove.parentNode.remove();
			changed();
		}
	};

	const onKeyDown = ( event ) => {
		// One line.
		if ( 'Enter' === event.key ) {
			event.preventDefault();
		}
	};

	const onPaste = ( event ) => {
		event.preventDefault();
		insertNode( document.createTextNode( event.clipboardData.getData( 'text/plain' ).replace( /\s+/g, ' ' ) ) );
	};

	const leave = ( event ) => {
		if ( typedTag( editor.current ) ) {
			render( editor.current, serialize( editor.current ) );
		}
		if ( onBlur ) {
			onBlur( event );
		}
	};

	const labelId = `photopress-template-${ instance }-label`;
	const helpId = `photopress-template-${ instance }-help`;
	const example = exampleOf( value );

	return (
		<div className="components-base-control photopress-template">
			<div className="components-base-control__field">
				<BaseControl.VisualLabel id={ labelId }>{ label }</BaseControl.VisualLabel>
				<div className="photopress-template__row">
					<div
						ref={ editor }
						id={ id }
						className="photopress-template__input"
						role="textbox"
						aria-multiline="false"
						aria-labelledby={ labelId }
						aria-describedby={ helpId }
						contentEditable
						suppressContentEditableWarning
						onInput={ changed }
						onClick={ onClick }
						onKeyDown={ onKeyDown }
						onPaste={ onPaste }
						onBlur={ leave }
					/>
					<Dropdown
						popoverProps={ { placement: 'bottom-end' } }
						renderToggle={ ( { isOpen, onToggle } ) => (
							<Button variant="secondary" aria-expanded={ isOpen } onClick={ onToggle } className="photopress-template__insert">
								{ __( 'Insert field' ) }
							</Button>
						) }
						renderContent={ ( { onClose } ) => TEMPLATE_FIELDS.map( ( group ) => (
							<MenuGroup key={ group.label } label={ group.label }>
								{ group.fields.map( ( f ) => (
									<MenuItem
										key={ f.tag }
										onClick={ () => {
											onClose();
											insertNode( pillNode( f.tag ) );
										} }
									>
										{ f.label }
									</MenuItem>
								) ) }
							</MenuGroup>
						) ) }
					/>
				</div>
			</div>
			<p id={ helpId } className="components-base-control__help">
				{ help }
				{ example && <> { sprintf( __( 'For example: “%s”' ), example ) }</> }
			</p>
		</div>
	);
}
