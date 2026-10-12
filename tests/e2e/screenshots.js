/**
 * The screenshots in .wordpress-org, made from the test images (see
 * screenshots.config.js). Each test writes over its own files; the numbers
 * are the order of README.txt's Screenshots section.
 */
const { execFileSync } = require( 'child_process' );
const fs = require( 'fs' );
const path = require( 'path' );
const { test, expect } = require( './test' );
const { wp, lastLine } = require( './wp' );

const OUT = process.env.PP_SHOTS_OUT || path.resolve( __dirname, '../../.wordpress-org' );
const SLIDESHOW = '.wp-block-photopress-gallery-slideshow';

// Captions for the slideshows; the people and places are the test images'
// own (see tests/fixtures/images/generate.sh).
const CAPTIONS = {
	'01-portrait-2x3': 'Alice at the test site in Testville.',
	'02-landscape-3x2': 'Bob in Exampleshire, on the west lawn of the test site in the late afternoon, with the hills behind Testville and the light coming in low from the left.',
	'03-square': 'Carol, square.',
	'04-wide-16x9': 'Dave on the street in Testville.',
	'05-portrait-4x5': 'Erin.',
	'06-panorama-3x1': 'Frank, a panorama of Exampleshire.',
	'07-tall-1x3': 'Grace and the tower.',
};
const NAMES = Object.keys( CAPTIONS );

const pages = {};
let made = null;

/** A draft page, with the fixture meta so that a sweep removes it should this run be killed. */
const draft = ( title, content, extra = '' ) => Number( lastLine( wp( 'eval', `
	$id = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => ${ JSON.stringify( title ) }, 'post_content' => base64_decode( '${ Buffer.from( content ).toString( 'base64' ) }' ) ${ extra } ] );
	update_post_meta( $id, '_pp_test_fixture', 1 );
	echo $id;
` ) ) );

const imageBlock = ( id ) => `<!-- wp:image {"id":${ id },"sizeSlug":"large","linkDestination":"none"} --><figure class="wp-block-image size-large"><img src="${ made.urls[ id ] }" alt="" class="wp-image-${ id }"/></figure><!-- /wp:image -->`;

const gallery = ( attrs, anchor, names = NAMES ) => `<!-- wp:gallery ${ JSON.stringify( { linkTo: 'none', align: 'wide', ...attrs } ) } --><figure class="wp-block-gallery alignwide has-nested-images columns-default is-cropped" id="${ anchor }">${ names.map( ( name ) => imageBlock( made.names[ name ] ) ).join( '' ) }</figure><!-- /wp:gallery -->`;

// In three masonry columns, an order that gives them about the same height.
const MASONRY = [ '07-tall-1x3', '01-portrait-2x3', '05-portrait-4x5', '03-square', '02-landscape-3x2', '04-wide-16x9', '06-panorama-3x1' ];

test.beforeAll( async () => {
	made = JSON.parse( process.env.PP_E2E_FIXTURES );
	const ids = NAMES.map( ( name ) => made.names[ name ] );
	made.urls = JSON.parse( lastLine( wp( 'eval', `$u = []; foreach ( ${ JSON.stringify( ids ) } as $id ) { $u[ $id ] = wp_get_attachment_image_url( $id, 'large' ); } echo wp_json_encode( $u );` ) ) );

	for ( const [ name, caption ] of Object.entries( CAPTIONS ) ) {
		wp( 'post', 'update', String( made.names[ name ] ), `--post_excerpt=${ caption }` );
	}

	pages.masonry = draft( 'Masonry', gallery( { photopressLayout: 'masonry', photopressColumnWidth: 280 }, 'masonry', MASONRY ) );
	pages.rows = draft( 'Rows', gallery( { photopressLayout: 'rows', photopressRowHeight: 220 }, 'rows' ) );
	pages.mosaic = draft( 'Mosaic', gallery( { photopressLayout: 'mosaic', photopressRowHeight: 220 }, 'mosaic' ) );
	pages.lightbox = draft( 'Full-screen slideshow', gallery( { photopressLayout: 'masonry', photopressColumnWidth: 280, photopressSlideshow: true }, 'lightbox', MASONRY ) );
	pages.slideshow = draft( 'Gallery Slideshow', `<!-- wp:photopress/gallery-slideshow {"galleryAnchor":"shots","maxHeightOffset":40,"align":"wide"} /-->${ gallery( {}, 'shots' ) }` );

	// A page of child pages, each with a test image as its featured image.
	pages.parent = draft( 'Places', '<!-- wp:photopress/childpages {"columns":3,"align":"wide"} /-->' );
	[ [ 'Testville', '03-square' ], [ 'Exampleshire', '02-landscape-3x2' ], [ 'Nowhere', '05-portrait-4x5' ] ].forEach( ( [ title, name ], i ) => {
		pages[ `child${ i }` ] = draft( title, '', `, 'post_parent' => ${ pages.parent }, 'menu_order' => ${ i }, 'meta_input' => [ '_thumbnail_id' => ${ made.names[ name ] } ]` );
	} );

} );

