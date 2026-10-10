/**
 * License embedding on upload, with the license settings on: an uploaded
 * JPEG or PNG gets the license in its XMP, keeps the XMP it had, and is not
 * re-encoded, sent as a raw body (wp_handle_sideload) or multipart
 * (wp_handle_upload, through move_uploaded_file).
 */
const path = require( 'path' );
const { test, expect } = require( './test' );
const { rest, file } = require( './rest' );
const { wp, lastLine } = require( './wp' );

const KEY = 'photopress_core_metadata';
const IMAGES = path.join( __dirname, '..', 'fixtures', 'images' );

// The settings are read when the module loads, so they apply from the next request.
let saved;

test.beforeAll( () => {
	saved = lastLine( wp( 'option', 'get', KEY, '--format=json' ) );
	wp( 'option', 'update', KEY, JSON.stringify( {
		...JSON.parse( saved ),
		embed_licensor_enable: true,
		web_statement_of_rights: 'https://example.test/license',
		licensor_name: 'Test Licensor',
		licensor_url: 'https://licensor.example',
	} ), '--format=json' );
} );

test.afterAll( () => {
	wp( 'option', 'update', KEY, saved, '--format=json' );
} );

/**
 * The stored original of an upload, compared with the file sent: its XMP,
 * and whether ImageMagick decodes both to the same pixels.
 */
function stored( id, sent ) {
	return JSON.parse( lastLine( wp( 'eval', `
		$file = wp_get_original_image_path( ${ Number( id ) } );
		$md = new PhotoPress\\modules\\metadata\\XmpReader();
		$md->loadFromFile( $file );
		echo wp_json_encode( [
			'statement' => $md->getXmp( 'xmpRights:WebStatement' ),
			'licensor'  => $md->getXmp( 'plus:Licensor' ),
			'title'     => $md->getXmp( 'dc:title' ),
			'pixels'    => ( new Imagick( $file ) )->getImageSignature() === ( new Imagick( ${ JSON.stringify( sent ) } ) )->getImageSignature(),
			'grew'      => filesize( $file ) - filesize( ${ JSON.stringify( sent ) } ),
		] );
	` ) ) );
}

const uploads = [
	{ name: '02-landscape-3x2.jpg', type: 'image/jpeg', multipart: false, title: 'Bob' },
	{ name: '03-square.jpg', type: 'image/jpeg', multipart: true, title: 'Carol' },
	{ name: '09-png-3x2.png', type: 'image/png', multipart: true, title: null },
];

for ( const { name, type, multipart, title } of uploads ) {
	test( `${ name }, ${ multipart ? 'multipart' : 'raw' }: the license is written without re-encoding`, async ( { page } ) => {
		await page.goto( '/' );
		const uploaded = await rest( page, { method: 'POST', route: '/wp/v2/media', file: file( name, `pp-fixture-upload-${ Date.now() }-${ name }`, type ), multipart } );
		expect( uploaded.status, JSON.stringify( uploaded.data ) ).toBe( 201 );

		try {
			const file = stored( uploaded.data.id, path.join( IMAGES, name ) );

			expect( file.statement ).toBe( 'https://example.test/license' );
			expect( file.licensor ).toEqual( { 'plus:LicensorName': 'Test Licensor', 'plus:LicensorURL': 'https://licensor.example' } );
			expect( file.pixels ).toBe( true );

			// Only the packet changed: a few hundred bytes, not a new encoding.
			expect( Math.abs( file.grew ) ).toBeLessThan( 4096 );

			if ( title ) {
				expect( file.title ).toBe( title );
			}
		} finally {
			await rest( page, { method: 'DELETE', route: `/wp/v2/media/${ uploaded.data.id }?force=true` } );
		}
	} );
}
