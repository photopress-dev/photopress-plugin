<?php

namespace PhotoPress\modules\metadata\xmp;

use WP_Error;

/**
 * One file format's XMP: where the packet is, and how to write a file with a
 * new one in its place without changing anything else.
 *
 * The output of plan() is a list of pieces: [ 'copy', offset, length ] from
 * the original, or [ 'bytes', string ].
 */
abstract class Format {

	/**
	 * Whether the file's first 16 bytes are this format's.
	 */
	abstract public function matches( $magic );

	/**
	 * The file's structure, with 'packet' its XMP packet ('' for none).
	 *
	 * @return array|WP_Error
	 */
	abstract public function parse( Stream $in );

	/**
	 * The file with $packet as its XMP.
	 *
	 * @return array|WP_Error Pieces.
	 */
	abstract public function plan( Stream $in, array $layout, $packet );

	/**
	 * Whether the written file has everything of the original but its XMP,
	 * unchanged. $after is the written file parsed.
	 */
	abstract public function unchanged( Stream $in, array $before, Stream $out, array $after );

	protected static function error( $code, $message ) {

		return new WP_Error( 'photopress_xmp_' . $code, $message );
	}

	/**
	 * Copies of $offset..$offset+$length with the given ranges overwritten by
	 * spaces: where an old packet was, so no stale copy of it is left for
	 * readers that search the file for one.
	 */
	protected static function copyWithBlanks( $offset, $length, array $blanks ) {

		$pieces = [];
		$end = $offset + $length;

		usort( $blanks, static function ( $a, $b ) {
			return $a[0] <=> $b[0];
		} );

		foreach ( $blanks as $blank ) {

			$from = max( $offset, $blank[0] );
			$to = min( $end, $blank[0] + $blank[1] );

			if ( $from >= $to ) {
				continue;
			}

			if ( $from > $offset ) {
				$pieces[] = [ 'copy', $offset, $from - $offset ];
			}

			$pieces[] = [ 'bytes', str_repeat( ' ', $to - $from ) ];
			$offset = $to;
		}

		if ( $offset < $end ) {
			$pieces[] = [ 'copy', $offset, $end - $offset ];
		}

		return $pieces;
	}

	/**
	 * For formats made of a list of blocks ('keep', 'xmp' or 'header'):
	 * the blocks as pieces, with $new in place of block $xmp, or inserted
	 * before block $at. Header blocks are left out, for the caller to add.
	 */
	protected static function splice( array $blocks, $at, $new ) {

		$pieces = [];

		foreach ( $blocks as $i => $block ) {

			if ( $i === $at ) {
				$pieces[] = [ 'bytes', $new ];
			}

			if ( 'keep' === $block['kind'] ) {
				$pieces[] = [ 'copy', $block['offset'], $block['length'] ];
			}
		}

		if ( $at >= count( $blocks ) ) {
			$pieces[] = [ 'bytes', $new ];
		}

		return $pieces;
	}

	/**
	 * For formats made of a list of blocks: the 'keep' blocks hash the same,
	 * in the same order, before and after.
	 */
	protected static function sameKeptBlocks( Stream $in, array $before, Stream $out, array $after ) {

		$kept = static function ( array $blocks ) {
			$ranges = [];
			foreach ( $blocks as $block ) {
				if ( 'keep' === $block['kind'] ) {
					$ranges[] = [ $block['offset'], $block['length'] ];
				}
			}
			return $ranges;
		};

		$hash = $in->hash( $kept( $before['blocks'] ) );

		return null !== $hash && $hash === $out->hash( $kept( $after['blocks'] ) );
	}
}