test.afterAll( () => {
	const ids = Object.values( pages ).filter( Boolean ).map( String );
	if ( ids.length ) {
		wp( 'post', 'delete', ...ids, '--force' );
	}
} );

/** A front-end page, without the admin bar, scrolled to the selector. */
async function front( page, url, viewport, selector ) {
	await page.setViewportSize( viewport );
	await page.goto( url );
	await page.addStyleTag( { content: '#wpadminbar { display: none !important; } html { margin-top: 0 !important; }' } );
	// The first shown: a theme may have the content twice, one hidden.
	const element = page.locator( selector ).filter( { visible: true } ).first();
	await element.evaluate( ( el ) => window.scrollBy( 0, el.getBoundingClientRect().top - 20 ) );
	await element.locator( 'img' ).evaluateAll( ( imgs ) => Promise.all( imgs.filter( ( img ) => img.src ).map( ( img ) => {
		img.loading = 'eager';
		return img.decode().catch( () => {} );
	} ) ) );
	await page.waitForTimeout( 500 );
	return element;
}

const draftUrl = ( id ) => `/?page_id=${ id }&preview=true`;

/** The box of an element, a little larger, within the window and above `below`. */
async function around( locator, page, margin = 20, below = Infinity ) {
	const box = await locator.boundingBox();
	const { width, height } = page.viewportSize();
	const x = Math.max( 0, Math.floor( box.x - margin ) );
	const y = Math.max( 0, Math.floor( box.y - margin ) );
	const bottom = Math.min( height, box.y + box.height + margin, below );
	return { x, y, width: Math.min( width - x, Math.ceil( box.width + 2 * margin ) ), height: Math.floor( bottom - y ) };
}

// Frames go on disk: os.tmpdir() can be memory (tmpfs).
const tmp = () => fs.mkdtempSync( path.join( __dirname, '.results', 'shots-' ) );

/** Frames of a recording, and the GIF made from them. */
function recorder( page, clip ) {
	const dir = tmp();
	const frames = [];
	return {
		async frame( delay ) {
			const file = path.join( dir, `${ String( frames.length ).padStart( 3, '0' ) }.png` );
			await page.screenshot( { path: file, clip } );
			frames.push( [ delay, file ] );
		},
		save( name ) {
			const args = frames.flatMap( ( [ delay, file ] ) => [ '-delay', String( delay ), file ] );
			// Every frame at once needs a few hundred MB: the limits keep
			// ImageMagick's pixel cache on disk, in the frames' directory.
			execFileSync( 'convert', [ '-limit', 'memory', '32MiB', '-limit', 'map', '64MiB', ...args, '+dither', '-colors', '64', '-loop', '0', '-layers', 'Optimize', path.join( OUT, name ) ], { env: { ...process.env, MAGICK_TEMPORARY_PATH: dir } } );
			fs.rmSync( dir, { recursive: true, force: true } );
		},
	};
}

/** Moves the mouse to a point in a few steps, a frame at each. */
async function glide( page, rec, from, to, steps = 3 ) {
	for ( let step = 1; step <= steps; step++ ) {
		await page.mouse.move( from.x + ( ( to.x - from.x ) * step ) / steps, from.y + ( ( to.y - from.y ) * step ) / steps );
		await rec.frame( 6 );
	}
	return to;
}

for ( const [ n, layout ] of [ [ 1, 'masonry' ], [ 2, 'rows' ], [ 3, 'mosaic' ] ] ) {
	test( `screenshot-${ n }: the ${ layout } gallery layout`, async ( { page } ) => {
		const figure = await front( page, draftUrl( pages[ layout ] ), { width: 1100, height: 1400 }, `#${ layout }` );
		await page.screenshot( { path: path.join( OUT, `screenshot-${ n }.png` ), clip: await around( figure, page ) } );
	} );
}

