<?php

namespace PhotoPress\modules\metadata\xmp;

/**
 * WebP: the packet is an 'XMP ' chunk, and the VP8X chunk's XMP flag says it
 * is there. The RIFF header and VP8X chunk are rewritten; a simple file (one
 * VP8 or VP8L chunk, no VP8X) is given a VP8X chunk, with the canvas size
 * and alpha flag read from its image chunk.
 */
class Webp extends Format {

	const FLAG_XMP = 0x04;

	const FLAG_ALPHA = 0x10;

	public function matches( $magic ) {

		return strncmp( $magic, 'RIFF', 4 ) === 0 && substr( $magic, 8, 4 ) === 'WEBP';
	}

	public function parse( Stream $in ) {

		$riff = unpack( 'V', $in->bytes( 4, 4 ) )[1];

		if ( $riff + 8 !== $in->size || $riff % 2 ) {
			return self::error( 'unrecognised', 'The WebP RIFF size does not match the file.' );
		}

		$blocks = [ [ 'offset' => 0, 'length' => 12, 'kind' => 'header' ] ];
		$xmp = null;
		$packet = '';
		$vp8x = null;
		$pos = 12;

		while ( $pos < $in->size ) {

			$head = $in->bytes( $pos, 8 );

			if ( strlen( $head ) < 8 ) {
				return self::error( 'unrecognised', 'A WebP chunk header is cut short.' );
			}

			$type = substr( $head, 0, 4 );
			$length = unpack( 'V', substr( $head, 4, 4 ) )[1];
			$padded = $length + ( $length % 2 );

			if ( $pos + 8 + $padded > $in->size ) {
				return self::error( 'unrecognised', 'A WebP chunk is outside the file.' );
			}

			$block = [ 'offset' => $pos, 'length' => 8 + $padded, 'kind' => 'keep', 'type' => $type ];

			if ( 'VP8X' === $type ) {

				if ( count( $blocks ) !== 1 || $length < 10 ) {
					return self::error( 'unrecognised', 'The WebP VP8X chunk is not first, or is cut short.' );
				}

				$vp8x = $in->bytes( $pos + 8, $length );
				$block['kind'] = 'header';

			} elseif ( 'XMP ' === $type ) {

				if ( null !== $xmp ) {
					return self::error( 'unrecognised', 'The WebP has more than one XMP chunk.' );
				}

				$xmp = count( $blocks );
				$block['kind'] = 'xmp';
				$packet = $in->bytes( $pos + 8, $length );

			} elseif ( null === $vp8x && ( 'VP8 ' === $type || 'VP8L' === $type ) ) {

				$block['image'] = $in->bytes( $pos + 8, min( $length, 10 ) );
			}

			$blocks[] = $block;
			$pos += 8 + $padded;
		}

		if ( null === $vp8x ) {

			// A simple file is a single image chunk.
			if ( count( $blocks ) !== 2 || ! isset( $blocks[1]['image'] ) ) {
				return self::error( 'unrecognised', 'The WebP has no VP8X chunk and is not a single image chunk.' );
			}

			$vp8x = self::simpleVp8x( $blocks[1]['type'], $blocks[1]['image'] );

			if ( null === $vp8x ) {
				return self::error( 'unrecognised', 'The WebP image chunk header could not be read.' );
			}
		}

		return [ 'blocks' => $blocks, 'xmp' => $xmp, 'packet' => $packet, 'vp8x' => $vp8x ];
	}

	/**
	 * The VP8X chunk data a simple file would have: no flags but alpha, and
	 * the canvas size of its image.
	 */
	protected static function simpleVp8x( $type, $head ) {

		if ( 'VP8 ' === $type ) {

			// A key frame's tag (3 bytes), start code, then 14-bit width and height.
			if ( strlen( $head ) < 10 || substr( $head, 3, 3 ) !== "\x9D\x01\x2A" || ( ord( $head[0] ) & 1 ) ) {
				return null;
			}

			$width = unpack( 'v', substr( $head, 6, 2 ) )[1] & 0x3FFF;
			$height = unpack( 'v', substr( $head, 8, 2 ) )[1] & 0x3FFF;
			$alpha = false;

		} else {

			// Signature, then 14-bit width-1 and height-1, and the alpha bit.
			if ( strlen( $head ) < 5 || "\x2F" !== $head[0] ) {
				return null;
			}

			$bits = unpack( 'V', substr( $head, 1, 4 ) )[1];
			$width = ( $bits & 0x3FFF ) + 1;
			$height = ( ( $bits >> 14 ) & 0x3FFF ) + 1;
			$alpha = (bool) ( ( $bits >> 28 ) & 1 );
		}

		if ( ! $width || ! $height ) {
			return null;
		}

		return chr( $alpha ? self::FLAG_ALPHA : 0 ) . "\0\0\0" . self::uint24( $width - 1 ) . self::uint24( $height - 1 );
	}

	protected static function uint24( $n ) {

		return chr( $n & 0xFF ) . chr( ( $n >> 8 ) & 0xFF ) . chr( ( $n >> 16 ) & 0xFF );
	}

	protected static function withXmpFlag( $vp8x ) {

		$vp8x[0] = chr( ord( $vp8x[0] ) | self::FLAG_XMP );

		return $vp8x;
	}

	public function plan( Stream $in, array $layout, $packet ) {

		$blocks = $layout['blocks'];

		// In place of the old chunk; otherwise last.
		$new = 'XMP ' . pack( 'V', strlen( $packet ) ) . $packet . ( strlen( $packet ) % 2 ? "\0" : '' );
		$pieces = self::splice( $blocks, null === $layout['xmp'] ? count( $blocks ) : $layout['xmp'], $new );

		$vp8x = self::withXmpFlag( $layout['vp8x'] );
		$vp8x = 'VP8X' . pack( 'V', strlen( $vp8x ) ) . $vp8x . ( strlen( $vp8x ) % 2 ? "\0" : '' );
		$riff = 4 + strlen( $vp8x );

		foreach ( $pieces as $piece ) {
			$riff += 'copy' === $piece[0] ? $piece[2] : strlen( $piece[1] );
		}

		array_unshift( $pieces, [ 'bytes', 'RIFF' . pack( 'V', $riff ) . 'WEBP' . $vp8x ] );

		return $pieces;
	}

	public function unchanged( Stream $in, array $before, Stream $out, array $after ) {

		return self::sameKeptBlocks( $in, $before, $out, $after ) && $after['vp8x'] === self::withXmpFlag( $before['vp8x'] );
	}
}
