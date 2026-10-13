/**
 * The templates PhotoPress gives block themes, on the site's own theme
 * (which has none of its own): an image taxonomy's archive shows its public
 * images with Taxonomy Gallery, a Gallery block variation whose layout is the
 * gallery's own setting; on a page it shows the images of a term, or the latest; and an image's page shows the image once, its
 * terms and its exposure details. Neither is offered while its kind of page
 * is off in the Pages & Search tab. Child Pages Gallery, the same variation,
 * shows a page's published child pages. And no other query on an archive page lists drafts.
 *
 * The images, the keyword, the draft page and post made here are deleted
 * afterwards, and the Gallery settings put back.
 */
const path = require( 'path' );
const { test, expect } = require( './test' );
const { wp, lastLine } = require( './wp' );

const KEY = 'photopress_core_gallery';
const stamp = Date.now();
const KEYWORD = `pp-e2e-archive-${ stamp }`;
const DRAFT = `PP E2E draft ${ stamp }`;
let made;
let saved;

const settings = () => JSON.parse( lastLine( wp( 'eval', `echo wp_json_encode( get_option( '${ KEY }', null ) );` ) ) );

const SEARCH = 'photopress_core_search';
let savedSearch;
const pagesOn = () => wp( 'option', 'update', SEARCH, JSON.stringify( { attachment_pages: true, taxonomy_pages: true } ), '--format=json' );

test.beforeAll( () => {
	saved = settings();
	// Image pages and taxonomy pages on, whatever ran before and whatever
	// WordPress's own Attachment pages setting is.
	savedSearch = lastLine( wp( 'eval', `echo wp_json_encode( get_option( '${ SEARCH }', null ) );` ) );
	pagesOn();

	// Three images under the keyword: two public, one attached to a draft
	// page; exposure details on the first; and a draft post.
	made = JSON.parse( lastLine( wp( 'eval', `
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		wp_set_current_user( (int) get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] )[0] );
		$draft_page = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'PP E2E draft page ${ stamp }' ] );
		$draft_post = wp_insert_post( [ 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => '${ DRAFT }' ] );
		$images = [];
		foreach ( [ '01-portrait-2x3.jpg', '02-landscape-3x2.jpg', '03-square.jpg' ] as $i => $name ) {
			$tmp = wp_tempnam( 'pp-e2e-archive' );
			copy( ${ JSON.stringify( path.join( __dirname, '../fixtures/images' ) ) } . '/' . $name, $tmp );
			$id = media_handle_sideload( [ 'name' => "pp-e2e-archive-$i.jpg", 'tmp_name' => $tmp ], 2 === $i ? $draft_page : 0, "PP E2E archive image $i" );
			wp_set_object_terms( $id, '${ KEYWORD }', 'photos_keywords', true );
			// In order, a minute apart, and the newest on the site.
			$date = gmdate( 'Y-m-d H:i:s', time() + 60 * ( $i + 1 ) );
			wp_update_post( [ 'ID' => $id, 'post_date' => get_date_from_gmt( $date ), 'post_date_gmt' => $date ] );
			$images[] = $id;
		}
		$meta = wp_get_attachment_metadata( $images[0] );
		$meta['image_meta'] = array_merge( (array) ( $meta['image_meta'] ?? [] ), [ 'aperture' => '2.8', 'shutter_speed' => '0.004', 'iso' => '400', 'focal_length' => '50', 'camera' => 'Canon EOS R5' ] );
		wp_update_attachment_metadata( $images[0], $meta );
		$term = get_term_by( 'slug', '${ KEYWORD }', 'photos_keywords' );
		echo wp_json_encode( [
			'images'    => $images,
			'draftPage' => $draft_page,
			'draftPost' => $draft_post,
			'archive'   => wp_make_link_relative( get_term_link( $term ) ),
			'page'      => wp_make_link_relative( get_attachment_link( $images[0] ) ),
		] );
	` ) ) );
} );

