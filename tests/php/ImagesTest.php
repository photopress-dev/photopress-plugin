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
		$this->assertSame( 92, $saved['quality'] );

		$given = images::settings( [ 'quality' => '90', 'disabled_sizes' => [ 'thumbnail', '800x800' ] ] );
		$this->settings( 90, '800x800, thumbnail' );
		$this->assertSame( images::settings(), $given );
		$this->assertSame( [ 'medium' ], array_keys( $given['sizes'] ) );
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

	public function test_a_scaled_image_is_checked_against_its_original_whatever_the_threshold(): void {

		$this->settings();
		$scaled = $this->meta( [ 'file' => '2024/05/photo-scaled.jpg', 'width' => 2560, 'height' => 1707, 'original_image' => 'photo.jpg' ] );
		$settings = images::settings();

		// Scaled at another threshold than WordPress's now: its own.
		$this->assertNull( images::work( $scaled, 'image/jpeg', 92, $settings ) );

		// A size larger than the -scaled copy, made from the original.
		$settings['sizes']['3000x3000'] = [ 'width' => 3000, 'height' => 3000, 'crop' => false ];
		$original = $this->uploads . '/2024/05/photo.jpg';
		mkdir( dirname( $original ), 0775, true );
		touch( $original );
		Functions\when( 'wp_get_upload_dir' )->justReturn( [ 'basedir' => $this->uploads ] );
		Functions\when( 'wp_basename' )->alias( 'basename' );

		try {
			Functions\when( 'wp_getimagesize' )->justReturn( [ 5000, 3333 ] );
			$this->assertSame( 'missing', images::work( $scaled, 'image/jpeg', 92, $settings ) );

			Functions\when( 'wp_getimagesize' )->justReturn( [ 2800, 1867 ] );
			$this->assertNull( images::work( $scaled, 'image/jpeg', 92, $settings ) );
		} finally {
			unlink( $original );
			rmdir( dirname( $original ) );
			rmdir( dirname( $original, 2 ) );
		}
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

		$as3cf = new class() {
			public bool $remove = true;
			public function get_setting( $key ) {
				return 'remove-local-file' === $key && $this->remove;
			}
		};
		\PhotoPress\modules\media\OffloadMedia::init( $as3cf );
		$this->assertTrue( images::offloadRemovesLocalFiles() );

		$as3cf->remove = false;
		$this->assertFalse( images::offloadRemovesLocalFiles() );
		\PhotoPress\modules\media\OffloadMedia::init( null );
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

	public function test_imagemagick_is_limited_whenever_wordpress_opens_an_image(): void {

		if ( ! class_exists( 'Imagick' ) ) {
			$this->markTestSkipped( 'Imagick is not installed.' );
		}

		$module = ( new \ReflectionClass( images::class ) )->newInstanceWithoutConstructor();
		$module->definePublicHooks();
		$this->assertNotFalse( has_filter( 'wp_image_editors', [ images::class, 'limitEditors' ] ) );

		$memory = \Imagick::getResourceLimit( \Imagick::RESOURCETYPE_MEMORY );
		$map = \Imagick::getResourceLimit( \Imagick::RESOURCETYPE_MAP );

		try {
			$editors = [ 'WP_Image_Editor_Imagick', 'WP_Image_Editor_GD' ];
			$this->assertSame( $editors, images::limitEditors( $editors ), 'the editors as they were' );
			$this->assertEquals( 256 * 1048576, \Imagick::getResourceLimit( \Imagick::RESOURCETYPE_MEMORY ) );
			$this->assertEquals( 512 * 1048576, \Imagick::getResourceLimit( \Imagick::RESOURCETYPE_MAP ) );
			$this->assertSame( $this->uploads . '/photopress-tmp', \Imagick::getRegistry( 'temporary-path' ) );

			// Filtered: a limit not above 0 is left as it is.
			Filters\expectApplied( 'photopress_imagick_limits' )->once()->with( [ 'memory' => 256 * 1048576, 'map' => 512 * 1048576 ], false )->andReturn( [ 'memory' => 128 * 1048576, 'map' => 0 ] );
			images::limitEditors( $editors );
			$this->assertEquals( 128 * 1048576, \Imagick::getResourceLimit( \Imagick::RESOURCETYPE_MEMORY ) );
			$this->assertEquals( 512 * 1048576, \Imagick::getResourceLimit( \Imagick::RESOURCETYPE_MAP ) );
		} finally {
			\Imagick::setResourceLimit( \Imagick::RESOURCETYPE_MEMORY, (int) $memory );
			\Imagick::setResourceLimit( \Imagick::RESOURCETYPE_MAP, (int) $map );
			\Imagick::setRegistry( 'temporary-path', sys_get_temp_dir() );
		}
	}

	/** Callbacks added to the hooks collected, by hook. */
	private array $hooks = [];

	/** The image as stored: _wp_attached_file, _wp_attachment_metadata and post meta, by key. */
	private array $store = [];

	/**
	 * WordPress's storage for image 7, in $store, with the hooks that hold a
	 * save back run as WordPress runs them.
	 */
	private function storage( string $original ): void {

		foreach ( [ 'update_post_metadata', 'add_post_metadata', 'update_attached_file', 'image_make_intermediate_size' ] as $hook ) {
			if ( ! isset( $this->hooks[ $hook ] ) ) {
				$this->hooks[ $hook ] = [];
				Filters\expectAdded( $hook )->zeroOrMoreTimes()->whenHappen( function ( $callback ) use ( $hook ) {
					$this->hooks[ $hook ][] = $callback;
				} );
			}
		}

		$save = function ( $key, $value ) {
			if ( null === $this->filter( 'update_post_metadata', null, 7, $key, $value, '' ) ) {
				$this->store[ $key ] = $value;
			}
			return true;
		};

		Functions\stubs( [
			'get_post_mime_type'           => 'image/jpeg',
			'wp_get_original_image_path'   => $original,
			'get_attached_file'            => fn() => $this->store['_wp_attached_file'],
			'wp_get_attachment_metadata'   => fn() => $this->store['_wp_attachment_metadata'],
			'get_post_meta'                => fn( $id, $key ) => $this->store[ $key ] ?? '',
			'update_post_meta'             => static fn( $id, $key, $value ) => $save( $key, $value ),
			'delete_post_meta'             => function ( $id, $key ) {
				unset( $this->store[ $key ] );
				return true;
			},
			'wp_update_attachment_metadata' => static fn( $id, $meta ) => $save( '_wp_attachment_metadata', $meta ),
			'update_attached_file'         => fn( $id, $file ) => $save( '_wp_attached_file', $this->filter( 'update_attached_file', $file, $id ) ),
			'wp_delete_file'               => static fn( $file ) => @unlink( $file ),
		] );
	}

	/** What apply_filters() gives: $value through each callback collected that is still added. */
	private function filter( string $hook, $value, ...$args ) {

		foreach ( $this->hooks[ $hook ] ?? [] as $callback ) {
			if ( false !== has_filter( $hook, $callback ) ) {
				$value = $callback( $value, ...$args );
			}
		}

		return $value;
	}

	/**
	 * An image made at quality 85, 2000 × 1333 when the threshold was off:
	 * its original, a medium and a thumbnail, now turned off. Returns the
	 * folder.
	 */
	private function madeAt85(): string {

		$dir = $this->uploads . '/' . uniqid( 'image-' );
		mkdir( $dir, 0775, true );

		foreach ( [ 'photo.jpg' => 'original', 'photo-300x200.jpg' => 'old medium', 'photo-150x150.jpg' => 'old thumbnail' ] as $name => $contents ) {
			file_put_contents( "$dir/$name", $contents );
		}

		$this->store = [
			'_wp_attached_file'       => "$dir/photo.jpg",
			'_wp_attachment_metadata' => [ 'file' => 'photo.jpg', 'width' => 2000, 'height' => 1333, 'sizes' => [ 'medium' => [ 'file' => 'photo-300x200.jpg' ], 'thumbnail' => [ 'file' => 'photo-150x150.jpg' ] ] ],
			images::QUALITY_META      => '85',
		];
		$this->settings( 92, 'thumbnail' );
		$this->storage( "$dir/photo.jpg" );

		return $dir;
	}

	/**
	 * What wp_generate_attachment_metadata() does with the threshold now on:
	 * a -scaled copy the image is pointed at, then each size, saving the
	 * metadata after each. $midway runs before the last size.
	 */
	private function generation( string $dir, ?callable $midway = null ): \Closure {

		return function ( $id, $file ) use ( $dir, $midway ) {

			$write = fn( $name ) => file_put_contents( $this->filter( 'image_make_intermediate_size', "$dir/$name" ), "new $name" );

			$write( 'photo-scaled.jpg' );
			update_attached_file( $id, "$dir/photo-scaled.jpg" );
			$meta = [ 'file' => 'photo-scaled.jpg', 'width' => 1920, 'height' => 1280, 'original_image' => 'photo.jpg', 'sizes' => [] ];
			wp_update_attachment_metadata( $id, $meta );

			foreach ( [ 'medium' => 'photo-300x200.jpg', '800x800' => 'photo-800x533.jpg' ] as $size => $name ) {
				if ( $midway && '800x800' === $size ) {
					$midway();
				}
				$write( $name );
				$meta['sizes'][ $size ] = [ 'file' => $name ];
				wp_update_attachment_metadata( $id, $meta );
			}

			// markUpToDate(), on wp_generate_attachment_metadata.
			update_post_meta( $id, images::QUALITY_META, 92 );

			return $meta;
		};
	}

	/** The folder's files and the image as stored, with the folder as {dir}. */
	private function state( string $dir ): array {

		$files = [];

		foreach ( glob( "$dir/*" ) as $file ) {
			$files[ basename( $file ) ] = file_get_contents( $file );
		}

		ksort( $files );

		return [ 'files' => $files, 'store' => json_decode( str_replace( $dir, '{dir}', json_encode( $this->store, JSON_UNESCAPED_SLASHES ) ), true ) ];
	}

	private function removeImage( string $dir ): void {

		array_map( 'unlink', glob( "$dir/*" ) );
		rmdir( $dir );
	}

	public function test_every_size_made_again_is_saved_only_once_all_are_made(): void {

		$dir = $this->madeAt85();
		$before = $this->state( $dir );
		$midway = null;
		Functions\when( 'wp_generate_attachment_metadata' )->alias( $this->generation( $dir, function () use ( $dir, &$midway ) {
			$midway = $this->state( $dir )['store'];
		} ) );

		try {
			$this->assertTrue( images::regenerate( 7 ) );

			// A PHP that died there would have left the image as it was.
			$this->assertSame( $before['store'], $midway );

			$after = $this->state( $dir );
			$this->assertSame( '{dir}/photo-scaled.jpg', $after['store']['_wp_attached_file'] );
			$this->assertSame( [
				'file'           => 'photo-scaled.jpg',
				'width'          => 1920,
				'height'         => 1280,
				'original_image' => 'photo.jpg',
				'sizes'          => [ 'medium' => [ 'file' => 'photo-300x200.jpg' ], '800x800' => [ 'file' => 'photo-800x533.jpg' ], 'thumbnail' => [ 'file' => 'photo-150x150.jpg' ] ],
			], $after['store']['_wp_attachment_metadata'] );
			$this->assertSame( 92, $after['store'][ images::QUALITY_META ] );
		} finally {
			$this->removeImage( $dir );
		}
	}

	public function test_a_failure_making_the_sizes_leaves_the_image_as_it_was(): void {

		$failures = [
			'an exception'    => fn( $dir ) => $this->generation( $dir, static function () {
				throw new \RuntimeException( 'cache resources exhausted' );
			} ),
			'no metadata'     => fn( $dir ) => function ( $id, $file ) use ( $dir ) {
				$this->generation( $dir )( $id, $file );
				return [];
			},
			'a filter\'s error' => fn( $dir ) => function ( $id, $file ) use ( $dir ) {
				$this->generation( $dir )( $id, $file );
				return new \WP_Error( 'nope', 'Not today.' );
			},
		];

		foreach ( $failures as $how => $generation ) {
			$dir = $this->madeAt85();
			$before = $this->state( $dir );
			Functions\when( 'wp_generate_attachment_metadata' )->alias( $generation( $dir ) );

			try {
				$this->assertInstanceOf( \WP_Error::class, images::regenerate( 7 ), $how );

				$after = $this->state( $dir );
				$this->assertSame( $before['store'], $after['store'], "$how: file, metadata and quality as they were" );
				// The medium made again over its file is kept; the files under
				// new names are gone.
				$this->assertSame( [ 'photo-150x150.jpg', 'photo-300x200.jpg', 'photo.jpg' ], array_keys( $after['files'] ), $how );
				$this->assertSame( 'original', $after['files']['photo.jpg'], $how );
			} finally {
				$this->removeImage( $dir );
			}
		}

		$this->assertSame( 'cache resources exhausted', ( function () {
			$dir = $this->madeAt85();
			Functions\when( 'wp_generate_attachment_metadata' )->alias( $this->generation( $dir, static function () {
				throw new \RuntimeException( 'cache resources exhausted' );
			} ) );
			try {
				return images::regenerate( 7 )->get_error_message();
			} finally {
				$this->removeImage( $dir );
			}
		} )(), 'the exception\'s message' );
	}

	public function test_making_the_sizes_again_after_a_death_or_twice_ends_as_once(): void {

		$once = $this->madeAt85();
		Functions\when( 'wp_generate_attachment_metadata' )->alias( $this->generation( $once ) );
		images::regenerate( 7 );
		$expected = $this->state( $once );

		// Twice, the second time with every size made again.
		Functions\when( 'wp_generate_attachment_metadata' )->alias( $this->generation( $once ) );
		$this->assertTrue( images::regenerate( 7 ) );
		$this->assertTrue( images::regenerate( 7, [ 'all' => true ] ) );
		$this->assertSame( $expected, $this->state( $once ) );
		$this->removeImage( $once );

		// A PHP that died before the last size: what it left, then the next
		// attempt.
		$died = $this->madeAt85();
		$left = null;
		Functions\when( 'wp_generate_attachment_metadata' )->alias( $this->generation( $died, function () use ( $died, &$left ) {
			$left = $this->state( $died );
			throw new \RuntimeException( 'died' );
		} ) );
		images::regenerate( 7 );

		array_map( 'unlink', glob( "$died/*" ) );
		foreach ( $left['files'] as $name => $contents ) {
			file_put_contents( "$died/$name", $contents );
		}
		$this->store = json_decode( str_replace( '{dir}', $died, json_encode( $left['store'], JSON_UNESCAPED_SLASHES ) ), true );

		try {
			Functions\when( 'wp_generate_attachment_metadata' )->alias( $this->generation( $died ) );
			$this->assertTrue( images::regenerate( 7 ) );
			$this->assertSame( $expected, $this->state( $died ) );
		} finally {
			$this->removeImage( $died );
		}
	}
}
