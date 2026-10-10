<?php
/**
 * Makes the test images in fixtures/images with ImageMagick: two colors,
 * 40x30, in each format XmpFile writes that GD cannot make, without XMP and
 * with an XMP packet written by ImageMagick (which writes none to GIF).
 *
 *   php tests/php/fixtures/make-images.php
 */

$dir = __DIR__ . '/images';
$xmp = file_get_contents( __DIR__ . '/xmp/capture-one.xml' );

function pp_test_image( $format ) {

	$im = new Imagick();
	$im->newImage( 40, 30, new ImagickPixel( 'rgb(30,120,200)' ) );
	$draw = new ImagickDraw();
	$draw->setFillColor( new ImagickPixel( 'rgb(200,40,40)' ) );
	$draw->rectangle( 0, 0, 19, 29 );
	$im->drawImage( $draw );
	$im->setImageFormat( $format );
	$im->stripImage();

	return $im;
}

foreach ( [ 'gif', 'tiff', 'avif', 'heic' ] as $format ) {

	$im = pp_test_image( $format );

	if ( 'tiff' === $format ) {
		$im->setImageCompression( Imagick::COMPRESSION_LZW );
	}

	$im->writeImage( "$dir/plain.$format" );

	if ( 'gif' !== $format ) {
		$im->setImageProfile( 'xmp', $xmp );
		$im->writeImage( "$dir/imagick-xmp.$format" );
	}
}

// Big-endian TIFF.
$im = pp_test_image( 'tiff' );
$im->setOption( 'tiff:endian', 'msb' );
$im->setImageProfile( 'xmp', $xmp );
$im->writeImage( "$dir/imagick-xmp-big-endian.tiff" );

// An animated GIF that loops: a looping extension, then frames each with a
// graphic control extension.
$frames = new Imagick();

foreach ( [ 'rgb(30,120,200)', 'rgb(200,40,40)' ] as $color ) {
	$frame = new Imagick();
	$frame->newImage( 40, 30, new ImagickPixel( $color ) );
	$frame->setImageFormat( 'gif' );
	$frame->setImageDelay( 50 );
	$frames->addImage( $frame );
}

$frames->setImageIterations( 0 );
$frames->writeImages( "$dir/animated.gif", true );
