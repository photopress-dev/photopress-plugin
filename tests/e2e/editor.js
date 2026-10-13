/**
 * The block editor and Site Editor, for the editor specs (editor-*.spec.js).
 *
 * They run only with PP_E2E_EDITOR=1, as in CI, where the browser has memory
 * to spare; never against the test site from its own server, where loading
 * the editor exhausted memory.
 *
 * Blocks are inserted and read through the editor's own data stores
 * (wp.data), as the editor's code does; controls are used as a person would,
 * by their labels.
 */
const { test, expect } = require( './test' );
const { wp, lastLine } = require( './wp' );

function editorOnly() {
	test.skip( ! process.env.PP_E2E_EDITOR, 'The block editor runs only with PP_E2E_EDITOR=1, as in CI.' );
}

/** Its welcome guide and pattern chooser turned off, and any dialog closed. */
async function settle( page ) {
	await page.evaluate( () => {
		const preferences = window.wp.data.dispatch( 'core/preferences' );
		for ( const [ scope, key ] of [ [ 'core/edit-post', 'welcomeGuide' ], [ 'core', 'welcomeGuide' ], [ 'core/edit-site', 'welcomeGuide' ], [ 'core/edit-site', 'welcomeGuideTemplate' ], [ 'core/edit-site', 'welcomeGuidePage' ], [ 'core/edit-site', 'welcomeGuideStyles' ], [ 'core', 'enableChoosePatternModal' ] ] ) {
			preferences.set( scope, key, false );
		}
	} );
	for ( const dialog of await page.getByRole( 'dialog' ).all() ) {
		if ( await dialog.isVisible() ) {
			await page.keyboard.press( 'Escape' );
		}
	}
}

let preferred = false;

/**
 * The editor's preferences for the test user, saved where WordPress keeps
 * them: no starter patterns for an empty page, which open in a dialog over
 * the editor, and no welcome guides. Once a run.
 */
function preferences() {
	if ( preferred ) {
		return;
	}
	wp( 'eval', `
		$user = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0];
		$key = $GLOBALS['wpdb']->get_blog_prefix() . 'persisted_preferences';
		$saved = (array) get_user_meta( $user->ID, $key, true );
		$saved['core'] = array_merge( (array) ( $saved['core'] ?? [] ), [ 'enableChoosePatternModal' => false, 'welcomeGuide' => false ] );
		$saved['core/edit-post'] = array_merge( (array) ( $saved['core/edit-post'] ?? [] ), [ 'welcomeGuide' => false ] );
		$saved['core/edit-site'] = array_merge( (array) ( $saved['core/edit-site'] ?? [] ), [ 'welcomeGuide' => false, 'welcomeGuideTemplate' => false, 'welcomeGuidePage' => false, 'welcomeGuideStyles' => false ] );
		$saved['_modified'] = gmdate( 'c' );
		update_user_meta( $user->ID, $key, $saved );
	` );
	preferred = true;
}

/** A new post or page in the editor, or an existing one by ID. */
async function openEditor( page, { type = 'post', id = 0 } = {} ) {
	preferences();
	await page.goto( id ? `/wp-admin/post.php?post=${ id }&action=edit` : `/wp-admin/post-new.php?post_type=${ type }` );
	await page.waitForFunction( () => window.wp?.data?.select( 'core/editor' )?.getCurrentPostId() && window.wp.blocks.getBlockTypes().length > 0, null, { timeout: 60000 } );
	await settle( page );
	// Should a dialog still open a moment later, over the editor: closed.
	const late = page.getByRole( 'dialog' );
	if ( await late.waitFor( { timeout: 1500 } ).then( () => true, () => false ) ) {
		await page.keyboard.press( 'Escape' );
		await late.waitFor( { state: 'hidden' } );
	}
}

/** Where the blocks are drawn: the editor's iframe, when it has one. */
function canvas( page ) {
	return page.frameLocator( 'iframe[name="editor-canvas"]' );
}

/**
 * A block inserted at the end, with its attributes and inner blocks:
 * [ name, attributes, inner ] each. Returns its client ID.
 */
async function insertBlock( page, name, attributes = {}, inner = [] ) {
	return page.evaluate( ( [ blockName, blockAttributes, innerBlocks ] ) => {
		const { createBlock } = window.wp.blocks;
		const make = ( [ n, a, i = [] ] ) => createBlock( n, a, i.map( make ) );
		const block = make( [ blockName, blockAttributes, innerBlocks ] );
		window.wp.data.dispatch( 'core/block-editor' ).insertBlocks( block );
		return block.clientId;
	}, [ name, attributes, inner ] );
}

/** Image blocks for these images, for a gallery's inner blocks. */
function imageBlocks( ids ) {
	return ids.map( ( id ) => [ 'core/image', { id, sizeSlug: 'large', linkDestination: 'none' } ] );
}

