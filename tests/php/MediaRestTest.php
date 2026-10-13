<?php

namespace PhotoPress\Tests;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PhotoPress\modules\media\MediaRest;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Recognizing an image by file name, and pointing content at its new files
 * after MediaRest gives it one.
 */
final class MediaRestTest extends TestCase {

	/** The checks on replaces scheduled with Action Scheduler: their arguments. */
	private array $settles = [];


	protected function setUp(): void {

		parent::setUp();

		Functions\stubs( [
			// WordPress's implementations, minus the filters.
			'wp_basename'              => static fn( $path ) => urldecode( basename( str_replace( [ '%2F', '%5C' ], '/', urlencode( $path ) ) ) ),
			'wp_get_upload_dir'        => [ 'basedir' => '/srv/wp-content/uploads' ],
			'is_serialized'            => static fn( $data ) => is_string( $data ) && ( 'b:0;' === trim( $data ) || false !== @unserialize( trim( $data ), [ 'allowed_classes' => false ] ) ),
		] );
	}

	public static function names(): array {

		return [
			'plain'                   => [ 'IMG_1234.jpg', 'IMG_1234' ],
			'no extension'            => [ 'IMG_1234', 'IMG_1234' ],
			'with a folder'           => [ '2024/05/IMG_1234.jpg', 'IMG_1234' ],
			'scaled'                  => [ 'IMG_1234-scaled.jpg', 'IMG_1234' ],
			'rotated'                 => [ 'IMG_1234-rotated.jpg', 'IMG_1234' ],
			'edited in WordPress'     => [ 'IMG_1234-e1712345678901.jpg', 'IMG_1234' ],
			'a -v in the name stays'  => [ 'IMG_1234-v3.jpg', 'IMG_1234-v3' ],
			'edited and scaled'       => [ 'Photo-e1712345678901-scaled.jpg', 'Photo' ],
			'a -1 copy is another'    => [ 'IMG_1234-1.jpg', 'IMG_1234-1' ],
			'a size is not the image' => [ 'IMG_1234-300x200.jpg', 'IMG_1234-300x200' ],
			'v in the name stays'     => [ 'trip-v.jpg', 'trip-v' ],
		];
	}

	#[DataProvider( 'names' )]
	public function test_stem( string $file, string $expected ): void {

		$this->assertSame( $expected, MediaRest::stem( $file ) );
	}

	public function test_attachment_files(): void {

		$meta = [
			'original_image' => 'photo.jpg',
			'sizes'          => [
				'thumbnail' => [ 'file' => 'photo-150x150.jpg', 'width' => 150 ],
				'large'     => [ 'file' => 'photo-1024x683.jpg', 'width' => 1024 ],
			],
		];

		$this->assertSame(
			[
				'full'      => '2024/05/photo-scaled.jpg',
				'original'  => '2024/05/photo.jpg',
				'thumbnail' => '2024/05/photo-150x150.jpg',
				'large'     => '2024/05/photo-1024x683.jpg',
			],
			MediaRest::attachmentFiles( '/srv/wp-content/uploads/2024/05/photo-scaled.jpg', $meta )
		);

		$this->assertSame( [ 'full' => 'photo.jpg' ], MediaRest::attachmentFiles( '/srv/wp-content/uploads/photo.jpg', [] ), 'no year/month folders' );
	}

	public function test_each_size_maps_to_the_same_size(): void {

		$old = [ 'full' => '2024/05/photo.jpg', 'thumbnail' => '2024/05/photo-150x150.jpg' ];
		$new = [ 'full' => '2024/05/photo-new.jpg', 'thumbnail' => '2024/05/photo-new-150x150.jpg', 'large' => '2024/05/photo-new-1024x683.jpg' ];

		$this->assertSame(
			[ '2024/05/photo.jpg' => '2024/05/photo-new.jpg', '2024/05/photo-150x150.jpg' => '2024/05/photo-new-150x150.jpg' ],
			MediaRest::fileReplacements( $old, $new )
		);
	}

	public function test_a_size_the_new_image_lacks_maps_to_the_nearest_width(): void {

		$old = [ 'full' => 'a/photo-scaled.jpg', 'original' => 'a/photo.jpg', 'large' => 'a/photo-1024x683.jpg', '1536x1536' => 'a/photo-1536x1024.jpg' ];
		$new = [ 'full' => 'a/photo-new.jpg', 'medium' => 'a/photo-new-300x200.jpg', 'large' => 'a/photo-new-1024x683.jpg' ];

		$map = MediaRest::fileReplacements(
			$old,
			$new,
			[ 'full' => 2560, 'large' => 1024, '1536x1536' => 1536 ],
			[ 'full' => 1200, 'medium' => 300, 'large' => 1024 ]
		);

		$this->assertSame( 'a/photo-new.jpg', $map['a/photo-1536x1024.jpg'], '1536 is nearer 1200 than 1024' );
		$this->assertSame( 'a/photo-new.jpg', $map['a/photo.jpg'], 'no original: the full size' );
		$this->assertSame( 'a/photo-new-1024x683.jpg', $map['a/photo-1024x683.jpg'] );
	}