test.afterAll( () => {
	if ( made ) {
		wp( 'eval', `
			foreach ( ${ JSON.stringify( made.images ) } as $id ) { wp_delete_attachment( $id, true ); }
			wp_delete_post( ${ made.draftPage }, true );
			wp_delete_post( ${ made.draftPost }, true );
			$term = get_term_by( 'slug', '${ KEYWORD }', 'photos_keywords' );
			if ( $term ) { wp_delete_term( $term->term_id, 'photos_keywords' ); }
		` );
	}
	if ( null === saved ) {
		wp( 'option', 'delete', KEY );
	} else {
		wp( 'option', 'update', KEY, JSON.stringify( saved ), '--format=json' );
	}
	if ( 'null' === savedSearch ) {
		wp( 'option', 'delete', SEARCH );
	} else {
		wp( 'option', 'update', SEARCH, savedSearch, '--format=json' );
	}
} );

test( 'an image taxonomy archive shows its public images in a gallery layout', async ( { page } ) => {
	wp( 'option', 'delete', KEY );
	const response = await page.goto( made.archive );
	expect( response.status() ).toBe( 200 );

	const images = page.locator( 'main figure.wp-block-gallery' );
	await expect( images ).toHaveClass( /photopress-layout-masonry/ );
	const [ first, second, attachedToDraft ] = made.images;
	await expect( images.locator( 'img' ) ).toHaveCount( 2 );
	await expect( images.locator( `img.wp-image-${ first }` ) ).toHaveCount( 1 );
	await expect( images.locator( `img.wp-image-${ second }` ) ).toHaveCount( 1 );
	await expect( images.locator( `img.wp-image-${ attachedToDraft }` ) ).toHaveCount( 0 );
	// Each links to its page.
	await expect( images.locator( `a:has(img.wp-image-${ first })` ) ).toHaveAttribute( 'href', new RegExp( `${ made.page.replace( /[/]/g, '\\/' ) }$` ) );
	await expect( page.locator( 'body' ) ).not.toContainText( DRAFT );

	// Laid out in columns of the default width, side by side.
	await expect( images ).toHaveClass( /photopress-laid-out/ );
	const boxes = await images.locator( 'figure.wp-block-image' ).evaluateAll( ( items ) => items.map( ( item ) => {
		const r = item.getBoundingClientRect();
		return { x: Math.round( r.x ), width: Math.round( r.width ) };
	} ) );
	expect( boxes.map( ( b ) => b.width ) ).toEqual( [ 300, 300 ] );
	expect( Math.abs( boxes[ 1 ].x - boxes[ 0 ].x ) ).toBeGreaterThanOrEqual( 300 );

	if ( process.env.PP_E2E_SHOTS ) {
		await page.screenshot( { path: `${ process.env.PP_E2E_SHOTS }/image-archive.png`, fullPage: true } );
	}
} );

test( 'with licensing on, an archive describes each image\'s license once', async ( { page } ) => {
	const META = 'photopress_core_metadata';
	const before = lastLine( wp( 'eval', `echo wp_json_encode( get_option( '${ META }' ) );` ) );
	wp( 'eval', `update_option( '${ META }', array_merge( (array) get_option( '${ META }' ), [ 'embed_licensor_enable' => true, 'licensor_name' => 'Jane Smith', 'web_statement_of_rights' => 'https://www.example.com/license-statement', 'licensor_url' => 'https://www.example.com/purchase-license' ] ) );` );

	try {
		const files = JSON.parse( lastLine( wp( 'eval', `echo wp_json_encode( array_map( 'wp_get_attachment_url', ${ JSON.stringify( made.images.slice( 0, 2 ) ) } ) );` ) ) );
		await page.goto( made.archive );
		const described = await page.locator( 'script[type="application/ld+json"]' ).evaluateAll( ( scripts ) => scripts.flatMap( ( script ) => {
			try {
				const data = JSON.parse( script.textContent );
				return ( Array.isArray( data ) ? data : [ data ] ).map( ( item ) => item.contentUrl ).filter( Boolean );
			} catch ( e ) {
				return [];
			}
		} ) );

		for ( const file of files ) {
			expect( described.filter( ( url ) => url === file ), file ).toHaveLength( 1 );
		}
	} finally {
		wp( 'option', 'update', META, before, '--format=json' );
	}
} );

