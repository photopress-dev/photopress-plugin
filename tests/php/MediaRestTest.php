<?php

namespace PhotoPress\Tests;

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
			'replaced'                => [ 'IMG_1234-v3.jpg', 'IMG_1234' ],
			'replaced and scaled'     => [ 'Photo-v12-scaled.jpg', 'Photo' ],
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
		$new = [ 'full' => '2024/05/photo-v2.jpg', 'thumbnail' => '2024/05/photo-v2-150x150.jpg', 'large' => '2024/05/photo-v2-1024x683.jpg' ];

		$this->assertSame(
			[ '2024/05/photo.jpg' => '2024/05/photo-v2.jpg', '2024/05/photo-150x150.jpg' => '2024/05/photo-v2-150x150.jpg' ],
			MediaRest::fileReplacements( $old, $new )
		);
	}

	public function test_a_size_the_new_image_lacks_maps_to_the_nearest_width(): void {

		$old = [ 'full' => 'a/photo-scaled.jpg', 'original' => 'a/photo.jpg', 'large' => 'a/photo-1024x683.jpg', '1536x1536' => 'a/photo-1536x1024.jpg' ];
		$new = [ 'full' => 'a/photo-v2.jpg', 'medium' => 'a/photo-v2-300x200.jpg', 'large' => 'a/photo-v2-1024x683.jpg' ];

		$map = MediaRest::fileReplacements(
			$old,
			$new,
			[ 'full' => 2560, 'large' => 1024, '1536x1536' => 1536 ],
			[ 'full' => 1200, 'medium' => 300, 'large' => 1024 ]
		);

		$this->assertSame( 'a/photo-v2.jpg', $map['a/photo-1536x1024.jpg'], '1536 is nearer 1200 than 1024' );
		$this->assertSame( 'a/photo-v2.jpg', $map['a/photo.jpg'], 'no original: the full size' );
		$this->assertSame( 'a/photo-v2-1024x683.jpg', $map['a/photo-1024x683.jpg'] );
	}

	public function test_rewrites_every_form_of_url(): void {

		$replacements = [
			'2024/05/photo-1024x683.jpg' => '2024/05/photo-v2-1024x683.jpg',
			'2024/05/photo.jpg'          => '2024/05/photo-v2.jpg',
		];

		$content = implode( "\n", [
			'<img src="https://example.com/wp-content/uploads/2024/05/photo-1024x683.jpg" class="wp-image-12"/>',
			'<!-- wp:cover {"url":"https:\/\/example.com\/wp-content\/uploads\/2024\/05\/photo.jpg"} -->',
			'<img src="https://cdn.example.com/wp-content/uploads/2024/05/06123456/photo.jpg">',
			'<a href="/wp-content/uploads/2024/05/photo.jpg?ver=1">',
		] );

		$this->assertSame( implode( "\n", [
			'<img src="https://example.com/wp-content/uploads/2024/05/photo-v2-1024x683.jpg" class="wp-image-12"/>',
			'<!-- wp:cover {"url":"https:\/\/example.com\/wp-content\/uploads\/2024\/05\/photo-v2.jpg"} -->',
			'<img src="https://cdn.example.com/wp-content/uploads/2024/05/06123456/photo-v2.jpg">',
			'<a href="/wp-content/uploads/2024/05/photo-v2.jpg?ver=1">',
		] ), MediaRest::rewriteReferences( $content, $replacements ) );
	}

	public function test_leaves_other_files_alone(): void {

		$content = '/uploads/2023/01/photo.jpg /uploads/2024/05/photo.jpeg /uploads/2024/05/myphoto.jpg /uploads/2024/05/photo.jpg.webp /uploads/2024/05/photo.jpg-x';

		$this->assertSame( $content, MediaRest::rewriteReferences( $content, [ '2024/05/photo.jpg' => '2024/05/photo-v2.jpg' ] ) );
	}

	public function test_rewrites_inside_serialized_meta(): void {

		$value = serialize( [ 'image' => [ 'url' => 'https://example.com/wp-content/uploads/2024/05/photo.jpg', 'id' => 12 ], 'title' => 'photo' ] );
		$rewritten = MediaRest::rewriteValue( $value, [ '2024/05/photo.jpg' => '2024/05/photo-v2.jpg' ] );

		$this->assertSame(
			[ 'image' => [ 'url' => 'https://example.com/wp-content/uploads/2024/05/photo-v2.jpg', 'id' => 12 ], 'title' => 'photo' ],
			unserialize( $rewritten ),
			'the string lengths are rebuilt'
		);
	}

	public function test_rewrites_json_meta_as_a_string(): void {

		$json = '[{"settings":{"image":{"url":"https:\/\/example.com\/wp-content\/uploads\/2024\/05\/photo.jpg","id":12}}}]';

		$this->assertSame(
			str_replace( 'photo.jpg', 'photo-v2.jpg', $json ),
			MediaRest::rewriteValue( $json, [ '2024/05/photo.jpg' => '2024/05/photo-v2.jpg' ] )
		);
	}

	public function test_leaves_serialized_objects_alone(): void {

		$value = serialize( (object) [ 'url' => 'https://example.com/wp-content/uploads/2024/05/photo.jpg' ] );

		$this->assertSame( $value, MediaRest::rewriteValue( $value, [ '2024/05/photo.jpg' => '2024/05/photo-v2.jpg' ] ) );
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
}