test( 'screenshot-4: the full-screen slideshow, opened from a gallery', async ( { page } ) => {
	test.setTimeout( 180000 );
	const figure = await front( page, draftUrl( pages.lightbox ), { width: 1100, height: 700 }, '#lightbox' );
	const rec = recorder( page, { x: 0, y: 0, width: 1100, height: 700 } );
	const center = page.locator( '.panels .center img' );
	const settle = async () => {
		await center.evaluate( ( img ) => img.decode() );
		await page.waitForTimeout( 600 );
		await rec.frame( 160 );
	};

	// The screenshots do not show the mouse pointer: the gallery, then the
	// slideshow opened on its landscape image.
	await rec.frame( 150 );
	const target = await figure.locator( `img.wp-image-${ made.names[ '02-landscape-3x2' ] }` ).boundingBox();
	let at = { x: target.x + target.width / 2, y: target.y + target.height / 2 };
	await page.mouse.click( at.x, at.y );
	await expect( center ).toBeVisible( { timeout: 30000 } );
	await settle();

	const panels = await page.locator( '.photopress-slideshow .panels' ).boundingBox();
	const y = panels.y + panels.height / 2;
	for ( const fraction of [ 0.8, 0.8, 0.2 ] ) {
		at = await glide( page, rec, at, { x: panels.x + panels.width * fraction, y } );
		await page.mouse.click( at.x, at.y );
		await settle();
	}
	rec.save( 'screenshot-4.gif' );
} );

test( 'screenshot-6: the Gallery Slideshow, moved on by presses', async ( { page } ) => {
	test.setTimeout( 180000 );
	const root = await front( page, draftUrl( pages.slideshow ), { width: 1100, height: 760 }, SLIDESHOW );
	const below = ( await page.locator( '#shots' ).boundingBox() ).y - 1;
	const rec = recorder( page, await around( root, page, 20, below ) );

	const box = await root.boundingBox();
	const point = ( fraction ) => ( { x: box.x + box.width * fraction, y: box.y + box.height * 0.45 } );
	let at = point( 0.65 );
	await page.mouse.move( at.x, at.y );
	await rec.frame( 120 );

	// Four slides on, then one back. The slide changes on the press.
	for ( const fraction of [ 0.8, 0.8, 0.8, 0.8, 0.2 ] ) {
		at = await glide( page, rec, at, point( fraction ) );
		await page.mouse.down();
		await page.mouse.up();
		await page.locator( `${ SLIDESHOW } .is-current img` ).evaluate( ( img ) => img.decode() );
		await page.waitForTimeout( 400 );
		await rec.frame( 160 );
	}
	rec.save( 'screenshot-6.gif' );
} );

test( 'screenshot-7: caption max width, on a desktop and a phone', async ( { page } ) => {
	const dir = tmp();
	const shot = async ( viewport, name ) => {
		const root = await front( page, draftUrl( pages.slideshow ), viewport, SLIDESHOW );
		// The landscape image, with the long caption.
		await root.evaluate( ( r ) => r.photopressSlideshow.next() );
		await page.locator( `${ SLIDESHOW } .is-current img` ).evaluate( ( img ) => img.decode() );
		await page.waitForTimeout( 800 );
		const below = ( await page.locator( '#shots' ).boundingBox() ).y - 1;
		const file = path.join( dir, name );
		await page.screenshot( { path: file, clip: await around( root, page, 20, below ) } );
		return file;
	};

	const desktop = await shot( { width: 1100, height: 760 }, 'desktop.png' );
	const phone = await shot( { width: 390, height: 760 }, 'phone.png' );
	const background = await page.evaluate( () => getComputedStyle( document.body ).backgroundColor );

	execFileSync( 'convert', [
		'-background', background, '-gravity', 'center',
		desktop, '-size', '40x1', `xc:${ background }`, '(', phone, '-bordercolor', '#cccccc', '-border', '1', ')', '+append',
		'-bordercolor', background, '-border', '20', '+repage',
		path.join( OUT, 'screenshot-7.png' ),
	] );
	fs.rmSync( dir, { recursive: true, force: true } );
} );

test( 'screenshot-8: the Child Pages block', async ( { page } ) => {
	const block = await front( page, draftUrl( pages.parent ), { width: 1100, height: 900 }, '.photopress-childpages' );
	await page.screenshot( { path: path.join( OUT, 'screenshot-8.png' ), clip: await around( block, page ) } );
} );

