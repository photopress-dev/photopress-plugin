/**
 * photopress_gallery: an image sent with a gallery target is added to that
 * post's gallery. Static galleries get an image block in the format of their
 * last one, which the editor must accept; dynamic galleries (WordPress 7.1)
 * get the image by attaching it, when it is new.
 */
const { test, expect } = require( './test' );
const { rest, file } = require( './rest' );
const { wp, lastLine } = require( './wp' );

/**
 * The post's saved blocks, flattened, as WordPress parses them. That a copied
 * block is the markup the editor saves is checked in GalleryAddTest.
 */
function savedBlocks( post ) {
	const blocks = JSON.parse( lastLine( wp( 'eval', `echo wp_json_encode( parse_blocks( get_post( ${ Number( post ) } )->post_content ) );` ) ) );
	const walk = ( list ) => list.filter( ( b ) => b.blockName ).flatMap( ( b ) => [ { name: b.blockName, attributes: b.attrs, html: b.innerHTML }, ...walk( b.innerBlocks ) ] );
	return walk( blocks );
}

const upload = ( page, name, query ) => rest( page, { method: 'POST', route: query ? `/wp/v2/media?${ query }` : '/wp/v2/media', file: file( name, `pp-fixture-upload-${ Date.now() }-${ name }` ) } );

test( 'static gallery: the new image takes the last image\'s format and its own content', async ( { page, made } ) => {
	const post = made.pages.addStatic;
	const uploaded = [];
	// A page to send the REST requests from.
	await page.goto( '/' );

	try {
		// Two galleries and none named: uploaded, but not added.
		const ambiguous = await upload( page, '03-square.jpg', `photopress_gallery[post]=${ post }` );
		expect( ambiguous.status, JSON.stringify( ambiguous.data ) ).toBe( 201 );
		uploaded.push( ambiguous.data.id );
		expect( ambiguous.data.photopress_gallery ).toMatchObject( { added: false, code: 'photopress_gallery_ambiguous', anchors: [ 'linked', 'expand' ] } );

		// Named: added to "linked".
		const added = await upload( page, '01-portrait-2x3.jpg', `photopress_gallery[post]=${ post }&photopress_gallery[gallery]=linked` );
		expect( added.status, JSON.stringify( added.data ) ).toBe( 201 );
		const id = added.data.id;
		uploaded.push( id );
		expect( added.data.photopress_gallery ).toEqual( { mode: 'static', added: true, attached: false, gallery: 'linked' } );
		expect( added.data.post ).toBeFalsy();

		// To "expand" too, on an update of the image; again, nothing to do.
		const update = await rest( page, { method: 'POST', route: `/wp/v2/media/${ id }`, json: { photopress_gallery: { post, gallery: 'expand' } } } );
		expect( update.data.photopress_gallery ).toMatchObject( { mode: 'static', added: true } );
		const again = await rest( page, { method: 'POST', route: `/wp/v2/media/${ id }`, json: { photopress_gallery: { post, gallery: 'expand' } } } );
		expect( again.data.photopress_gallery ).toMatchObject( { mode: 'static', added: false } );
		expect( again.data.photopress_gallery.code ).toBeUndefined();

		// A post that is not there: refused before anything is changed.
		const missing = await upload( page, '03-square.jpg', 'photopress_gallery[post]=999999999' );
		expect( missing.status ).toBe( 400 );

		const blocks = savedBlocks( post );
		const mine = blocks.filter( ( b ) => b.name === 'core/image' && b.attributes.id === id );
		expect( mine ).toHaveLength( 2 );
		const [ linked, expand ] = mine;
		const images = blocks.filter( ( b ) => b.name === 'core/image' );

		// Like for like: the attributes of the gallery's images, with the new
		// id. In "linked", those of its first image, as the resized last one's
		// size and crop are not copied.
		const [ first, , , lightbox ] = images;
		expect( linked.attributes ).toEqual( { ...first.attributes, id } );
		expect( expand.attributes ).toEqual( { ...lightbox.attributes, id } );

		// The image's own file, link, alt text and caption.
		expect( linked.html ).toContain( `<a href="${ added.data.source_url }">` );
		expect( linked.html ).toContain( `src="${ added.data.media_details.sizes.large.source_url }"` );
		expect( linked.html ).toContain( `alt="${ added.data.alt_text }"` );
		expect( linked.html ).toContain( `class="wp-image-${ id }"` );
		if ( added.data.caption.raw ) {
			expect( linked.html ).toContain( `<figcaption class="wp-element-caption">${ added.data.caption.raw }</figcaption>` );
		} else {
			expect( linked.html ).not.toContain( 'figcaption' );
		}
		expect( expand.html ).not.toContain( '<a ' );

		// Replace: the first of a set takes the place of the gallery's images,
		// the rest are added after it. "expand" is left as it was.
		const replacing = await upload( page, '02-landscape-3x2.jpg', `photopress_gallery[post]=${ post }&photopress_gallery[gallery]=linked&photopress_gallery[replace]=true` );
		uploaded.push( replacing.data.id );
		expect( replacing.data.photopress_gallery ).toEqual( { mode: 'static', added: true, attached: false, gallery: 'linked', replaced: true } );
		const following = await upload( page, '03-square.jpg', `photopress_gallery[post]=${ post }&photopress_gallery[gallery]=linked` );
		uploaded.push( following.data.id );
		expect( following.data.photopress_gallery ).toEqual( { mode: 'static', added: true, attached: false, gallery: 'linked' } );

		const after = savedBlocks( post ).filter( ( b ) => b.name === 'core/image' ).map( ( b ) => b.attributes.id );
		expect( after ).toEqual( [ replacing.data.id, following.data.id, ...images.slice( 3 ).map( ( b ) => b.attributes.id ) ] );
		expect( savedBlocks( post ).find( ( b ) => b.attributes.id === replacing.data.id ).attributes ).toEqual( { ...images[ 0 ].attributes, id: replacing.data.id } );
	} finally {
		for ( const id of uploaded ) {
			await rest( page, { method: 'DELETE', route: `/wp/v2/media/${ id }?force=true` } );
		}
	}
} );

