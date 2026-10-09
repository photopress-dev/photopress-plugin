<?php

namespace PhotoPress\Tests;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PhotoPress\modules\metadata\XmpReader;
use PhotoPress\modules\metadata\XmpFile;

final class XmpFileTest extends TestCase {

	private const STANDARD = "http://ns.adobe.com/xap/1.0/\0";

	protected function setUp(): void {

		parent::setUp();

		Functions\stubs( [ 'is_wp_error' => static fn( $thing ) => $thing instanceof \WP_Error ] );
	}

	private static function packet( string $statement = 'https://example.test/licence' ): string {

		return "<?xpacket begin=\"\xEF\xBB\xBF\" id=\"W5M0MpCehiHzreSzNTczkc9d\"?>\n"
			. '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
			. '<rdf:Description rdf:about="" xmlns:xmpRights="http://ns.adobe.com/xap/1.0/rights/">'
			. '<xmpRights:WebStatement>' . $statement . '</xmpRights:WebStatement>'
			. '</rdf:Description></rdf:RDF></x:xmpmeta>'
			. "\n<?xpacket end=\"w\"?>";
	}

	private static function segment( int $marker, string $payload ): string {

		return "\xFF" . chr( $marker ) . pack( 'n', strlen( $payload ) + 2 ) . $payload;
	}

	/**
	 * A small JPEG from GD: SOI, JFIF, the tables, then the image data.
	 */
	private static function gdJpeg(): string {

		$im = imagecreatetruecolor( 40, 30 );
		imagefilledrectangle( $im, 0, 0, 19, 29, imagecolorallocate( $im, 200, 40, 40 ) );
		ob_start();
		imagejpeg( $im, null, 90 );

		return ob_get_clean();
	}

	/**
	 * The JPEG with segments inserted after its JFIF segment.
	 */
	private static function withSegments( string $jpeg, string ...$segments ): string {

		$jfif = 4 + unpack( 'n', substr( $jpeg, 4, 2 ) )[1];

		return substr( $jpeg, 0, $jfif ) . implode( '', $segments ) . substr( $jpeg, $jfif );
	}

	private static function gdPng( bool $alpha = false ): string {

		$im = imagecreatetruecolor( 40, 30 );
		imagesavealpha( $im, $alpha );
		imagefill( $im, 0, 0, imagecolorallocatealpha( $im, 30, 120, 200, $alpha ? 60 : 0 ) );
		ob_start();
		imagepng( $im );

		return ob_get_clean();
	}

	private static function gdWebp( int $quality = 80, bool $alpha = false ): string {

		$im = imagecreatetruecolor( 40, 30 );
		imagesavealpha( $im, $alpha );
		imagefill( $im, 0, 0, imagecolorallocatealpha( $im, 30, 120, 200, $alpha ? 60 : 0 ) );
		ob_start();
		imagewebp( $im, null, $quality );

		return ob_get_clean();
	}

	private static function pngChunk( string $type, string $data ): string {

		return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
	}

	/**
	 * The file's bytes with its JPEG XMP segment removed.
	 */
	private static function withoutJpegXmp( string $jpeg ): string {

		$at = strpos( $jpeg, "\xFF\xE1", 2 );

		while ( false !== $at && substr( $jpeg, $at + 4, strlen( self::STANDARD ) ) !== self::STANDARD ) {
			$at = strpos( $jpeg, "\xFF\xE1", $at + 2 );
		}

		$length = 2 + unpack( 'n', substr( $jpeg, $at + 2, 2 ) )[1];

		return substr( $jpeg, 0, $at ) . substr( $jpeg, $at + $length );
	}

	private function update( string $file, ?string $packet = null, &$existing = null ) {

		return XmpFile::update( $file, static function ( $was ) use ( $packet, &$existing ) {
			$existing = $was;
			return $packet ?? self::packet();
		} );
	}

	private static function readXmp( string $file ): array {

		return ( new XmpReader() )->extractXmp( $file );
	}

	public function test_a_jpeg_gets_a_segment_after_jfif_and_nothing_else_changes(): void {

		$jpeg = self::gdJpeg();
		$file = $this->tempFile( $jpeg );

		$this->assertTrue( $this->update( $file, null, $existing ) );
		$this->assertSame( '', $existing );

		$after = file_get_contents( $file );
		$this->assertSame( $jpeg, self::withoutJpegXmp( $after ) );
		$this->assertSame( 'https://example.test/licence', self::readXmp( $file )['xmpRights:WebStatement'] );

		// Straight after JFIF.
		$jfif = 4 + unpack( 'n', substr( $jpeg, 4, 2 ) )[1];
		$this->assertSame( "\xFF\xE1", substr( $after, $jfif, 2 ) );
		$this->assertNotFalse( imagecreatefromstring( $after ) );
	}