test( 'screenshot-9: the Image Metadata block, on an image\'s page', async ( { page } ) => {
	const id = made.names[ '01-portrait-2x3' ];
	// The test images have no EXIF; exposure details as a camera writes them.
	wp( 'eval', `$meta = wp_get_attachment_metadata( ${ id } ); $meta['image_meta'] = array_merge( (array) ( $meta['image_meta'] ?? [] ), [ 'camera' => 'Canon EOS R5', 'aperture' => '4', 'shutter_speed' => '0.002', 'iso' => '200', 'focal_length' => '85', 'created_timestamp' => '1757240400' ] ); wp_update_attachment_metadata( ${ id }, $meta );` );
	// Its permalink: ?attachment_id= redirects to it, and PP_E2E_ORIGIN does
	// not follow redirects.
	const url = new URL( lastLine( wp( 'eval', `echo get_permalink( ${ id } );` ) ) );
	const first = await front( page, url.pathname + url.search, { width: 1100, height: 1400 }, '.wp-block-photopress-image-taxonomies' );
	// The image page template has two: the terms, then the exposure details.
	const blocks = page.locator( '.wp-block-photopress-image-taxonomies' ).filter( { visible: true } );
	await expect( blocks, await blocks.evaluateAll( ( els ) => els.map( ( el ) => el.parentElement.className + ' > ' + el.textContent.slice( 0, 40 ) ).join( ' | ' ) ) ).toHaveCount( 2 );
	const last = blocks.last();
	const [ a, b ] = [ await around( first, page ), await around( last, page ) ];
	const x = Math.min( a.x, b.x );
	await page.screenshot( { path: path.join( OUT, 'screenshot-9.png' ), clip: { x, y: a.y, width: Math.max( a.x + a.width, b.x + b.width ) - x, height: b.y + b.height - a.y } } );
} );

const center = ( box ) => ( { x: box.x + box.width / 2, y: box.y + box.height / 2 } );

// A mouse pointer drawn in the page, for the settings recording: screenshots
// leave out the real one.
const POINTER = () => window.addEventListener( 'DOMContentLoaded', () => {
	const pointer = document.createElement( 'div' );
	pointer.style.cssText = 'position:fixed;left:0;top:0;width:20px;height:22px;z-index:2147483647;pointer-events:none;transform:translate(-50px,-50px);'
		+ 'background:url("data:image/svg+xml,' + encodeURIComponent( '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="22"><path d="M2 1v17l4.5-4.2 3 6.7 3-1.3-3-6.6H16z" fill="#000" stroke="#fff" stroke-width="1.5" stroke-linejoin="round"/></svg>' ) + '") no-repeat';
	document.body.appendChild( pointer );
	window.addEventListener( 'mousemove', ( e ) => {
		pointer.style.transform = `translate(${ e.clientX - 2 }px,${ e.clientY - 1 }px)`;
	}, true );
} );

test( 'screenshot-17: an image taxonomy archive, in the Image Archives template', async ( { page } ) => {
	// The keyword every test image has.
	const term = lastLine( wp( 'eval', `$t = get_term_by( 'slug', 'fixture', 'photos_keywords' ); echo $t ? wp_make_link_relative( get_term_link( $t ) ) : '';` ) );
	test.skip( ! term, 'The test images have no fixture keyword.' );
	const block = await front( page, term, { width: 1100, height: 1400 }, 'main figure.wp-block-gallery' );
	await expect( block ).toHaveClass( /photopress-laid-out/ );
	// From the archive's title.
	const heading = page.locator( '.wp-block-query-title' ).first();
	await heading.evaluate( ( el ) => window.scrollBy( 0, el.getBoundingClientRect().top - 20 ) );
	const title = await heading.boundingBox();
	const box = await around( block, page );
	await page.screenshot( { path: path.join( OUT, 'screenshot-17.png' ), clip: { ...box, y: Math.floor( title.y - 20 ), height: box.y + box.height - Math.floor( title.y - 20 ) } } );
} );

