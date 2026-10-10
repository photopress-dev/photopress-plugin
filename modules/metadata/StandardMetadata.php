<?php

namespace PhotoPress\modules\metadata;

/**
 * The camera and lens of an image, from each place cameras and photo
 * software (Lightroom, Capture One, Photo Mechanic) record them, in order,
 * and cleaned up so one camera is one term.
 */
final class StandardMetadata {

	/** A make as cameras write it (lower-cased) => the brand. */
	const BRANDS = [
		'apple'                     => 'Apple',
		'canon'                     => 'Canon',
		'casio computer co.,ltd.'   => 'Casio',
		'dji'                       => 'DJI',
		'eastman kodak company'     => 'Kodak',
		'fuji photo film co., ltd.' => 'Fujifilm',
		'fujifilm'                  => 'Fujifilm',
		'google'                    => 'Google',
		'gopro'                     => 'GoPro',
		'hasselblad'                => 'Hasselblad',
		'huawei'                    => 'Huawei',
		'kodak'                     => 'Kodak',
		'konica minolta'            => 'Konica Minolta',
		'leaf'                      => 'Leaf',
		'leica'                     => 'Leica',
		'leica camera ag'           => 'Leica',
		'mamiya'                    => 'Mamiya',
		'minolta co., ltd.'         => 'Minolta',
		'nikon'                     => 'Nikon',
		'nikon corporation'         => 'Nikon',
		'olympus'                   => 'Olympus',
		'olympus corporation'       => 'Olympus',
		'olympus imaging corp.'     => 'Olympus',
		'olympus optical co.,ltd'   => 'Olympus',
		'om digital solutions'      => 'OM System',
		'panasonic'                 => 'Panasonic',
		'pentax'                    => 'Pentax',
		'pentax corporation'        => 'Pentax',
		'phase one'                 => 'Phase One',
		'phase one a/s'             => 'Phase One',
		'ricoh'                     => 'Ricoh',
		'ricoh imaging company, ltd.' => 'Ricoh',
		'samsung'                   => 'Samsung',
		'samsung techwin'           => 'Samsung',
		'sigma'                     => 'Sigma',
		'sony'                      => 'Sony',
		'sony corporation'          => 'Sony',
		'xiaomi'                    => 'Xiaomi',
	];

	/** Written by editing or scanning software, not a camera. */
	const SOFTWARE = '/\b(adobe|photoshop|lightroom|capture one|tiff file|silverfast|vuescan)\b/i';

	/**
	 * EXIF make and model (written by the camera), else XMP make and model
	 * (where photo software copies them), else a model alone.
	 */
	public static function camera( XmpReader $md ): string {

		$exif_make  = self::text( $md->getExif( 'Make' ) );
		$exif_model = self::text( $md->getExif( 'Model' ) );
		$xmp_make   = self::text( $md->getXmp( 'tiff:Make' ) );
		$xmp_model  = self::text( $md->getXmp( 'tiff:Model' ) );

		if ( '' !== $exif_make && '' !== $exif_model ) {
			return self::cleanCamera( $exif_make, $exif_model );
		}

		if ( '' !== $xmp_make && '' !== $xmp_model ) {
			return self::cleanCamera( $xmp_make, $xmp_model );
		}

		return self::cleanCamera( '', '' !== $exif_model ? $exif_model : $xmp_model );
	}

	/**
	 * Canon Canon PowerShot G9 -> Canon PowerShot G9; NIKON CORPORATION
	 * NIKON D800 -> Nikon D800; Leaf Aptus-II 5(LI300047 )/Mamiya 645 AFD ->
	 * Leaf Aptus-II 5; Adobe Systems Inc. Tiff File -> nothing.
	 */
	public static function cleanCamera( string $make, string $model ): string {

		$make  = self::collapse( $make );
		$model = self::collapse( $model );

		if ( preg_match( self::SOFTWARE, $make . ' ' . $model ) ) {
			return '';
		}

		// A serial number in brackets, and anything after it.
		$model = trim( preg_replace( '/\s*\(.*$/s', '', $model ) );

		if ( '' === $make ) {

			// A model that starts with its brand: NIKON D800E.
			$words = explode( ' ', $model, 2 );
			$brand = self::BRANDS[ strtolower( $words[0] ) ] ?? null;

			return $brand ? self::collapse( $brand . ' ' . ( $words[1] ?? '' ) ) : $model;
		}

		$brand = self::brand( $make );

		// The brand once: models often repeat the make, in any of its forms.
		foreach ( array_unique( [ strtolower( $make ), strtolower( $brand ), strtolower( strtok( $make, ' ' ) ) ] ) as $prefix ) {
			if ( '' !== $prefix && 0 === stripos( $model . ' ', $prefix . ' ' ) ) {
				$model = trim( substr( $model, strlen( $prefix ) ) );
				break;
			}
		}

		return self::collapse( $brand . ' ' . $model );
	}

	/** NIKON CORPORATION -> Nikon; SONY -> Sony; ACME IMAGING CO., LTD. -> Acme. */
	public static function brand( string $make ): string {

		$make = self::collapse( $make );

		if ( isset( self::BRANDS[ strtolower( $make ) ] ) ) {
			return self::BRANDS[ strtolower( $make ) ];
		}

		do {
			$before = $make;
			$make   = trim( preg_replace( '/[\s,]+(corporation|corp\.?|company|co\.?,?\s*ltd\.?|co\.?|inc\.?|ltd\.?|ag|gmbh|a\/s|imaging)$/i', '', $make ) );
		} while ( $make !== $before && '' !== $make );

		if ( isset( self::BRANDS[ strtolower( $make ) ] ) ) {
			return self::BRANDS[ strtolower( $make ) ];
		}

		return ( strlen( $make ) > 3 && strtoupper( $make ) === $make ) ? ucwords( strtolower( $make ) ) : $make;
	}

	/**
	 * The lens model (XMP, then EXIF), else Adobe's lens field, which for a
	 * camera with a fixed lens holds its focal length and aperture. Never a
	 * placeholder such as "----" or "-- mm f/--"; an unknown aperture is left
	 * out ("125 mm f/--" is 125 mm).
	 */
	public static function lens( XmpReader $md ): string {

		$candidates = [
			$md->getXmp( 'exifEX:LensModel' ),
			$md->getExif( 'UndefinedTag:0xA434' ),
			$md->getXmp( 'aux:Lens' ),
		];

		foreach ( $candidates as $value ) {

			$value = self::collapse( preg_replace( '#\s*f/--#i', '', self::text( $value ) ) );

			if ( '' !== $value && preg_match( '/[a-z0-9]/i', $value ) && false === strpos( $value, '--' ) ) {
				return $value;
			}
		}

		return '';
	}

	private static function text( $value ): string {

		if ( is_array( $value ) ) {
			$value = reset( $value );
		}

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	private static function collapse( string $text ): string {

		return trim( preg_replace( '/\s+/', ' ', $text ) );
	}
}
