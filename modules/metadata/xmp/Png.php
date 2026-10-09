<?php

namespace PhotoPress\modules\metadata\xmp;

/**
 * PNG: the packet is the text of an iTXt chunk with the keyword
 * XML:com.adobe.xmp. It is written uncompressed, straight after IHDR, as the
 * XMP specification recommends; one already in the file is replaced where it
 * is. Data after IEND is kept.
 */
class Png extends Format {

	const SIGNATURE = "\x89PNG\r\n\x1A\n";

	const KEYWORD = 'XML:com.adobe.xmp';

	public function matches( $magic ) {

		return strncmp( $magic, self::SIGNATURE, 8 ) === 0;
	}

	public function parse( Stream $in ) {

		$blocks = [ [ 'offset' => 0, 'length' => 8, 'kind' => 'keep' ] ];
		$xmp = null;
		$packet = '';
		$pos = 8;
		$type = '';

		while ( 'IEND' !== $type ) {

			$head = $in->bytes( $pos, 8 );

			if ( strlen( $head ) < 8 ) {
				return self::error( 'unrecognised', 'The PNG ends before IEND.' );
			}

			$length = unpack( 'N', substr( $head, 0, 4 ) )[1];
			$type = substr( $head, 4, 4 );

			if ( $pos + 12 + $length > $in->size || ( count( $blocks ) === 1 && 'IHDR' !== $type ) ) {
				return self::error( 'unrecognised', 'A PNG chunk is outside the file, or IHDR is not first.' );
			}

			$block = [ 'offset' => $pos, 'length' => 12 + $length, 'kind' => 'keep' ];

			if ( 'iTXt' === $type && strncmp( $in->bytes( $pos + 8, strlen( self::KEYWORD ) + 1 ), self::KEYWORD . "\0", strlen( self::KEYWORD ) + 1 ) === 0 ) {

				if ( null !== $xmp ) {
					return self::error( 'unrecognised', 'The PNG has more than one XMP chunk.' );
				}

				$text = self::text( $in->bytes( $pos + 8, $length ) );

				if ( null === $text ) {
					return self::error( 'unrecognised', 'The PNG XMP chunk could not be read.' );
				}

				$xmp = count( $blocks );
				$block['kind'] = 'xmp';
				$packet = $text;
			}

			$blocks[] = $block;
			$pos += 12 + $length;
		}

		if ( $pos < $in->size ) {
			$blocks[] = [ 'offset' => $pos, 'length' => $in->size - $pos, 'kind' => 'keep' ];
		}

		return [ 'blocks' => $blocks, 'xmp' => $xmp, 'packet' => $packet ];
	}

	/**
	 * The text of iTXt chunk data: keyword, compression flag and method,
	 * language tag, translated keyword, then the text, compressed or not.
	 */
	protected static function text( $data ) {

		$parts = explode( "\0", $data, 2 );

		if ( count( $parts ) < 2 || strlen( $parts[1] ) < 2 ) {
			return null;
		}

		$compressed = ord( $parts[1][0] );
		$rest = explode( "\0", substr( $parts[1], 2 ), 3 );

		if ( count( $rest ) < 3 ) {
			return null;
		}

		if ( ! $compressed ) {
			return $rest[2];
		}

		$text = function_exists( 'gzuncompress' ) ? @gzuncompress( $rest[2] ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors

		return false === $text ? null : $text;
	}

	public function plan( Stream $in, array $layout, $packet ) {

		$data = self::KEYWORD . "\0\0\0\0\0" . $packet;
		$new = pack( 'N', strlen( $data ) ) . 'iTXt' . $data . pack( 'N', crc32( 'iTXt' . $data ) );

		return self::splice( $layout['blocks'], null === $layout['xmp'] ? 2 : $layout['xmp'], $new );
	}

	public function unchanged( Stream $in, array $before, Stream $out, array $after ) {

		return self::sameKeptBlocks( $in, $before, $out, $after );
	}
}
