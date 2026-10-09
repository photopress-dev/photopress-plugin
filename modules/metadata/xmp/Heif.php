<?php

namespace PhotoPress\modules\metadata\xmp;

/**
 * HEIF still images: HEIC and AVIF. The packet is an item of type 'mime'
 * with content type application/rdf+xml, listed in the meta box's iinf and
 * iloc, and linked to the primary image by a 'cdsc' reference in iref.
 *
 * The packet goes in a new mdat box at the end of the file. The meta box
 * grows by the new item's entries, which moves every box after it, so iloc
 * offsets into those boxes move by the same amount; nothing else in the
 * file holds absolute offsets. A packet already in the file is overwritten
 * with spaces and its item pointed at the new one.
 *
 * Not written: image sequences (a moov box), a meta box with more than one
 * of the boxes this changes, items stored in other files or by construction
 * method 2, an encoded (compressed) packet, and a last box that runs to the
 * end of the file.
 */
class Heif extends Format {

	const XMP_TYPE = 'application/rdf+xml';

	const BRANDS = [ 'mif1', 'heic', 'heix', 'heim', 'heis', 'avif', 'miaf' ];

	/**
	 * Largest meta box read into memory.
	 */
	const MAX_META = 16777216;

	public function matches( $magic ) {

		return substr( $magic, 4, 4 ) === 'ftyp' && in_array( substr( $magic, 8, 4 ), self::BRANDS, true );
	}