test( 'screenshot-19: search results with images, in the Image Search template', async ( { page } ) => {
	// Images in search results, for this shot only: the setting put back.
	const before = lastLine( wp( 'eval', `$o = get_option( 'photopress_core_search' ); echo is_array( $o ) && array_key_exists( 'images_in_search', $o ) ? wp_json_encode( $o['images_in_search'] ) : 'unset';` ) );
	const jobs = JSON.parse( lastLine( wp( 'eval', `echo wp_json_encode( array_values( (array) get_option( 'photopress_jobs', [] ) ) );` ) ) );
	wp( 'eval', `$o = get_option( 'photopress_core_search' ); $o = is_array( $o ) ? $o : []; $o['images_in_search'] = true; update_option( 'photopress_core_search', $o );` );
	// With Relevanssi, the test images in its index now, as its own
	// indexing would have them.
	wp( 'eval', `if ( function_exists( 'relevanssi_index_doc' ) ) { foreach ( ${ JSON.stringify( Object.values( made.names ) ) } as $id ) { relevanssi_index_doc( $id, true, relevanssi_get_custom_fields(), true ); } }` );

	try {
		const results = await front( page, '/?s=fixture', { width: 1100, height: 1400 }, 'main' );
		await expect( results.locator( 'figure.wp-block-gallery.photopress-has-details' ) ).toBeVisible();
		await page.screenshot( { path: path.join( OUT, 'screenshot-19.png' ), clip: await around( results, page, 0 ) } );
	} finally {
		// The indexing job turning images on started.
		wp( 'eval', `
			$before = json_decode( '${ JSON.stringify( jobs ) }', true );
			foreach ( array_diff( (array) get_option( 'photopress_jobs', [] ), $before ) as $id ) {
				delete_option( "photopress_job_$id" );
				as_unschedule_all_actions( 'photopress_job_batch', [ $id ], 'photopress' );
			}
			update_option( 'photopress_jobs', $before, false );
		` );
		wp( 'eval', `$o = get_option( 'photopress_core_search' ); $o = is_array( $o ) ? $o : []; if ( 'unset' === '${ before }' ) { unset( $o['images_in_search'] ); } else { $o['images_in_search'] = json_decode( '${ before }' ); } $o ? update_option( 'photopress_core_search', $o ) : delete_option( 'photopress_core_search' );` );
	}
} );