test( 'dynamic gallery: a new image is attached, a published one is not moved', async ( { page, made } ) => {
	const post = made.pages.addDynamic;
	const uploaded = [];
	await page.goto( '/' );

	try {
		// Replace is refused here, and the image attached as usual.
		const replace = await upload( page, '01-portrait-2x3.jpg', `photopress_gallery[post]=${ post }&photopress_gallery[replace]=true` );
		expect( replace.status, JSON.stringify( replace.data ) ).toBe( 201 );
		uploaded.push( replace.data.id );
		expect( replace.data.photopress_gallery ).toMatchObject( { mode: 'dynamic', added: true, attached: true, gallery: 'dynamic', replaced: false, code: 'photopress_gallery_replace_dynamic' } );

		const added = await upload( page, '02-landscape-3x2.jpg', `photopress_gallery[post]=${ post }` );
		expect( added.status, JSON.stringify( added.data ) ).toBe( 201 );
		uploaded.push( added.data.id );
		expect( added.data.photopress_gallery ).toEqual( { mode: 'dynamic', added: true, attached: true, gallery: 'dynamic' } );

		// A published image, unattached: replacing it into the gallery would
		// move its attachment page, so it is replaced but not added.
		const loose = await upload( page, '03-square.jpg', '' );
		const other = loose.data.id;
		uploaded.push( other );
		const replaced = await rest( page, { method: 'POST', route: `/photopress/v1/media/${ other }/file?photopress_gallery[post]=${ post }`, file: file( '02-landscape-3x2.jpg', 'IMG_1234.jpg' ) } );
		expect( replaced.status, JSON.stringify( replaced.data ) ).toBe( 200 );
		expect( replaced.data.photopress_gallery ).toMatchObject( { mode: 'dynamic', added: false, code: 'photopress_gallery_reparent' } );
		expect( replaced.data.post ).toBeFalsy();

		// The page shows the attached images, in the gallery and the slideshow.
		await page.goto( `/?page_id=${ post }&preview=true` );
		const gallery = page.locator( 'figure.wp-block-gallery' );
		await expect( gallery.locator( 'img' ) ).toHaveCount( 2 );
		await expect( page.locator( '.photopress-gallery-slideshow__slide' ) ).toHaveCount( 2 );
	} finally {
		for ( const id of uploaded ) {
			await rest( page, { method: 'DELETE', route: `/wp/v2/media/${ id }?force=true` } );
		}
	}
} );
