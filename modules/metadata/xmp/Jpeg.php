<?php

namespace PhotoPress\modules\metadata\xmp;

/**
 * JPEG: the packet is in an APP1 segment headed by the XMP namespace, before
 * the image data.
 *
 * Not written: a packet larger than one segment (Extended XMP), and a
 * multi-picture file (MPF) whose XMP segment comes after its MPF segment.
 * The further images of a multi-picture file are found by offsets from the
 * MPF segment, so nothing between the two may change size. Extended XMP
 * segments the file already has are kept as they are.
 */
class Jpeg extends Format {

	const STANDARD = "http://ns.adobe.com/xap/1.0/\0";

	/**
	 * The largest packet one segment holds: the 16-bit length counts itself
	 * (2 bytes) and the namespace header (29).
	 */
	const MAX_PACKET = 65535 - 2 - 29;

	public function matches( $magic ) {

		return strncmp( $magic, "\xFF\xD8\xFF", 3 ) === 0;
	}

	/**
	 * The segments up to the start of the image data; everything from there
	 * to the end of the file (the image data, and any further images of a
	 * multi-picture file) is one block.
	 */
	public function parse( Stream $in ) {

		$blocks = [ [ 'offset' => 0, 'length' => 2, 'kind' => 'keep', 'type' => 0xD8 ] ];
		$xmp = null;
		$packet = '';
		$pos = 2;

		while ( true ) {

			$start = $pos;

			if ( $in->bytes( $pos++, 1 ) !== "\xFF" ) {
				return self::error( 'unrecognised', 'A JPEG segment does not start with a marker.' );
			}

			// Markers may be preceded by 0xFF fill bytes, kept as part of the segment.
			do {
				$type = $in->bytes( $pos++, 1 );
			} while ( "\xFF" === $type );

			if ( '' === $type ) {
				return self::error( 'unrecognised', 'The JPEG ends before its image data.' );
			}

			$type = ord( $type );

			// Start of scan: the image data, to the end of the file.
			if ( 0xDA === $type ) {
				$blocks[] = [ 'offset' => $start, 'length' => $in->size - $start, 'kind' => 'keep', 'type' => $type ];
				break;
			}

			// Markers without a length, or the end of an image with no image data.
			if ( 0x01 === $type || ( $type >= 0xD0 && $type <= 0xD9 ) ) {
				return self::error( 'unrecognised', sprintf( 'Unexpected JPEG marker 0x%02X before the image data.', $type ) );
			}

			$length = $in->bytes( $pos, 2 );
			$length = strlen( $length ) === 2 ? unpack( 'n', $length )[1] : 0;

			if ( $length < 2 || $pos + $length > $in->size ) {
				return self::error( 'unrecognised', 'A JPEG segment length is outside the file.' );
			}

			$block = [ 'offset' => $start, 'length' => $pos + $length - $start, 'kind' => 'keep', 'type' => $type ];

			if ( 0xE1 === $type || 0xE2 === $type ) {

				$head = $in->bytes( $pos + 2, min( $length - 2, strlen( self::STANDARD ) ) );
				$block['mpf'] = 0xE2 === $type && strncmp( $head, "MPF\0", 4 ) === 0;
				$block['exif'] = 0xE1 === $type && strncmp( $head, "Exif\0", 5 ) === 0;

				if ( 0xE1 === $type && self::STANDARD === $head ) {

					if ( null !== $xmp ) {
						return self::error( 'unrecognised', 'The JPEG has more than one XMP segment.' );
					}

					$xmp = count( $blocks );
					$block['kind'] = 'xmp';
					$packet = $in->bytes( $pos + 2 + strlen( self::STANDARD ), $length - 2 - strlen( self::STANDARD ) );
				}
			}

			$blocks[] = $block;
			$pos += $length;
		}

		return [ 'blocks' => $blocks, 'xmp' => $xmp, 'packet' => $packet ];
	}

	public function plan( Stream $in, array $layout, $packet ) {

		if ( strlen( $packet ) > self::MAX_PACKET ) {
			return self::error( 'too_large', 'The packet is larger than one JPEG segment holds.' );
		}

		$blocks = $layout['blocks'];
		$new = "\xFF\xE1" . pack( 'n', 2 + strlen( self::STANDARD ) + strlen( $packet ) ) . self::STANDARD . $packet;

		// In place of the old segment; otherwise after the JFIF and Exif
		// segments that open the file.
		$at = $layout['xmp'];

		if ( null === $at ) {
			for ( $at = 1; $at < count( $blocks ) && ( 0xE0 === $blocks[ $at ]['type'] || ! empty( $blocks[ $at ]['exif'] ) ); $at++ ) {
				continue;
			}
		}

		foreach ( $blocks as $i => $block ) {
			if ( ! empty( $block['mpf'] ) && $i < $at ) {
				return self::error( 'mpf', 'The XMP segment would come after the multi-picture (MPF) segment.' );
			}
		}

		return self::splice( $blocks, $at, $new );
	}

	public function unchanged( Stream $in, array $before, Stream $out, array $after ) {

		return self::sameKeptBlocks( $in, $before, $out, $after );
	}
}
