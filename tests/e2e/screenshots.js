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

	// The Image Taxonomies block in an image's description, shown on its
	// attachment page.
	wp( 'post', 'update', String( made.names[ '01-portrait-2x3' ] ), '--post_content=<!-- wp:photopress/image-taxonomies /-->' );
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
	const centre = page.locator( '.panels .center img' );
	const settle = async () => {
		await centre.evaluate( ( img ) => img.decode() );
		await page.waitForTimeout( 600 );
		await rec.frame( 160 );
	};

	// The screenshots do not show the mouse pointer: the gallery, then the
	// slideshow opened on its landscape image.
	await rec.frame( 150 );
	const target = await figure.locator( `img.wp-image-${ made.names[ '02-landscape-3x2' ] }` ).boundingBox();
	let at = { x: target.x + target.width / 2, y: target.y + target.height / 2 };
	await page.mouse.click( at.x, at.y );
	await expect( centre ).toBeVisible( { timeout: 30000 } );
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

test( 'screenshot-9: the Image Taxonomies block, on an image\'s page', async ( { page } ) => {
	const id = made.names[ '01-portrait-2x3' ];
	// Its permalink: ?attachment_id= redirects to it, and PP_E2E_ORIGIN does
	// not follow redirects.
	const url = new URL( lastLine( wp( 'eval', `echo get_permalink( ${ id } );` ) ) );
	const block = await front( page, url.pathname + url.search, { width: 1100, height: 1400 }, '.wp-block-photopress-image-taxonomies' );
	await page.screenshot( { path: path.join( OUT, 'screenshot-9.png' ), clip: await around( block, page ) } );
} );

test.describe( 'settings', () => {
	test.use( { viewport: { width: 1100, height: 1400 }, deviceScaleFactor: 2 } );

	const tab = async ( page, name ) => {
		await page.goto( '/wp-admin/admin.php?page=photopress-core-base' );
		await page.getByRole( 'tab', { name } ).click();
		return page.locator( '.components-tab-panel__tab-content' );
	};

	const panel = ( page, title ) => page.locator( '.components-panel__body' ).filter( { has: page.locator( '.components-panel__body-title', { hasText: title } ) } );

	test( 'screenshot-5: the Slideshow tab', async ( { page } ) => {
		const content = await tab( page, 'Slideshow' );
		await expect( content ).toContainText( 'Enable Slideshows' );
		await content.screenshot( { path: path.join( OUT, 'screenshot-5.png' ) } );
	} );

	test( 'screenshot-10 to 12: the Meta-data tab\'s panels', async ( { page } ) => {
		await tab( page, 'Meta-data' );
		await expect( panel( page, 'Custom Taxonomies' ) ).toBeVisible();
		await panel( page, 'Custom Taxonomies' ).screenshot( { path: path.join( OUT, 'screenshot-10.png' ) } );

		// Example values, typed but not saved.
		const altText = panel( page, 'Alt Text' );
		await altText.locator( '#description_template' ).fill( '[dc:description]' );
		await altText.screenshot( { path: path.join( OUT, 'screenshot-11.png' ) } );

		const licensing = panel( page, 'Licensing' );
		const fields = licensing.locator( 'input[type="text"], input:not([type])' );
		const values = [ 'Jane Smith', 'https://www.example.com/license-statement', 'https://www.example.com/purchase-license' ];
		for ( let i = 0; i < values.length; i++ ) {
			await fields.nth( i ).fill( values[ i ] );
		}
		// Down to the Re-read section (screenshot-13).
		await licensing.scrollIntoViewIfNeeded();
		const box = await licensing.boundingBox();
		const reread = await page.getByRole( 'heading', { name: 'Re-read image metadata' } ).boundingBox();
		await page.screenshot( { path: path.join( OUT, 'screenshot-12.png' ), clip: { x: box.x, y: box.y, width: box.width, height: reread.y - box.y - 12 } } );
	} );

	test( 'screenshot-13: re-reading image metadata, on the Meta-data tab', async ( { page } ) => {
		await tab( page, 'Meta-data' );

		// The fixture images only, never the whole library.
		const started = await page.evaluate( async ( ids ) => {
			const nonce = await ( await fetch( '/wp-admin/admin-ajax.php?action=rest-nonce' ) ).text();
			const response = await fetch( '/?rest_route=/photopress/v1/jobs', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
				body: JSON.stringify( { type: 'metadata.reprocess', args: { ids } } ),
			} );
			return response.status;
		}, made.images );
		expect( started ).toBe( 200 );

		try {
			await tab( page, 'Meta-data' );
			const job = page.locator( '.photopress-job[data-job-type="metadata.reprocess"]' );
			await expect( job.locator( '.photopress-job__progress' ) ).toHaveAttribute( 'data-status', 'done', { timeout: 90000 } );

			// From the heading above the panel to the end of it.
			await job.scrollIntoViewIfNeeded();
			const top = await page.getByRole( 'heading', { name: 'Re-read image metadata' } ).boundingBox();
			const bottom = await job.boundingBox();
			const card = await page.locator( '.components-tab-panel__tab-content' ).boundingBox();
			await page.screenshot( { path: path.join( OUT, 'screenshot-13.png' ), clip: { x: card.x, y: top.y - 20, width: card.width, height: bottom.y + bottom.height - top.y + 40 } } );
		} finally {
			wp( 'eval', `foreach ( (array) get_option( 'photopress_jobs', [] ) as $id ) { delete_option( "photopress_job_$id" ); as_unschedule_all_actions( 'photopress_job_batch', [ $id ], 'photopress' ); } delete_option( 'photopress_jobs' );` );
		}
	} );

	test( 'screenshot-14: the Offload Media tab', async ( { page } ) => {
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
				const bucket = [ ...root.querySelectorAll( 'th' ) ].find( ( th ) => th.textContent === 'Bucket' );
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

			await content.screenshot( { path: path.join( OUT, 'screenshot-14.png' ) } );
		} finally {
			wp( 'plugin', 'deactivate', 'amazon-s3-and-cloudfront' );
		}
	} );
} );