test.describe( 'settings', () => {
	// Shown on and filled in; the settings are put back afterwards.
	const EXAMPLES = {
		photopress_core_slideshow: { enable: true },
		photopress_core_metadata: {
			alt_text_enable: true,
			description_enable: true,
			description_template: '[dc:description]',
			embed_licensor_enable: true,
			licensor_name: 'Jane Smith',
			web_statement_of_rights: 'https://www.example.com/license-statement',
			licensor_url: 'https://www.example.com/purchase-license',
		},
	};
	const php = ( value ) => `json_decode( base64_decode( '${ Buffer.from( JSON.stringify( value ) ).toString( 'base64' ) }' ), true )`;
	let before;

	test.beforeAll( () => {
		// The settings, the jobs and any Genres terms there were, to put back.
		before = JSON.parse( lastLine( wp( 'eval', `
			global $wpdb;
			$settings = [];
			foreach ( array_keys( ${ php( EXAMPLES ) } ) as $key ) {
				$settings[ $key ] = get_option( $key );
			}
			echo wp_json_encode( [
				'settings' => $settings,
				'jobs'     => array_values( (array) get_option( 'photopress_jobs', [] ) ),
				'genres'   => array_map( 'intval', $wpdb->get_col( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'pp_genre'" ) ),
			] );
		` ) ) );
		wp( 'eval', `foreach ( ${ php( EXAMPLES ) } as $key => $values ) { update_option( $key, array_merge( (array) get_option( $key ), $values ) ); }` );
	} );

	test.afterAll( () => {
		wp( 'eval', `
			$before = ${ php( before ) };
			if ( taxonomy_exists( 'pp_genre' ) ) {
				foreach ( get_terms( [ 'taxonomy' => 'pp_genre', 'hide_empty' => false ] ) as $t ) {
					if ( ! in_array( (int) $t->term_taxonomy_id, $before['genres'], true ) ) {
						wp_delete_term( $t->term_id, 'pp_genre' );
					}
				}
			}
			foreach ( array_diff( (array) get_option( 'photopress_jobs', [] ), $before['jobs'] ) as $id ) {
				delete_option( "photopress_job_$id" );
				as_unschedule_all_actions( 'photopress_job_batch', [ $id ], 'photopress' );
			}
			update_option( 'photopress_jobs', $before['jobs'], false );
			foreach ( $before['settings'] as $key => $value ) {
				update_option( $key, $value );
			}
		` );
	} );

	const tab = async ( page, name ) => {
		await page.goto( '/wp-admin/admin.php?page=photopress-core-base' );
		await page.getByRole( 'tab', { name } ).click();
		return page.locator( '.components-tab-panel__tab-content' );
	};

	const feature = ( page, name ) => page.getByRole( 'region', { name, exact: true } );

	test.describe( 'at twice the size', () => {
		test.use( { viewport: { width: 1100, height: 1400 }, deviceScaleFactor: 2 } );

		test( 'screenshot-5: the Slideshow tab', async ( { page } ) => {
			const content = await tab( page, 'Slideshow' );
			await expect( content ).toContainText( 'Thumbnail' );
			await content.screenshot( { path: path.join( OUT, 'screenshot-5.png' ) } );
		} );

		test( 'screenshot-18: the Pages & Search tab', async ( { page } ) => {
			const content = await tab( page, 'Pages & Search' );
			await expect( feature( page, 'Image taxonomy pages' ) ).toBeVisible( { timeout: 30000 } );
			await content.screenshot( { path: path.join( OUT, 'screenshot-18.png' ) } );
		} );

		test( 'screenshot-16: the Gallery tab', async ( { page } ) => {
			const content = await tab( page, 'Gallery' );
			await expect( feature( page, 'Remove deleted images' ) ).toBeVisible( { timeout: 30000 } );
			await content.screenshot( { path: path.join( OUT, 'screenshot-16.png' ) } );
		} );
	} );

	test( 'screenshot-11: a parent keyword in the photos given a taxonomy, and its images reprocessed', async ( { page } ) => {
		test.setTimeout( 240000 );
		await page.setViewportSize( { width: 1100, height: 1050 } );
		await page.addInitScript( POINTER );

		await tab( page, 'Metadata' );

		// The affected images are the test images only, never the library.
		await page.evaluate( ( ids ) => window.wp.apiFetch.use( ( options, next ) => {
			const result = next( options );
			return ( options.path || '' ).startsWith( '/photopress/v1/image-taxonomies/scope' )
				? result.then( ( scope ) => ( { ...scope, ids: scope.ids.filter( ( id ) => ids.includes( id ) ) } ) )
				: result;
		} ), made.images );

		const found = page.locator( '.photopress-taxonomies__found li' ).filter( { has: page.locator( 'code', { hasText: /^genre$/ } ) } );
		await expect( found ).toBeVisible( { timeout: 30000 } );
		await page.getByRole( 'heading', { name: 'Hierarchical Keyword Metadata' } ).evaluate( ( el ) => window.scrollBy( 0, el.getBoundingClientRect().top - 60 ) );
		await page.waitForTimeout( 300 );

		const card = await page.locator( '.components-tab-panel__tab-content' ).boundingBox();
		const clip = { x: Math.floor( card.x ), y: 32, width: Math.ceil( card.width ), height: 1050 - 32 };
		const rec = recorder( page, clip );
		let at = { x: clip.x + clip.width * 0.6, y: 300 };
		await page.mouse.move( at.x, at.y );
		await rec.frame( 150 );

		// Add taxonomy beside genre: its screen, filled in from the keyword.
		const add = found.getByRole( 'button', { name: 'Add taxonomy' } );
		at = await glide( page, rec, at, center( await add.boundingBox() ), 5 );
		await rec.frame( 50 );
		await add.click();
		const save = page.locator( '.photopress-taxonomies__editor' ).getByRole( 'button', { name: /and reprocess \d+ affected images?$/ } );
		await expect( save ).toBeVisible( { timeout: 30000 } );
		// The examples and the count of affected images, in place.
		await page.waitForTimeout( 2500 );
		await rec.frame( 300 );

		at = await glide( page, rec, at, center( await save.boundingBox() ), 5 );
		await rec.frame( 50 );
		await save.click();
		await rec.frame( 50 );

		// Back on the tab once saved: a frame at each change of the progress.
		const progress = page.locator( '.photopress-job[data-job-type="metadata.reprocess"] .photopress-job__progress' );
		await expect( progress ).toBeVisible( { timeout: 30000 } );
		await rec.frame( 80 );
		let last = '';
		const until = Date.now() + 150000;
		while ( 'done' !== ( await progress.getAttribute( 'data-status' ) ) && Date.now() < until ) {
			const text = await progress.innerText();
			if ( text !== last ) {
				last = text;
				await rec.frame( 80 );
			}
			await page.waitForTimeout( 400 );
		}
		await expect( progress ).toHaveAttribute( 'data-status', 'done' );
		await page.waitForTimeout( 300 );
		await rec.frame( 300 );

		// The new taxonomy, with its terms.
		const genres = page.locator( '.photopress-taxonomies__parents tr[data-taxonomy="pp_genre"]' );
		await expect( genres.locator( 'td.num' ) ).toHaveText( /^[1-9]/, { timeout: 30000 } );
		await page.getByRole( 'heading', { name: 'Hierarchical Keyword Metadata' } ).evaluate( ( el ) => window.scrollBy( 0, el.getBoundingClientRect().top - 60 ) );
		await page.waitForTimeout( 300 );
		await rec.frame( 400 );
		rec.save( 'screenshot-11.gif' );
	} );

	test.describe( 'at twice the size, after', () => {
		test.use( { viewport: { width: 1100, height: 1400 }, deviceScaleFactor: 2 } );

		test( 'screenshot-10: the Image Taxonomies section of the Metadata tab', async ( { page } ) => {
			await tab( page, 'Metadata' );
			const section = feature( page, 'Image Taxonomies' );
			await expect( section.locator( '.photopress-taxonomies__parents tr[data-taxonomy="pp_genre"] td.num' ) ).toHaveText( /^[1-9]/, { timeout: 30000 } );
			await section.screenshot( { path: path.join( OUT, 'screenshot-10.png' ) } );
		} );

		test( 'screenshot-12 and 13: alt text and description templates, and licensing', async ( { page } ) => {
			await tab( page, 'Metadata' );
			const alt = feature( page, 'Alt Text' );
			await expect( alt.locator( '#alt_text_template' ) ).toBeVisible( { timeout: 30000 } );
			// Both cards, from the top of the page.
			const scrolled = await page.evaluate( () => window.scrollY );
			const top = await alt.boundingBox();
			const bottom = await feature( page, 'Description' ).boundingBox();
			await page.screenshot( { path: path.join( OUT, 'screenshot-12.png' ), fullPage: true, clip: { x: top.x, y: top.y + scrolled, width: top.width, height: bottom.y + bottom.height - top.y } } );

			await feature( page, 'Licensing' ).screenshot( { path: path.join( OUT, 'screenshot-13.png' ) } );
		} );

		test( 'screenshot-14: the Image Sizes tab', async ( { page } ) => {
			const content = await tab( page, 'Image Sizes' );
			await expect( content.locator( '.photopress-images-settings table tbody tr' ).first() ).toBeVisible( { timeout: 30000 } );
			await content.screenshot( { path: path.join( OUT, 'screenshot-14.png' ) } );
		} );

		test( 'screenshot-15: the Offload Media tab', async ( { page } ) => {
			test.skip( ! process.env.PP_E2E_OFFLOAD, 'Set PP_E2E_OFFLOAD=1: needs WP Offload Media and the test bucket.' );
			wp( 'plugin', 'activate', 'amazon-s3-and-cloudfront' );

			try {
				const content = await tab( page, 'Offload Media' );
				await expect( content ).toContainText( 'Storage', { timeout: 60000 } );

				// The test site's bucket and distribution, as AWS's documentation
				// examples.
				await content.evaluate( ( root ) => {
					const swap = [
						[ /\bd[a-z0-9]{12,14}\.cloudfront\.net\b/g, 'd111111abcdef8.cloudfront.net' ],
						[ /\bE[A-Z0-9]{12,14}\b/g, 'EDFDVBD6EXAMPLE' ],
					];
					const bucket = [ ...root.querySelectorAll( 'th' ) ].find( ( th ) => th.textContent === 'Cloud storage' );
					const name = bucket && bucket.nextElementSibling.textContent.split( ' ' )[ 0 ];
					if ( name ) {
						swap.push( [ new RegExp( name.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ), 'g' ), 'example-photos' ] );
					}
					const walker = document.createTreeWalker( root, NodeFilter.SHOW_TEXT );
					while ( walker.nextNode() ) {
						for ( const [ from, to ] of swap ) {
							walker.currentNode.textContent = walker.currentNode.textContent.replace( from, to );
						}
					}
				} );

				await content.screenshot( { path: path.join( OUT, 'screenshot-15.png' ) } );
			} finally {
				wp( 'plugin', 'deactivate', 'amazon-s3-and-cloudfront' );
			}
		} );
	} );
} );