	public function test_rewrites_every_form_of_url(): void {

		$replacements = [
			'2024/05/photo-1024x683.jpg' => '2024/05/photo-new-1024x683.jpg',
			'2024/05/photo.jpg'          => '2024/05/photo-new.jpg',
		];

		$content = implode( "\n", [
			'<img src="https://example.com/wp-content/uploads/2024/05/photo-1024x683.jpg" class="wp-image-12"/>',
			'<!-- wp:cover {"url":"https:\/\/example.com\/wp-content\/uploads\/2024\/05\/photo.jpg"} -->',
			'<img src="https://cdn.example.com/wp-content/uploads/2024/05/06123456/photo.jpg">',
			'<a href="/wp-content/uploads/2024/05/photo.jpg?ver=1">',
		] );

		$this->assertSame( implode( "\n", [
			'<img src="https://example.com/wp-content/uploads/2024/05/photo-new-1024x683.jpg" class="wp-image-12"/>',
			'<!-- wp:cover {"url":"https:\/\/example.com\/wp-content\/uploads\/2024\/05\/photo-new.jpg"} -->',
			'<img src="https://cdn.example.com/wp-content/uploads/2024/05/06123456/photo-new.jpg">',
			'<a href="/wp-content/uploads/2024/05/photo-new.jpg?ver=1">',
		] ), MediaRest::rewriteReferences( $content, $replacements ) );
	}

	public function test_leaves_other_files_alone(): void {

		$content = '/uploads/2023/01/photo.jpg /uploads/2024/05/photo.jpeg /uploads/2024/05/myphoto.jpg /uploads/2024/05/photo.jpg.webp /uploads/2024/05/photo.jpg-x';

		$this->assertSame( $content, MediaRest::rewriteReferences( $content, [ '2024/05/photo.jpg' => '2024/05/photo-new.jpg' ] ) );
	}

	public function test_rewrites_inside_serialized_meta(): void {

		$value = serialize( [ 'image' => [ 'url' => 'https://example.com/wp-content/uploads/2024/05/photo.jpg', 'id' => 12 ], 'title' => 'photo' ] );
		$rewritten = MediaRest::rewriteValue( $value, [ '2024/05/photo.jpg' => '2024/05/photo-new.jpg' ] );

		$this->assertSame(
			[ 'image' => [ 'url' => 'https://example.com/wp-content/uploads/2024/05/photo-new.jpg', 'id' => 12 ], 'title' => 'photo' ],
			unserialize( $rewritten ),
			'the string lengths are rebuilt'
		);
	}

	public function test_rewrites_json_meta_as_a_string(): void {

		$json = '[{"settings":{"image":{"url":"https:\/\/example.com\/wp-content\/uploads\/2024\/05\/photo.jpg","id":12}}}]';

		$this->assertSame(
			str_replace( 'photo.jpg', 'photo-new.jpg', $json ),
			MediaRest::rewriteValue( $json, [ '2024/05/photo.jpg' => '2024/05/photo-new.jpg' ] )
		);
	}

	public function test_leaves_serialized_objects_alone(): void {

		$value = serialize( (object) [ 'url' => 'https://example.com/wp-content/uploads/2024/05/photo.jpg' ] );

		$this->assertSame( $value, MediaRest::rewriteValue( $value, [ '2024/05/photo.jpg' => '2024/05/photo-new.jpg' ] ) );
	}

	public function test_size_limit_is_wordpress_threshold(): void {

		$this->assertSame( 2560, MediaRest::imageSizeThreshold(), 'WordPress default' );

		Filters\expectApplied( 'big_image_size_threshold' )->once()->with( 2560, [ 0, 0 ], '', 0 )->andReturn( 4096 );
		$this->assertSame( 4096, MediaRest::imageSizeThreshold() );
	}

	public function test_size_limit_turned_off_is_zero(): void {

		Filters\expectApplied( 'big_image_size_threshold' )->once()->andReturn( false );
		$this->assertSame( 0, MediaRest::imageSizeThreshold() );
	}

	public function test_limits_route_answers_with_the_threshold(): void {

		Functions\when( 'rest_ensure_response' )->alias( static fn( $data ) => new \WP_REST_Response( $data ) );

		$this->assertSame( [ 'image_size_threshold' => 2560 ], MediaRest::limits()->get_data() );
	}

	public function test_the_namespace_index_gives_the_versions_to_uploaders(): void {

		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'upload_files' === $cap );
		Functions\when( 'get_bloginfo' )->alias( static fn( $show ) => 'version' === $show ? '7.1.3' : '' );

