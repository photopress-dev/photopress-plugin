/**
 * An image deleted from the media library is taken out of the static
 * galleries that show it, at any depth; the others' images stay. Not with
 * Remove deleted images turned off in the Gallery tab, which is put back
 * afterwards.
 */
const { test, expect } = require( './test' );
const { rest, file } = require( './rest' );
const { wp, lastLine } = require( './wp' );

/** The image ids of each gallery in the post, as saved. */
function galleryIds( post ) {
	const php = `$out = []; $walk = function ( $blocks ) use ( &$walk, &$out ) { foreach ( $blocks as $b ) { if ( 'core/gallery' === $b['blockName'] ) { $out[] = array_map( fn( $i ) => $i['attrs']['id'] ?? 0, $b['innerBlocks'] ); } else { $walk( $b['innerBlocks'] ); } } }; $walk( parse_blocks( get_post( ${ Number( post ) } )->post_content ) ); echo wp_json_encode( $out );`;
	return JSON.parse( lastLine( wp( 'eval', php ) ) );
}

const imageBlock = ( id ) => `<!-- wp:image {"id":${ id },"sizeSlug":"large","linkDestination":"none"} -->\n<figure class="wp-block-image size-large"><img src="/wp-content/uploads/${ id }.jpg" alt="" class="wp-image-${ id }"/></figure>\n<!-- /wp:image -->`;

const KEY = 'photopress_core_gallery';
let saved;

const settings = () => JSON.parse( lastLine( wp( 'eval', `echo wp_json_encode( get_option( '${ KEY }', null ) );` ) ) );

test.beforeAll( () => {
	saved = settings();
} );

test.afterAll( () => {
	if ( null === saved ) {
		wp( 'option', 'delete', KEY );
	} else {
		wp( 'option', 'update', KEY, JSON.stringify( saved ), '--format=json' );
	}
} );

const upload = async ( page, name ) => {
	const added = await rest( page, { method: 'POST', route: '/wp/v2/media', file: file( name, `pp-fixture-upload-${ Date.now() }-${ name }` ) } );
	expect( added.status, JSON.stringify( added.data ) ).toBe( 201 );
	return added.data.id;
};

const gallery = ( ids ) => `<!-- wp:gallery {"linkTo":"none"} -->\n<figure class="wp-block-gallery has-nested-images columns-default is-cropped">${ ids.map( imageBlock ).join( '\n\n' ) }</figure>\n<!-- /wp:gallery -->`;

test( 'a deleted image is taken out of the galleries that show it', async ( { page, made } ) => {
	wp( 'option', 'delete', KEY );
	await page.goto( '/' );
	const uploaded = [];
	let post;

	try {
		for ( const name of [ '01-portrait-2x3.jpg', '02-landscape-3x2.jpg' ] ) {
			uploaded.push( await upload( page, name ) );
		}
		const [ deleted, kept ] = uploaded;
		const other = made.images[ 0 ];

		const content = gallery( [ other, deleted, kept ] )
			+ `<!-- wp:group -->\n<div class="wp-block-group">${ gallery( [ deleted ] ) }</div>\n<!-- /wp:group -->`
			+ `<!-- wp:paragraph -->\n<p>Text around the galleries.</p>\n<!-- /wp:paragraph -->`;
		const created = await rest( page, { method: 'POST', route: '/wp/v2/pages', json: { title: 'E2E: galleries of a deleted image', status: 'draft', content } } );
		expect( created.status, JSON.stringify( created.data ) ).toBe( 201 );
		post = created.data.id;
		expect( galleryIds( post ) ).toEqual( [ [ other, deleted, kept ], [ deleted ] ] );

		const removed = await rest( page, { method: 'DELETE', route: `/wp/v2/media/${ deleted }?force=true` } );
		expect( removed.status ).toBe( 200 );
		uploaded.shift();

		// Out of both; the second gallery is left, empty.
		expect( galleryIds( post ) ).toEqual( [ [ other, kept ], [] ] );
		const saved = await rest( page, { route: `/wp/v2/pages/${ post }?context=edit` } );
		expect( saved.data.content.raw ).not.toContain( `wp-image-${ deleted }` );
		expect( saved.data.content.raw ).toContain( '<p>Text around the galleries.</p>' );
		// The same markup as a gallery made with the images left.
		expect( saved.data.content.raw ).toContain( gallery( [ other, kept ] ) );
	} finally {
		for ( const id of uploaded ) {
			await rest( page, { method: 'DELETE', route: `/wp/v2/media/${ id }?force=true` } );
		}
		if ( post ) {
			await rest( page, { method: 'DELETE', route: `/wp/v2/pages/${ post }?force=true` } );
		}
	}
} );