test( 'other queries on an archive page list published posts only', () => {
	const statuses = JSON.parse( lastLine( wp( 'eval', `
		global $wp_query, $wp_the_query;
		$wp_the_query = $wp_query = new WP_Query();
		$wp_query->query( [ 'photos_keywords' => '${ KEYWORD }' ] );
		$main = array_map( 'intval', wp_list_pluck( $wp_query->posts, 'ID' ) );
		$side = new WP_Query( [ 'post_type' => 'post', 'posts_per_page' => -1, 'fields' => 'ids' ] );
		echo wp_json_encode( [ 'main' => $main, 'side' => array_values( array_unique( array_map( 'get_post_status', $side->posts ) ) ), 'draft' => in_array( ${ made.draftPost }, $side->posts, true ) ] );
	` ) ) );

	expect( statuses.main.sort() ).toEqual( made.images.slice( 0, 2 ).sort() );
	expect( statuses.draft ).toBe( false );
	expect( statuses.side.every( ( s ) => s === 'publish' ) ).toBe( true );
} );

test( 'an image page shows the image once, its terms and its exposure details', async ( { page } ) => {
	wp( 'option', 'delete', KEY );
	expect( ( await page.goto( made.page ) ).status() ).toBe( 200 );

	const [ first ] = made.images;
	await expect( page.locator( '.wp-block-photopress-attachment-image img' ) ).toHaveCount( 1 );
	// Not again before the description.
	await expect( page.locator( `img.wp-image-${ first }` ) ).toHaveCount( 1 );

	const rows = page.locator( '.wp-block-photopress-image-taxonomies .container' );
	await expect( rows.filter( { hasText: 'Keywords:' } ).getByRole( 'link', { name: KEYWORD } ) ).toHaveAttribute( 'href', new RegExp( `${ made.archive.replace( /[/]/g, '\\/' ) }$` ) );
	for ( const [ label, value ] of [ [ 'Camera model', 'Canon EOS R5' ], [ 'Aperture', 'f/2.8' ], [ 'Shutter speed', '1/250 s' ], [ 'ISO', '400' ], [ 'Focal length', '50 mm' ] ] ) {
		await expect( rows.filter( { hasText: `${ label }:` } ) ).toHaveText( `${ label }: ${ value }` );
	}

	if ( process.env.PP_E2E_SHOTS ) {
		await page.screenshot( { path: `${ process.env.PP_E2E_SHOTS }/image-page.png`, fullPage: true } );
	}
} );

test( 'an archive template edited in the Site Editor lays its Taxonomy Gallery out as chosen', async ( { page } ) => {
	wp( 'option', 'delete', KEY );
	// The theme's own copy of Image Archives, as the Site Editor saves it,
	// with the gallery set to Mosaic.
	const edited = Number( lastLine( wp( 'eval', `
		$content = str_replace( '"photopressLayout":"masonry"', '"photopressLayout":"mosaic","photopressRowHeight":200', file_get_contents( WP_PLUGIN_DIR . '/photopress-plugin/modules/gallery/templates/taxonomy-attachment.html' ) );
		$id = wp_insert_post( [ 'post_type' => 'wp_template', 'post_status' => 'publish', 'post_name' => 'taxonomy-attachment', 'post_title' => 'Image Archives', 'post_content' => $content, 'tax_input' => [ 'wp_theme' => [ get_stylesheet() ] ] ] );
		wp_set_object_terms( $id, get_stylesheet(), 'wp_theme' );
		echo $id;
	` ) ) );

	try {
		expect( ( await page.goto( made.archive ) ).status() ).toBe( 200 );
		const images = page.locator( 'main figure.wp-block-gallery' );
		await expect( images ).toHaveClass( /photopress-layout-mosaic/ );
		await expect( images ).toHaveAttribute( 'data-pp-row-height', '200' );
		await expect( images.locator( 'img' ) ).toHaveCount( 2 );
	} finally {
		wp( 'post', 'delete', String( edited ), '--force' );
	}
} );

