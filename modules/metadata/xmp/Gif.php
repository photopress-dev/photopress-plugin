<?php

namespace PhotoPress\modules\metadata\xmp;

/**
 * GIF: the packet is an application extension with the identifier
 * "XMP Data" and authentication code "XMP". The packet is stored as it is,
 * not in sub-blocks, followed by a 258-byte "magic trailer" that brings a
 * reader skipping it as sub-blocks to the block terminator.
 *
 * Written before the first image (and its graphic control extension), after
 * any looping extension; one already in the file is replaced where it is.
 * A GIF87a file becomes GIF89a, the version that has extensions.
 */
class Gif extends Format {

	const APP = "\x0BXMP DataXMP";

	public function matches( $magic ) {

		return strncmp( $magic, 'GIF87a', 6 ) === 0 || strncmp( $magic, 'GIF89a', 6 ) === 0;
	}

	/**
	 * 0x01, then 0xFF down to 0x00, then the block terminator.
	 */
	protected static function trailer() {

		$trailer = "\x01";

		for ( $i = 255; $i >= 0; $i-- ) {
			$trailer .= chr( $i );
		}

		return $trailer . "\0";
	}

	public function parse( Stream $in ) {

		$screen = $in->bytes( 6, 7 );

		if ( strlen( $screen ) < 7 ) {
			return self::error( 'unrecognised', 'The GIF is cut short.' );
		}

		// The version, rewritten; the screen descriptor and global colour table.
		$packed = ord( $screen[4] );
		$table = ( $packed & 0x80 ) ? 3 * ( 2 << ( $packed & 7 ) ) : 0;
		$blocks = [
			[ 'offset' => 0, 'length' => 6, 'kind' => 'header' ],
			[ 'offset' => 6, 'length' => 7 + $table, 'kind' => 'keep', 'type' => 'screen' ],
		];
		$xmp = null;
		$packet = '';
		$pos = 13 + $table;

		while ( true ) {

			$introducer = $in->bytes( $pos, 1 );

			if ( "\x3B" === $introducer ) {
				$blocks[] = [ 'offset' => $pos, 'length' => $in->size - $pos, 'kind' => 'keep', 'type' => 'trailer' ];
				break;
			}

			if ( "\x2C" === $introducer ) {

				$descriptor = $in->bytes( $pos, 10 );

				if ( strlen( $descriptor ) < 10 ) {
					return self::error( 'unrecognised', 'A GIF image descriptor is cut short.' );
				}

				$packed = ord( $descriptor[9] );
				$table = ( $packed & 0x80 ) ? 3 * ( 2 << ( $packed & 7 ) ) : 0;

				// The descriptor, local colour table and LZW code size, then the data.
				$end = self::subBlocksEnd( $in, $pos + 10 + $table + 1 );
				$type = 'image';

			} elseif ( "\x21" === $introducer ) {

				$label = $in->bytes( $pos + 1, 1 );
				$type = "\xF9" === $label ? 'control' : 'extension';

				if ( "\xFF" === $label && $in->bytes( $pos + 2, 12 ) === self::APP ) {
					$type = 'xmp';
				}

				$end = self::subBlocksEnd( $in, $pos + 2 );

			} else {
				return self::error( 'unrecognised', 'Unexpected GIF block, or the file ends before its trailer.' );
			}

			if ( null === $end ) {
				return self::error( 'unrecognised', 'A GIF block runs past the end of the file.' );
			}

			$block = [ 'offset' => $pos, 'length' => $end - $pos, 'kind' => 'keep', 'type' => $type ];

			if ( 'xmp' === $type ) {

				$trailer = self::trailer();
				$start = $pos + 2 + strlen( self::APP );

				if ( null !== $xmp || $end - strlen( $trailer ) < $start || $in->bytes( $end - strlen( $trailer ), strlen( $trailer ) ) !== $trailer ) {
					return self::error( 'unrecognised', 'The GIF has more than one XMP block, or one without its trailer.' );
				}

				$xmp = count( $blocks );
				$block['kind'] = 'xmp';
				$packet = $in->bytes( $start, $end - strlen( $trailer ) - $start );
			}

			$blocks[] = $block;
			$pos = $end;
		}

		return [ 'blocks' => $blocks, 'xmp' => $xmp, 'packet' => $packet ];
	}

	/**
	 * The offset after a run of sub-blocks (each a length byte and that many
	 * bytes) and its zero terminator; null if it runs past the end.
	 */
	protected static function subBlocksEnd( Stream $in, $pos ) {

		while ( $pos < $in->size ) {

			$length = $in->bytes( $pos, 1 );

			if ( '' === $length ) {
				return null;
			}

			$pos += 1 + ord( $length );

			if ( "\0" === $length ) {
				return $pos;
			}
		}

		return null;
	}

	public function plan( Stream $in, array $layout, $packet ) {

		// A zero byte would end the block early for a reader skipping it.
		if ( strpos( $packet, "\0" ) !== false ) {
			return self::error( 'unrecognised', 'The packet has a zero byte, which a GIF cannot hold.' );
		}

		$blocks = $layout['blocks'];
		$new = "\x21\xFF" . self::APP . $packet . self::trailer();
		$at = $layout['xmp'];

		if ( null === $at ) {
			for ( $at = 2; $at < count( $blocks ) && ! in_array( $blocks[ $at ]['type'], [ 'image', 'control', 'trailer' ], true ); $at++ ) {
				continue;
			}
		}

		$pieces = self::splice( $blocks, $at, $new );
		array_unshift( $pieces, [ 'bytes', 'GIF89a' ] );

		return $pieces;
	}

	public function unchanged( Stream $in, array $before, Stream $out, array $after ) {

		return self::sameKeptBlocks( $in, $before, $out, $after ) && $out->bytes( 0, 6 ) === 'GIF89a';
	}
}
