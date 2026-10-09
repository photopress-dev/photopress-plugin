<?php

namespace PhotoPress\modules\metadata\xmp;

/**
 * TIFF: the packet is the value of tag 700 (XMLPacket) in the first image
 * directory (IFD0).
 *
 * Nothing in the file moves: the packet and a copy of IFD0 with the new tag
 * are added at the end, and the header is pointed at the copy. Every offset
 * in the file stays valid, the image data included. The old packet, if any,
 * is overwritten with spaces; the old IFD0 is left unused. BigTIFF is not
 * written.
 */
class Tiff extends Format {

	const TAG = 700;

	public function matches( $magic ) {

		return strncmp( $magic, "II*\0", 4 ) === 0 || strncmp( $magic, "MM\0*", 4 ) === 0;
	}

	public function parse( Stream $in ) {

		$le = 'I' === $in->bytes( 0, 1 );
		$u16 = $le ? 'v' : 'n';
		$u32 = $le ? 'V' : 'N';

		$ifd = unpack( $u32, $in->bytes( 4, 4 ) )[1];
		$count = $ifd >= 8 ? unpack( $u16, $in->bytes( $ifd, 2 ) . "\0\0" )[1] : 0;
		$table = $in->bytes( $ifd + 2, 12 * $count + 4 );

		if ( ! $count || strlen( $table ) !== 12 * $count + 4 ) {
			return self::error( 'unrecognised', 'The TIFF first directory is outside the file.' );
		}

		$entries = [];
		$xmp = null;
		$packet = '';

		for ( $i = 0; $i < $count; $i++ ) {

			$entry = substr( $table, 12 * $i, 12 );
			$fields = unpack( "{$u16}tag/{$u16}type/{$u32}count/{$u32}value", $entry );
			$entries[] = [ 'tag' => $fields['tag'], 'raw' => $entry ];

			if ( self::TAG !== $fields['tag'] ) {
				continue;
			}

			if ( null !== $xmp || ! in_array( $fields['type'], [ 1, 7 ], true ) ) {
				return self::error( 'unrecognised', 'The TIFF has more than one XMP tag, or one of an unexpected type.' );
			}

			$xmp = [ 'offset' => $fields['count'] > 4 ? $fields['value'] : $ifd + 2 + 12 * $i + 8, 'length' => $fields['count'], 'inline' => $fields['count'] <= 4 ];
			$packet = $xmp['inline'] ? substr( $entry, 8, $fields['count'] ) : $in->bytes( $xmp['offset'], $xmp['length'] );

			if ( strlen( $packet ) !== $fields['count'] ) {
				return self::error( 'unrecognised', 'The TIFF XMP tag points outside the file.' );
			}
		}

		return [
			'le'      => $le,
			'ifd'     => $ifd,
			'entries' => $entries,
			'next'    => substr( $table, 12 * $count, 4 ),
			'xmp'     => $xmp,
			'packet'  => $packet,
		];
	}

	public function plan( Stream $in, array $layout, $packet ) {

		$u16 = $layout['le'] ? 'v' : 'n';
		$u32 = $layout['le'] ? 'V' : 'N';

		// Word-aligned, as TIFF offsets should be.
		$pad = $in->size % 2 ? "\0" : '';
		$at = $in->size + strlen( $pad );
		$ifd = $at + strlen( $packet ) + ( strlen( $packet ) % 2 );

		if ( $ifd + 6 + 12 * ( count( $layout['entries'] ) + 1 ) > 0xFFFFFFFF ) {
			return self::error( 'too_large', 'The TIFF would be larger than 4 GB.' );
		}

		// IFD0 with the new tag, entries in tag order.
		$entries = array_filter( $layout['entries'], static function ( $entry ) {
			return self::TAG !== $entry['tag'];
		} );
		$entries[] = [ 'tag' => self::TAG, 'raw' => pack( "{$u16}{$u16}{$u32}{$u32}", self::TAG, 1, strlen( $packet ), $at ) ];
		usort( $entries, static function ( $a, $b ) {
			return $a['tag'] <=> $b['tag'];
		} );

		$directory = pack( $u16, count( $entries ) ) . implode( '', array_column( $entries, 'raw' ) ) . $layout['next'];
		$blanks = $layout['xmp'] && ! $layout['xmp']['inline'] ? [ [ $layout['xmp']['offset'], $layout['xmp']['length'] ] ] : [];

		return array_merge(
			[ [ 'bytes', substr( $in->bytes( 0, 4 ), 0, 4 ) . pack( $u32, $ifd ) ] ],
			self::copyWithBlanks( 8, $in->size - 8, $blanks ),
			[ [ 'bytes', $pad . $packet . ( strlen( $packet ) % 2 ? "\0" : '' ) . $directory ] ]
		);
	}

	public function unchanged( Stream $in, array $before, Stream $out, array $after ) {

		// The original's bytes after the header, at the same offsets, except
		// the old packet.
		$ranges = [];
		$from = 8;
		$old = $before['xmp'] && ! $before['xmp']['inline'] ? $before['xmp'] : null;

		if ( $old && $old['offset'] >= 8 ) {
			$ranges[] = [ $from, $old['offset'] - $from ];
			$from = $old['offset'] + $old['length'];
		}

		$ranges[] = [ $from, $in->size - $from ];
		$hash = $in->hash( $ranges );

		$others = static function ( array $layout ) {
			$raw = [];
			foreach ( $layout['entries'] as $entry ) {
				if ( self::TAG !== $entry['tag'] ) {
					$raw[] = $entry['raw'];
				}
			}
			return $raw;
		};

		return null !== $hash && $hash === $out->hash( $ranges )
			&& $in->bytes( 0, 4 ) === $out->bytes( 0, 4 )
			&& $others( $before ) === $others( $after )
			&& $before['next'] === $after['next'];
	}
}