test( 'on a page, Taxonomy Gallery shows the images of a term, or the latest, public ones only', async ( { page } ) => {
	const [ first, second, attachedToDraft ] = made.images;
	const gallery = ( attrs ) => `<!-- wp:gallery ${ JSON.stringify( { linkTo: 'attachment', sizeSlug: 'large', photopressLayout: 'rows', photopressSource: 'query', ...attrs } ) } -->\n<figure class="wp-block-gallery has-nested-images columns-default is-cropped"></figure>\n<!-- /wp:gallery -->`;
	const term = Number( lastLine( wp( 'eval', `echo get_term_by( 'slug', '${ KEYWORD }', 'photos_keywords' )->term_id;` ) ) );
	const content = gallery( { photopressTaxonomy: 'photos_keywords', photopressTerm: term, photopressCount: 1, photopressOrder: 'date-asc', anchor: 'by-term' } ) + gallery( { photopressCount: 2, anchor: 'latest' } );
	const post = Number( lastLine( wp( 'eval', `echo wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'PP E2E image query ${ stamp }', 'post_content' => ${ JSON.stringify( content ) } ] );` ) ) );

	try {
		await page.goto( `/?page_id=${ post }&preview=true` );

		const byTerm = page.locator( 'figure.wp-block-gallery#by-term' );
		await expect( byTerm ).toHaveClass( /photopress-layout-rows/ );
		await expect( byTerm.locator( 'img' ) ).toHaveCount( 1 );
		await expect( byTerm.locator( `img.wp-image-${ first }` ) ).toHaveCount( 1 );

		// The newest public images: not the one attached to a draft.
		const latest = page.locator( 'figure.wp-block-gallery#latest' );
		await expect( latest.locator( 'img' ) ).toHaveCount( 2 );
		await expect( latest.locator( `img.wp-image-${ second }` ) ).toHaveCount( 1 );
		await expect( latest.locator( `img.wp-image-${ attachedToDraft }` ) ).toHaveCount( 0 );
	} finally {
		wp( 'post', 'delete', String( post ), '--force' );
	}
} );