test( 'with Remove deleted images off, galleries keep a deleted image', async ( { page, made } ) => {
	wp( 'option', 'update', KEY, JSON.stringify( { remove_deleted_images: false } ), '--format=json' );
	await page.goto( '/' );
	let image;
	let post;

	try {
		image = await upload( page, '03-square.jpg' );
		const id = image;
		const created = await rest( page, { method: 'POST', route: '/wp/v2/pages', json: { title: 'E2E: gallery of a deleted image, kept', status: 'draft', content: gallery( [ made.images[ 0 ], image ] ) } } );
		expect( created.status, JSON.stringify( created.data ) ).toBe( 201 );
		post = created.data.id;

		const removed = await rest( page, { method: 'DELETE', route: `/wp/v2/media/${ image }?force=true` } );
		expect( removed.status ).toBe( 200 );
		image = null;

		expect( galleryIds( post ) ).toEqual( [ [ made.images[ 0 ], id ] ] );
	} finally {
		if ( image ) {
			await rest( page, { method: 'DELETE', route: `/wp/v2/media/${ image }?force=true` } );
		}
		if ( post ) {
			await rest( page, { method: 'DELETE', route: `/wp/v2/pages/${ post }?force=true` } );
		}
	}
} );

test( 'a post another user has open in the editor is left, and done by the retry once they are not', async ( { page, made } ) => {
	wp( 'option', 'delete', KEY );
	await page.goto( '/' );
	const editor = Number( lastLine( wp( 'user', 'create', `pp-e2e-lock-${ Date.now() }`, `pp-e2e-lock-${ Date.now() }@example.com`, '--role=editor', '--porcelain' ) ) );
	let image;
	let post;

	try {
		image = await upload( page, '03-square.jpg' );
		const id = image;
		const created = await rest( page, { method: 'POST', route: '/wp/v2/pages', json: { title: 'E2E: gallery of a deleted image, being edited', status: 'draft', content: gallery( [ made.images[ 0 ], image ] ) } } );
		expect( created.status, JSON.stringify( created.data ) ).toBe( 201 );
		post = created.data.id;
		wp( 'post', 'meta', 'update', String( post ), '_edit_lock', `${ Math.floor( Date.now() / 1000 ) }:${ editor }` );

		const removed = await rest( page, { method: 'DELETE', route: `/wp/v2/media/${ image }?force=true` } );
		expect( removed.status ).toBe( 200 );
		image = null;

		expect( galleryIds( post ) ).toEqual( [ [ made.images[ 0 ], id ] ] );
		const retry = JSON.parse( lastLine( wp( 'eval', `echo wp_json_encode( array_values( array_map( fn( $a ) => $a->get_args(), as_get_scheduled_actions( [ 'hook' => 'photopress_gallery_remove_retry', 'status' => 'pending', 'group' => 'photopress' ] ) ) ) );` ) ) );
		const mine = retry.find( ( args ) => args[ 0 ] === post );
		expect( mine?.[ 1 ] ).toEqual( [ id ] );

		// The editor gone, the retry takes the image out.
		wp( 'post', 'meta', 'delete', String( post ), '_edit_lock' );
		wp( 'eval', `do_action( 'photopress_gallery_remove_retry', ...${ JSON.stringify( mine ) } );` );
		expect( galleryIds( post ) ).toEqual( [ [ made.images[ 0 ] ] ] );
	} finally {
		wp( 'eval', `foreach ( as_get_scheduled_actions( [ 'hook' => 'photopress_gallery_remove_retry', 'status' => 'pending', 'group' => 'photopress' ] ) as $id => $a ) { if ( ( $a->get_args()[0] ?? 0 ) === ${ post || 0 } ) { ActionScheduler::store()->cancel_action( $id ); } }` );
		if ( image ) {
			await rest( page, { method: 'DELETE', route: `/wp/v2/media/${ image }?force=true` } );
		}
		if ( post ) {
			await rest( page, { method: 'DELETE', route: `/wp/v2/pages/${ post }?force=true` } );
		}
		wp( 'user', 'delete', String( editor ), '--yes' );
	}
} );