	public function test_a_jpeg_packet_is_replaced_where_it_was_and_given_to_merge(): void {

		$old = self::packet( 'https://example.test/old' );
		$exif = self::segment( 0xE1, "Exif\0\0" . str_repeat( "\0", 20 ) );
		$jpeg = self::withSegments( self::gdJpeg(), $exif, self::segment( 0xE2, "ICC_PROFILE\0" . str_repeat( 'i', 30 ) ), self::segment( 0xE1, self::STANDARD . $old ) );
		$file = $this->tempFile( $jpeg );

		$this->assertTrue( $this->update( $file, null, $existing ) );
		$this->assertSame( $old, $existing );

		$after = file_get_contents( $file );
		$this->assertSame( self::withoutJpegXmp( $jpeg ), self::withoutJpegXmp( $after ) );
		$this->assertSame( 'https://example.test/licence', self::readXmp( $file )['xmpRights:WebStatement'] );
		$this->assertSame( 1, substr_count( $after, self::STANDARD ) );

		// After the ICC segment, where the old one was.
		$this->assertGreaterThan( strpos( $after, 'ICC_PROFILE' ), strpos( $after, self::STANDARD ) );
	}

	public function test_a_jpeg_keeps_fill_bytes_and_data_after_the_image(): void {

		$jpeg = self::gdJpeg();
		$jfif = 4 + unpack( 'n', substr( $jpeg, 4, 2 ) )[1];

		// 0xFF fill before the next marker, and a second image after EOI.
		$jpeg = substr( $jpeg, 0, $jfif ) . "\xFF\xFF" . substr( $jpeg, $jfif ) . 'trailing second image';
		$file = $this->tempFile( $jpeg );

		$this->assertTrue( $this->update( $file ) );
		$this->assertSame( $jpeg, self::withoutJpegXmp( file_get_contents( $file ) ) );
	}

