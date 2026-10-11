<?php

namespace PhotoPress\Tests;

use Brain\Monkey\Filters;
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
			// Whether an image this large gets a size: larger than it on a side.
			'image_resize_dimensions'          => static fn( $w, $h, $sw, $sh ) => ( $sw && $w > $sw ) || ( $sh && $h > $sh ) ? [ 0, 0, 0, 0, $sw, $sh, $w, $h ] : false,
			'get_option'                       => static fn( $name, $default = false ) => $default,
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

	/** An image's metadata: 2000 × 1500, with every size it is large enough for. */
	private function meta( array $more = [] ): array {

		return $more + [ 'file' => '2024/05/photo.jpg', 'width' => 2000, 'height' => 1500, 'sizes' => [ 'thumbnail' => [ 'file' => 'photo-150x150.jpg' ], 'medium' => [ 'file' => 'photo-300x225.jpg' ], '800x800' => [ 'file' => 'photo-800x600.jpg' ] ] ];
	}

	public function test_the_settings_given_are_those_saved_once_saved(): void {

		$this->settings( 92 );
		$saved = images::settings();

		$this->assertSame( $saved, images::settings( [ 'quality' => 92, 'disabled_sizes' => '' ] ) );
		$this->assertSame( [ 92, 2560 ], [ $saved['quality'], $saved['threshold'] ] );

		$given = images::settings( [ 'quality' => '90', 'disabled_sizes' => [ 'thumbnail', '800x800' ] ] );
		$this->settings( 90, '800x800, thumbnail' );
		$this->assertSame( images::settings(), $given );
		$this->assertSame( [ 'medium' ], array_keys( $given['sizes'] ) );
	}

	public function test_large_uploads_are_scaled_to_the_longest_side_set_or_not_at_all(): void {

		// Never saved: WordPress's own.
		$this->assertSame( 2560, images::threshold( 2560 ) );

		\pp_api::$options['core/images/big_image_threshold'] = 3200;
		$this->assertSame( 3200, images::threshold( 2560 ) );

		\pp_api::$options['core/images/scale_large_uploads'] = false;
		$this->assertFalse( images::threshold( 2560 ) );
	}

	public function test_the_threshold_is_never_less_than_the_largest_size_made(): void {

		$this->settings();
		\pp_api::$options['core/images/big_image_threshold'] = 500;
		$this->assertSame( 800, images::threshold( 2560 ) );

		// 800x800 turned off: medium, 300, is the largest.
		$this->settings( 92, '800x800' );
		$this->assertSame( 500, images::threshold( 2560 ) );
		$this->assertSame( 800, images::settings( [ 'disabled_sizes' => '', 'scale_large_uploads' => true, 'big_image_threshold' => 500 ] )['threshold'] );
	}

	public function test_the_threshold_given_is_the_one_saved_once_saved(): void {

		$this->settings();

		$this->assertSame( 4000, images::settings( [ 'scale_large_uploads' => true, 'big_image_threshold' => '4000' ] )['threshold'] );
		$this->assertSame( 0, images::settings( [ 'scale_large_uploads' => false, 'big_image_threshold' => 4000 ] )['threshold'] );
		$this->assertSame( 2560, images::settings( [ 'big_image_threshold' => 0 ] )['threshold'] );
	}

	public function test_an_image_with_every_size_at_the_quality_needs_nothing(): void {

		$this->settings();

		$this->assertNull( images::work( $this->meta(), 'image/jpeg', 92, images::settings() ) );
	}

	public function test_without_metadata_every_size_is_made_again(): void {

		$this->settings();

		$this->assertSame( 'full', images::work( false, 'image/jpeg', 92, images::settings() ) );
	}

	public function test_another_quality_makes_every_size_again_for_jpeg_and_webp_only(): void {

		$this->settings( 85 );

		$this->assertSame( 'full', images::work( $this->meta(), 'image/jpeg', 92, images::settings() ) );
		$this->assertSame( 'full', images::work( $this->meta(), 'image/webp', 92, images::settings() ) );
		$this->assertNull( images::work( $this->meta(), 'image/png', 92, images::settings() ) );
	}

	public function test_a_size_turned_off_is_nothing_to_do(): void {

		$this->settings( 92, '800x800' );

		$this->assertNull( images::work( $this->meta(), 'image/jpeg', 92, images::settings() ) );
	}

	public function test_a_size_turned_on_is_missing_where_the_image_is_large_enough(): void {

		$this->settings();
		$without = $this->meta();
		unset( $without['sizes']['800x800'] );
		$small = [ 'width' => 600, 'height' => 400 ] + $without;

		$this->assertSame( 'missing', images::work( $without, 'image/jpeg', 92, images::settings() ) );
		// Never made for an image smaller than it.
		$this->assertNull( images::work( $small, 'image/jpeg', 92, images::settings() ) );
	}

	public function test_a_new_threshold_counts_only_where_it_scales_the_image_otherwise(): void {

		$this->settings();
		$scaled = $this->meta( [ 'file' => '2024/05/photo-scaled.jpg', 'width' => 2560, 'height' => 1707, 'original_image' => 'photo.jpg' ] );
		// Turned upright, not scaled.
		$rotated = $this->meta( [ 'file' => '2024/05/photo-rotated.jpg', 'original_image' => 'photo.jpg' ] );
		$settings = images::settings();

		$this->assertNull( images::work( $scaled, 'image/jpeg', 92, $settings ) );
		$this->assertNull( images::work( $rotated, 'image/jpeg', 92, $settings ) );

		$settings['threshold'] = 3000;
		$this->assertSame( 'full', images::work( $scaled, 'image/jpeg', 92, $settings ) );
		$this->assertNull( images::work( $this->meta(), 'image/jpeg', 92, $settings ) );

		$settings['threshold'] = 1600;
		$this->assertSame( 'full', images::work( $this->meta(), 'image/jpeg', 92, $settings ) );
		$this->assertSame( 'full', images::work( $rotated, 'image/jpeg', 92, $settings ) );

		// Turned off: the scaled one is served from its original.
		$settings['threshold'] = 0;
		$this->assertSame( 'full', images::work( $scaled, 'image/jpeg', 92, $settings ) );
		$this->assertNull( images::work( $this->meta(), 'image/jpeg', 92, $settings ) );
	}

	public function test_an_image_uploaded_is_recorded_at_the_quality(): void {

		$this->settings( 88 );
		$saved = [];
		Functions\when( 'update_post_meta' )->alias( static function ( $id, $key, $value ) use ( &$saved ) { $saved[ $id ][ $key ] = $value; } );

		$this->assertSame( $this->meta(), images::markUpToDate( $this->meta(), 7, 'create' ) );
		// Not an edit in the image editor, nor a file without sizes.
		images::markUpToDate( $this->meta(), 8, 'update' );
		images::markUpToDate( [ 'file' => 'doc.pdf' ], 9, 'create' );

		$this->assertSame( [ 7 => [ images::QUALITY_META => 88 ] ], $saved );
	}

	public function test_images_without_a_quality_are_taken_to_have_the_first_setting(): void {

		$this->settings( 90 );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\expect( 'add_option' )->once()->with( images::ASSUMED_QUALITY_OPTION, 90, '', false );

		$this->assertSame( 90, images::qualityOf( 7 ) );

		Functions\when( 'get_option' )->justReturn( 90 );
		$this->settings( 80 );
		$this->assertSame( 90, images::qualityOf( 7 ) );
	}

	public function test_offload_media_removing_files_from_the_server_is_noticed(): void {

		$this->assertFalse( images::offloadRemovesLocalFiles() );

		$GLOBALS['as3cf'] = new class() {
			public bool $remove = true;
			public function get_setting( $key ) {
				return 'remove-local-file' === $key && $this->remove;
			}
		};
		$this->assertTrue( images::offloadRemovesLocalFiles() );

		$GLOBALS['as3cf']->remove = false;
		$this->assertFalse( images::offloadRemovesLocalFiles() );
		unset( $GLOBALS['as3cf'] );
	}

	/** The image: its metadata and its quality. */
	private function stored( array $meta, $quality = 92 ): void {

		Functions\when( 'wp_get_attachment_metadata' )->justReturn( $meta );
		Functions\when( 'get_post_meta' )->justReturn( (string) $quality );
		Functions\when( 'get_post_mime_type' )->justReturn( 'image/jpeg' );
	}

	public function test_an_image_up_to_date_is_skipped(): void {

		$this->settings();
		$this->stored( $this->meta() );
		Functions\expect( 'wp_generate_attachment_metadata' )->never();
		Functions\expect( 'wp_update_image_subsizes' )->never();

		$this->assertTrue( images::regenerate( 7 ) );
	}

	public function test_an_image_without_its_original_on_the_server_is_an_error(): void {

		$this->settings( 85 );
		$this->stored( $this->meta() );
		Functions\when( 'wp_get_original_image_path' )->justReturn( '/nowhere/photo.jpg' );
		Functions\expect( 'wp_generate_attachment_metadata' )->never();

		$this->assertSame( 'photopress_regenerate_missing', images::regenerate( 7 )->get_error_code() );
	}

	public function test_a_size_turned_on_is_made_alone_by_wordpress(): void {

		$this->settings( 92, 'thumbnail' );
		$meta = $this->meta();
		unset( $meta['sizes']['800x800'], $meta['sizes']['thumbnail'] );
		$this->stored( $meta );
		$original = $this->tempFile( 'jpeg' );
		$made = $this->meta();
		$only = null;
		// What WordPress would make: every size the image lacks, turned off
		// or not; narrowed to those turned on.
		Filters\expectAdded( 'wp_get_missing_image_subsizes' )->once()->whenHappen( static function ( $narrow ) use ( &$only ) {
			$only = $narrow( array_intersect_key( self::SIZES, array_flip( [ 'thumbnail', '800x800' ] ) ) );
		} );

		Functions\when( 'wp_get_original_image_path' )->justReturn( $original );
		Functions\expect( 'wp_generate_attachment_metadata' )->never();
		Functions\expect( 'wp_update_image_subsizes' )->once()->with( 7 )->andReturn( $made );
		Functions\expect( 'wp_update_attachment_metadata' )->once()->with( 7, $made );
		Functions\expect( 'update_post_meta' )->once()->with( 7, images::QUALITY_META, 92 );
		Functions\when( 'delete_post_meta' )->justReturn( true );

		$this->assertTrue( images::regenerate( 7 ) );
		$this->assertSame( [ '800x800' ], array_keys( $only ) );
	}

	public function test_every_size_is_made_again_from_the_original_keeping_those_turned_off(): void {

		$this->settings( 85, '800x800' );
		$original = $this->tempFile( 'jpeg' );
		$dir = dirname( $original );
		// The 800x800 file is there; the old thumbnail's is not.
		$kept = $this->tempFile( 'jpeg' );
		$this->stored( $this->meta( [ 'sizes' => [ 'thumbnail' => [ 'file' => 'gone.jpg' ], '800x800' => [ 'file' => basename( $kept ) ] ] ] ), 92 );

		Functions\when( 'wp_get_original_image_path' )->justReturn( $original );
		// Pointed at its -scaled copy, as an image over the threshold is.
		Functions\when( 'get_attached_file' )->justReturn( $dir . '/photo-scaled.jpg' );
		Functions\expect( 'update_attached_file' )->once()->with( 7, $original );
		Functions\expect( 'wp_generate_attachment_metadata' )->once()->with( 7, $original )->andReturn( [ 'file' => 'photo.jpg', 'width' => 900, 'height' => 600, 'sizes' => [ 'medium' => [ 'file' => 'm.jpg' ] ] ] );
		Functions\expect( 'wp_update_attachment_metadata' )->once()->with( 7, [ 'file' => 'photo.jpg', 'width' => 900, 'height' => 600, 'sizes' => [ 'medium' => [ 'file' => 'm.jpg' ], '800x800' => [ 'file' => basename( $kept ) ] ] ] );
		Functions\expect( 'update_post_meta' )->once()->with( 7, images::QUALITY_META, 85 );
		Functions\expect( 'delete_post_meta' )->once()->with( 7, images::SIGNATURE_META );

		$this->assertTrue( images::regenerate( 7 ) );
	}

	public function test_all_makes_up_to_date_images_again_too(): void {

		$this->settings();
		$original = $this->tempFile( 'jpeg' );

		$this->stored( $this->meta() );
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