/** The editor's blocks: name, attributes, whether valid, and inner blocks. */
async function blocks( page ) {
	return page.evaluate( () => {
		const tree = ( list ) => list.map( ( b ) => ( { name: b.name, clientId: b.clientId, attributes: b.attributes, isValid: b.isValid, innerBlocks: tree( b.innerBlocks ) } ) );
		return tree( window.wp.data.select( 'core/block-editor' ).getBlocks() );
	} );
}

/** Blocks the editor could not read: "This block contains unexpected or invalid content". */
async function invalidBlocks( page ) {
	const flat = ( list ) => list.flatMap( ( b ) => [ b, ...flat( b.innerBlocks ) ] );
	return flat( await blocks( page ) ).filter( ( b ) => ! b.isValid ).map( ( b ) => b.name );
}

/** A block selected, with the settings sidebar open on it. */
async function selectBlock( page, clientId ) {
	await page.evaluate( ( id ) => {
		const { dispatch } = window.wp.data;
		dispatch( 'core/block-editor' ).selectBlock( id );
		// The editor package has it since WordPress 6.6; before, the post editor did.
		const editor = dispatch( 'core/editor' )?.openGeneralSidebar ? dispatch( 'core/editor' ) : dispatch( 'core/edit-post' );
		editor?.openGeneralSidebar?.( 'edit-post/block' );
	}, clientId );
	const sidebar = page.getByRole( 'region', { name: /Editor settings/i } );
	// Closed on an earlier visit, which the editor remembers: its button.
	if ( ! await sidebar.waitFor( { timeout: 5000 } ).then( () => true, () => false ) ) {
		await page.getByRole( 'region', { name: /Editor top bar/i } ).getByRole( 'button', { name: /^Settings/ } ).click();
	}
	await expect( sidebar ).toBeVisible();
	// Its Block tab, not the post's.
	const blockTab = sidebar.getByRole( 'tab', { name: 'Block', exact: true } );
	if ( await blockTab.isVisible() && 'true' !== await blockTab.getAttribute( 'aria-selected' ) ) {
		await blockTab.click();
	}
	return sidebar;
}

/** A panel of the sidebar opened, if it was closed. */
async function openPanel( sidebar, title ) {
	const toggle = sidebar.getByRole( 'button', { name: title, exact: true } );
	// A block with style settings has them on a tab of their own.
	const tab = sidebar.getByRole( 'tab', { name: 'Settings', exact: true } );
	if ( ! await toggle.isVisible() && await tab.isVisible() ) {
		await tab.click();
	}
	try {
		await toggle.waitFor( { timeout: 10000 } );
	} catch ( error ) {
		throw new Error( `No "${ title }" panel in the sidebar, which has: ${ ( await sidebar.innerText() ).slice( 0, 1500 ) }` );
	}
	if ( 'false' === await toggle.getAttribute( 'aria-expanded' ) ) {
		await toggle.click();
	}
}

/** A block's attributes now. */
async function attributesOf( page, clientId ) {
	return page.evaluate( ( id ) => window.wp.data.select( 'core/block-editor' ).getBlockAttributes( id ), clientId );
}

/**
 * The post saved, published unless a status is given. Returns its ID and
 * address.
 */
async function save( page, status = 'publish' ) {
	return page.evaluate( async ( postStatus ) => {
		const { dispatch, select } = window.wp.data;
		dispatch( 'core/editor' ).editPost( { status: postStatus } );
		await dispatch( 'core/editor' ).savePost();
		const editor = select( 'core/editor' );
		if ( editor.didPostSaveRequestFail() ) {
			throw new Error( 'The post was not saved.' );
		}
		return { id: editor.getCurrentPostId(), link: editor.getPermalink() };
	}, status );
}

/** A post's content as saved. */
function savedContent( id ) {
	return JSON.parse( lastLine( wp( 'eval', `echo wp_json_encode( get_post_field( 'post_content', ${ Number( id ) } ) );` ) ) );
}

/** Posts made by a test, deleted. */
function deletePosts( ids ) {
	const list = ids.filter( Boolean ).map( Number );
	if ( list.length ) {
		wp( 'eval', `foreach ( [ ${ list.join( ',' ) } ] as $id ) { wp_delete_post( $id, true ); }` );
	}
}

/** A post made as it would be saved by an older version, for the editor to open. */
function makePost( content, type = 'page' ) {
	const encoded = Buffer.from( content ).toString( 'base64' );
	return Number( lastLine( wp( 'eval', `echo wp_insert_post( [ 'post_type' => '${ type }', 'post_status' => 'draft', 'post_title' => 'E2E editor ${ Date.now() }', 'post_content' => wp_slash( base64_decode( '${ encoded }' ) ) ] );` ) ) );
}

module.exports = {
	editorOnly,
	openEditor,
	canvas,
	insertBlock,
	imageBlocks,
	blocks,
	invalidBlocks,
	selectBlock,
	openPanel,
	attributesOf,
	save,
	savedContent,
	deletePosts,
	makePost,
	settle,
};