	/**
	 * @return array<string, array{string[]}>
	 */
	public static function multiPictureLayouts(): array {

		$mpf = self::segment( 0xE2, "MPF\0II*\0" . str_repeat( "\0", 16 ) );
		$exif = self::segment( 0xE1, "Exif\0\0" . str_repeat( "\0", 20 ) );

		return [
			'MPF straight after JFIF' => [ [ $mpf ] ],
			'MPF after Exif'          => [ [ $exif, $mpf ] ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'multiPictureLayouts' )]
	public function test_a_multi_picture_jpeg_gets_the_segment_before_mpf( array $segments ): void {

		$jpeg = self::withSegments( self::gdJpeg(), ...$segments ) . 'second image';
		$file = $this->tempFile( $jpeg );

		$this->assertTrue( $this->update( $file ) );

		$after = file_get_contents( $file );
		$this->assertLessThan( strpos( $after, "MPF\0" ), strpos( $after, self::STANDARD ) );

		// Everything from the MPF segment on is where it was relative to it.
		$this->assertSame( substr( $jpeg, strpos( $jpeg, "MPF\0" ) ), substr( $after, strpos( $after, "MPF\0" ) ) );
	}

	public function test_a_jpeg_xmp_segment_after_mpf_is_not_resized(): void {

		$mpf = self::segment( 0xE2, "MPF\0II*\0" . str_repeat( "\0", 16 ) );
		$jpeg = self::withSegments( self::gdJpeg(), $mpf, self::segment( 0xE1, self::STANDARD . self::packet( 'old' ) ) );
		$file = $this->tempFile( $jpeg );

		$this->assertSame( 'photopress_xmp_mpf', $this->update( $file )->get_error_code() );
		$this->assertSame( $jpeg, file_get_contents( $file ) );
	}

	public function test_a_packet_larger_than_a_jpeg_segment_is_refused(): void {

		$file = $this->tempFile( $jpeg = self::gdJpeg() );

		$result = $this->update( $file, self::packet( str_repeat( 'x', 70000 ) ) );

		$this->assertSame( 'photopress_xmp_too_large', $result->get_error_code() );
		$this->assertSame( $jpeg, file_get_contents( $file ) );
	}

	public function test_a_jpeg_with_extended_xmp_keeps_it(): void {

		$extension = "http://ns.adobe.com/xmp/extension/\0" . str_repeat( 'A', 32 ) . pack( 'N', 10 ) . pack( 'N', 0 ) . 'extended!!';
		$jpeg = self::withSegments( self::gdJpeg(), self::segment( 0xE1, self::STANDARD . self::packet( 'old' ) ), self::segment( 0xE1, $extension ) );
		$file = $this->tempFile( $jpeg );

		$this->assertTrue( $this->update( $file ) );
		$this->assertStringContainsString( $extension, file_get_contents( $file ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function brokenJpegs(): array {

		$jpeg = self::gdJpeg();
		$xmp = self::segment( 0xE1, self::STANDARD . self::packet( 'one' ) );

		return [
			'two XMP segments'             => [ self::withSegments( $jpeg, $xmp, $xmp ) ],
			'cut short before image data'  => [ substr( $jpeg, 0, 40 ) ],
			'a length past the end'        => [ "\xFF\xD8\xFF\xE0\x7F\xFF" . 'JFIF' ],
			'no marker where one is due'   => [ substr( $jpeg, 0, 4 + unpack( 'n', substr( $jpeg, 4, 2 ) )[1] ) . 'not a marker' ],
			'end of image before any scan' => [ "\xFF\xD8\xFF\xD9" ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'brokenJpegs' )]
	public function test_a_jpeg_it_cannot_follow_is_left_alone( string $jpeg ): void {

		$file = $this->tempFile( $jpeg );

		$this->assertSame( 'photopress_xmp_unrecognised', $this->update( $file )->get_error_code() );
		$this->assertSame( $jpeg, file_get_contents( $file ) );
	}

	public function test_a_png_gets_an_itxt_chunk_after_ihdr(): void {

		$png = self::gdPng();
		$file = $this->tempFile( $png, '.png' );

		$this->assertTrue( $this->update( $file ) );

		$after = file_get_contents( $file );
		$this->assertSame( 'iTXt', substr( $after, 33 + 4, 4 ), 'straight after the signature and IHDR' );
		$this->assertSame( 'https://example.test/licence', self::readXmp( $file )['xmpRights:WebStatement'] );

		// The new chunk taken out again gives the original.
		$length = 12 + unpack( 'N', substr( $after, 33, 4 ) )[1];
		$this->assertSame( $png, substr( $after, 0, 33 ) . substr( $after, 33 + $length ) );
		$this->assertNotFalse( imagecreatefromstring( $after ) );
	}

	public function test_a_png_chunk_is_replaced_and_a_compressed_one_read(): void {

		$png = self::gdPng();
		$data = 'XML:com.adobe.xmp' . "\0\1\0\0\0" . gzcompress( self::packet( 'old' ) );
		$idat = strpos( $png, 'IDAT' ) - 4;
		$png = substr( $png, 0, $idat ) . self::pngChunk( 'iTXt', $data ) . substr( $png, $idat );
		$file = $this->tempFile( $png, '.png' );

		$this->assertTrue( $this->update( $file, null, $existing ) );
		$this->assertSame( self::packet( 'old' ), $existing );

		$this->assertTrue( $this->update( $file, self::packet( 'twice' ) ) );
		$after = file_get_contents( $file );
		$this->assertSame( 1, substr_count( $after, 'XML:com.adobe.xmp' ) );
		$this->assertSame( 'twice', self::readXmp( $file )['xmpRights:WebStatement'] );
	}

	/**
	 * @return array<string, array{int, bool, int}>
	 */
	public static function simpleWebps(): array {

		return [
			'lossy (VP8)'                => [ 80, false, 0 ],
			'lossless with alpha (VP8L)' => [ 101, true, 0x10 ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'simpleWebps' )]
	public function test_a_simple_webp_gets_vp8x_and_an_xmp_chunk( int $quality, bool $alpha, int $flags ): void {

		$webp = self::gdWebp( $quality, $alpha );
		$this->assertNotSame( 'VP8X', substr( $webp, 12, 4 ), 'GD writes simple files' );
		$file = $this->tempFile( $webp, '.webp' );

		$this->assertTrue( $this->update( $file ) );

		$after = file_get_contents( $file );
		$this->assertSame( 'VP8X', substr( $after, 12, 4 ) );
		$this->assertSame( 0x04 | $flags, ord( $after[20] ) );
		$this->assertSame( strlen( $after ) - 8, unpack( 'V', substr( $after, 4, 4 ) )[1] );
		$this->assertSame( [ 40, 30 ], array_slice( getimagesize( $file ), 0, 2 ) );
		$this->assertSame( 'https://example.test/licence', self::readXmp( $file )['xmpRights:WebStatement'] );

		// The image chunk as it was, after VP8X.
		$this->assertSame( substr( $webp, 12 ), substr( $after, 30, strlen( $webp ) - 12 ) );
		$this->assertNotFalse( imagecreatefromstring( $after ) );
	}

	public function test_a_webp_xmp_chunk_is_replaced_and_padded(): void {

		$file = $this->tempFile( self::gdWebp(), '.webp' );

		$this->assertTrue( $this->update( $file, self::packet( 'odd' ) ) );
		$this->assertTrue( $this->update( $file, self::packet( 'even' ), $existing ) );
		$this->assertSame( self::packet( 'odd' ), $existing );

		$after = file_get_contents( $file );
		$this->assertSame( 1, substr_count( $after, 'XMP ' ) );
		$this->assertSame( 0, strlen( $after ) % 2 );
		$this->assertSame( strlen( $after ) - 8, unpack( 'V', substr( $after, 4, 4 ) )[1] );
		$this->assertSame( 'even', self::readXmp( $file )['xmpRights:WebStatement'] );
	}

	public function test_formats_without_a_place_for_xmp_are_left_to_the_caller(): void {

		$file = $this->tempFile( $bmp = 'BM' . str_repeat( "\0", 52 ), '.bmp' );

		$this->assertSame( 'photopress_xmp_unsupported', $this->update( $file )->get_error_code() );
		$this->assertSame( $bmp, file_get_contents( $file ) );
	}

	/**
	 * Files made by ImageMagick (tests/php/fixtures/images): GIF, TIFF, AVIF
	 * and HEIC, with no XMP, and with an XMP packet in ImageMagick's layout.
	 *
	 * @return array<string, array{string}>
	 */
	public static function fixtureImages(): array {

		$files = [];

		foreach ( glob( __DIR__ . '/fixtures/images/*' ) as $file ) {
			$files[ basename( $file ) ] = [ basename( $file ) ];
		}

		return $files;
	}

	private function fixtureCopy( string $name ): string {

		return $this->tempFile( file_get_contents( __DIR__ . '/fixtures/images/' . $name ), '.' . pathinfo( $name, PATHINFO_EXTENSION ) );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'fixtureImages' )]
	public function test_each_format_is_written_and_replaced( string $name ): void {

		$file = $this->fixtureCopy( $name );
		$had = str_starts_with( $name, 'imagick-xmp' );

		$this->assertTrue( $this->update( $file, null, $existing ) );
		$this->assertSame( $had, str_contains( $existing, 'Capture One' ), 'the packet it had is given to merge' );
		$this->assertSame( 'https://example.test/licence', self::readXmp( $file )['xmpRights:WebStatement'] );

		// Again, as a replacement: one packet, and no stale copy of the last.
		$this->assertTrue( $this->update( $file, self::packet( 'https://example.test/second' ), $existing ) );
		$this->assertStringContainsString( 'https://example.test/licence', $existing );
		$after = file_get_contents( $file );
		$this->assertSame( 1, substr_count( $after, '<x:xmpmeta' ) );
		$this->assertStringNotContainsString( 'Capture One', $after );
		$this->assertSame( 'https://example.test/second', self::readXmp( $file )['xmpRights:WebStatement'] );
	}

	public function test_an_animated_gif_gets_the_block_after_its_loop_and_before_its_first_frame(): void {

		$file = $this->fixtureCopy( 'animated.gif' );

		$this->assertTrue( $this->update( $file ) );

		$after = file_get_contents( $file );
		$xmp = strpos( $after, "\x21\xFF\x0BXMP DataXMP" );
		$this->assertGreaterThan( strpos( $after, 'NETSCAPE2.0' ), $xmp );
		$this->assertLessThan( strpos( $after, "\x21\xF9" ), $xmp, 'before the first frame\'s graphic control extension' );
	}

	public function test_a_gif87a_becomes_gif89a(): void {

		$file = $this->tempFile( 'GIF87a' . substr( file_get_contents( __DIR__ . '/fixtures/images/plain.gif' ), 6 ), '.gif' );

		$this->assertTrue( $this->update( $file ) );
		$this->assertSame( 'GIF89a', substr( file_get_contents( $file ), 0, 6 ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function heifNotWritten(): array {

		$heic = file_get_contents( __DIR__ . '/fixtures/images/plain.heic' );
		$last = strrpos( $heic, 'mdat' ) - 4;

		return [
			'an image sequence'                   => [ $heic . pack( 'N', 16 ) . 'moov' . str_repeat( "\0", 8 ), 'photopress_xmp_unsupported' ],
			'a last box running to the end'       => [ substr_replace( $heic, "\0\0\0\0", $last, 4 ), 'photopress_xmp_unsupported' ],
			'a box past the end of the file'      => [ substr( $heic, 0, -4 ), 'photopress_xmp_unrecognised' ],
			'no meta box'                         => [ str_replace( 'meta', 'free', $heic ), 'photopress_xmp_unrecognised' ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'heifNotWritten' )]
	public function test_a_heif_file_it_does_not_write_is_left_alone( string $heic, string $code ): void {

		$file = $this->tempFile( $heic, '.heic' );

		$this->assertSame( $code, $this->update( $file )->get_error_code() );
		$this->assertSame( $heic, file_get_contents( $file ) );
	}

	public function test_a_heif_image_item_after_the_meta_box_is_moved_with_it(): void {

		$file = $this->fixtureCopy( 'plain.avif' );
		$size = getimagesize( $file );
		$before = file_get_contents( $file );
		$data = substr( $before, strrpos( $before, 'mdat' ) + 4 );

		$this->assertTrue( $this->update( $file ) );

		// The image data, now further on by what the meta box grew.
		$after = file_get_contents( $file );
		$grew = strpos( $after, 'mdat' ) - strpos( $before, 'mdat' );
		$this->assertGreaterThan( 0, $grew );
		$this->assertSame( $data, substr( $after, strpos( $after, 'mdat' ) + 4, strlen( $data ) ) );
		$this->assertSame( $size, getimagesize( $file ) );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'fixtureImages' )]
	public function test_each_format_decodes_to_the_same_pixels( string $name ): void {

		$format = strtoupper( pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( ! class_exists( 'Imagick' ) || ! in_array( $format, \Imagick::queryFormats(), true ) ) {
			$this->markTestSkipped( "Imagick cannot read $format here." );
		}

		$file = $this->fixtureCopy( $name );
		$before = ( new \Imagick( $file ) )->getImageSignature();

		$this->assertTrue( $this->update( $file ) );
		$this->assertSame( $before, ( new \Imagick( $file ) )->getImageSignature() );
	}

	public function test_a_packet_that_does_not_read_back_leaves_the_file_alone(): void {

		$file = $this->tempFile( $jpeg = self::gdJpeg() );

		$this->assertSame( 'photopress_xmp_check', $this->update( $file, 'not xml' )->get_error_code() );
		$this->assertSame( $jpeg, file_get_contents( $file ) );
		$this->assertSame( [ basename( $file ) ], array_values( preg_grep( '/^pp-xmp-|' . preg_quote( basename( $file ), '/' ) . '$/', scandir( dirname( $file ) ) ) ) );
	}

	/**
	 * ExifTool, when installed (CI installs it), as a reader written by
	 * someone else: it must find the licence, and find nothing wrong with the
	 * file that it did not find with the original.
	 *
	 * @return array<string, array{string}>
	 */
	public static function exiftoolFiles(): array {

		$files = [ 'GD JPEG' => [ 'gd:gdJpeg:.jpg' ], 'GD PNG' => [ 'gd:gdPng:.png' ], 'GD WebP' => [ 'gd:gdWebp:.webp' ] ];

		return $files + self::fixtureImages();
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'exiftoolFiles' )]
	#[RequiresFunction( 'shell_exec' )]
	public function test_exiftool_reads_the_licence_and_finds_nothing_new_wrong( string $source ): void {

		$exiftool = trim( (string) shell_exec( 'command -v exiftool 2>/dev/null' ) );

		if ( '' === $exiftool ) {
			$this->markTestSkipped( 'ExifTool is not installed.' );
		}

		if ( str_starts_with( $source, 'gd:' ) ) {
			[ , $make, $suffix ] = explode( ':', $source );
			$file = $this->tempFile( self::$make(), $suffix );
		} else {
			$file = $this->fixtureCopy( $source );
		}

		$validate = static function ( $file ) use ( $exiftool ) {
			$json = json_decode( (string) shell_exec( escapeshellarg( $exiftool ) . ' -j -validate -warning -error -a -XMP-xmpRights:WebStatement ' . escapeshellarg( $file ) ), true );
			return $json[0] ?? [];
		};

		$before = $validate( $file );
		$this->assertTrue( $this->update( $file ) );
		$after = $validate( $file );

		$this->assertSame( 'https://example.test/licence', $after['WebStatement'] ?? null );
		$this->assertArrayNotHasKey( 'Error', $after );
		$this->assertSame( (array) ( $before['Warning'] ?? [] ), (array) ( $after['Warning'] ?? [] ), json_encode( $after ) );
	}
}
