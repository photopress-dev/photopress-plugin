<?php

namespace PhotoPress\Tests;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PhotoPress\modules\media\MediaRest;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Recognising an image by file name, and pointing content at its new files
 * after MediaRest gives it one.
 */
final class MediaRestTest extends TestCase {

	protected function setUp(): void {

		parent::setUp();

		Functions\stubs( [
			// WordPress's implementations, minus the filters.
			'wp_basename'              => static fn( $path ) => urldecode( basename( str_replace( [ '%2F', '%5C' ], '/', urlencode( $path ) ) ) ),
			'_wp_relative_upload_path' => static fn( $path ) => preg_replace( '#^/srv/wp-content/uploads/#', '', $path ),
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
	public function test_cache_control_from_the_two_durations( $cache, $stale, string $expected ): void {

		$this->assertSame( $expected, \PhotoPress\modules\media\media::cacheControl( $cache, $stale ) );
	}

	public static function cacheControls(): array {

		return [
			'not set: a day, then an hour' => [ null, null, 'max-age=86400, stale-while-revalidate=3600' ],
			'two days, then six hours'     => [ 172800, 21600, 'max-age=172800, stale-while-revalidate=21600' ],
			'as strings, as saved'         => [ '3600', '600', 'max-age=3600, stale-while-revalidate=600' ],
			'no stale period'              => [ 3600, 0, 'max-age=3600' ],
			'invalid: the defaults'        => [ 'a year', -5, 'max-age=86400, stale-while-revalidate=3600' ],
			'zero lifetime: the default'   => [ 0, 60, 'max-age=86400, stale-while-revalidate=60' ],
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

		$state = [ \PhotoPress\modules\media\CdnInvalidator::class, 'pluginState' ];

		Functions\when( 'get_plugins' )->justReturn( [ 'akismet/akismet.php' => [] ] );
		$this->assertSame( 'missing', $state() );

		Functions\when( 'get_plugins' )->justReturn( [ 'amazon-s3-and-cloudfront/wordpress-s3.php' => [] ] );
		$this->assertSame( 'inactive', $state() );

		Functions\when( 'get_plugins' )->justReturn( [ 'amazon-s3-and-cloudfront-pro/amazon-s3-and-cloudfront-pro.php' => [] ] );
		$this->assertSame( 'inactive', $state(), 'the Pro version too' );
	}

	public function test_bucket_keys_of_removed_files(): void {

		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

		$invalidator = new \ReflectionClass( \PhotoPress\modules\media\CdnInvalidator::class );
		$as3cf = new class() {
			public function get_setting( $key ) {
				return [ 'serve-from-s3' => true, 'enable-delivery-domain' => true, 'delivery-domain' => 'cdn.example.com' ][ $key ] ?? null;
			}
		};
		$invalidator->setStaticPropertyValue( 'as3cf', $as3cf );
		$key = [ \PhotoPress\modules\media\CdnInvalidator::class, 'objectKey' ];

		try {
			$this->assertSame( 'wp-content/uploads/2026/10/07072326/photo-300x200.jpg', $key( 'https://cdn.example.com/wp-content/uploads/2026/10/07072326/photo-300x200.jpg', 'images.example.com' ) );
			$this->assertSame( 'wp-content/uploads/a b.jpg', $key( 'https://images.example.com.s3.us-east-1.amazonaws.com/wp-content/uploads/a%20b.jpg', 'images.example.com' ), 'served from the bucket' );
			$this->assertNull( $key( 'https://www.example.com/wp-content/uploads/photo.jpg', 'images.example.com' ), 'served from this server' );
		} finally {
			$invalidator->setStaticPropertyValue( 'as3cf', null );
		}
	}

	public function test_replaced_files_stay_in_the_bucket_unless_the_setting_is_on(): void {

		$invalidator = new \ReflectionClass( \PhotoPress\modules\media\CdnInvalidator::class );
		$as3cf = new class() {
			public function get_setting( $key ) {
				return [ 'bucket' => 'images.example.com', 'region' => 'us-east-1', 'serve-from-s3' => true, 'enable-delivery-domain' => true, 'delivery-domain' => 'cdn.example.com' ][ $key ] ?? null;
			}
		};
		$invalidator->setStaticPropertyValue( 'as3cf', $as3cf );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
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
			$invalidator->setStaticPropertyValue( 'as3cf', null );
		}
	}
}