test( 'Child Pages Gallery shows the published child pages, by featured image or else gallery image; the old Child Pages block shows as one', async ( { page } ) => {
	const [ featured, inGallery, attached ] = made.images;
	const galleryOf = ( id ) => `<!-- wp:gallery {"linkTo":"none"} -->\n<figure class="wp-block-gallery has-nested-images columns-default is-cropped"><!-- wp:image {"id":${ id }} -->\n<figure class="wp-block-image"><img src="/x.jpg" alt="" class="wp-image-${ id }"/></figure>\n<!-- /wp:image --></figure>\n<!-- /wp:gallery -->`;
	const content = `<!-- wp:gallery {"photopressSource":"children","photopressLayout":"masonry","sizeSlug":"medium","anchor":"children"} -->\n<figure class="wp-block-gallery has-nested-images columns-default is-cropped"></figure>\n<!-- /wp:gallery -->`
		+ '<!-- wp:photopress/childpages {"columns":3} /-->';

	const pages = JSON.parse( lastLine( wp( 'eval', `
		$parent = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'PP E2E parent ${ stamp }', 'post_content' => ${ JSON.stringify( content ) } ] );
		$child = static function ( $title, $order, $status = 'publish', $content = '' ) use ( $parent ) {
			return wp_insert_post( wp_slash( [ 'post_type' => 'page', 'post_status' => $status, 'post_parent' => $parent, 'menu_order' => $order, 'post_title' => $title, 'post_content' => $content ] ) );
		};
		$a = $child( 'PP E2E child A ${ stamp }', 1 );
		set_post_thumbnail( $a, ${ featured } );
		$b = $child( 'PP E2E child B ${ stamp }', 2, 'publish', ${ JSON.stringify( galleryOf( inGallery ) ) } );
		$c = $child( 'PP E2E child C ${ stamp }', 3, 'draft' );
		set_post_thumbnail( $c, ${ featured } );
		$d = $child( 'PP E2E child D ${ stamp }', 4 );
		// A dynamic gallery: the image attached to it, as WordPress shows it.
		$e = $child( 'PP E2E child E ${ stamp }', 5, 'publish', '<!-- wp:gallery {"dynamicContent":{"source":"core/attached-media"}} /-->' );
		wp_update_post( [ 'ID' => ${ attached }, 'post_parent' => $e ] );
		echo wp_json_encode( [ 'parent' => $parent, 'children' => [ $a, $b, $c, $d, $e ], 'links' => array_map( static fn( $id ) => wp_make_link_relative( get_permalink( $id ) ), [ $a, $b ] ) ] );
	` ) ) );

	try {
		await page.goto( `/?page_id=${ pages.parent }&preview=true` );

		// A by its featured image, B by its gallery's, E by its dynamic
		// gallery's; C a draft, D without either.
		const gallery = page.locator( 'figure.wp-block-gallery#children' );
		await expect( gallery ).toHaveClass( /photopress-layout-masonry/ );
		const images = gallery.locator( 'img' );
		await expect( images ).toHaveCount( 3 );
		await expect( images.nth( 2 ) ).toHaveClass( new RegExp( `wp-image-${ attached }\\b` ) );
		await expect( images.nth( 0 ) ).toHaveClass( new RegExp( `wp-image-${ featured }\\b` ) );
		await expect( images.nth( 1 ) ).toHaveClass( new RegExp( `wp-image-${ inGallery }\\b` ) );
		await expect( images.nth( 0 ) ).toHaveAttribute( 'srcset', /\S/ );
		await expect( images.nth( 0 ) ).toHaveAttribute( 'sizes', /\(max-width: 300px\) 100vw, 300px$/ );

		const items = gallery.locator( 'figure.wp-block-image' );
		await expect( items.nth( 0 ).locator( 'a' ).first() ).toHaveAttribute( 'href', new RegExp( `${ pages.links[ 0 ] }$` ) );
		await expect( items.nth( 0 ).locator( 'figcaption' ) ).toHaveText( `PP E2E child A ${ stamp }` );
		await expect( items.nth( 1 ).locator( 'figcaption a' ) ).toHaveAttribute( 'href', new RegExp( `${ pages.links[ 1 ] }$` ) );

		// The old block, not yet converted: a Child Pages Gallery in its 3
		// columns, of the same pages.
		const old = page.locator( 'figure.wp-block-gallery.columns-3' );
		await expect( old.locator( 'img' ) ).toHaveCount( 3 );
		await expect( old ).not.toContainText( `PP E2E child C ${ stamp }` );
		await expect( old.locator( `img.wp-image-${ featured }` ) ).toHaveAttribute( 'srcset', /\S/ );
		await expect( old.locator( 'a[target]' ) ).toHaveCount( 0 );
	} finally {
		wp( 'eval', `wp_update_post( [ 'ID' => ${ attached }, 'post_parent' => ${ made.draftPage } ] ); foreach ( ${ JSON.stringify( [ ...pages.children, pages.parent ] ) } as $id ) { wp_delete_post( $id, true ); }` );
	}
} );

test( 'with image pages and taxonomy pages off, no template is offered for them', async ( { page } ) => {
	const registered = () => JSON.parse( lastLine( wp( 'eval', `echo wp_json_encode( array_values( wp_list_pluck( get_block_templates( [ 'slug__in' => [ 'taxonomy-attachment', 'attachment' ] ] ), 'slug' ) ) );` ) ) );
	expect( registered().sort() ).toEqual( [ 'attachment', 'taxonomy-attachment' ] );

	wp( 'option', 'update', SEARCH, JSON.stringify( { attachment_pages: false, taxonomy_pages: false } ), '--format=json' );

	try {
		expect( registered() ).toEqual( [] );
	} finally {
		pagesOn();
	}
} );