	public function parse( Stream $in ) {

		$top = self::boxes( $in, 0, $in->size );

		if ( is_wp_error( $top ) ) {
			return $top;
		}

		$metas = array_keys( array_filter( array_column( $top, 'type' ), static function ( $type ) {
			return 'meta' === $type;
		} ) );

		if ( in_array( 'moov', array_column( $top, 'type' ), true ) ) {
			return self::error( 'unsupported', 'HEIF image sequences are not written.' );
		}

		if ( count( $metas ) !== 1 ) {
			return self::error( 'unrecognised', 'The HEIF file does not have one meta box.' );
		}

		$meta = $top[ $metas[0] ];

		if ( $meta['size'] > self::MAX_META ) {
			return self::error( 'unrecognised', 'The HEIF meta box is too large to read.' );
		}

		$children = self::boxes( $in, $meta['offset'] + $meta['header'] + 4, $meta['offset'] + $meta['size'] );

		if ( is_wp_error( $children ) ) {
			return $children;
		}

		$layout = [ 'top' => $top, 'meta' => $metas[0], 'children' => $children ];

		foreach ( [ 'pitm', 'iloc', 'iinf', 'iref', 'idat' ] as $type ) {

			$found = array_keys( array_filter( array_column( $children, 'type' ), static function ( $t ) use ( $type ) {
				return $t === $type;
			} ) );

			if ( count( $found ) > 1 || ( ! $found && in_array( $type, [ 'pitm', 'iloc', 'iinf' ], true ) ) ) {
				return self::error( 'unrecognised', "The HEIF meta box does not have one $type box." );
			}

			$layout[ $type ] = $found ? $found[0] : null;
		}

		$pitm = self::payload( $in, $children[ $layout['pitm'] ] );
		$layout['primary'] = strlen( $pitm ) >= 6 ? self::uint( $pitm, 4, ord( $pitm[0] ) ? 4 : 2 ) : null;

		if ( null === $layout['primary'] ) {
			return self::error( 'unrecognised', 'The HEIF pitm box is cut short.' );
		}

		$layout['locations'] = self::parseIloc( self::payload( $in, $children[ $layout['iloc'] ] ) );
		$layout['infos'] = self::parseIinf( $in, $children[ $layout['iinf'] ] );
		$layout['refs'] = null === $layout['iref'] ? null : self::parseIref( $in, $children[ $layout['iref'] ] );

		foreach ( [ 'locations', 'infos', 'refs' ] as $key ) {
			if ( is_wp_error( $layout[ $key ] ) ) {
				return $layout[ $key ];
			}
		}

		$xmp = array_keys( array_filter( $layout['infos']['items'], static function ( $info ) {
			return 'mime' === $info['type'] && self::XMP_TYPE === $info['content_type'];
		} ) );

		if ( count( $xmp ) > 1 ) {
			return self::error( 'unrecognised', 'The HEIF file has more than one XMP item.' );
		}

		$layout['xmp'] = $xmp ? $layout['infos']['items'][ $xmp[0] ]['id'] : null;
		$layout['packet'] = '';

		if ( null !== $layout['xmp'] ) {

			$ranges = self::ranges( $layout, $layout['xmp'] );

			if ( null === $ranges ) {
				return self::error( 'unrecognised', 'The HEIF XMP item is not stored in this file.' );
			}

			$packet = '';
			foreach ( $ranges as $range ) {
				$packet .= $in->bytes( $range[0], $range[1] );
			}

			$encoding = $layout['infos']['items'][ $xmp[0] ]['encoding'];
			$layout['encoded'] = '' !== $encoding;

			if ( 'deflate' === $encoding && function_exists( 'gzuncompress' ) ) {
				$packet = (string) @gzuncompress( $packet ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			} elseif ( '' !== $encoding ) {
				$packet = '';
			}

			$layout['packet'] = $packet;
		}

		return $layout;
	}

	/**
	 * The boxes between $start and $end: offset, size, type and header size.
	 *
	 * @return array|\WP_Error
	 */
	protected static function boxes( Stream $in, $start, $end ) {

		$boxes = [];
		$pos = $start;

		while ( $pos < $end ) {

			$head = $in->bytes( $pos, 16 );

			if ( strlen( $head ) < 8 ) {
				return self::error( 'unrecognised', 'A HEIF box header is cut short.' );
			}

			$size = unpack( 'N', substr( $head, 0, 4 ) )[1];
			$type = substr( $head, 4, 4 );
			$header = 8;
			$large = false;
			$open = false;

			if ( 1 === $size ) {

				if ( strlen( $head ) < 16 ) {
					return self::error( 'unrecognised', 'A HEIF box header is cut short.' );
				}

				$size = self::uint( $head, 8, 8 );
				$header = 16;
				$large = true;

			} elseif ( 0 === $size ) {

				$size = $end - $pos;
				$open = true;
			}

			if ( 'uuid' === $type ) {
				$header += 16;
			}

			if ( $size < $header || $pos + $size > $end ) {
				return self::error( 'unrecognised', 'A HEIF box is outside its parent.' );
			}

			$boxes[] = [ 'offset' => $pos, 'size' => $size, 'type' => $type, 'header' => $header, 'large' => $large, 'open' => $open ];
			$pos += $size;
		}

		return $boxes;
	}

	protected static function payload( Stream $in, array $box ) {

		return $in->bytes( $box['offset'] + $box['header'], $box['size'] - $box['header'] );
	}

	/**
	 * A big-endian unsigned integer of 0, 2, 4 or 8 bytes.
	 */
	protected static function uint( $bytes, $offset, $size ) {

		$data = substr( $bytes, $offset, $size );

		if ( strlen( $data ) !== $size ) {
			return null;
		}

		switch ( $size ) {
			case 0:
				return 0;
			case 2:
				return unpack( 'n', $data )[1];
			case 4:
				return unpack( 'N', $data )[1];
			case 8:
				$value = unpack( 'J', $data )[1];
				return $value < 0 ? null : $value;
		}

		return null;
	}

	/**
	 * Packs $value into $size bytes; null when it does not fit.
	 */
	protected static function packUint( $value, $size ) {

		if ( $value < 0 ) {
			return null;
		}

		switch ( $size ) {
			case 0:
				return 0 === $value ? '' : null;
			case 2:
				return $value <= 0xFFFF ? pack( 'n', $value ) : null;
			case 4:
				return $value <= 0xFFFFFFFF ? pack( 'N', $value ) : null;
			case 8:
				return pack( 'J', $value );
		}

		return null;
	}

	/**
	 * The iloc box payload as fields, checked by writing it back: a payload
	 * this cannot reproduce exactly is not one it understands.
	 */
	protected static function parseIloc( $data ) {

		if ( strlen( $data ) < 8 ) {
			return self::error( 'unrecognised', 'The HEIF iloc box is cut short.' );
		}

		$version = ord( $data[0] );

		if ( $version > 2 ) {
			return self::error( 'unrecognised', 'Unknown HEIF iloc version.' );
		}

		$iloc = [
			'version'     => $version,
			'flags'       => substr( $data, 1, 3 ),
			'offset_size' => ord( $data[4] ) >> 4,
			'length_size' => ord( $data[4] ) & 15,
			'base_size'   => ord( $data[5] ) >> 4,
			'index_size'  => $version ? ord( $data[5] ) & 15 : 0,
			'reserved'    => $version ? 0 : ord( $data[5] ) & 15,
			'items'       => [],
		];

		foreach ( [ 'offset_size', 'length_size', 'base_size', 'index_size' ] as $key ) {
			if ( ! in_array( $iloc[ $key ], [ 0, 4, 8 ], true ) ) {
				return self::error( 'unrecognised', 'Unknown HEIF iloc field size.' );
			}
		}

		$id = $version < 2 ? 2 : 4;
		$pos = 6;
		$count = self::uint( $data, $pos, $id );
		$pos += $id;

		for ( $i = 0; $i < (int) $count; $i++ ) {

			$item = [ 'id' => self::uint( $data, $pos, $id ), 'method_field' => 0 ];
			$pos += $id;

			if ( $version ) {
				$item['method_field'] = self::uint( $data, $pos, 2 );
				$pos += 2;
			}

			$item['method'] = (int) $item['method_field'] & 15;
			$item['dref'] = self::uint( $data, $pos, 2 );
			$item['base'] = self::uint( $data, $pos + 2, $iloc['base_size'] );
			$extents = self::uint( $data, $pos + 2 + $iloc['base_size'], 2 );
			$pos += 4 + $iloc['base_size'];
			$item['extents'] = [];

			for ( $e = 0; $e < (int) $extents; $e++ ) {
				$extent = [
					'index'  => self::uint( $data, $pos, $iloc['index_size'] ),
					'offset' => self::uint( $data, $pos + $iloc['index_size'], $iloc['offset_size'] ),
					'length' => self::uint( $data, $pos + $iloc['index_size'] + $iloc['offset_size'], $iloc['length_size'] ),
				];
				$pos += $iloc['index_size'] + $iloc['offset_size'] + $iloc['length_size'];

				if ( in_array( null, $extent, true ) ) {
					return self::error( 'unrecognised', 'The HEIF iloc box is cut short.' );
				}

				$item['extents'][] = $extent;
			}

			if ( in_array( null, [ $item['id'], $item['method_field'], $item['dref'], $item['base'], $extents ], true ) ) {
				return self::error( 'unrecognised', 'The HEIF iloc box is cut short.' );
			}

			$iloc['items'][] = $item;
		}

		if ( null === $count || self::buildIloc( $iloc ) !== $data ) {
			return self::error( 'unrecognised', 'The HEIF iloc box has data this does not understand.' );
		}

		return $iloc;
	}

	/**
	 * The iloc payload for the fields; null when a value does not fit its field.
	 */
	protected static function buildIloc( array $iloc ) {

		$id = $iloc['version'] < 2 ? 2 : 4;
		$out = chr( $iloc['version'] ) . $iloc['flags']
			. chr( ( $iloc['offset_size'] << 4 ) | $iloc['length_size'] )
			. chr( ( $iloc['base_size'] << 4 ) | ( $iloc['version'] ? $iloc['index_size'] : $iloc['reserved'] ) )
			. self::packUint( count( $iloc['items'] ), $id );

		foreach ( $iloc['items'] as $item ) {

			$fields = [ self::packUint( $item['id'], $id ) ];

			if ( $iloc['version'] ) {
				$fields[] = self::packUint( $item['method_field'], 2 );
			}

			$fields[] = self::packUint( $item['dref'], 2 );
			$fields[] = self::packUint( $item['base'], $iloc['base_size'] );
			$fields[] = self::packUint( count( $item['extents'] ), 2 );

			foreach ( $item['extents'] as $extent ) {
				$fields[] = self::packUint( $extent['index'], $iloc['index_size'] );
				$fields[] = self::packUint( $extent['offset'], $iloc['offset_size'] );
				$fields[] = self::packUint( $extent['length'], $iloc['length_size'] );
			}

			if ( in_array( null, $fields, true ) ) {
				return null;
			}

			$out .= implode( '', $fields );
		}

		return $out;
	}

	/**
	 * The iinf box: its version, and each infe entry's raw bytes, ID, item
	 * type, content type and content encoding.
	 */
	protected static function parseIinf( Stream $in, array $box ) {

		$data = self::payload( $in, $box );

		if ( strlen( $data ) < 6 ) {
			return self::error( 'unrecognised', 'The HEIF iinf box is cut short.' );
		}

		$version = ord( $data[0] );
		$size = $version ? 4 : 2;
		$count = self::uint( $data, 4, $size );
		$entries = self::boxes( $in, $box['offset'] + $box['header'] + 4 + $size, $box['offset'] + $box['size'] );

		if ( null === $count || is_wp_error( $entries ) || count( $entries ) !== $count ) {
			return self::error( 'unrecognised', 'The HEIF iinf box does not list its entries.' );
		}

		$items = [];

		foreach ( $entries as $entry ) {

			$raw = $in->bytes( $entry['offset'], $entry['size'] );
			$infe = substr( $raw, $entry['header'] );
			$v = strlen( $infe ) >= 12 ? ord( $infe[0] ) : 0;

			if ( 'infe' !== $entry['type'] || $v < 2 || $v > 3 ) {
				return self::error( 'unrecognised', 'A HEIF item entry is of an older or unknown version.' );
			}

			$id_size = 2 === $v ? 2 : 4;
			$item_type = substr( $infe, 4 + $id_size + 2, 4 );
			$strings = explode( "\0", substr( $infe, 4 + $id_size + 6 ) );

			$items[] = [
				'id'           => self::uint( $infe, 4, $id_size ),
				'raw'          => $raw,
				'type'         => $item_type,
				'content_type' => 'mime' === $item_type ? ( $strings[1] ?? '' ) : '',
				'encoding'     => 'mime' === $item_type ? ( $strings[2] ?? '' ) : '',
			];
		}

		return [ 'version' => $version, 'flags' => substr( $data, 1, 3 ), 'items' => $items ];
	}

	/**
	 * The iref box: its version, and each reference's raw bytes, type, from
	 * and to IDs.
	 */
	protected static function parseIref( Stream $in, array $box ) {

		$data = self::payload( $in, $box );

		if ( strlen( $data ) < 4 ) {
			return self::error( 'unrecognised', 'The HEIF iref box is cut short.' );
		}

		$version = ord( $data[0] );
		$id = $version ? 4 : 2;
		$boxes = self::boxes( $in, $box['offset'] + $box['header'] + 4, $box['offset'] + $box['size'] );

		if ( is_wp_error( $boxes ) ) {
			return $boxes;
		}

		$refs = [];

		foreach ( $boxes as $ref ) {

			$raw = $in->bytes( $ref['offset'], $ref['size'] );
			$body = substr( $raw, $ref['header'] );
			$count = self::uint( $body, $id, 2 );
			$to = [];

			for ( $i = 0; $i < (int) $count; $i++ ) {
				$to[] = self::uint( $body, $id + 2 + $id * $i, $id );
			}

			$refs[] = [ 'raw' => $raw, 'type' => $ref['type'], 'from' => self::uint( $body, 0, $id ), 'to' => $to ];
		}

		return [ 'version' => $version, 'flags' => substr( $data, 1, 3 ), 'refs' => $refs ];
	}

	/**
	 * Where an item's data is, as absolute [ offset, length ] ranges; null
	 * when it is not in this file, or is stored in a way this does not read.
	 */
	protected static function ranges( array $layout, $id ) {

		foreach ( $layout['locations']['items'] as $item ) {

			if ( $item['id'] !== $id ) {
				continue;
			}

			if ( 0 !== $item['dref'] || $item['method'] > 1 ) {
				return null;
			}

			$base = $item['base'];

			if ( 1 === $item['method'] ) {

				if ( null === $layout['idat'] ) {
					return null;
				}

				$idat = $layout['children'][ $layout['idat'] ];
				$base += $idat['offset'] + $idat['header'];
			}

			$ranges = [];

			foreach ( $item['extents'] as $extent ) {

				if ( ! $extent['length'] ) {
					return null;
				}

				$ranges[] = [ $base + $extent['offset'], $extent['length'] ];
			}

			return $ranges;
		}

		// An item with no location has no data.
		return [];
	}

	/**
	 * A box with $payload; $like gives the header form (64-bit size) to keep.
	 */
	protected static function box( $type, $payload, array $like = null ) {

		$size = 8 + strlen( $payload );

		if ( $like && $like['large'] ) {
			return pack( 'N', 1 ) . $type . pack( 'J', $size + 8 ) . $payload;
		}

		return $size > 0xFFFFFFFF ? null : pack( 'N', $size ) . $type . $payload;
	}

	public function plan( Stream $in, array $layout, $packet ) {

		if ( ! empty( $layout['encoded'] ) ) {
			return self::error( 'unsupported', 'The HEIF XMP item is encoded.' );
		}

		$top = $layout['top'];
		$meta = $top[ $layout['meta'] ];
		$children = $layout['children'];

		if ( end( $top )['open'] ) {
			return self::error( 'unsupported', 'The last HEIF box runs to the end of the file.' );
		}

		foreach ( $children as $child ) {
			if ( 'uuid' === $child['type'] || $child['large'] ) {
				return self::error( 'unsupported', 'The HEIF meta box has boxes this does not rewrite.' );
			}
		}

		$iloc = $layout['locations'];
		$id = $layout['xmp'];
		$adding = null === $id;
		$blanks = $adding ? [] : (array) self::ranges( $layout, $id );

		if ( $adding ) {

			$ids = array_merge( array_column( $iloc['items'], 'id' ), array_column( $layout['infos']['items'], 'id' ) );
			$id = max( $ids ? $ids : [ 0 ] ) + 1;

			if ( $id > 0xFFFF && ( $iloc['version'] < 2 || ( $layout['refs'] && ! $layout['refs']['version'] ) ) ) {
				return self::error( 'unsupported', 'The HEIF item IDs are too large for its boxes.' );
			}
		}

		// The meta box's children, rebuilt with the new item; iloc for now with
		// placeholder offsets, as its size does not depend on their values.
		$replaced = [];

		if ( $adding ) {

			$v = $id > 0xFFFF ? 3 : 2;
			$infe = self::box( 'infe', chr( $v ) . "\0\0\0" . self::packUint( $id, 2 === $v ? 2 : 4 ) . "\0\0mime" . "XMP\0" . self::XMP_TYPE . "\0" );
			$infos = $layout['infos'];
			$count = self::packUint( count( $infos['items'] ) + 1, $infos['version'] ? 4 : 2 );

			if ( null === $count ) {
				return self::error( 'unsupported', 'The HEIF iinf box cannot list another item.' );
			}

			$replaced[ $layout['iinf'] ] = self::box( 'iinf', chr( $infos['version'] ) . $infos['flags'] . $count . implode( '', array_column( $infos['items'], 'raw' ) ) . $infe );

			$refs = $layout['refs'] ? $layout['refs'] : [ 'version' => $id > 0xFFFF || $layout['primary'] > 0xFFFF ? 1 : 0, 'flags' => "\0\0\0", 'refs' => [] ];
			$size = $refs['version'] ? 4 : 2;
			$cdsc = self::box( 'cdsc', self::packUint( $id, $size ) . pack( 'n', 1 ) . self::packUint( $layout['primary'], $size ) );
			$iref = self::box( 'iref', chr( $refs['version'] ) . $refs['flags'] . implode( '', array_column( $refs['refs'], 'raw' ) ) . $cdsc );

			if ( null === $layout['iref'] ) {
				$replaced['new'] = $iref;
			} else {
				$replaced[ $layout['iref'] ] = $iref;
			}
		}

		$located = self::locate( $iloc, $id, 0, 0, 0, $meta );

		if ( is_wp_error( $located ) ) {
			return $located;
		}

		$replaced[ $layout['iloc'] ] = self::box( 'iloc', self::buildIloc( $located ) );
		$build = function ( array $replaced ) use ( $in, $children, $meta, $blanks ) {

			$payload = $in->bytes( $meta['offset'] + $meta['header'], 4 );

			foreach ( $children as $i => $child ) {
				$payload .= $replaced[ $i ] ?? self::blankBytes( $in->bytes( $child['offset'], $child['size'] ), $child['offset'], $blanks );
			}

			$payload .= $replaced['new'] ?? '';

			return self::box( 'meta', $payload, $meta );
		};

		$delta = strlen( (string) $build( $replaced ) ) - $meta['size'];

		// The new mdat box goes at the end of the file, moved by $delta too.
		$data = $in->size + $delta + 8;
		$located = self::locate( $iloc, $id, $data, strlen( $packet ), $delta, $meta );

		if ( is_wp_error( $located ) ) {
			return $located;
		}

		$replaced[ $layout['iloc'] ] = self::box( 'iloc', self::buildIloc( $located ) );
		$metaBytes = $build( $replaced );
		$mdat = self::box( 'mdat', $packet );

		if ( null === $metaBytes || null === $mdat || strlen( $metaBytes ) - $meta['size'] !== $delta ) {
			return self::error( 'too_large', 'The HEIF boxes would be too large.' );
		}

		$pieces = [];

		foreach ( $top as $i => $box ) {
			if ( $i === $layout['meta'] ) {
				$pieces[] = [ 'bytes', $metaBytes ];
			} else {
				$pieces = array_merge( $pieces, self::copyWithBlanks( $box['offset'], $box['size'], $blanks ) );
			}
		}

		$pieces[] = [ 'bytes', $mdat ];

		return $pieces;
	}

	/**
	 * The iloc fields with the XMP item at $offset ($length bytes, as one
	 * extent in the file), and every other item's offsets past the meta box
	 * moved by $delta.
	 *
	 * @return array|\WP_Error
	 */
	protected static function locate( array $iloc, $id, $offset, $length, $delta, array $meta ) {

		$start = $meta['offset'];
		$end = $meta['offset'] + $meta['size'];

		if ( ! $iloc['length_size'] || ( ! $iloc['offset_size'] && ! $iloc['base_size'] ) ) {
			return self::error( 'unsupported', 'The HEIF iloc box cannot locate a new item.' );
		}

		$xmp = [
			'id'           => $id,
			'method_field' => 0,
			'method'       => 0,
			'dref'         => 0,
			'base'         => $iloc['offset_size'] ? 0 : $offset,
			'extents'      => [ [ 'index' => 0, 'offset' => $iloc['offset_size'] ? $offset : 0, 'length' => $length ] ],
		];
		$found = false;

		foreach ( $iloc['items'] as $i => $item ) {

			if ( $item['id'] === $id ) {
				$iloc['items'][ $i ] = $xmp;
				$found = true;
				continue;
			}

			if ( 0 !== $item['method'] ) {
				continue;
			}

			// Moved: offsets into boxes after the meta box. Into the meta box
			// itself, which is rebuilt, is not supported.
			foreach ( $item['extents'] as $e => $extent ) {

				$at = $item['base'] + $extent['offset'];

				if ( $at >= $start && $at < $end ) {
					return self::error( 'unsupported', 'A HEIF item is stored inside the meta box by file offset.' );
				}

				if ( $at >= $end && ! $iloc['base_size'] ) {
					$iloc['items'][ $i ]['extents'][ $e ]['offset'] += $delta;
				}
			}

			if ( $iloc['base_size'] && $item['extents'] ) {

				$after = array_map( static function ( $extent ) use ( $item, $end ) {
					return $item['base'] + $extent['offset'] >= $end;
				}, $item['extents'] );

				if ( count( array_unique( $after ) ) > 1 ) {
					return self::error( 'unsupported', 'A HEIF item has data both before and after the meta box.' );
				}

				if ( $after[0] ) {
					$iloc['items'][ $i ]['base'] += $delta;
				}
			}
		}

		if ( ! $found ) {
			$iloc['items'][] = $xmp;
		}

		if ( null === self::buildIloc( $iloc ) ) {
			return self::error( 'too_large', 'A HEIF item offset no longer fits its field.' );
		}

		return $iloc;
	}

	/**
	 * $bytes (found at $offset in the file) with the blank ranges as spaces.
	 */
	protected static function blankBytes( $bytes, $offset, array $blanks ) {

		foreach ( $blanks as $blank ) {

			$from = max( 0, $blank[0] - $offset );
			$to = min( strlen( $bytes ), $blank[0] + $blank[1] - $offset );

			if ( $from < $to ) {
				$bytes = substr_replace( $bytes, str_repeat( ' ', $to - $from ), $from, $to - $from );
			}
		}

		return $bytes;
	}

	public function unchanged( Stream $in, array $before, Stream $out, array $after ) {

		$types = array_column( $before['top'], 'type' );
		$types[] = 'mdat';

		if ( array_column( $after['top'], 'type' ) !== $types || $before['primary'] !== $after['primary'] ) {
			return false;
		}

		// Boxes outside the meta box, but mdat (its contents are items, checked
		// below, and the old packet, now spaces).
		foreach ( $before['top'] as $i => $box ) {
			if ( ! in_array( $box['type'], [ 'meta', 'mdat' ], true ) ) {
				$other = $after['top'][ $i ];
				if ( $in->hash( [ [ $box['offset'], $box['size'] ] ] ) !== $out->hash( [ [ $other['offset'], $other['size'] ] ] ) ) {
					return false;
				}
			} elseif ( $box['size'] !== $after['top'][ $i ]['size'] && 'mdat' === $box['type'] ) {
				return false;
			}
		}

		// The meta box's other children.
		$children = array_column( $before['children'], 'type' );

		if ( null === $before['iref'] && null !== $after['iref'] ) {
			$children[] = 'iref';
		}

		if ( array_column( $after['children'], 'type' ) !== $children ) {
			return false;
		}

		foreach ( $before['children'] as $i => $child ) {
			if ( ! in_array( $child['type'], [ 'iinf', 'iloc', 'iref', 'idat' ], true ) ) {
				$other = $after['children'][ $i ];
				if ( $in->hash( [ [ $child['offset'], $child['size'] ] ] ) !== $out->hash( [ [ $other['offset'], $other['size'] ] ] ) ) {
					return false;
				}
			}
		}

		// Every other item: the same entry, and the same data where it now is.
		$xmp = $after['xmp'];
		$entries = static function ( array $layout ) use ( $xmp ) {
			$raw = [];
			foreach ( $layout['infos']['items'] as $item ) {
				if ( $item['id'] !== $xmp ) {
					$raw[ $item['id'] ] = $item['raw'];
				}
			}
			return $raw;
		};

		if ( null === $xmp || ( null !== $before['xmp'] && $before['xmp'] !== $xmp ) || $entries( $before ) !== $entries( $after ) ) {
			return false;
		}

		foreach ( array_unique( array_merge( array_column( $before['locations']['items'], 'id' ), array_column( $before['infos']['items'], 'id' ) ) ) as $id ) {

			if ( $id === $xmp ) {
				continue;
			}

			$was = self::ranges( $before, $id );
			$now = self::ranges( $after, $id );

			if ( null === $was || null === $now || array_sum( array_column( $was, 1 ) ) !== array_sum( array_column( $now, 1 ) ) || $in->hash( $was ) !== $out->hash( $now ) ) {
				return false;
			}
		}

		// References: all of the original's, and the new item's to the image.
		$refs = static function ( $layout ) {
			return $layout['refs'] ? array_column( $layout['refs']['refs'], 'raw' ) : [];
		};

		return ! array_diff( $refs( $before ), $refs( $after ) );
	}
}
