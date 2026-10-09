<?php

namespace PhotoPress\modules\metadata\xmp;

/**
 * A file opened for reading by offset, for the format parsers.
 */
class Stream {

	public $size;

	protected $fh;

	/**
	 * @return Stream|null
	 */
	public static function open( $path ) {

		clearstatcache( true, $path );
		$size = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$fh = $size ? @fopen( $path, 'rb' ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( ! $fh ) {
			return null;
		}

		$stream = new self();
		$stream->fh = $fh;
		$stream->size = $size;

		return $stream;
	}

	/**
	 * Up to $length bytes from $offset; fewer at the end of the file.
	 */
	public function bytes( $offset, $length ) {

		if ( $length <= 0 || $offset < 0 || $offset >= $this->size || fseek( $this->fh, $offset ) !== 0 ) {
			return '';
		}

		$data = '';

		while ( strlen( $data ) < $length && ! feof( $this->fh ) ) {

			$chunk = fread( $this->fh, $length - strlen( $data ) );

			if ( false === $chunk || '' === $chunk ) {
				break;
			}

			$data .= $chunk;
		}

		return $data;
	}

	/**
	 * Copies $length bytes from $offset to $out.
	 */
	public function copyTo( $out, $offset, $length ) {

		return fseek( $this->fh, $offset ) === 0 && stream_copy_to_stream( $this->fh, $out, $length ) === $length;
	}

	/**
	 * One hash of the given ranges, in order: [ [ offset, length ], ... ].
	 * Null when a range is outside the file.
	 */
	public function hash( array $ranges ) {

		$ctx = hash_init( 'sha256' );

		foreach ( $ranges as $range ) {

			if ( $range[1] && ( fseek( $this->fh, $range[0] ) !== 0 || hash_update_stream( $ctx, $this->fh, $range[1] ) !== $range[1] ) ) {
				return null;
			}
		}

		return hash_final( $ctx );
	}

	public function close() {

		fclose( $this->fh );
	}
}
