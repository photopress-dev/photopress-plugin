<?php

namespace PhotoPress\modules\metadata;

use PhotoPress\modules\metadata\xmp\Format;
use PhotoPress\modules\metadata\xmp\Gif;
use PhotoPress\modules\metadata\xmp\Heif;
use PhotoPress\modules\metadata\xmp\Jpeg;
use PhotoPress\modules\metadata\xmp\Png;
use PhotoPress\modules\metadata\xmp\Stream;
use PhotoPress\modules\metadata\xmp\Tiff;
use PhotoPress\modules\metadata\xmp\Webp;
use WP_Error;

/**
 * Reads and writes the XMP packet of an image file without decoding the
 * image: JPEG, PNG, WebP, GIF, TIFF, and HEIF stills (HEIC and AVIF), every
 * image format WordPress accepts that has a place for XMP. Only the metadata
 * changes; every other byte is kept, so the image keeps the pixels and
 * encoding it was exported with.
 *
 * A written file is checked before it replaces the original: it must parse,
 * hold the new packet, have everything else of the original unchanged (each
 * format says what that means; see xmp\Format::unchanged()), read back with
 * the new packet through XmpReader, and give the same type and size to
 * getimagesize(). Anything this does not recognize, or a result that fails
 * a check, leaves the file untouched and returns a WP_Error, so the caller
 * can fall back to another way of writing it.
 */
class XmpFile {

	/**
	 * @return Format[]
	 */
	protected static function formats() {

		return [ new Jpeg(), new Png(), new Webp(), new Gif(), new Tiff(), new Heif() ];
	}

	/**
	 * The file's XMP packet: '' when it has none, null when its format is not
	 * one of these or it could not be read.
	 */
	public static function read( $path ) {

		$in = Stream::open( $path );

		if ( ! $in ) {
			return null;
		}

		$format = self::format( $in );
		$layout = $format ? $format->parse( $in ) : null;
		$in->close();

		return is_array( $layout ) ? (string) $layout['packet'] : null;
	}

	/**
	 * Replaces the file's XMP packet with the one $merge returns for the
	 * packet it has now ('' when it has none).
	 *
	 * @param string   $path  The file.
	 * @param callable $merge function( string $existing ): string.
	 * @return true|WP_Error True when the file was written; otherwise it is unchanged.
	 */
	public static function update( $path, callable $merge ) {

		$in = Stream::open( $path );

		if ( ! $in ) {
			return self::error( 'unreadable', 'The file could not be read.' );
		}

		$result = self::write( $in, $path, $merge );
		$in->close();

		return $result;
	}

	protected static function write( Stream $in, $path, callable $merge ) {

		$format = self::format( $in );

		if ( ! $format ) {
			return self::error( 'unsupported', 'Not an image format with a place for XMP.' );
		}

		$before = $format->parse( $in );

		if ( is_wp_error( $before ) ) {
			return $before;
		}

		$packet = (string) call_user_func( $merge, $before['packet'] );
		$pieces = $format->plan( $in, $before, $packet );

		if ( is_wp_error( $pieces ) ) {
			return $pieces;
		}

		// In the file's own folder, so the rename into place does not cross
		// file systems.
		$tmp = @tempnam( dirname( $path ), 'pp-xmp-' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$fh = $tmp ? @fopen( $tmp, 'wb' ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( ! $fh ) {
			return self::error( 'unwritable', 'A temporary file could not be written next to the file.' );
		}

		$written = true;

		foreach ( $pieces as $piece ) {
			$written = $written && ( 'bytes' === $piece[0] ? fwrite( $fh, $piece[1] ) === strlen( $piece[1] ) : $in->copyTo( $fh, $piece[1], $piece[2] ) );
		}

		$written = fflush( $fh ) && $written;
		fclose( $fh );

		$checked = $written ? self::check( $format, $in, $before, $tmp, $packet, $path ) : self::error( 'unwritable', 'The temporary file could not be written.' );

		if ( true !== $checked ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return $checked;
		}

		@chmod( $tmp, fileperms( $path ) & 0777 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return self::error( 'unwritable', 'The written file could not be moved into place.' );
		}

		return true;
	}

	/**
	 * The format of the file, from its first bytes.
	 *
	 * @return Format|null
	 */
	protected static function format( Stream $in ) {

		$magic = $in->bytes( 0, 16 );

		foreach ( self::formats() as $format ) {
			if ( $format->matches( $magic ) ) {
				return $format;
			}
		}

		return null;
	}

	/**
	 * The checks a written file must pass before it replaces the original.
	 *
	 * @return true|WP_Error
	 */
	protected static function check( Format $format, Stream $in, array $before, $tmp, $packet, $path ) {

		$out = Stream::open( $tmp );

		if ( ! $out ) {
			return self::error( 'check', 'The written file could not be read back.' );
		}

		$after = $format->parse( $out );
		$same = is_array( $after ) && $after['packet'] === $packet && $format->unchanged( $in, $before, $out, $after );
		$out->close();

		if ( ! $same ) {
			return self::error( 'check', 'The written file does not have the new packet and the rest of the original unchanged.' );
		}

		// Read as other code reads it: the type and size, and the packet.
		$was = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$now = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( ( $was ? array_slice( $was, 0, 3 ) : false ) !== ( $now ? array_slice( $now, 0, 3 ) : false ) ) {
			return self::error( 'check', 'The written file does not have the same image type and size.' );
		}

		// Every property of the packet; a JPEG's Extended XMP, kept as it was,
		// adds more.
		$reader = new XmpReader();
		$expected = $reader->parsePacket( $packet );
		$read = array_intersect_key( $reader->extractXmp( $tmp ), $expected );
		ksort( $expected );
		ksort( $read );

		if ( ! $expected || $expected !== $read ) {
			return self::error( 'check', 'The written file does not read back with the new packet.' );
		}

		return true;
	}

	protected static function error( $code, $message ) {

		return new WP_Error( 'photopress_xmp_' . $code, $message );
	}
}
