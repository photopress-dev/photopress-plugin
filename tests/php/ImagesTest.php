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

	/** A record of an image made with the settings as they are. */
	private function record( array $meta = [ 'width' => 2000, 'height' => 1500, 'sizes' => [ 'thumbnail' => [], 'medium' => [], '800x800' => [] ] ] ): array {

		return images::recordFor( 7, $meta, images::settings() );
	}

	public function test_the_settings_given_are_those_saved_once_saved(): void {

		$this->settings( 92 );
		$saved = images::settings();

		$this->assertSame( $saved, images::settings( [ 'quality' => 92, 'disabled_sizes' => '' ] ) );
		$this->assertSame( [ 92, 2560 ], [ $saved['quality'], $saved['threshold'] ] );
		$this->assertSame( [ 150, 150, true ], $saved['sizes']['thumbnail'] );

		$given = images::settings( [ 'quality' => '90', 'disabled_sizes' => [ 'thumbnail', '800x800' ] ] );
		$this->settings( 90, '800x800, thumbnail' );
		$this->assertSame( images::settings(), $given );
		$this->assertSame( [ 'medium' ], array_keys( $given['sizes'] ) );
	}

	public function test_a_record_says_which_sizes_were_made(): void {

		$this->settings();
		// Too small for 800x800.
		$record = $this->record( [ 'width' => 600, 'height' => 400, 'sizes' => [ 'thumbnail' => [], 'medium' => [] ] ] );

		$this->assertSame( 600, $record['long'] );
		$this->assertSame( [ 150, 150, true, true ], $record['sizes']['thumbnail'] );
		$this->assertSame( [ 800, 800, false, false ], $record['sizes']['800x800'] );
	}

	public function test_an_image_made_with_the_settings_needs_nothing(): void {

		$this->settings();

		$this->assertNull( images::work( $this->record(), 'image/jpeg', images::settings() ) );
	}

	public function test_without_a_record_every_size_is_made_again(): void {

		$this->assertSame( 'full', images::work( null, 'image/jpeg', images::settings() ) );
	}

	public function test_a_new_quality_makes_every_size_again_for_jpeg_and_webp_only(): void {

		$this->settings( 92 );
		$record = $this->record();
		$this->settings( 85 );

		$this->assertSame( 'full', images::work( $record, 'image/jpeg', images::settings() ) );
		$this->assertSame( 'full', images::work( $record, 'image/webp', images::settings() ) );
		$this->assertNull( images::work( $record, 'image/png', images::settings() ) );
	}

	public function test_a_size_turned_off_is_dropped_where_it_was_made(): void {

		$this->settings();
		$made = $this->record();
		$small = $this->record( [ 'width' => 600, 'height' => 400, 'sizes' => [ 'thumbnail' => [], 'medium' => [] ] ] );
		$this->settings( 92, '800x800' );

		$this->assertSame( [ 'make' => [], 'drop' => [ '800x800' ] ], images::work( $made, 'image/jpeg', images::settings() ) );
		// Never made for an image smaller than it: nothing to do.
		$this->assertNull( images::work( $small, 'image/jpeg', images::settings() ) );
	}

	public function test_a_size_turned_on_or_resized_is_made_alone(): void {

		$this->settings( 92, '800x800' );
		$record = $this->record();
		$this->settings();
		$this->assertSame( [ 'make' => [ '800x800' ], 'drop' => [] ], images::work( $record, 'image/jpeg', images::settings() ) );

		$record = $this->record();
		Functions\when( 'wp_get_registered_image_subsizes' )->justReturn( [ 'medium' => [ 'width' => 400, 'height' => 400, 'crop' => false ] ] + self::SIZES );
		$this->assertSame( [ 'make' => [ 'medium' ], 'drop' => [] ], images::work( $record, 'image/jpeg', images::settings() ) );
	}

	public function test_a_new_threshold_counts_only_where_it_scales_the_image(): void {

		$this->settings();
		$small = $this->record( [ 'width' => 2000, 'height' => 1500, 'sizes' => [] ] );
		Functions\when( 'wp_getimagesize' )->justReturn( [ 6000, 4000 ] );
		Functions\when( 'get_attached_file' )->justReturn( '/uploads/photo-scaled.jpg' );
		$large = $this->record( [ 'width' => 2560, 'height' => 1707, 'original_image' => 'photo.jpg', 'sizes' => [] ] );
		$this->assertSame( 6000, $large['long'] );

		$settings = images::settings();
		$settings['threshold'] = 3000;
		$this->assertNull( images::work( $small, 'image/jpeg', $settings ) );
		$this->assertSame( 'full', images::work( $large, 'image/jpeg', $settings ) );

		// Turned off: the large one is no longer scaled.
		$settings['threshold'] = 0;
		$this->assertNull( images::work( $small, 'image/jpeg', $settings ) );
		$this->assertSame( 'full', images::work( $large, 'image/jpeg', $settings ) );
	}

	public function test_an_image_uploaded_is_recorded(): void {

		$this->settings();
		$saved = [];
		Functions\when( 'update_post_meta' )->alias( static function ( $id, $key, $value ) use ( &$saved ) { $saved[ $id ][ $key ] = $value; } );

		$meta = [ 'file' => 'photo.jpg', 'width' => 2000, 'height' => 1500, 'sizes' => [ 'medium' => [] ] ];
		$this->assertSame( $meta, images::markUpToDate( $meta, 7, 'create' ) );
		// Not an edit in the image editor, nor a file without sizes.
		images::markUpToDate( $meta, 8, 'update' );
		images::markUpToDate( [ 'file' => 'doc.pdf' ], 9, 'create' );

		$this->assertSame( [ 7 ], array_keys( $saved ) );
		$this->assertSame( images::recordFor( 7, $meta, images::settings() ), $saved[7][ images::MADE_META ] );
	}

	/** get_post_meta: the image's record, or the hash of before records. */
	private function stored( $record, string $signature = '' ): void {

		Functions\when( 'get_post_meta' )->alias( static fn( $id, $key ) => images::MADE_META === $key ? $record : $signature );
		Functions\when( 'get_post_mime_type' )->justReturn( 'image/jpeg' );
	}

	public function test_an_image_up_to_date_is_skipped(): void {

		$this->settings();
		$this->stored( $this->record() );
		Functions\expect( 'wp_generate_attachment_metadata' )->never();

		$this->assertTrue( images::regenerate( 7 ) );
	}

	public function test_a_hash_of_the_settings_as_they_are_becomes_a_record(): void {

		$this->settings();
		$this->stored( '', images::legacySignature() );
		$meta = [ 'width' => 2000, 'height' => 1500, 'sizes' => [ 'thumbnail' => [], 'medium' => [], '800x800' => [] ] ];
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( $meta );
		Functions\expect( 'update_post_meta' )->once()->with( 7, images::MADE_META, images::recordFor( 7, $meta, images::settings() ) );
		Functions\expect( 'delete_post_meta' )->once()->with( 7, images::SIGNATURE_META );
		Functions\expect( 'wp_generate_attachment_metadata' )->never();

		$this->assertTrue( images::regenerate( 7 ) );
	}

	public function test_an_image_without_its_original_on_the_server_is_an_error(): void {

		$this->settings();
		$this->stored( '' );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( [ 'sizes' => [] ] );
		Functions\when( 'wp_get_original_image_path' )->justReturn( '/nowhere/photo.jpg' );
		Functions\expect( 'wp_generate_attachment_metadata' )->never();

		$this->assertSame( 'photopress_regenerate_missing', images::regenerate( 7 )->get_error_code() );
	}

	public function test_a_size_turned_off_is_dropped_without_making_anything(): void {

		$this->settings();
		$record = $this->record();
		$this->settings( 92, '800x800' );
		$this->stored( $record );

		Functions\when( 'wp_get_attachment_metadata' )->justReturn( [ 'width' => 2000, 'height' => 1500, 'sizes' => [ 'thumbnail' => [ 'file' => 't.jpg' ], 'medium' => [ 'file' => 'm.jpg' ], '800x800' => [ 'file' => 'l.jpg' ] ] ] );
		Functions\when( 'wp_get_original_image_path' )->justReturn( '/nowhere/photo.jpg' );
		Functions\expect( 'wp_generate_attachment_metadata' )->never();
		Functions\expect( 'wp_update_attachment_metadata' )->once()->with( 7, [ 'width' => 2000, 'height' => 1500, 'sizes' => [ 'thumbnail' => [ 'file' => 't.jpg' ], 'medium' => [ 'file' => 'm.jpg' ] ] ] );
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'delete_post_meta' )->justReturn( true );

		$this->assertTrue( images::regenerate( 7 ) );
	}

	public function test_sizes_are_made_again_from_the_original_and_the_image_recorded(): void {

		$this->settings();
		$original = $this->tempFile( 'jpeg' );
		$saved = [];

		$this->stored( '', 'an older signature' );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( [ 'sizes' => [] ] );
		Functions\when( 'wp_get_original_image_path' )->justReturn( $original );
		// Pointed at its -scaled copy, as an image over the threshold is.
		Functions\when( 'get_attached_file' )->justReturn( str_replace( '.jpg', '-scaled.jpg', $original ) );
		Functions\expect( 'update_attached_file' )->once()->with( 7, $original );
		Functions\expect( 'wp_generate_attachment_metadata' )->once()->with( 7, $original )->andReturn( [ 'file' => 'photo-scaled.jpg', 'width' => 900, 'height' => 600, 'sizes' => [ 'medium' => [] ] ] );
		Functions\expect( 'wp_update_attachment_metadata' )->once()->with( 7, [ 'file' => 'photo-scaled.jpg', 'width' => 900, 'height' => 600, 'sizes' => [ 'medium' => [] ] ] );
		Functions\when( 'update_post_meta' )->alias( static function ( $id, $key, $value ) use ( &$saved ) { $saved[ $key ] = $value; } );
		Functions\expect( 'delete_post_meta' )->once()->with( 7, images::SIGNATURE_META );

		$this->assertTrue( images::regenerate( 7 ) );
		$this->assertSame( [ 92, 2560, 900 ], [ $saved[ images::MADE_META ]['quality'], $saved[ images::MADE_META ]['threshold'], $saved[ images::MADE_META ]['long'] ] );
		$this->assertSame( [ 300, 300, false, true ], $saved[ images::MADE_META ]['sizes']['medium'] );
	}

	public function test_all_makes_up_to_date_images_again_too(): void {

		$this->settings();
		$original = $this->tempFile( 'jpeg' );

		$this->stored( $this->record() );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( [ 'sizes' => [] ] );
		Functions\when( 'wp_get_original_image_path' )->justReturn( $original );
		Functions\when( 'get_attached_file' )->justReturn( $original );
		Functions\expect( 'update_attached_file' )->never();
		Functions\expect( 'wp_generate_attachment_metadata' )->once()->andReturn( [ 'sizes' => [] ] );
		Functions\when( 'wp_update_attachment_metadata' )->justReturn( true );
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'delete_post_meta' )->justReturn( true );

		$this->assertTrue( images::regenerate( 7, [ 'all' => true ] ) );
	}
}