		$index = static function ( $namespace ) {
			$request = new \WP_REST_Request( 'GET', "/$namespace" );
			$request->set_param( 'namespace', $namespace );
			return MediaRest::addVersions( new \WP_REST_Response( [ 'namespace' => $namespace, 'routes' => [] ] ), $request )->get_data();
		};

		$this->assertSame( [
			'namespace' => 'photopress/v1',
			'versions'  => [ 'wordpress' => '7.1.3', 'photopress' => 'test' ],
			'routes'    => [],
		], $index( 'photopress/v1' ) );
		$this->assertSame( [ 'namespace' => 'wp/v2', 'routes' => [] ], $index( 'wp/v2' ) );

		Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertSame( [ 'namespace' => 'photopress/v1', 'routes' => [] ], $index( 'photopress/v1' ), 'not to the public' );
	}

	public function test_limits_need_upload_rights(): void {

		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => 'upload_files' === $cap );
		$this->assertTrue( MediaRest::canUpload() );

		Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertFalse( MediaRest::canUpload() );
	}

	public function test_replacing_needs_upload_and_edit_rights(): void {

		$caps = [];
		Functions\when( 'current_user_can' )->alias( static function ( $cap, ...$args ) use ( &$caps ) {
			return in_array( $args ? "$cap:" . $args[0] : $cap, $caps, true );
		} );

		$request = new \WP_REST_Request( 'POST', '/photopress/v1/media/12/file' );
		$request->set_url_params( [ 'id' => '12' ] );

		$caps = [ 'upload_files' ];
		$this->assertFalse( MediaRest::canReplace( $request ), 'an author cannot replace an image of someone else' );

		$caps = [ 'upload_files', 'edit_post:12' ];
		$this->assertTrue( MediaRest::canReplace( $request ) );
	}

	public function test_metadata_is_reread_unless_the_client_says_not_to(): void {

		$m = $this->getMockBuilder( \PhotoPress\modules\metadata\metadata::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'addAttachment' ] )
			->getMock();
		$m->expects( $this->exactly( 2 ) )->method( 'addAttachment' )->with( 12 );

		$m->fileReplaced( 12, [], [], [ 'reprocess_metadata' => true ] );
		$m->fileReplaced( 12, [], [], [ 'reprocess_metadata' => false ] );
		$m->fileReplaced( 12 ); // Other callers: as for an upload.
	}

	public function test_an_image_block_gets_the_alt_text_and_caption(): void {

		Functions\when( 'wp_kses_post' )->returnArg();

		$with_caption = '<figure class="wp-block-image size-large"><img src="a.jpg" alt="Old" class="wp-image-12"/><figcaption class="wp-element-caption">Old caption</figcaption></figure>';
		$without = '<figure class="wp-block-image size-large"><img src="a.jpg" alt="" class="wp-image-12"/></figure>';

		$this->assertSame(
			'<figure class="wp-block-image size-large"><img src="a.jpg" alt="Bob at the lake" class="wp-image-12"/><figcaption class="wp-element-caption">New <em>caption</em></figcaption></figure>',
			MediaRest::imageBlockWith( $with_caption, 'Bob at the lake', 'New <em>caption</em>' )
		);
		$this->assertSame(
			'<figure class="wp-block-image size-large"><img src="a.jpg" alt="Bob" class="wp-image-12"/><figcaption class="wp-element-caption">Added</figcaption></figure>',
			MediaRest::imageBlockWith( $without, 'Bob', 'Added' ),
			'a caption is added where there was none'
		);
		$this->assertSame(
			'<figure class="wp-block-image size-large"><img src="a.jpg" alt="Old" class="wp-image-12"/></figure>',
			MediaRest::imageBlockWith( $with_caption, 'Old', '' ),
			'an empty caption removes the figcaption, as the block saves it'
		);
	}

	public function test_sync_reaches_images_in_galleries_and_only_this_image(): void {

		Functions\when( 'wp_kses_post' )->returnArg();

		$image = static fn( $id ) => [
			'blockName'    => 'core/image',
			'attrs'        => [ 'id' => $id ],
			'innerBlocks'  => [],
			'innerHTML'    => '<figure class="wp-block-image"><img src="a.jpg" alt="Old" class="wp-image-' . $id . '"/></figure>',
			'innerContent' => [ '<figure class="wp-block-image"><img src="a.jpg" alt="Old" class="wp-image-' . $id . '"/></figure>' ],
		];
		$blocks = [
			[ 'blockName' => 'core/gallery', 'attrs' => [], 'innerBlocks' => [ $image( 12 ), $image( 13 ) ], 'innerHTML' => '', 'innerContent' => [] ],
		];

		$this->assertTrue( MediaRest::syncBlocks( $blocks, 12, 'New', '' ) );
		$this->assertStringContainsString( 'alt="New"', $blocks[0]['innerBlocks'][0]['innerHTML'] );
		$this->assertSame( [ $blocks[0]['innerBlocks'][0]['innerHTML'] ], $blocks[0]['innerBlocks'][0]['innerContent'] );
		$this->assertStringContainsString( 'alt="Old"', $blocks[0]['innerBlocks'][1]['innerHTML'], 'another image is left alone' );
		$this->assertFalse( MediaRest::syncBlocks( $blocks, 12, 'New', '' ), 'nothing left to change' );
	}

	#[DataProvider( 'cacheControls' )]
	public function test_cache_control_from_the_cache_lifetime( $cache, string $expected ): void {

		$this->assertSame( $expected, \PhotoPress\modules\media\media::cacheControl( $cache ) );
	}

	public static function cacheControls(): array {

		return [
			'not set: a day'             => [ null, 'max-age=86400, stale-while-revalidate=300' ],
			'two days'                   => [ 172800, 'max-age=172800, stale-while-revalidate=300' ],
			'as a string, as saved'      => [ '3600', 'max-age=3600, stale-while-revalidate=300' ],
			'invalid: the default'       => [ 'a year', 'max-age=86400, stale-while-revalidate=300' ],
			'zero lifetime: the default' => [ 0, 'max-age=86400, stale-while-revalidate=300' ],
		];
	}

	public function test_invalidation_batches_stay_within_the_wildcard_limit(): void {

		$batch = [ \PhotoPress\modules\media\CdnInvalidator::class, 'batch' ];

		$few = [ '/u/2026/10/01/a*', '/u/2026/10/02/b*', '/u/2026/10/02/b*' ];
		$this->assertSame( [ '/u/2026/10/01/a*', '/u/2026/10/02/b*' ], $batch( $few ), 'up to 15: as they are, once each' );

		$many = array_map( static fn( $i ) => '/u/2026/' . ( $i % 3 ? '10' : '09' ) . "/img$i*", range( 1, 20 ) );
		$this->assertSame( [ '/u/2026/09/*', '/u/2026/10/*' ], $this->sorted( $batch( $many ) ), 'more: one per folder' );

		$scattered = array_map( static fn( $i ) => "/u/folder$i/img*", range( 1, 20 ) );
		$this->assertSame( [ '/*' ], $batch( $scattered ), 'more folders than that: everything' );
	}

	private function sorted( array $paths ): array {

		sort( $paths );
		return $paths;
	}

	public function test_offload_media_installed_or_not(): void {

		$state = [ \PhotoPress\modules\media\OffloadMedia::class, 'pluginState' ];

		Functions\when( 'get_plugins' )->justReturn( [ 'akismet/akismet.php' => [] ] );
		$this->assertSame( 'missing', $state() );

		Functions\when( 'get_plugins' )->justReturn( [ 'amazon-s3-and-cloudfront/wordpress-s3.php' => [] ] );
		$this->assertSame( 'inactive', $state() );

		Functions\when( 'get_plugins' )->justReturn( [ 'amazon-s3-and-cloudfront-pro/amazon-s3-and-cloudfront-pro.php' => [] ] );
		$this->assertSame( 'inactive', $state(), 'the Pro version too' );
	}

	public function test_bucket_keys_of_removed_files(): void {

		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

		$as3cf = new class() {
			public function get_setting( $key ) {
				return [ 'serve-from-s3' => true, 'enable-delivery-domain' => true, 'delivery-domain' => 'cdn.example.com' ][ $key ] ?? null;
			}
		};
		\PhotoPress\modules\media\OffloadMedia::init( $as3cf );
		$key = [ \PhotoPress\modules\media\CdnInvalidator::class, 'objectKey' ];

		try {
			$this->assertSame( 'wp-content/uploads/2026/10/07072326/photo-300x200.jpg', $key( 'https://cdn.example.com/wp-content/uploads/2026/10/07072326/photo-300x200.jpg', 'images.example.com' ) );
			$this->assertSame( 'wp-content/uploads/a b.jpg', $key( 'https://images.example.com.s3.us-east-1.amazonaws.com/wp-content/uploads/a%20b.jpg', 'images.example.com' ), 'served from the bucket' );
			$this->assertNull( $key( 'https://www.example.com/wp-content/uploads/photo.jpg', 'images.example.com' ), 'served from this server' );
		} finally {
			\PhotoPress\modules\media\OffloadMedia::init( null );
		}
	}

	public function test_replaced_files_stay_in_the_bucket_unless_the_setting_is_on(): void {

		$as3cf = new class() {
			public function get_setting( $key ) {
				return [ 'bucket' => 'images.example.com', 'region' => 'us-east-1', 'serve-from-s3' => true, 'enable-delivery-domain' => true, 'delivery-domain' => 'cdn.example.com' ][ $key ] ?? null;
			}
		};
		\PhotoPress\modules\media\OffloadMedia::init( $as3cf );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		// Action Scheduler loaded, with work pending (jobs/Scheduler.php).
		Functions\stubs( [ 'as_has_scheduled_action' => false, 'get_option' => 'pending' ] );
		$urls = [ 'https://cdn.example.com/wp-content/uploads/2026/10/photo-300x200.jpg' ];

		try {
			Functions\expect( 'as_schedule_single_action' )->never();
			\PhotoPress\modules\media\CdnInvalidator::scheduleDeletion( 12, $urls );

			\pp_api::$options['core/media/delete_replaced_objects'] = true;
			Functions\expect( 'as_schedule_single_action' )->once()->with(
				\Mockery::type( 'int' ),
				'photopress_offload_delete_objects',
				[ 'images.example.com', 'us-east-1', [ 'wp-content/uploads/2026/10/photo-300x200.jpg' ], 1 ],
				'photopress'
			);
			\PhotoPress\modules\media\CdnInvalidator::scheduleDeletion( 12, $urls );
		} finally {
			\PhotoPress\modules\media\OffloadMedia::init( null );
		}
	}

	public function test_references_are_looked_for_as_file_names_after_a_slash(): void {

		global $wpdb;
		$saved = $wpdb;
		$wpdb = new class() { public function esc_like( $text ) { return addcslashes( $text, '_%\\' ); } };

		$likes = ( new \ReflectionMethod( MediaRest::class, 'referenceLikes' ) )->invoke( null, 'a' );
		$wpdb = $saved;

		// A name as short as "a" no longer matches every row with an a in it.
		$this->assertSame( [ '%/a.%', '%/a-%', '%\\\\/a.%', '%\\\\/a-%' ], $likes );
	}

	/** The uploads folder of the replace tests. */
	private string $uploads = '';

	/** Image 12 as stored: its file, metadata, MIME type and post meta, by key. */
	private array $store = [];

	/** Callbacks added to image_make_intermediate_size. */
	private array $tracking = [];

	/**
	 * Image 12, 2000 × 1333, with a medium and a large size, in uploads in
	 * a temporary folder; the new file for it saved next to them. Returns
	 * the folder.
	 */
	private function storedImage(): string {

		$this->uploads = sys_get_temp_dir() . '/pp-test-replace-' . uniqid();
		$dir = $this->uploads . '/2024/05';
		mkdir( $dir, 0775, true );

		foreach ( [ 'photo.jpg' => 'old original', 'photo-300x200.jpg' => 'old medium', 'photo-1024x683.jpg' => 'old large' ] as $name => $contents ) {
			file_put_contents( "$dir/$name", $contents );
		}

		$this->store = [
			'file' => "$dir/photo.jpg",
			'meta' => [ 'file' => '2024/05/photo.jpg', 'width' => 2000, 'height' => 1333, 'sizes' => [ 'medium' => [ 'file' => 'photo-300x200.jpg' ], 'large' => [ 'file' => 'photo-1024x683.jpg' ] ] ],
			'mime' => 'image/jpeg',
		];

		if ( ! $this->tracking ) {
			$this->tracking = [ 'collected' => true ];
			\Brain\Monkey\Filters\expectAdded( 'image_make_intermediate_size' )->zeroOrMoreTimes()->whenHappen( function ( $callback ) {
				$this->tracking[] = $callback;
			} );
		}

		Functions\stubs( [
			// Action Scheduler, with work pending: each check on a replace scheduled is kept.
			'get_option'                    => 'pending',
			'as_has_scheduled_action'       => false,
			'as_get_scheduled_actions'      => fn( $query ) => array_keys( array_filter( $this->settles, static fn( $args ) => $args === ( $query['args'] ?? $args ) ) ),
			'as_schedule_single_action'     => function ( $when, $hook, $args ) {
				$this->settles[] = $args;
				return 1;
			},
			'wp_get_upload_dir'             => [ 'basedir' => $this->uploads ],
			'path_join'                     => static fn( $base, $path ) => str_starts_with( $path, '/' ) ? $path : rtrim( $base, '/' ) . '/' . $path,
			'is_wp_error'                   => static fn( $thing ) => $thing instanceof \WP_Error,
			'get_post_mime_type'            => fn() => $this->store['mime'],
			'wp_update_post'                => function ( $post ) {
				$this->store['mime'] = $post['post_mime_type'];
				return $post['ID'];
			},
			'get_attached_file'             => fn() => $this->store['file'],
			'update_attached_file'          => function ( $id, $file ) {
				$this->store['file'] = $file;
				return true;
			},
			'wp_get_attachment_metadata'    => fn() => $this->store['meta'],
			'wp_update_attachment_metadata' => function ( $id, $meta ) {
				$this->store['meta'] = $meta;
				return true;
			},
			'get_post_meta'                 => fn( $id, $key ) => $this->store[ $key ] ?? '',
			'update_post_meta'              => function ( $id, $key, $value ) {
				$this->store[ $key ] = $value;
				return true;
			},
			'add_post_meta'                 => function ( $id, $key, $value, $unique ) {
				if ( $unique && isset( $this->store[ $key ] ) ) {
					return false;
				}
				$this->store[ $key ] = $value;
				return 1;
			},
			'delete_post_meta'              => function ( $id, $key ) {
				unset( $this->store[ $key ] );
				return true;
			},
			'clean_attachment_cache'        => null,
			'wp_delete_file'                => static fn( $file ) => @unlink( $file ),
			// The new file: 3000 × 2000, so WordPress makes a -scaled copy.
			'wp_getimagesize'               => [ 3000, 2000, 'mime' => 'image/jpeg' ],
			'wp_image_editor_supports'      => true,
			'wp_get_registered_image_subsizes' => [
				'medium' => [ 'width' => 300, 'height' => 300, 'crop' => false ],
				'large'  => [ 'width' => 1024, 'height' => 1024, 'crop' => false ],
				'huge'   => [ 'width' => 4000, 'height' => 4000, 'crop' => false ],
			],
			'image_resize_dimensions'       => static fn( $w, $h, $sw, $sh ) => ( $sw && $w > $sw ) || ( $sh && $h > $sh ) ? [ 0, 0, 0, 0, $sw, $sh, $w, $h ] : false,
		] );

		file_put_contents( "$dir/photo-photopress-replacing.jpg", 'new original' );

		return $dir;
	}

	private function removeUploads(): void {

		array_map( 'unlink', glob( $this->uploads . '/2024/05/*' ) );
		rmdir( $this->uploads . '/2024/05' );
		rmdir( $this->uploads . '/2024' );
		rmdir( $this->uploads );
	}

	/**
	 * What wp_generate_attachment_metadata() does with the new file: a
	 * -scaled copy, then each size, saving the metadata after each. A size
	 * in $skipped is not made, as when Imagick fails; $midway runs before
	 * the large size.
	 */
	private function generation( string $dir, array $skipped = [], ?callable $midway = null ): \Closure {

		return function ( $id, $file ) use ( $dir, $skipped, $midway ) {

			$write = function ( $name ) use ( $dir ) {
				$path = "$dir/$name";
				foreach ( $this->tracking as $key => $callback ) {
					if ( 'collected' !== $key && false !== has_filter( 'image_make_intermediate_size', $callback ) ) {
						$path = $callback( $path );
					}
				}
				file_put_contents( $path, "new $name" );
			};

			$write( 'photo-scaled.jpg' );
			update_attached_file( $id, "$dir/photo-scaled.jpg" );
			$meta = [ 'file' => '2024/05/photo-scaled.jpg', 'width' => 2560, 'height' => 1707, 'original_image' => 'photo.jpg', 'sizes' => [] ];
			wp_update_attachment_metadata( $id, $meta );

			foreach ( [ 'medium' => 'photo-300x200.jpg', 'large' => 'photo-1024x683.jpg' ] as $size => $name ) {
				if ( $midway && 'large' === $size ) {
					$midway();
				}
				if ( in_array( $size, $skipped, true ) ) {
					continue;
				}
				$write( $name );
				$meta['sizes'][ $size ] = [ 'file' => $name ];
				wp_update_attachment_metadata( $id, $meta );
			}

			return $meta;
		};
	}

	/** The image's files and the image as stored, with the folder as {dir}. */
	private function state( string $dir ): array {

		$files = [];

		foreach ( glob( "$dir/*" ) as $file ) {
			$files[ basename( $file ) ] = file_get_contents( $file );
		}

		ksort( $files );

		return [ 'files' => $files, 'store' => json_decode( str_replace( $dir, '{dir}', json_encode( $this->store, JSON_UNESCAPED_SLASHES ) ), true ) ];
	}

	private function install( string $dir ) {

		$install = new \ReflectionMethod( MediaRest::class, 'install' );
		$own = [ "$dir/photo.jpg", "$dir/photo-300x200.jpg", "$dir/photo-1024x683.jpg" ];

		return $install->invoke( null, 12, "$dir/photo-photopress-replacing.jpg", "$dir/photo.jpg", 'image/jpeg', $this->store['file'], $this->store['meta'], $own );
	}

	private const REPLACED = [
		'files' => [
			'photo-1024x683.jpg' => 'new photo-1024x683.jpg',
			'photo-300x200.jpg'  => 'new photo-300x200.jpg',
			'photo-scaled.jpg'   => 'new photo-scaled.jpg',
			'photo.jpg'          => 'new original',
		],
		'store' => [
			'file' => '{dir}/photo-scaled.jpg',
			'meta' => [ 'file' => '2024/05/photo-scaled.jpg', 'width' => 2560, 'height' => 1707, 'original_image' => 'photo.jpg', 'sizes' => [ 'medium' => [ 'file' => 'photo-300x200.jpg' ], 'large' => [ 'file' => 'photo-1024x683.jpg' ] ] ],
			'mime' => 'image/jpeg',
		],
	];

	public function test_a_new_file_with_every_size_made_replaces_the_old_with_no_backup_left(): void {

		$dir = $this->storedImage();
		Functions\when( 'wp_generate_attachment_metadata' )->alias( $this->generation( $dir ) );

		try {
			$this->assertTrue( $this->install( $dir ) );
			$this->assertSame( self::REPLACED, $this->state( $dir ) );
		} finally {
			$this->removeUploads();
		}
	}

	public function test_a_size_wordpress_skipped_leaves_the_image_as_it_was(): void {

		$dir = $this->storedImage();
		$before = $this->state( $dir );
		unset( $before['files']['photo-photopress-replacing.jpg'] );
		Functions\when( 'wp_generate_attachment_metadata' )->alias( $this->generation( $dir, [ 'large' ] ) );

		try {
			$error = $this->install( $dir );
			$this->assertInstanceOf( \WP_Error::class, $error );
			$this->assertSame( 'photopress_sizes_not_made', $error->get_error_code() );
			$this->assertStringContainsString( ': large.', $error->get_error_message() );
			$this->assertSame( [ 'status' => 500, 'sizes' => [ 'large' ] ], $error->get_error_data() );

			// The old files and metadata back; the new files, the -scaled
			// copy among them, and the backups gone.
			$this->assertSame( $before, $this->state( $dir ) );
		} finally {
			$this->removeUploads();
		}
	}

	public function test_an_exception_making_the_sizes_leaves_the_image_as_it_was(): void {

		$dir = $this->storedImage();
		$before = $this->state( $dir );
		unset( $before['files']['photo-photopress-replacing.jpg'] );
		Functions\when( 'wp_generate_attachment_metadata' )->alias( $this->generation( $dir, [], static function () {
			throw new \RuntimeException( 'cache resources exhausted' );
		} ) );

		try {
			$this->assertSame( 'cache resources exhausted', $this->install( $dir )->get_error_message() );
			$this->assertSame( $before, $this->state( $dir ) );
		} finally {
			$this->removeUploads();
		}
	}

	public function test_a_replace_that_died_is_undone_by_the_next_and_two_end_as_one(): void {

		$dir = $this->storedImage();
		$before = $this->state( $dir );
		unset( $before['files']['photo-photopress-replacing.jpg'] );

		// What a PHP that died before the large size left: the new original
		// and two of its files in place, the backups, and the record.
		$left = null;
		Functions\when( 'wp_generate_attachment_metadata' )->alias( $this->generation( $dir, [], function () use ( $dir, &$left ) {
			$left = $this->state( $dir );
			throw new \RuntimeException( 'died' );
		} ) );
		$this->install( $dir );

		array_map( 'unlink', glob( "$dir/*" ) );
		foreach ( $left['files'] as $name => $contents ) {
			file_put_contents( "$dir/$name", $contents );
		}
		$this->store = json_decode( str_replace( '{dir}', $dir, json_encode( $left['store'], JSON_UNESCAPED_SLASHES ) ), true );
		$this->assertArrayHasKey( 'photo.jpg.photopress-backup', $left['files'] );

		$end = new \ReflectionMethod( MediaRest::class, 'endPending' );

		try {
			// Still under way, as far as anyone can tell: another replace waits,
			// and its new file is removed.
			$this->assertSame( 409, $end->invoke( null, 12 )->get_error_data()['status'] );
			file_put_contents( "$dir/photo-photopress-replacing.jpg", 'new original' );
			$this->assertSame( 409, $this->install( $dir )->get_error_data()['status'] );
			$this->assertSame( $left, $this->state( $dir ) );

			$this->store[ MediaRest::RESTORE_META_KEY ]['time'] -= MediaRest::REPLACE_SECONDS;
			$this->assertTrue( $end->invoke( null, 12 ) );
			$this->assertSame( $before, $this->state( $dir ) );
			$this->assertTrue( $end->invoke( null, 12 ), 'nothing left to undo' );
			$this->assertSame( $before, $this->state( $dir ) );

			// Then the replace again, and once more with the same file.
			Functions\when( 'wp_generate_attachment_metadata' )->alias( $this->generation( $dir ) );
			file_put_contents( "$dir/photo-photopress-replacing.jpg", 'new original' );
			$this->assertTrue( $this->install( $dir ) );
			$this->assertSame( self::REPLACED, $this->state( $dir ) );

			file_put_contents( "$dir/photo-photopress-replacing.jpg", 'new original' );
			$install = new \ReflectionMethod( MediaRest::class, 'install' );
			$own = [ "$dir/photo-scaled.jpg", "$dir/photo.jpg", "$dir/photo-300x200.jpg", "$dir/photo-1024x683.jpg" ];
			$this->assertTrue( $install->invoke( null, 12, "$dir/photo-photopress-replacing.jpg", "$dir/photo.jpg", 'image/jpeg', $this->store['file'], $this->store['meta'], $own ) );
			$this->assertSame( self::REPLACED, $this->state( $dir ) );
		} finally {
			$this->removeUploads();
		}
	}

	public function test_a_replace_that_died_is_undone_by_its_scheduled_check_without_another(): void {

		$dir = $this->storedImage();
		$before = $this->state( $dir );
		unset( $before['files']['photo-photopress-replacing.jpg'] );
		Functions\when( 'wp_generate_attachment_metadata' )->alias( $this->generation( $dir, [], static function () {
			throw new \RuntimeException( 'died' );
		} ) );

		try {
			// The check is scheduled as the replace begins.
			$this->install( $dir );
			$this->assertSame( [ [ 12 ] ], $this->settles );

			// As if the request had died: the record left, and its time past.
			$this->store[ MediaRest::RESTORE_META_KEY ] = [ 'time' => time() - MediaRest::REPLACE_SECONDS - 1, 'file' => $before['store']['file'] ?? $this->store['file'], 'meta' => $this->store['meta'], 'mime' => $this->store['mime'], 'saved' => "$dir/photo-photopress-replacing.jpg", 'backups' => [] ];
			MediaRest::settle( 12 );
			$this->assertArrayNotHasKey( MediaRest::RESTORE_META_KEY, $this->store );

			// Run again: nothing left to do, nothing scheduled.
			MediaRest::settle( 12 );
			$this->assertSame( [ [ 12 ] ], $this->settles );
		} finally {
			$this->removeUploads();
		}
	}

	public function test_a_replace_that_died_after_its_files_were_kept_is_finished(): void {

		$dir = $this->storedImage();
		Functions\when( 'wp_generate_attachment_metadata' )->alias( $this->generation( $dir ) );

		try {
			$this->assertTrue( $this->install( $dir ) );

			// Died after the new metadata was saved, before the backups went.
			file_put_contents( "$dir/photo.jpg.photopress-backup", 'old original' );
			$this->store[ MediaRest::RESTORE_META_KEY ] = [ 'time' => time(), 'committed' => true, 'backups' => [ "$dir/photo.jpg" => "$dir/photo.jpg.photopress-backup" ] ];

			$this->assertTrue( ( new \ReflectionMethod( MediaRest::class, 'endPending' ) )->invoke( null, 12 ) );
			$this->assertSame( self::REPLACED, $this->state( $dir ) );
		} finally {
			$this->removeUploads();
		}
	}

	public function test_sizes_too_large_for_the_new_file_or_not_made_are_not_missing(): void {

		$this->storedImage();

		try {
			$meta = [ 'sizes' => [ 'medium' => [ 'file' => 'a.jpg' ], 'large' => [ 'file' => 'b.jpg' ] ] ];
			$this->assertSame( [], MediaRest::missingSizes( 12, 'photo.jpg', $meta ), 'huge is larger than the file' );
			$this->assertSame( [ 'large' ], MediaRest::missingSizes( 12, 'photo.jpg', [ 'sizes' => [ 'medium' => [ 'file' => 'a.jpg' ] ] ] ) );

			// Turned off (the images module), or for a type no editor here handles.
			\Brain\Monkey\Filters\expectApplied( 'intermediate_image_sizes_advanced' )->once()->andReturn( [ 'medium' => [ 'width' => 300, 'height' => 300, 'crop' => false ] ] );
			$this->assertSame( [], MediaRest::missingSizes( 12, 'photo.jpg', [ 'sizes' => [ 'medium' => [ 'file' => 'a.jpg' ] ] ] ) );
			Functions\when( 'wp_image_editor_supports' )->justReturn( false );
			$this->assertSame( [], MediaRest::missingSizes( 12, 'photo.jpg', [] ) );
		} finally {
			$this->removeUploads();
		}
	}

	public function test_the_temporary_file_is_removed_when_the_body_cannot_be_saved(): void {

		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Permissions do not hold for root.' );
		}

		$tmp = sys_get_temp_dir() . '/pp-test-body-' . uniqid() . '.tmp';
		// As wp_tempnam() makes it, here one that cannot be written.
		Functions\when( 'wp_tempnam' )->alias( static function () use ( $tmp ) {
			touch( $tmp );
			chmod( $tmp, 0444 );
			return $tmp;
		} );

		require_once ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-posts-controller.php';
		require_once ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-attachments-controller.php';

		$request = new \WP_REST_Request( 'POST', '/photopress/v1/media/12/file' );
		$request->set_body( 'jpeg' );
		$request->set_header( 'Content-Disposition', 'attachment; filename="photo.jpg"' );

		$error = @( new \ReflectionMethod( MediaRest::class, 'uploadedFile' ) )->invoke( null, $request );

		$this->assertSame( 'rest_upload_unknown_error', $error->get_error_code() );
		$this->assertFileDoesNotExist( $tmp );
	}
}
