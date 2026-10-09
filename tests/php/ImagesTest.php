<?php

namespace PhotoPress\Tests;

use Brain\Monkey\Functions;
use PhotoPress\modules\images\images;

final class ImagesTest extends TestCase {

	private const SIZES = [
		'thumbnail' => [ 'width' => 150, 'height' => 150, 'crop' => true ],
		'medium'    => [ 'width' => 300, 'height' => 300, 'crop' => false ],
		'800x800'   => [ 'width' => 800, 'height' => 800, 'crop' => false ],
	];

	protected function setUp(): void {

		parent::setUp();

		$this->uploads = sys_get_temp_dir() . '/pp-test-uploads-' . getmypid();

		Functions\stubs( [
			'wp_get_registered_image_subsizes' => self::SIZES,
			'__'                               => static fn( $text ) => $text,
			'wp_upload_dir'                    => [ 'basedir' => $this->uploads, 'error' => false ],
			'trailingslashit'                  => static fn( $path ) => rtrim( $path, '/' ) . '/',
			'wp_mkdir_p'                       => static fn( $dir ) => is_dir( $dir ) || mkdir( $dir, 0775, true ),
			'wp_is_writable'                   => static fn( $dir ) => is_writable( $dir ),
		] );
	}

	protected function tearDown(): void {

		foreach ( glob( $this->uploads . '/photopress-tmp/{,.}[!.]*', GLOB_BRACE ) ?: [] as $file ) {
			unlink( $file );
		}
		@rmdir( $this->uploads . '/photopress-tmp' );
		@rmdir( $this->uploads );

		parent::tearDown();
	}

	private string $uploads;

	/**
	 * What ImageMagick cannot keep in its memory goes to a folder on disk in
	 * uploads, closed to the web, not the system's temporary folder (often
	 * in memory).
	 */
	public function test_imagemagick_works_in_a_closed_folder_in_uploads(): void {

		$dir = ( new \ReflectionMethod( images::class, 'temporaryDir' ) )->invoke( null );

		$this->assertSame( $this->uploads . '/photopress-tmp', $dir );
		$this->assertDirectoryExists( $dir );
		$this->assertStringContainsString( 'Require all denied', file_get_contents( $dir . '/.htaccess' ) );
		$this->assertFileExists( $dir . '/index.html' );
	}

	private function settings( int $quality = 92, string $disabled = '' ): void {

		\pp_api::$options['core/images/quality'] = $quality;
		\pp_api::$options['core/images/disabled_sizes'] = $disabled;
	}

	public function test_quality_is_the_setting_for_jpeg_and_webp_only(): void {

		$this->settings( 92 );

		$this->assertSame( 92, images::quality( 82, 'image/jpeg' ) );
		$this->assertSame( 92, images::quality( 82, 'image/webp' ) );
		$this->assertSame( 82, images::quality( 82, 'image/png' ) );
		$this->assertSame( 50, images::quality( 50, 'image/avif' ) );
	}

	public function test_a_quality_out_of_range_leaves_wordpress_its_own(): void {

		$this->settings( 0 );
		$this->assertSame( 82, images::quality( 82, 'image/jpeg' ) );

		$this->settings( 150 );
		$this->assertSame( 82, images::quality( 82, 'image/jpeg' ) );
	}

	public function test_sizes_turned_off_are_not_made(): void {

		$this->settings( 92, '800x800, thumbnail' );

		$this->assertSame( [ '800x800', 'thumbnail' ], images::disabledSizes() );
		$this->assertSame( [ 'medium' ], array_keys( images::enabledSizes( self::SIZES ) ) );
	}

	public function test_the_signature_changes_with_the_sizes_and_the_quality(): void {

		$this->settings( 92 );
		$first = images::signature();

		$this->assertSame( $first, images::signature() );

		$this->settings( 90 );
		$this->assertNotSame( $first, images::signature() );

		$this->settings( 92, '800x800' );
		$this->assertNotSame( $first, images::signature() );
	}

	public function test_an_image_made_with_these_settings_is_skipped(): void {

		$this->settings();
		Functions\when( 'get_post_meta' )->justReturn( images::signature() );
		Functions\expect( 'wp_generate_attachment_metadata' )->never();

		$this->assertTrue( images::regenerate( 7 ) );
	}

	public function test_an_image_without_its_original_on_the_server_is_an_error(): void {

		$this->settings();
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'wp_get_original_image_path' )->justReturn( '/nowhere/photo.jpg' );
		Functions\expect( 'wp_generate_attachment_metadata' )->never();

		$this->assertSame( 'photopress_regenerate_missing', images::regenerate( 7 )->get_error_code() );
	}

	public function test_sizes_are_made_again_from_the_original_and_the_image_marked_up_to_date(): void {

		$this->settings();
		$original = $this->tempFile( 'jpeg' );
		$saved = [];

		Functions\when( 'get_post_meta' )->justReturn( 'an older signature' );
		Functions\when( 'wp_get_original_image_path' )->justReturn( $original );
		// Pointed at its -scaled copy, as an image over the threshold is.
		Functions\when( 'get_attached_file' )->justReturn( str_replace( '.jpg', '-scaled.jpg', $original ) );
		Functions\expect( 'update_attached_file' )->once()->with( 7, $original );
		Functions\expect( 'wp_generate_attachment_metadata' )->once()->with( 7, $original )->andReturn( [ 'file' => 'photo-scaled.jpg', 'sizes' => [] ] );
		Functions\expect( 'wp_update_attachment_metadata' )->once()->with( 7, [ 'file' => 'photo-scaled.jpg', 'sizes' => [] ] );
		Functions\when( 'update_post_meta' )->alias( static function ( $id, $key, $value ) use ( &$saved ) { $saved[ $key ] = $value; } );

		$this->assertTrue( images::regenerate( 7 ) );
		$this->assertSame( images::signature(), $saved[ images::SIGNATURE_META ] );
	}

	public function test_all_makes_up_to_date_images_again_too(): void {

		$this->settings();
		$original = $this->tempFile( 'jpeg' );

		Functions\when( 'get_post_meta' )->justReturn( images::signature() );
		Functions\when( 'wp_get_original_image_path' )->justReturn( $original );
		Functions\when( 'get_attached_file' )->justReturn( $original );
		Functions\expect( 'update_attached_file' )->never();
		Functions\expect( 'wp_generate_attachment_metadata' )->once()->andReturn( [ 'sizes' => [] ] );
		Functions\when( 'wp_update_attachment_metadata' )->justReturn( true );
		Functions\when( 'update_post_meta' )->justReturn( true );

		$this->assertTrue( images::regenerate( 7, [ 'all' => true ] ) );
	}
}
